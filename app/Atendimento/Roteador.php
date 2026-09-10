<?php

declare(strict_types=1);

namespace SimpleAIman\Atendimento;

use Database;
use PDO;
use SimpleAIman\Tools\LeadTool;
use Throwable;

/**
 * Atendimento sem IA: menu de setores, contato e fila.
 *
 * Serve a três situações diferentes, e é a mesma máquina nas três:
 *
 *  1. **Cliente que não quer pagar LLM.** Um "fale conosco" com roteamento
 *     resolve muita gente, e agente em `modo = 'roteador'` não chama provedor
 *     nenhuma vez — não precisa nem de chave de API.
 *  2. **Degradação quando o provedor cai.** Sem isto, provedor fora do ar
 *     significa "não consegui responder agora" e a conversa morre ali. Com o
 *     menu, a pessoa ainda chega a quem resolve. Este é o ganho maior, e o que
 *     menos se pensa antes de acontecer.
 *  3. **Caminho de adoção.** Começa como roteador, liga a IA depois.
 *
 * **Menu numerado, e não botões**: número funciona igual no widget e no
 * WhatsApp, sem interface nova nem mensagem interativa da Meta.
 *
 * **Sem estado.** Cada mensagem é interpretada sozinha — número escolhe setor,
 * palavra-chave dispara ação, o resto mostra o menu. Guardar "em que passo a
 * pessoa está" exigiria coluna, e quebraria assim que ela digitasse algo fora
 * de ordem, que é o que as pessoas fazem.
 */
final class Roteador
{
    private const PALAVRAS_ATENDENTE = ['atendente', 'humano', 'pessoa', 'alguem', 'falar com alguem'];
    private const PALAVRAS_MENU = ['menu', 'voltar', 'opcoes', 'inicio', 'ajuda'];

    /**
     * A mensagem é uma palavra de navegação, e nada além disso?
     *
     * Existe porque `menu` era palavra reservada só no modo roteador e não
     * significava nada no modo IA — quem digitasse esperando o menu de setores
     * recebia o RAG tentando adivinhar. E adivinha mal: uma palavra solta gera
     * um vetor difuso, todo trecho pontua parecido e o agente responde com
     * confiança sobre material que não tem relação nenhuma.
     *
     * Só casa a mensagem INTEIRA. "quero ver o menu de hoje no RU" é pergunta
     * de conteúdo e segue o caminho normal; "menu" é navegação.
     *
     * @return 'menu'|'atendente'|null
     */
    public static function comandoDeNavegacao(string $entrada): ?string
    {
        // Só pontuação e caixa são descartadas. A comparação é EXATA contra a
        // lista: sem isso, "não quero atendente" viraria comando de
        // transferência, e "qual o menu do RU" viraria menu de setores.
        $chave = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\s]+/u', '', self::normalizar($entrada)) ?? '') ?? '');

        if ($chave === '') {
            return null;
        }

        if (in_array($chave, self::PALAVRAS_MENU, true)) {
            return 'menu';
        }

        return in_array($chave, self::PALAVRAS_ATENDENTE, true) ? 'atendente' : null;
    }

    /**
     * Há material para montar um menu?
     *
     * Sem setor ativo não há para onde rotear, e um menu vazio é pior que a
     * mensagem de erro honesta. É esta checagem que decide se a degradação por
     * falha do provedor vale a pena.
     */
    public static function temMenu(): bool
    {
        return self::setores() !== [];
    }

    /**
     * Responde uma entrada do visitante.
     *
     * @param string $preambulo texto opcional antes do menu (usado quando o
     *                          roteador entra como degradação, para a pessoa
     *                          entender por que o tom mudou)
     */
    public static function responder(
        int $conversaId,
        string $entrada,
        string $preambulo = '',
        bool $captarContato = false,
    ): string {
        // Captação vem ANTES de tudo, e só quando foi oferecida.
        //
        // Não é opcional por preciosismo: gravar e-mail ou telefone de quem não
        // ofereceu é coletar dado pessoal sem pedido. Quem chama com `true` é
        // quem acabou de dizer "deixe seu contato" — a permissão vem do
        // convite, não do formato do texto.
        if ($captarContato) {
            $contato = self::extrairContato($entrada);

            if ($contato !== null) {
                return self::registrarContato($conversaId, $contato);
            }
        }

        $setores = self::setores();

        if ($setores === []) {
            return $captarContato
                ? 'Deixe seu e-mail ou telefone que alguém retorna assim que possível.'
                : 'No momento não consigo encaminhar seu atendimento. Tente novamente mais tarde.';
        }

        $chave = self::normalizar($entrada);

        // Pedido explícito de gente vem antes de tudo: quem digitou
        // "atendente" não quer ver menu.
        if (self::contem($chave, self::PALAVRAS_ATENDENTE)) {
            return self::transferir($conversaId, $setores);
        }

        if (self::contem($chave, self::PALAVRAS_MENU)) {
            return self::menu($setores, $preambulo);
        }

        // Número da lista.
        if (preg_match('/^\D*(\d{1,2})\D*$/', $chave, $m)) {
            $indice = (int) $m[1] - 1;

            if (isset($setores[$indice])) {
                return self::fichaDoSetor($setores[$indice], $setores);
            }
        }

        return self::menu($setores, $preambulo);
    }

    /**
     * Saudação + lista numerada.
     *
     * @param list<array<string, mixed>> $setores
     */
    private static function menu(array $setores, string $preambulo = ''): string
    {
        $linhas = [];

        if ($preambulo !== '') {
            $linhas[] = $preambulo;
            $linhas[] = '';
        }

        $linhas[] = 'Escolha o assunto digitando o *número* correspondente:';
        $linhas[] = '';

        foreach ($setores as $i => $s) {
            $rotulo = '*' . ($i + 1) . '* · ' . $s['nome'];

            // A descrição que orienta o modelo serve igualmente para orientar
            // a pessoa — é a mesma pergunta ("o que este setor resolve?").
            if (trim((string) $s['descricao_llm']) !== '') {
                $rotulo .= ' — ' . self::resumir((string) $s['descricao_llm']);
            }

            $linhas[] = $rotulo;
        }

        if (Fila::haDisponivel()) {
            $linhas[] = '';
            $linhas[] = 'Ou digite *ATENDENTE* para falar com uma pessoa agora.';
        }

        return implode("\n", $linhas);
    }

    /**
     * Contatos de um setor.
     *
     * @param array<string, mixed> $setor
     * @param list<array<string, mixed>> $setores
     */
    private static function fichaDoSetor(array $setor, array $setores): string
    {
        $linhas = ['*' . $setor['nome'] . '*'];

        if (trim((string) $setor['descricao_llm']) !== '') {
            $linhas[] = self::resumir((string) $setor['descricao_llm']);
        }

        $linhas[] = '';

        $contatos = array_filter([
            $setor['telefone'] ? '📞 ' . $setor['telefone'] . ($setor['ramal'] ? ' (ramal ' . $setor['ramal'] . ')' : '') : null,
            $setor['whatsapp'] ? '💬 ' . whatsapp_url((string) $setor['whatsapp'], 'Olá! Vim pelo atendimento virtual.') : null,
            $setor['email'] ? '✉️ ' . $setor['email'] : null,
            $setor['horario_atendimento'] ? '🕐 ' . $setor['horario_atendimento'] : null,
            $setor['local'] ? '📍 ' . $setor['local'] : null,
        ]);

        // Setor sem contato nenhum não pode virar resposta vazia — a mesma
        // regra do `contato_setor`, e pelo mesmo motivo: a lacuna seria pior
        // que dizer que não temos.
        $linhas[] = $contatos === []
            ? 'Ainda não temos um contato direto cadastrado para este setor.'
            : implode("\n", $contatos);

        $linhas[] = '';
        $linhas[] = Fila::haDisponivel()
            ? 'Digite *MENU* para ver os assuntos, ou *ATENDENTE* para falar com uma pessoa agora.'
            : 'Digite *MENU* para ver os outros assuntos.';

        return implode("\n", $linhas);
    }

    /**
     * @param list<array<string, mixed>> $setores
     */
    private static function transferir(int $conversaId, array $setores): string
    {
        $resultado = Fila::solicitar($conversaId, null, 'Pedido pelo menu de atendimento');

        if ($resultado['transferido']) {
            // O convite vai JUNTO da confirmacao, e nao numa pergunta separada.
            //
            // O roteador nao guarda estado de proposito: "em que passo a pessoa
            // esta" quebra assim que ela digita fora de ordem. Perguntar o nome
            // numa mensagem e esperar a resposta na seguinte seria exatamente
            // esse estado. Convidando aqui, quem quiser responde na mensagem
            // seguinte e a captacao passa a ser legitima — a permissao vem do
            // convite, nao do formato do texto.
            return "Certo! Estou chamando um atendente. Aguarde um instante nesta janela, por favor.

"
                . 'Se quiser, me diga seu nome e um telefone ou e-mail para retorno, '
                . 'caso a conversa caia.';
        }

        // Ninguém disponível. A mesma regra de sempre: não se promete o que
        // não se pode cumprir. Aqui isso importa ainda mais, porque uma
        // instalação pode simplesmente não ter atendente nenhum — só o menu.
        return "Não há atendente disponível no momento.\n\n"
            . implode("\n", array_map(
                static fn (array $s): string => '*' . $s['nome'] . '*'
                    . ($s['email'] ? ' — ✉️ ' . $s['email'] : '')
                    . ($s['telefone'] ? ' — 📞 ' . $s['telefone'] : ''),
                array_slice($setores, 0, 5)
            ))
            . "\n\nDigite *MENU* para ver todos os assuntos.";
    }

    /**
     * E-mail ou telefone dentro de uma frase.
     *
     * Deliberadamente tolerante: a pessoa escreve "pode ser 55 99999-8888" ou
     * "meu email eh joao@x.com", não preenche um formulário. Exigir formato
     * exato aqui devolveria o menu para quem acabou de fazer o que pedimos.
     *
     * @return array{email?: string, telefone?: string}|null
     */
    private static function extrairContato(string $texto): ?array
    {
        $achado = [];

        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.-]{2,}/u', $texto, $m)) {
            $achado['email'] = rtrim($m[0], '.');
        }

        // Telefone brasileiro com DDD, com ou sem separadores. O corte em 10
        // dígitos evita confundir com CEP, protocolo ou ano.
        $digitos = preg_replace('/\D+/', '', $texto) ?? '';

        if (strlen($digitos) >= 10 && strlen($digitos) <= 13) {
            $achado['telefone'] = $digitos;
        }

        return $achado === [] ? null : $achado;
    }

    /** @param array{email?: string, telefone?: string} $contato */
    private static function registrarContato(int $conversaId, array $contato): string
    {
        $agenteId = (int) (Database::connection()
            ->query('SELECT agente_id FROM conversas WHERE id = ' . $conversaId)
            ->fetchColumn() ?: 0);

        try {
            // Reaproveita a captação de sempre: mesma tabela, mesma entrega ao
            // CRM com retry e dead letter. Um caminho paralelo só para este
            // caso seria um segundo lugar de onde lead some sem ninguém saber.
            (new LeadTool($conversaId, $agenteId))->registrar($contato);
        } catch (Throwable $e) {
            \Log::erro('roteador_captacao_falhou', ['erro' => $e->getMessage()]);

            return 'Não consegui registrar agora. Se puder, use um dos contatos abaixo — '
                . 'digite *MENU* para vê-los.';
        }

        return implode("\n", [
            'Pronto, anotei seu contato! Alguém retorna assim que possível.',
            '',
            'Se preferir falar agora, digite *MENU* para ver os contatos diretos.',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function setores(): array
    {
        return Database::connection()->query(
            'SELECT id, slug, nome, descricao_llm, email, telefone, whatsapp, ramal,
                    horario_atendimento, local
             FROM setores WHERE ativo = 1 ORDER BY ordem, nome LIMIT 12'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Primeira frase, para a lista não virar parede de texto. */
    private static function resumir(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        $corte = mb_strpos($texto, '. ');

        if ($corte !== false && $corte < 120) {
            return mb_substr($texto, 0, $corte + 1);
        }

        return mb_strlen($texto) > 120 ? mb_substr($texto, 0, 117) . '…' : $texto;
    }

    /**
     * Minúsculas e sem acento, para "ATENDENTE", "atendente" e "Atendênte"
     * caírem no mesmo lugar.
     */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($texto, \Normalizer::FORM_D);

            if ($n !== false) {
                $texto = preg_replace('/\p{Mn}/u', '', $n) ?? $texto;
            }
        }

        return $texto;
    }

    /** @param list<string> $palavras */
    private static function contem(string $chave, array $palavras): bool
    {
        foreach ($palavras as $p) {
            if (str_contains($chave, $p)) {
                return true;
            }
        }

        return false;
    }
}
