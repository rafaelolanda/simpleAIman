<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use PDO;
use SimpleAIman\Atendimento\Fila;

/**
 * A ferramenta `transferir_atendimento` — o Nível 2 do handoff.
 *
 * O que ela faz de mais importante é **falhar bem**. Transferir só funciona
 * se houver alguém do outro lado; quando não há, ela não devolve erro nem
 * silêncio: cai para o Nível 1 (registrar chamado), que é a saída que
 * funciona às 3h da manhã e no domingo.
 *
 * Sem esse degrau, o agente prometeria transferência para uma sala vazia — e
 * a pessoa ficaria olhando uma tela de espera que nunca termina, que é pior
 * do que ter dito desde o começo que ninguém está online.
 */
final class AtendimentoTool
{
    public function __construct(private readonly int $conversaId)
    {
    }

    /** @param array<string, mixed> $parametros */
    public function transferir(array $parametros): string
    {
        $setorId = $this->setorDe($parametros['setor'] ?? null);
        $motivo = trim((string) ($parametros['motivo'] ?? ''));

        $resultado = Fila::solicitar($this->conversaId, $setorId, $motivo);

        if ($resultado['transferido']) {
            return json_encode([
                'transferido' => true,
                'instrucao' => 'Diga que está transferindo para um atendente e peça que aguarde um instante '
                    . 'nesta mesma janela. NÃO faça mais perguntas nem continue o assunto: a partir de agora '
                    . 'quem responde é uma pessoa. NÃO prometa prazo.',
            ], JSON_UNESCAPED_UNICODE);
        }

        // Ninguém disponível. O agente precisa saber disso de forma acionável,
        // não como falha: o próximo passo dele é oferecer o caminho assíncrono.
        return json_encode([
            'transferido' => false,
            'motivo' => 'Nenhum atendente disponível no momento.',
            'instrucao' => 'Informe que não há atendente disponível agora. Ofereça registrar a dúvida '
                . 'para retorno (ferramenta de abrir chamado) ou passar o contato do setor. '
                . 'NÃO diga que alguém vai entrar na conversa.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Slug de setor vindo do modelo → id, ou null.
     *
     * Null é resposta legítima aqui, e não erro: sem setor a fila procura
     * qualquer atendente disponível. Diferente do `contato_setor`, onde não
     * ter setor significaria não ter o que responder.
     */
    private function setorDe(mixed $slug): ?int
    {
        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT id FROM setores WHERE slug = :s AND ativo = 1');
        $stmt->execute(['s' => $slug]);

        $id = $stmt->fetchColumn(0);

        return $id === false ? null : (int) $id;
    }
}
