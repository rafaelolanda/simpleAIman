<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

/**
 * Traduz a tabela `ferramentas` em ferramentas que o modelo enxerga.
 *
 * A callable de cada uma aponta para o `Executor`, e não para a
 * implementação: é assim que validação, trava de ordem e auditoria ficam no
 * caminho de TODA chamada, em vez de dependerem de cada tipo lembrar de
 * fazê-las.
 */
final class ToolRegistry
{
    public function __construct(
        private readonly int $agenteId,
        private readonly int $conversaId,
    ) {
    }

    /**
     * Ferramentas ativas vinculadas ao agente.
     *
     * @return list<Tool>
     */
    public function paraAgente(): array
    {
        $executor = new Executor($this->conversaId, $this->agenteId);
        $tools = [];

        foreach ($this->linhas() as $f) {
            $tool = Tool::make((string) $f['slug'], $this->descricao($f));

            foreach ($this->parametros((int) $f['id']) as $p) {
                $tool->addProperty(new ToolProperty(
                    (string) $p['nome'],
                    $this->tipo((string) $p['tipo']),
                    $this->descricaoParametro($p, $executor),
                    (bool) $p['obrigatorio'],
                ));
            }

            // Os argumentos chegam como parâmetros nomeados, então a callable
            // precisa aceitar qualquer combinação — quem valida é o Executor.
            $tool->setCallable(
                static fn (mixed ...$args): string => $executor->executar($f, self::nomear($args))
            );

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * A descrição que o modelo lê para decidir QUANDO chamar.
     *
     * O campo `depende_de` também vira texto aqui: o Executor recusa a
     * chamada fora de ordem de qualquer jeito, mas avisar antes evita gastar
     * um turno inteiro numa tentativa que já se sabe que vai falhar.
     *
     * @param array<string, mixed> $f
     */
    private function descricao(array $f): string
    {
        $texto = trim((string) $f['descricao_llm']);

        if (!empty($f['depende_de'])) {
            $stmt = Database::connection()->prepare('SELECT slug FROM ferramentas WHERE id = :id');
            $stmt->execute(['id' => (int) $f['depende_de']]);
            $anterior = (string) ($stmt->fetchColumn() ?: '');

            if ($anterior !== '') {
                $texto .= " Só use depois de ter executado \"{$anterior}\" nesta conversa.";
            }
        }

        if (($f['efeito'] ?? '') === 'escrita') {
            $texto .= ' Esta ação REGISTRA algo — confirme com a pessoa antes de usá-la.';
        }

        return $texto;
    }

    /**
     * Descrição do parâmetro, com as opções listadas quando houver.
     *
     * Enum dinâmico vem do banco na hora da chamada: criar um setor no admin
     * o torna escolhível imediatamente, sem tocar em código nem no prompt.
     *
     * @param array<string, mixed> $p
     */
    private function descricaoParametro(array $p, Executor $executor): string
    {
        $texto = trim((string) ($p['descricao_llm'] ?? ''));
        $opcoes = $executor->opcoesDoParametro($p);

        if ($opcoes !== []) {
            $texto .= ' Valores aceitos: ' . implode(', ', $opcoes) . '.';
        }

        if (!empty($p['exemplo'])) {
            $texto .= ' Exemplo: ' . $p['exemplo'] . '.';
        }

        return trim($texto);
    }

    private function tipo(string $tipo): PropertyType
    {
        return match ($tipo) {
            'number' => PropertyType::NUMBER,
            'boolean' => PropertyType::BOOLEAN,
            default => PropertyType::STRING,
        };
    }

    /**
     * Os argumentos chegam nomeados pelo PHP quando a callable é invocada com
     * `...$args` associativo. Esta normalização protege o caso de virem
     * posicionais.
     *
     * @param array<int|string, mixed> $args
     * @return array<string, mixed>
     */
    private static function nomear(array $args): array
    {
        $nomeados = [];

        foreach ($args as $chave => $valor) {
            if (is_string($chave)) {
                $nomeados[$chave] = $valor;
            }
        }

        return $nomeados;
    }

    /** @return list<array<string, mixed>> */
    private function linhas(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT f.* FROM ferramentas f
             JOIN agente_ferramentas af ON af.ferramenta_id = f.id
             WHERE af.agente_id = :a AND f.ativo = 1
             ORDER BY af.ordem, f.nome'
        );
        $stmt->execute(['a' => $this->agenteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function parametros(int $ferramentaId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ferramenta_parametros WHERE ferramenta_id = :id ORDER BY ordem, id'
        );
        $stmt->execute(['id' => $ferramentaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** O agente tem alguma ferramenta de encaminhamento ligada? */
    public static function temHandoff(int $agenteId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM ferramentas f
             JOIN agente_ferramentas af ON af.ferramenta_id = f.id
             WHERE af.agente_id = :a AND f.ativo = 1
               AND f.tipo IN (\'contato_setor\', \'abrir_chamado\', \'handoff\', \'transferir_atendimento\')'
        );
        $stmt->execute(['a' => $agenteId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
