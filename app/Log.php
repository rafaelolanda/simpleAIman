<?php

declare(strict_types=1);

/**
 * Log de evento em UMA LINHA de JSON.
 *
 * Substitui as chamadas soltas de `error_log()` com texto livre. O problema
 * delas não era o destino — era o formato: mensagem em prosa, quase sempre sem
 * o id da conversa, impossível de filtrar por máquina. Quando o atendimento
 * falhava às 23h, descobrir QUAIS conversas foram afetadas exigia ler o log
 * inteiro com o olho.
 *
 * O destino continua sendo o `error_log()` do PHP de propósito: em hospedagem
 * compartilhada é o único lugar que sempre existe e que já tem rotação
 * resolvida pelo painel do provedor. O que muda é que cada linha passa a ser um
 * objeto JSON, com `trace` e `conversa` dentro sempre que houver turno aberto.
 *
 * O prefixo `[simpleAIman]` fica: quem já tem `grep` montado continua achando.
 *
 * CONTEÚDO NÃO ENTRA AQUI. Nem pergunta, nem resposta, nem trecho recuperado.
 * O log viveria fora do alcance da anonimização e do expurgo configurados em
 * `config`, e criaria uma segunda cópia de dado pessoal que ninguém lembra de
 * apagar. Só metadado: duração, contagem, id, status, causa. O corte em
 * `MAX_TEXTO` é rede de segurança para quando alguém esquecer disso, não
 * permissão para mandar texto.
 */
final class Log
{
    /** Teto por campo de texto. Rede de segurança, não convite. */
    private const MAX_TEXTO = 300;

    /** @param array<string, mixed> $campos */
    public static function evento(string $nome, array $campos = []): void
    {
        self::escrever('info', $nome, $campos);
    }

    /** @param array<string, mixed> $campos */
    public static function erro(string $nome, array $campos = []): void
    {
        self::escrever('erro', $nome, $campos);
    }

    /**
     * Falha de infraestrutura vira log, nunca exceção.
     *
     * Log é observação, e observação que derruba o que observa é pior que
     * nenhuma. Todo o corpo vive dentro de um try/catch cego pela mesma razão
     * de `registrarFalha()` no ChatService: falhar ao registrar a falha não
     * pode custar o atendimento.
     *
     * @param array<string, mixed> $campos
     */
    private static function escrever(string $nivel, string $nome, array $campos): void
    {
        try {
            $linha = ['ev' => $nome, 'nivel' => $nivel];

            if (Turno::ativo()) {
                $linha['trace'] = Turno::id();
                $contexto = Turno::contexto();

                foreach (['conversa_id', 'agente_id', 'canal'] as $chave) {
                    if (isset($contexto[$chave])) {
                        $linha[$chave] = $contexto[$chave];
                    }
                }
            }

            foreach ($campos as $chave => $valor) {
                $linha[$chave] = is_string($valor) ? mb_substr($valor, 0, self::MAX_TEXTO) : $valor;
            }

            $json = json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            error_log('[simpleAIman] ' . ($json !== false ? $json : $nome));
        } catch (Throwable) {
            // Silêncio proposital: ver o bloco acima.
        }
    }
}
