<?php

declare(strict_types=1);

use SimpleAIman\Jobs\Batimento;
use SimpleAIman\Jobs\Queue;
use SimpleAIman\Llm\ProviderFactory;

/**
 * Diagnóstico da infraestrutura em que esta instância roda.
 *
 * Nasceu da primeira instalação de produção, na Hostinger, em 11/09/2026: o
 * WhatsApp só devolvia o aviso de turno interrompido, o playground ficou bem
 * mais lento que no ambiente local, e uma indexação de 300 KB demorou demais.
 * Não havia como saber se a culpa era do SQLite, do worker, de extensão
 * faltando ou do servidor web.
 *
 * Cada verificação existe para confirmar ou descartar uma hipótese concreta,
 * e o `detalhe` diz qual.
 *
 * ## Duas faces, um código
 *
 * `bin/diagnostico.php` e a tela Sistema › Infraestrutura leem esta mesma
 * classe — como `DiagnosticoProvedor` serve ao painel e ao
 * `bin/testar-provedor.php`. Duas implementações garantiriam que uma delas
 * estivesse desatualizada justamente quando alguém precisasse dela.
 *
 * E as duas faces não são redundantes: o PHP da linha de comando NÃO é o PHP
 * que serve o site. OPcache, limites de tempo e o comportamento do servidor web
 * só existem no processo web. Por isso as verificações que dependem dele dizem,
 * na CLI, para olhar no painel.
 */
final class DiagnosticoInfra
{
    /** Extensões sem as quais algo quebra. */
    private const EXTENSOES = ['pdo_sqlite', 'mbstring', 'curl', 'openssl', 'zip', 'json'];

    /**
     * @param bool $completo inclui os testes que custam: escrita em disco e rede
     *                       (uma chamada real de embedding ao fornecedor)
     *
     * @return list<array{grupo: string, item: string, estado: string, valor: string, detalhe: string}>
     */
    public static function executar(bool $completo = false): array
    {
        $grupos = [
            fn (): array => self::ambiente(),
            fn (): array => self::banco($completo),
            fn (): array => self::worker(),
        ];

        if ($completo) {
            $grupos[] = fn (): array => self::rede();
        }

        $saida = [];

        foreach ($grupos as $grupo) {
            // Um grupo que quebra não pode levar o relatório inteiro junto:
            // diagnóstico que falha é o pior momento para ficar sem nenhum.
            try {
                array_push($saida, ...$grupo());
            } catch (Throwable $e) {
                $saida[] = self::item('Diagnóstico', 'Falha ao verificar', 'erro', $e->getMessage());
            }
        }

        return $saida;
    }

    // -----------------------------------------------------------------
    // Ambiente
    // -----------------------------------------------------------------

    /** @return list<array<string, string>> */
    private static function ambiente(): array
    {
        $web = PHP_SAPI !== 'cli';
        $itens = [];

        $itens[] = self::item(
            'Ambiente',
            'PHP',
            PHP_VERSION_ID >= 80400 ? 'ok' : 'erro',
            PHP_VERSION,
            PHP_BINARY
        );

        $itens[] = self::item(
            'Ambiente',
            'Processo',
            'info',
            PHP_SAPI . ($web ? ' · ' . (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'servidor não identificado') : ''),
            $web
                ? 'O PHP que serve o site. É este que roda o chat, o webhook e o kick do worker.'
                : 'Linha de comando. É o PHP do cron — pode ser outro binário, outra versão e outro php.ini que os do site.'
        );

        $faltando = array_values(array_filter(self::EXTENSOES, static fn (string $e): bool => !extension_loaded($e)));

        $itens[] = self::item(
            'Ambiente',
            'Extensões',
            $faltando === [] ? 'ok' : 'erro',
            $faltando === [] ? 'todas presentes' : 'faltando: ' . implode(', ', $faltando),
            implode(', ', self::EXTENSOES)
        );

        if ($web) {
            $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
            $ligado = is_array($status) && !empty($status['opcache_enabled']);

            $itens[] = self::item(
                'Ambiente',
                'OPcache',
                $ligado ? 'ok' : 'alerta',
                $ligado ? 'ligado' : 'desligado',
                $ligado
                    ? 'O código compilado fica em memória entre requisições.'
                    : 'Cada requisição recompila todo o vendor/ — o Neuron, o Guzzle e as dependências deles. '
                        . 'É uma causa comum de o playground ser mais lento no servidor que na máquina local. '
                        . 'Ative no painel da hospedagem, na configuração do PHP.'
            );
        } else {
            $itens[] = self::item(
                'Ambiente',
                'OPcache',
                'info',
                'não se aplica',
                'Na linha de comando ele não conta. O que importa é o do site: veja em Sistema › Infraestrutura, no painel.'
            );
        }

        $mecanismo = function_exists('fastcgi_finish_request')
            ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : 'nenhum');

        if ($web) {
            $itens[] = self::item(
                'Ambiente',
                'Liberação de conexão',
                $mecanismo === 'nenhum' ? 'alerta' : 'ok',
                $mecanismo,
                $mecanismo === 'nenhum'
                    ? 'O webhook e o kick não conseguem responder antes de trabalhar: a conexão fica aberta até o fim. '
                        . 'Se o servidor encerrar o processo quando o cliente desconecta, o trabalho morre no meio. Rode a sonda.'
                    : 'O webhook e o kick respondem e seguem trabalhando. Se o servidor deixa terminar é outra '
                        . 'questão — a sonda responde.'
            );
        } else {
            $itens[] = self::item(
                'Ambiente',
                'Liberação de conexão',
                'info',
                'não se aplica',
                'Só existe no processo web. Veja no painel.'
            );
        }

        $memoria = (string) ini_get('memory_limit');

        $itens[] = self::item(
            'Ambiente',
            'Limites',
            self::bytesDeIni($memoria) !== -1 && self::bytesDeIni($memoria) < 128 * 1024 * 1024 ? 'alerta' : 'info',
            'memória ' . $memoria . ' · execução ' . ini_get('max_execution_time') . ' s',
            'No Linux o limite de execução conta só tempo de CPU: sleep() e espera de rede não entram. '
                . 'Uma chamada ao modelo quase nunca estoura este limite — quem encerra o processo, quando encerra, é o servidor.'
        );

        $itens[] = self::item(
            'Ambiente',
            'Diretório temporário',
            'info',
            sys_get_temp_dir(),
            'Onde fica a trava que impede cron e kick de rodarem juntos. Se a linha de comando e o site '
                . 'usarem diretórios diferentes, as travas não se enxergam — o cartão do worker compara os dois.'
        );

        return $itens;
    }

    // -----------------------------------------------------------------
    // Banco
    // -----------------------------------------------------------------

    /** @return list<array<string, string>> */
    private static function banco(bool $completo): array
    {
        $itens = [];
        $pdo = Database::connection();
        $pasta = dirname(DB_PATH);

        $versao = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();

        try {
            $memoria = new PDO('sqlite::memory:');
            $memoria->exec('CREATE VIRTUAL TABLE t USING fts5(x)');
            $fts = true;
        } catch (Throwable) {
            $fts = false;
        }

        $itens[] = self::item(
            'Banco',
            'SQLite',
            $fts ? 'ok' : 'alerta',
            $versao . ($fts ? ' · FTS5' : ' · sem FTS5'),
            $fts ? 'A metade lexical da busca híbrida está disponível.' : 'Sem FTS5 a busca fica só vetorial: códigos e nomes próprios passam a errar.'
        );

        $diario = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());

        $itens[] = self::item(
            'Banco',
            'Modo de diário',
            $diario === 'wal' ? 'ok' : 'alerta',
            $diario,
            $diario === 'wal'
                ? 'Leitura e escrita não se bloqueiam: o chat responde enquanto a indexação grava.'
                : 'Fora do WAL, cada escrita trava as leituras. Costuma indicar sistema de arquivos que não suporta WAL — storage de rede.'
        );

        $itens[] = self::item(
            'Banco',
            'Arquivo',
            'info',
            formatar_bytes((int) @filesize(DB_PATH)),
            DB_PATH
        );

        $itens[] = self::item(
            'Banco',
            'Pasta gravável',
            is_writable($pasta) ? 'ok' : 'erro',
            is_writable($pasta) ? 'sim' : 'NÃO',
            'O WAL cria os arquivos -wal e -shm ao lado do banco. Sem permissão de escrita na pasta, nada grava.'
        );

        $livre = (int) @disk_free_space($pasta);

        $itens[] = self::item(
            'Banco',
            'Espaço livre',
            $livre > 0 && $livre < 500 * 1024 * 1024 ? 'alerta' : 'info',
            $livre > 0 ? formatar_bytes($livre) : 'não informado',
            'No volume onde está o banco.'
        );

        if ($completo) {
            $itens[] = self::latenciaDeEscrita($pasta);
        }

        return $itens;
    }

    /**
     * Mede a escrita NO DIRETÓRIO DO BANCO, não no temporário.
     *
     * O `benchmark-sqlite.php` mede em `sys_get_temp_dir()`, que num host
     * compartilhado pode ser disco local mesmo quando o home está em storage de
     * rede — e é o home que importa, porque é lá que o banco mora.
     */
    private static function latenciaDeEscrita(string $pasta): array
    {
        $arquivo = $pasta . '/.diagnostico-' . getmypid() . '.sqlite';

        try {
            $pdo = new PDO('sqlite:' . $arquivo);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

            $insere = $pdo->prepare('INSERT INTO t (v) VALUES (?)');
            $turnos = 100;
            $inicio = microtime(true);

            // Um turno de conversa grava perto de seis linhas numa transação.
            for ($i = 0; $i < $turnos; $i++) {
                $pdo->beginTransaction();

                for ($j = 0; $j < 6; $j++) {
                    $insere->execute(['linha ' . $i . '.' . $j]);
                }

                $pdo->commit();
            }

            $porTurno = (microtime(true) - $inicio) * 1000 / $turnos;
            unset($insere, $pdo);
        } finally {
            foreach ([$arquivo, $arquivo . '-wal', $arquivo . '-shm'] as $f) {
                @unlink($f);
            }
        }

        return self::item(
            'Banco',
            'Latência de escrita',
            $porTurno < 5 ? 'ok' : 'alerta',
            sprintf('%.2f ms por turno', $porTurno),
            $porTurno < 5
                ? 'Escrita local e rápida. O SQLite não é o gargalo.'
                : 'Escrita lenta para um disco local. Suspeite de storage de rede: o lock do SQLite fica lento e pouco '
                    . 'confiável sobre ele. Vale perguntar ao suporte do host onde fica o home.'
        );
    }

    // -----------------------------------------------------------------
    // Worker
    // -----------------------------------------------------------------

    /** @return list<array<string, string>> */
    private static function worker(): array
    {
        $itens = [];

        $itens[] = self::item(
            'Worker',
            'Token do kick',
            WORKER_TOKEN !== '' ? 'ok' : 'erro',
            WORKER_TOKEN !== '' ? 'definido' : 'vazio',
            WORKER_TOKEN !== ''
                ? 'O upload e o webhook conseguem acordar o worker na hora.'
                : 'Sem WORKER_TOKEN no .env o kick fica desligado: tudo espera o próximo ciclo do cron.'
        );

        $host = (string) (parse_url(APP_URL, PHP_URL_HOST) ?? '');
        $resolve = $host !== '' && gethostbyname($host) !== $host;

        $itens[] = self::item(
            'Worker',
            'APP_URL',
            $resolve ? 'ok' : 'erro',
            APP_URL !== '' ? APP_URL : 'vazio',
            $resolve
                ? 'É por este endereço que a instância chama a si mesma para acordar o worker.'
                : 'O endereço não resolve daqui. O kick não chega, e só o cron processa a fila.'
        );

        $fila = Queue::resumo();
        $presos = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM jobs WHERE status = 'processando' AND lock_ate < '" . now() . "'")
            ->fetchColumn();

        $itens[] = self::item(
            'Worker',
            'Fila',
            ($fila['erro'] ?? 0) > 0 || $presos > 0 ? 'alerta' : 'ok',
            sprintf('%d pendente · %d processando · %d com erro', $fila['pendente'] ?? 0, $fila['processando'] ?? 0, $fila['erro'] ?? 0),
            $presos > 0
                ? "{$presos} job(s) com a trava vencida: um worker começou e não terminou."
                : 'Contagem por situação na tabela jobs.'
        );

        $bat = Batimento::resumo();

        if ($bat['ultima'] === null) {
            $itens[] = self::item(
                'Worker',
                'Última execução',
                'erro',
                'nenhuma registrada',
                'O worker nunca rodou desde que o batimento existe. Confira o cron.'
            );
        } else {
            $calado = time() - (int) $bat['ultima'];

            $itens[] = self::item(
                'Worker',
                'Última execução',
                $calado <= 600 ? 'ok' : 'erro',
                self::ha((int) $bat['ultima']),
                $calado <= 600 ? 'O worker está vivo.' : 'Calado há mais de dez minutos: ninguém está processando a fila.'
            );
        }

        $itens[] = self::item(
            'Worker',
            'Cron',
            $bat['ultima_cli'] !== null && time() - (int) $bat['ultima_cli'] <= 600 ? 'ok' : 'alerta',
            $bat['ultima_cli'] === null ? 'nenhuma execução pela linha de comando' : self::ha((int) $bat['ultima_cli']) . ' · ' . $bat['cli_ultima_hora'] . ' na última hora',
            'Com o cron a cada cinco minutos, o esperado são cerca de 12 execuções por hora. '
                . 'Execuções manuais pela linha de comando também contam aqui.'
        );

        if ($bat['interrompidas'] > 0) {
            $itens[] = self::item(
                'Worker',
                'Execuções interrompidas',
                'erro',
                $bat['interrompidas'] . ' (' . $bat['interrompidas_kick'] . ' pelo kick)',
                'Começaram e nunca terminaram, sem erro fatal registrado: o processo foi encerrado de fora. '
                    . 'Pelo kick, é o servidor web matando o PHP no meio do trabalho — a causa do turno interrompido '
                    . 'no WhatsApp. Rode a sonda para medir quanto tempo o servidor deixa viver.'
            );
        }

        if ($bat['temp_cli'] !== [] && $bat['temp_kick'] !== [] && array_intersect($bat['temp_cli'], $bat['temp_kick']) === []) {
            $itens[] = self::item(
                'Worker',
                'Travas',
                'alerta',
                'cron: ' . implode(', ', $bat['temp_cli']) . ' · kick: ' . implode(', ', $bat['temp_kick']),
                'Cron e kick usam diretórios temporários diferentes, então a trava de um não enxerga a do outro: '
                    . 'os dois podem pegar o mesmo job ao mesmo tempo.'
            );
        }

        return $itens;
    }

    // -----------------------------------------------------------------
    // Rede
    // -----------------------------------------------------------------

    /** @return list<array<string, string>> */
    private static function rede(): array
    {
        $itens = [];

        try {
            $embedding = ProviderFactory::padraoEmbedding();
            $destino = $embedding->destino('embedding');

            if ($destino !== null) {
                $conexao = self::conectar($destino);

                $itens[] = self::item(
                    'Rede',
                    'Conexão · embedding',
                    $conexao['ms'] === null ? 'erro' : ($conexao['ms'] < 300 ? 'ok' : 'alerta'),
                    $conexao['ms'] === null ? 'falhou: ' . $conexao['erro'] : sprintf('%.0f ms até %s', $conexao['ms'], $destino['host']),
                    'DNS, TCP e TLS, sem a API. Separa rede lenta de fornecedor lento.'
                );
            }

            $inicio = microtime(true);
            $embedding->embeddings(ProviderFactory::TAREFA_CONSULTAR)->embedText('diagnóstico de latência');
            $ms = (microtime(true) - $inicio) * 1000;

            // A ingestão faz UMA chamada por trecho, em sequência. O custo de uma
            // chamada multiplicado pelo número de trechos é o piso do tempo de
            // indexação — antes de o cron repartir o trabalho em fatias.
            $segundosPor100 = $ms * 100 / 1000;
            $ciclos = (int) ceil($segundosPor100 / max(1, WORKER_TEMPO_MAX_S));

            $itens[] = self::item(
                'Rede',
                'Chamada de embedding',
                $ms < 800 ? 'ok' : 'alerta',
                sprintf('%.0f ms · %s', $ms, $embedding->nome()),
                sprintf(
                    'Cada trecho indexado custa uma chamada destas, em sequência. 100 trechos ≈ %.0f s só de rede. '
                        . 'Se só o cron processar — %d s de trabalho a cada 5 min —, isso vira cerca de %d min.',
                    $segundosPor100,
                    WORKER_TEMPO_MAX_S,
                    $ciclos * 5
                )
            );
        } catch (Throwable $e) {
            $itens[] = self::item('Rede', 'Embedding', 'erro', 'falhou', $e->getMessage());
        }

        try {
            $chat = ProviderFactory::padraoChat();
            $destino = $chat->destino('chat');

            if ($destino !== null) {
                $conexao = self::conectar($destino);

                $itens[] = self::item(
                    'Rede',
                    'Conexão · chat',
                    $conexao['ms'] === null ? 'erro' : ($conexao['ms'] < 300 ? 'ok' : 'alerta'),
                    $conexao['ms'] === null ? 'falhou: ' . $conexao['erro'] : sprintf('%.0f ms até %s', $conexao['ms'], $destino['host']),
                    'O tempo de resposta do modelo em si aparece em Provedores › Testar e na tela de Diagnóstico.'
                );
            }
        } catch (Throwable $e) {
            $itens[] = self::item('Rede', 'Chat', 'erro', 'falhou', $e->getMessage());
        }

        return $itens;
    }

    /**
     * @param array{host: string, porta: int, tls: bool} $destino
     *
     * @return array{ms: float|null, erro: string|null}
     */
    private static function conectar(array $destino): array
    {
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $alvo = ($destino['tls'] ? 'ssl://' : 'tcp://') . $destino['host'] . ':' . $destino['porta'];

        $inicio = microtime(true);
        $socket = @stream_socket_client($alvo, $numero, $mensagem, 8, STREAM_CLIENT_CONNECT, $contexto);
        $ms = (microtime(true) - $inicio) * 1000;

        if ($socket === false) {
            return ['ms' => null, 'erro' => $mensagem !== '' ? $mensagem : 'sem resposta'];
        }

        fclose($socket);

        return ['ms' => $ms, 'erro' => null];
    }

    // -----------------------------------------------------------------
    // Sonda
    // -----------------------------------------------------------------

    /**
     * Dispara a sonda pelo mesmo caminho do kick e devolve o id, ou null.
     */
    public static function dispararSonda(int $segundos, bool $liberar): ?string
    {
        if (WORKER_TOKEN === '') {
            return null;
        }

        self::limparSondas();

        $id = bin2hex(random_bytes(8));

        $aceito = Queue::dispararInterno('/api/sonda.php?' . http_build_query([
            'token' => WORKER_TOKEN,
            'id' => $id,
            'segundos' => $segundos,
            'liberar' => $liberar ? '1' : '0',
        ]));

        return $aceito ? $id : null;
    }

    /**
     * Estado de uma sonda: aguardando | rodando | terminou | morreu.
     *
     * "Morreu" é o arquivo que parou de ser atualizado sem chegar ao fim.
     *
     * @return array<string, mixed>
     */
    public static function lerSonda(string $id): array
    {
        $id = (string) preg_replace('/[^a-f0-9]/', '', $id);
        $bruto = @file_get_contents(caminho_storage('sondas') . '/' . $id . '.json');
        $dados = is_string($bruto) ? json_decode($bruto, true) : null;

        if (!is_array($dados)) {
            return ['estado' => 'aguardando'];
        }

        $parado = time() - (int) $dados['vivo_em'];

        $dados['viveu'] = (int) $dados['segundos'];
        $dados['estado'] = !empty($dados['terminou']) ? 'terminou' : ($parado > 4 ? 'morreu' : 'rodando');

        return $dados;
    }

    /** Mantém só as sondas mais recentes. */
    private static function limparSondas(): void
    {
        $arquivos = glob(caminho_storage('sondas') . '/*.json') ?: [];

        usort($arquivos, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));

        foreach (array_slice($arquivos, 10) as $velho) {
            @unlink($velho);
        }
    }

    // -----------------------------------------------------------------
    // Auxiliares
    // -----------------------------------------------------------------

    /** @return array{grupo: string, item: string, estado: string, valor: string, detalhe: string} */
    private static function item(string $grupo, string $item, string $estado, string $valor, string $detalhe = ''): array
    {
        return ['grupo' => $grupo, 'item' => $item, 'estado' => $estado, 'valor' => $valor, 'detalhe' => $detalhe];
    }

    public static function ha(int $timestamp): string
    {
        $s = max(0, time() - $timestamp);

        return match (true) {
            $s < 60 => "há {$s} s",
            $s < 3600 => 'há ' . intdiv($s, 60) . ' min',
            $s < 86400 => 'há ' . intdiv($s, 3600) . ' h',
            default => 'há ' . intdiv($s, 86400) . ' dia(s)',
        };
    }

    /** "256M" em bytes; -1 para ilimitado. */
    private static function bytesDeIni(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '-1') {
            return -1;
        }

        $numero = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1024 * 1024 * 1024,
            'M' => $numero * 1024 * 1024,
            'K' => $numero * 1024,
            default => $numero,
        };
    }
}
