<?php

declare(strict_types=1);

/**
 * Quem enxerga o quê no painel.
 *
 * Duas ideias sustentam esta classe:
 *
 * 1. **A trava é no servidor, não no menu.** Esconder o link não protege nada:
 *    a pessoa digita `agentes.php` na barra de endereço. Quem barra é o
 *    `_init.php`, que toda tela autenticada carrega.
 *
 * 2. **Menu e trava leem a MESMA lista.** Se fossem duas, divergiriam na
 *    primeira tela nova — e a divergência silenciosa aqui significa ou um link
 *    que dá erro, ou uma tela acessível que não devia ser.
 *
 * `papel` (o que a pessoa enxerga) é independente de `atende` (se recebe
 * transferências). Um administrador pode muito bem atender também; derivar um
 * do outro tiraria o painel dele no instante em que entrasse na fila.
 */
final class Painel
{
    public const PAPEIS = [
        'admin' => 'Administrador',
        'editor' => 'Editor de conteúdo',
        'atendente' => 'Atendente',
    ];

    /**
     * Tela → papéis que podem abri-la.
     *
     * Tela ausente daqui é acessível **só por admin**. O padrão é fechado de
     * propósito: esquecer de listar uma tela nova erra para o lado seguro, e o
     * sintoma (um editor não vê algo que devia) aparece rápido e é inofensivo.
     * O erro inverso — tela sensível aberta por omissão — só aparece quando
     * alguém já aproveitou.
     */
    private const TELAS = [
        // Todo mundo que loga
        'perfil.php' => ['admin', 'editor', 'atendente'],
        // A ajuda serve aos tres papeis, e a secao de atendimento e justamente
        // a que o atendente precisa. Esconder dele seria esconder o manual de
        // quem mais usa o sistema.
        'ajuda.php' => ['admin', 'editor', 'atendente'],

        // Atendimento humano
        'atendimento.php' => ['admin', 'editor', 'atendente'],
        'chamados.php' => ['admin', 'editor', 'atendente'],
        // Arquivo que a pessoa mandou na conversa. Acompanha a tela de
        // atendimento: de nada adianta o atendente ver "[image]" e não poder
        // abrir a foto que a pessoa mandou para explicar o problema.
        'anexo.php' => ['admin', 'editor', 'atendente'],

        // Panorama do momento: admin e editor, NAO o atendente.
        //
        // A tela mostra quem esta com cada conversa e ha quanto tempo. Nas
        // maos de quem atende ao lado, isso vira placar entre colegas —
        // custo social que a tela nao precisa pagar, porque quem atende ja
        // tem a fila e a propria conversa na tela de Atendimento.
        //
        // O editor entra porque nesta instalacao ele e a lideranca do setor:
        // alimenta o RAG, responde pelo assunto e precisa da visao ampla.
        'mapa-agora.php' => ['admin', 'editor'],

        // Dashboard para os três papéis, com DADOS diferentes.
        //
        // O atendente vê um painel só dele: as conversas que passaram pelas
        // mãos dele, e nada de configuração, alerta de sistema ou número dos
        // colegas. A tela é a mesma; o recorte é feito em index.php, pelo
        // `atendente_id` e pelo autor das mensagens.
        'index.php' => ['admin', 'editor', 'atendente'],

        // Conteúdo — o que o agente sabe
        'bases.php' => ['admin', 'editor'],
        'artefatos.php' => ['admin', 'editor'],
        'faq.php' => ['admin', 'editor'],
        'setores.php' => ['admin', 'editor'],
        'testar-busca.php' => ['admin', 'editor'],
        'conversas.php' => ['admin', 'editor'],
        'playground.php' => ['admin', 'editor'],

        // O resto é só admin: agentes, provedores, canais, ferramentas,
        // testar-ferramenta, leads (dado pessoal), privacidade, logs, usuarios.
    ];

    /** Onde cada papel cai ao entrar. */
    public const INICIO = [
        'admin' => 'index.php',
        'editor' => 'index.php',
        'atendente' => 'atendimento.php',
    ];

    public static function papelValido(mixed $papel): string
    {
        return array_key_exists((string) $papel, self::PAPEIS) ? (string) $papel : 'atendente';
    }

    public static function podeVer(string $papel, string $tela): bool
    {
        if ($papel === 'admin') {
            return true;
        }

        return in_array($papel, self::TELAS[$tela] ?? [], true);
    }

    /**
     * A tela para onde mandar quem caiu onde não devia.
     *
     * Nunca devolve erro seco: a pessoa é levada para onde ela de fato
     * trabalha. Um atendente que clicou num link antigo vê a fila, não um 403.
     */
    public static function inicioDe(string $papel): string
    {
        return self::INICIO[$papel] ?? 'atendimento.php';
    }

    /**
     * Filtra os grupos da sidebar, descartando itens e grupos vazios.
     *
     * @param array<string, array<string, array<string, string>>> $grupos
     * @return array<string, array<string, array<string, string>>>
     */
    public static function filtrarMenu(array $grupos, string $papel): array
    {
        $saida = [];

        foreach ($grupos as $titulo => $itens) {
            $permitidos = array_filter(
                $itens,
                static fn (string $arquivo): bool => self::podeVer($papel, $arquivo),
                ARRAY_FILTER_USE_KEY
            );

            if ($permitidos !== []) {
                $saida[$titulo] = $permitidos;
            }
        }

        return $saida;
    }
}
