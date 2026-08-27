<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Database;
use PDOException;
use SimpleAIman\Atendimento\Fila;
use SimpleAIman\Canais\Anexos;
use SimpleAIman\Canais\CanalWhatsapp;
use SimpleAIman\Llm\ChatService;
use SimpleAIman\Llm\ErroAgente;
use SimpleAIman\Rag\Ingestor;
use Throwable;

/**
 * Processa a fila até o tempo acabar, e sai limpo.
 *
 * Desenhado para hospedagem compartilhada, onde não há processo persistente:
 * o cron chama a cada N minutos e o upload dispara um "kick" logo depois.
 * Por isso o worker nunca trabalha "até terminar" — trabalha até o orçamento
 * de tempo, grava onde parou e devolve o job para a fila.
 *
 * O `max_execution_time` não é uma ameaça a evitar: é o relógio do desenho.
 */
final class Worker
{
    /** Margem antes do limite real, para dar tempo de gravar o progresso. */
    private const MARGEM_SEGUNDOS = 5;

    private float $inicio;

    public function __construct(
        private readonly int $tempoMaximo = 20,
        private readonly int $tamanhoLote = 25,
    ) {
        $this->inicio = microtime(true);
    }

    private function tempoAcabou(): bool
    {
        return (microtime(true) - $this->inicio) >= max(1, $this->tempoMaximo - self::MARGEM_SEGUNDOS);
    }

    /**
     * @param callable(string): void|null $log
     * @return array{processados: int, concluidos: int, falhas: int, pausas: int}
     */
    public function executar(?callable $log = null): array
    {
        $log ??= static fn (string $m): null => null;

        // Rotinas de manutencao entram aqui, no maximo uma vez por dia. Nao ha
        // agendador proprio nesta arquitetura: o cron so chama o worker, entao
        // e o worker que decide o que ja passou da hora.
        if (Queue::agendarPeriodico('retencao')) {
            $log('retencao do dia enfileirada.');
        }

        // Conversas que pediram atendente e ninguém assumiu. As telas também
        // fazem esta varredura, mas só quando alguém está olhando — e o caso
        // que mais importa é justamente o contrário: ninguém no painel, o
        // visitante esperando sozinho. Aqui é a rede de segurança.
        $expiradas = Fila::expirarAbandonadas();

        if ($expiradas > 0) {
            $log("{$expiradas} conversa(s) devolvida(s) ao assistente por espera longa.");
        }

        // Conversas paradas. Como a varredura de abandono, roda aqui porque o
        // caso que mais importa e o de NAO haver ninguem no painel olhando.
        // Conversa presa com atendente que fechou o navegador. Aqui é a rede
        // de segurança: se ninguém abrir o painel, ninguém a resgataria.
        $orfas = Fila::resgatarOrfas();

        if ($orfas > 0) {
            $log("{$orfas} conversa(s) devolvida(s) a fila: atendente sem presenca.");
        }

        $inativas = Fila::encerrarInativas();

        if (array_sum($inativas) > 0) {
            $log("inatividade: {$inativas['avisadas']} aviso(s), "
                . "{$inativas['humano']} atendimento(s) e {$inativas['bot']} conversa(s) encerrada(s).");
        }

        $liberados = Queue::liberarPresos();

        if ($liberados > 0) {
            $log("{$liberados} job(s) com lock expirado devolvidos à fila.");
        }

        $placar = ['processados' => 0, 'concluidos' => 0, 'falhas' => 0, 'pausas' => 0];

        while (!$this->tempoAcabou()) {
            $job = Queue::proximo();

            if ($job === null) {
                break;
            }

            $placar['processados']++;
            $resultado = $this->processar($job, $log);
            $placar[$resultado] = ($placar[$resultado] ?? 0) + 1;
        }

        return $placar;
    }

    /**
     * Anonimizacao e expurgo do conteudo das conversas.
     *
     * Nasce desligado (dias = 0 nos dois estagios), entao esta rodada custa
     * duas leituras e termina. So faz alguma coisa depois que alguem escolhe
     * um prazo em Configuracoes.
     *
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'
     */
    private function aplicarRetencao(array $job, callable $log): string
    {
        $placar = (new \SimpleAIman\Jobs\Retencao())->executar($log);

        Queue::concluir((int) $job['id']);

        if ($placar['anonimizadas'] === 0 && $placar['expurgadas'] === 0) {
            $log('retencao: nada vencido.');
        }

        return 'concluidos';
    }

    /**
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'falhas'|'pausas'
     */
    private function processar(array $job, callable $log): string
    {
        $id = (int) $job['id'];

        try {
            return match ($job['tipo']) {
                'ingestao' => $this->ingerir($job, $log),
                'entrega_lead' => $this->entregarLead($job, $log),
                'retencao' => $this->aplicarRetencao($job, $log),
                'entrada_whatsapp' => $this->responderWhatsapp($job, $log),
                default => throw new \RuntimeException("Tipo de job desconhecido: {$job['tipo']}"),
            };
        } catch (ErroAgente $e) {
            // Cota estourada NÃO é falha do artefato: é situação normal numa
            // ingestão grande no free tier. Vira pausa, e o job volta sozinho
            // quando a janela virar. Marcar como erro faria o admin ver um
            // documento "quebrado" que só precisava esperar.
            if ($e->codigo === 'provedor_cota') {
                Queue::pausar($id, 90, 'Limite de requisições do provedor. Retomando automaticamente.');
                $log("job #{$id}: cota do provedor — pausado por 90s.");

                return 'pausas';
            }

            Queue::falhar($id, $e->paraLog());
            $this->marcarArtefatoComErro($job, $e->paraLog());
            $log("job #{$id}: FALHOU — {$e->paraLog()}");

            return 'falhas';
        } catch (Throwable $e) {
            Queue::falhar($id, $e->getMessage());
            $this->marcarArtefatoComErro($job, $e->getMessage());
            $log("job #{$id}: FALHOU — {$e->getMessage()}");

            return 'falhas';
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'pausas'
     */
    private function ingerir(array $job, callable $log): string
    {
        $id = (int) $job['id'];
        $artefatoId = (int) ($job['payload']['artefato_id'] ?? 0);

        if ($artefatoId <= 0) {
            throw new \RuntimeException('Job de ingestão sem artefato_id.');
        }

        $pdo = Database::connection();
        $ingestor = new Ingestor();

        $pdo->prepare('UPDATE artefatos SET status = \'processando\', erro = NULL, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $artefatoId]);

        // Fase 1 só na primeira passada. Nas retomadas os chunks já existem —
        // refazer apagaria e recriaria tudo, jogando fora os vetores prontos.
        if (empty($job['progresso']['extraido'])) {
            $total = $ingestor->extrair($artefatoId);
            Queue::progresso($id, ['extraido' => true, 'total' => $total, 'feitos' => 0]);
            $log("job #{$id}: artefato {$artefatoId} extraído em {$total} chunks.");
        }

        $feitos = (int) ($job['progresso']['feitos'] ?? 0);
        $total = (int) ($job['progresso']['total'] ?? $ingestor->pendentes($artefatoId) + $feitos);

        // Fase 2, em lotes, respeitando o orçamento de tempo.
        while (!$this->tempoAcabou()) {
            $n = $ingestor->embeddar($artefatoId, $this->tamanhoLote);

            if ($n === 0) {
                break;
            }

            $feitos += $n;
            Queue::progresso($id, ['extraido' => true, 'total' => $total, 'feitos' => $feitos]);
            $log("job #{$id}: {$feitos}/{$total} chunks embeddados.");
        }

        $restantes = $ingestor->pendentes($artefatoId);

        if ($restantes > 0) {
            // Ainda falta: devolve à fila e continua na próxima execução.
            Queue::devolver($id);
            $pdo->prepare('UPDATE artefatos SET status = \'processando\', editado_em = :agora WHERE id = :id')
                ->execute(['agora' => now(), 'id' => $artefatoId]);
            $log("job #{$id}: tempo esgotado com {$restantes} chunk(s) restantes — continua na próxima execução.");

            return 'pausas';
        }

        Queue::concluir($id);
        $pdo->prepare('UPDATE artefatos SET status = \'ok\', erro = NULL, editado_em = :agora WHERE id = :id')
            ->execute(['agora' => now(), 'id' => $artefatoId]);

        $log("job #{$id}: artefato {$artefatoId} concluído ({$feitos} chunks).");

        return 'concluidos';
    }

    /**
     * Entrega de lead ao destino externo.
     *
     * A falha aqui NÃO marca o job como erro: o `lead_destinos` já registra
     * tentativa, backoff e dead letter por conta própria, e é ele quem o
     * painel mostra. Duplicar o estado no job faria o admin ver dois lugares
     * dizendo coisas diferentes sobre a mesma entrega.
     *
     * @param array<string, mixed> $job
     * @param callable(string): void $log
     * @return 'concluidos'|'pausas'
     */
    private function entregarLead(array $job, callable $log): string
    {
        $leadId = (int) ($job['payload']['lead_id'] ?? 0);

        if ($leadId <= 0) {
            throw new \RuntimeException('Job de entrega sem lead_id.');
        }

        try {
            \SimpleAIman\Tools\LeadTool::entregar($leadId);
            Queue::concluir((int) $job['id']);
            $log("job #{$job['id']}: lead {$leadId} entregue.");

            return 'concluidos';
        } catch (Throwable $e) {
            // O reagendamento já foi feito pelo LeadTool, com backoff.
            Queue::concluir((int) $job['id']);
            $log("job #{$job['id']}: {$e->getMessage()}");

            return 'pausas';
        }
    }

    /**
     * Um turno de conversa vindo do WhatsApp.
     *
     * Roda aqui, e não no webhook, porque a Meta espera 200 em segundos e
     * reenvia se demorar — e reenvio vira resposta duplicada para a pessoa.
     * A LLM leva o tempo que leva; o webhook não pode esperar por ela.
     *
     * **Todo caminho de saída precisa chamar `Queue::concluir()`.** Quem
     * processa é dono de fechar o job: o laço do worker não fecha por ele.
     * Sem isso o job fica `processando`, o `liberarPresos()` o devolve à fila
     * quando o lock vence, e a pessoa recebe a MESMA resposta de novo — de
     * cinco em cinco minutos, até estourarem as tentativas. Foi o que
     * aconteceu no primeiro teste real.
     *
     * @param array<string, mixed> $job
     *
     * @return 'concluidos'
     */
    private function responderWhatsapp(array $job, callable $log): string
    {
        // O payload já chega decodificado — a fila faz isso para todos os
        // handlers, como em `ingerir()` e `entregarLead()`.
        $dados = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        $canalId = (int) ($dados['canal_id'] ?? 0);

        // O canal vem pelo id que o webhook gravou, não por uma variável fixa:
        // uma instalação pode ter mais de um número, cada um com seu prefixo
        // de credenciais e seu agente.
        $canal = CanalWhatsapp::porId($canalId);
        $de = (string) ($dados['de'] ?? '');

        if ($canal === null || $de === '') {
            $log('whatsapp: canal ' . $canalId . ' não configurado; mensagem descartada.');
            Queue::concluir((int) $job['id']);

            return 'concluidos';
        }

        $wamid = (string) ($dados['wamid'] ?? '');

        // Já tratamos esta mensagem? Então acabou.
        //
        // A Meta reenvia o webhook quando não recebe 200 depressa, e o reenvio
        // traz o MESMO `wamid`. Sem esta verificação, cada repetição consome a
        // LLM e manda outra resposta para a mesma pergunta.
        //
        // Isto é o caminho rápido, não a garantia: dois webhooks simultâneos
        // passam os dois por aqui. Quem desempata é o índice único da coluna
        // `mensagens.externo_id`, no `catch` lá embaixo.
        if ($wamid !== '' && self::jaProcessada($wamid)) {
            $log('whatsapp: ' . $wamid . ' já tratada; reenvio ignorado.');
            Queue::concluir((int) $job['id']);

            return 'concluidos';
        }

        $svc = ChatService::paraAgente($canal->agenteId());
        $conversa = $svc->conversa($canalId, $de, null);

        try {
            $tipo = (string) ($dados['tipo'] ?? 'text');

            // -------------------------------------------------------------
            // Mídia: só com atendente humano na conversa
            //
            // Arquivo só é baixado quando alguém do outro lado assumiu o
            // atendimento. Com o agente respondendo, o arquivo é recusado sem
            // sequer ser buscado na Meta.
            //
            // A razão é abuso: quem quiser encher o disco do cliente manda
            // arquivo atrás de arquivo, de graça, e cada um vira espaço nosso.
            // O agente não sabe ler imagem nem áudio, então o custo seria pago
            // sem nenhum ganho.
            //
            // A trava é `modo === 'humano'` e não "pediu atendimento": pedir
            // atendente é digitar uma frase, coisa que o atacante faz sozinho.
            // Um humano ter ASSUMIDO a conversa é o que ele não consegue
            // acionar — por isso é aqui que a linha fica.
            // -------------------------------------------------------------
            if ($tipo !== 'text') {
                $legenda = trim((string) ($dados['texto'] ?? ''));

                // A mensagem do visitante é gravada mesmo quando o arquivo é
                // recusado: sem ela, o painel mostra o bot falando sozinho, e
                // o atendente não entende o que aconteceu. É também o que
                // ancora o `wamid` deste turno.
                $mensagemId = $svc->gravarMensagem(
                    $conversa,
                    'usuario',
                    $legenda !== '' ? $legenda : '[' . $tipo . ' recusado]',
                    null,
                    null,
                    $wamid
                );

                if (!Fila::comAtendenteHumano($conversa)) {
                    // Recusa e ORIENTA. O agente não pede documento e não
                    // recebe documento; o caminho para quem precisa mesmo
                    // enviar algo é a pessoa. É oferta, não promessa: se a
                    // fila estiver vazia, ninguém assume — e prometer olho
                    // humano que não existe é o que este projeto passa o tempo
                    // todo tentando não fazer.
                    $aviso = 'Não consigo receber arquivos por aqui. Se precisar enviar um documento '
                        . 'ou uma foto, posso te encaminhar para um atendente — quer que eu faça isso?';

                    $svc->gravarMensagem($conversa, 'bot', $aviso);
                    $canal->enviar($de, $aviso);

                    $log('whatsapp: ' . $tipo . ' recusado na conversa ' . $conversa . ' (sem atendente).');
                    Queue::concluir((int) $job['id']);

                    return 'concluidos';
                }

                $guardado = $this->guardarMidia($canal, $dados, $tipo, $mensagemId, $log);

                // Com atendente na conversa, quem fala é ele — o bot cala,
                // como já faz no caminho de texto. A exceção é a falha: sem
                // aviso, a pessoa acha que o arquivo chegou e o atendente fica
                // esperando um documento que nunca vai aparecer na tela.
                if (!$guardado) {
                    $aviso = 'Não consegui receber esse arquivo. Pode tentar enviar de novo?';
                    $svc->gravarMensagem($conversa, 'bot', $aviso);
                    $canal->enviar($de, $aviso);
                }

                $log('whatsapp: ' . $tipo . ' na conversa ' . $conversa . ($guardado ? '; guardado.' : '; NÃO guardado.'));
                Queue::concluir((int) $job['id']);

                return 'concluidos';
            }

            if (trim((string) $dados['texto']) === '') {
                $log('whatsapp: mensagem de texto vazia na conversa ' . $conversa . '; ignorada.');
                Queue::concluir((int) $job['id']);

                return 'concluidos';
            }

            $texto = (string) $dados['texto'];

            \SimpleAIman\Atendimento\Fila::reabrirSeEncerrada($conversa);

            // Conversa em atendimento humano: grava e cala. Quem responde é a
            // pessoa, pelo painel — e a resposta dela sai por `Saida::entregar()`.
            if (!$svc->botDeveResponder($conversa)) {
                $svc->gravarMensagem($conversa, 'usuario', $texto, null, null, $wamid);

                $log('whatsapp: conversa ' . $conversa . ' com atendente; apenas registrada.');
                Queue::concluir((int) $job['id']);

                return 'concluidos';
            }

            $resposta = $svc->responder($conversa, $texto, $wamid);
        } catch (ErroAgente $e) {
            // O agente falhou — e no WhatsApp isso deixa a pessoa esperando.
            //
            // No widget a mensagem pública aparece na tela sozinha; aqui não
            // existe tela, e o job morria calado. Uma oscilação de rede de dois
            // segundos com o provedor bastava para alguém nunca mais receber
            // resposta, sem nada indicando isso — nem para ela, nem para nós.
            //
            // Não há retentativa automática de propósito: o `wamid` do visitante
            // já foi gravado antes da chamada ao provedor, então a segunda
            // tentativa bateria no dedup e seria descartada em silêncio — pior
            // que não tentar. A mensagem pública convida a repetir, e o reenvio
            // gera `wamid` novo, que passa limpo.
            try {
                $svc->gravarMensagem($conversa, 'bot', $e->mensagemPublica());
                $canal->enviar($de, $e->mensagemPublica());
            } catch (Throwable $aviso) {
                // Avisar falhou também. Não pode mascarar a causa original, que
                // é o que o admin precisa ver.
                error_log('[simpleAIman] whatsapp: nem o aviso de falha saiu: ' . $aviso->getMessage());
            }

            $log('whatsapp: agente falhou na conversa ' . $conversa . '; visitante avisado.');

            // Sobe para virar `erro` no painel: a pessoa foi avisada, mas isso
            // não torna a falha aceitável nem a esconde de quem administra.
            throw $e;
        } catch (PDOException $e) {
            // Índice único de `mensagens.externo_id` recusando o reenvio que
            // escapou da verificação acima — dois webhooks ao mesmo tempo.
            //
            // Não é falha: é exatamente o que o índice existe para fazer, e o
            // INSERT vem ANTES do envio, então nada saiu duas vezes. Qualquer
            // outro erro de banco continua subindo.
            if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw $e;
            }

            $log('whatsapp: ' . $wamid . ' chegou duplicada; segunda descartada pelo índice.');
            Queue::concluir((int) $job['id']);

            return 'concluidos';
        }

        $canal->enviar($de, $resposta);

        // "Aceito", não "entregue": a Meta responde 200 e só depois entrega —
        // ou falha, e a falha chega no webhook de status, nunca aqui. Dizer
        // "respondido" mandou quatro mensagens que nunca chegaram parecerem
        // sucesso durante o primeiro teste real.
        $log('whatsapp: resposta aceita pela Meta na conversa ' . $conversa . ' (entrega confirma no status).');
        Queue::concluir((int) $job['id']);

        return 'concluidos';
    }

    /**
     * Baixa a mídia na Meta e guarda em disco.
     *
     * Nunca deixa a exceção subir: o turno já gravou a mensagem do visitante e
     * precisa terminar respondendo. Arquivo grande demais, tipo recusado ou
     * rede caída não podem virar job em `erro` — do lado de quem enviou, o que
     * aconteceria é silêncio.
     *
     * @param array<string, mixed> $dados
     */
    private function guardarMidia(
        CanalWhatsapp $canal,
        array $dados,
        string $tipo,
        int $mensagemId,
        callable $log,
    ): bool {
        $midiaId = (string) ($dados['midia_id'] ?? '');

        if ($midiaId === '') {
            $log('whatsapp: ' . $tipo . ' sem id de mídia no evento; nada a baixar.');

            return false;
        }

        try {
            $midia = $canal->baixarMidia($midiaId, Anexos::tetoBytes(), Anexos::mimesAceitos());

            Anexos::guardar(
                $mensagemId,
                $tipo,
                $midia['mime'],
                $midia['bytes'],
                (string) ($dados['nome_arquivo'] ?? '') ?: null,
                $midiaId
            );

            return true;
        } catch (Throwable $e) {
            // Detalhe no log, nunca na conversa: a mensagem da Meta cita id
            // interno, e o nosso teto de tamanho é decisão de infraestrutura.
            error_log('[simpleAIman] whatsapp: anexo ' . $midiaId . ' não guardado: ' . $e->getMessage());
            $log('whatsapp: anexo recusado — ' . $e->getMessage());

            return false;
        }
    }

    /** Esta mensagem do canal já virou linha em `mensagens`? */
    private static function jaProcessada(string $externoId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM mensagens WHERE externo_id = :e LIMIT 1'
        );
        $stmt->execute(['e' => $externoId]);

        return $stmt->fetchColumn() !== false;
    }

    private function marcarArtefatoComErro(array $job, string $erro): void
    {
        $artefatoId = (int) ($job['payload']['artefato_id'] ?? 0);

        if ($artefatoId <= 0) {
            return;
        }

        try {
            Database::connection()
                ->prepare('UPDATE artefatos SET status = \'erro\', erro = :erro, editado_em = :agora WHERE id = :id')
                ->execute(['erro' => mb_substr($erro, 0, 1000), 'agora' => now(), 'id' => $artefatoId]);
        } catch (Throwable) {
            // Falhar ao registrar a falha não pode derrubar o worker.
        }
    }
}
