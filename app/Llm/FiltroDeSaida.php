<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

/**
 * Tira da resposta o RACIOCÍNIO INTERNO do modelo.
 *
 * Modelos de raciocínio aberto (gpt-oss da Groq, DeepSeek-R1, QwQ) mandam o
 * pensamento no mesmo campo da resposta, marcado com `<think>` ou com os
 * marcadores do formato harmony (`<|channel|>analysis<|message|>`). Quem lê o
 * `content` sem filtrar recebe os dois grudados.
 *
 * Aconteceu em produção em 11/09/2026: uma mensagem de WhatsApp saiu com
 * "Segue * * * * ***", várias linhas em branco e o pensamento do modelo em
 * inglês ("We got a messed up output. Need to produce proper answer...") antes
 * da resposta de verdade.
 *
 * `ProviderFactory` já pede à Groq que não mande o raciocínio. Isto aqui é a
 * segunda linha: o parâmetro vale para um fornecedor, o filtro vale para
 * todos, inclusive o que ainda não foi cadastrado.
 *
 * Serve ao texto inteiro (`texto()`) e ao streaming, pedaço a pedaço, onde o
 * marcador pode chegar partido entre dois pedaços — daí o estado.
 */
final class FiltroDeSaida
{
    /** Marcadores que ABREM um trecho de raciocínio. */
    private const ABRE = [
        '<think>',
        '<|channel|>analysis<|message|>',
        '<|channel|>analysis',
    ];

    /** Marcadores que FECHAM. */
    private const FECHA = [
        '</think>',
        'assistantfinal',
        '<|channel|>final<|message|>',
        '<|start|>assistant<|message|>',
    ];

    /** O maior marcador, em bytes: o tanto que se segura à espera do resto. */
    private const MAIOR = 30;

    private string $buffer = '';

    private bool $dentro = false;

    /**
     * Processa um pedaço do streaming e devolve o que pode sair agora.
     *
     * Segura os últimos bytes: eles podem ser o começo de um marcador que
     * ainda não chegou inteiro. O que ficou retido sai em `fim()`.
     */
    public function pedaco(string $texto): string
    {
        $this->buffer .= $texto;
        $saida = '';

        while (true) {
            if ($this->dentro) {
                $pos = self::primeiro($this->buffer, self::FECHA, $marcador);

                if ($pos === null) {
                    // Dentro do raciocínio nada sai. Guarda só a ponta, que
                    // pode ser metade do marcador de fechamento.
                    $this->buffer = substr($this->buffer, -self::MAIOR);

                    return self::semTokens($saida);
                }

                $this->buffer = substr($this->buffer, $pos + strlen($marcador));
                $this->dentro = false;

                continue;
            }

            $pos = self::primeiro($this->buffer, self::ABRE, $marcador);

            if ($pos === null) {
                break;
            }

            $saida .= substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + strlen($marcador));
            $this->dentro = true;
        }

        $solto = strlen($this->buffer) - self::MAIOR;

        if ($solto > 0) {
            // Corta em fronteira de CARACTERE, não de byte.
            //
            // Cortar no meio de um "ç" produz um pedaço com UTF-8 inválido, e
            // aí `preg_replace` com `/u` devolve null — o pedaço inteiro virava
            // string vazia e a resposta chegava com buracos no meio das
            // palavras ("Sistemas de Infoão"). Visto em 16/09/2026, no mesmo
            // dia em que este filtro entrou.
            $solto = self::prefixoValido(substr($this->buffer, 0, $solto));
        }

        if ($solto > 0) {
            $saida .= substr($this->buffer, 0, $solto);
            $this->buffer = substr($this->buffer, $solto);
        }

        return self::semTokens($saida);
    }

    /** O que ficou retido. Raciocínio sem fechamento é descartado inteiro. */
    public function fim(): string
    {
        $resto = $this->dentro ? '' : $this->buffer;

        $this->buffer = '';
        $this->dentro = false;

        return self::semTokens($resto);
    }

    /**
     * Limpa um texto completo: raciocínio fora, e as sobras de formatação que
     * ele deixa (negrito vazio, fileira de asteriscos, linhas em branco).
     */
    public static function texto(string $texto): string
    {
        // Sem marcador de abertura, mas com o de fechamento do harmony: o que
        // vale é o que vem DEPOIS do último.
        $corte = strripos($texto, 'assistantfinal');

        if ($corte !== false) {
            $texto = substr($texto, $corte + strlen('assistantfinal'));
        }

        $filtro = new self();
        $limpo = $filtro->pedaco($texto) . $filtro->fim();

        return self::cosmetica($limpo);
    }

    /** Sobras de formatação: `** **`, fileira de asteriscos, linhas vazias. */
    public static function cosmetica(string $texto): string
    {
        $texto = self::trocar('/\*\*\s*\*\*/u', '', $texto);
        $texto = self::trocar('/(?<!\S)(?:\*[ \t]*){2,}(?!\S)/u', ' ', $texto);
        $texto = self::trocar('/[ \t]+\n/u', "\n", $texto);
        $texto = self::trocar('/\n{3,}/u', "\n\n", $texto);

        return trim($texto);
    }

    /** Tokens soltos do harmony (`<|start|>`, `<|end|>`) fora de marcador conhecido. */
    private static function semTokens(string $texto): string
    {
        return self::trocar('/<\|[^|>]{0,40}\|>/u', '', $texto);
    }

    /**
     * `preg_replace` que devolve o ORIGINAL quando a expressão falha.
     *
     * Com `/u`, um único byte solto faz o preg devolver null. Convertido para
     * string, isso apagava o texto inteiro em vez de deixá-lo como estava —
     * perder formatação é aceitável, perder a resposta não é.
     */
    private static function trocar(string $padrao, string $por, string $texto): string
    {
        $novo = preg_replace($padrao, $por, $texto);

        return $novo === null ? $texto : $novo;
    }

    /**
     * Tamanho do maior prefixo que é UTF-8 completo.
     *
     * Um caractere UTF-8 tem no máximo 4 bytes, então basta recuar até 3.
     */
    private static function prefixoValido(string $texto): int
    {
        $tamanho = strlen($texto);

        for ($i = $tamanho; $i > max(0, $tamanho - 4); $i--) {
            if (mb_check_encoding(substr($texto, 0, $i), 'UTF-8')) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Posição do primeiro marcador da lista, e qual foi.
     *
     * @param list<string> $marcadores
     */
    private static function primeiro(string $texto, array $marcadores, ?string &$achado): ?int
    {
        $melhor = null;
        $achado = null;

        foreach ($marcadores as $m) {
            $pos = stripos($texto, $m);

            if ($pos !== false && ($melhor === null || $pos < $melhor)) {
                $melhor = $pos;
                $achado = substr($texto, $pos, strlen($m));
            }
        }

        return $melhor;
    }
}
