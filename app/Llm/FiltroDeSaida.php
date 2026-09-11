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
        $texto = (string) preg_replace('/\*\*\s*\*\*/u', '', $texto);
        $texto = (string) preg_replace('/(?<!\S)(?:\*[ \t]*){2,}(?!\S)/u', ' ', $texto);
        $texto = (string) preg_replace('/[ \t]+\n/u', "\n", $texto);
        $texto = (string) preg_replace('/\n{3,}/u', "\n\n", $texto);

        return trim($texto);
    }

    /** Tokens soltos do harmony (`<|start|>`, `<|end|>`) fora de marcador conhecido. */
    private static function semTokens(string $texto): string
    {
        return (string) preg_replace('/<\|[^|>]{0,40}\|>/u', '', $texto);
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
