<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use Database;
use PDO;

/**
 * Modelos prontos das ferramentas embutidas.
 *
 * As embutidas são código (`SetorTool`, `LeadTool`, `AtendimentoTool`), mas só
 * existem para o modelo depois de virarem **linha** em `ferramentas`. Isso é
 * de propósito e não é burocracia: o campo `descricao_llm` é o que decide
 * QUANDO o modelo chama a ferramenta, e ele precisa ser afinado por cliente —
 * "falar com uma pessoa" numa universidade não é a mesma frase que numa
 * concessionária. Uma embutida com descrição fixa no código tiraria justamente
 * o botão que mais move o comportamento do agente.
 *
 * O que sobrava de ruim era outra coisa: ninguém descobria que elas existiam, e
 * quem descobria escrevia descrição e parâmetros na mão toda vez. Este catálogo
 * resolve as duas — cria a linha já preenchida com um texto que funciona, e
 * deixa você ajustar depois.
 */
final class Catalogo
{
    /**
     * @return array<string, array{
     *     nome: string, slug: string, efeito: string, resumo: string,
     *     descricao_llm: string,
     *     parametros: list<array{nome: string, tipo: string, descricao: string, obrigatorio: int, fonte?: string}>
     * }>
     */
    public static function embutidas(): array
    {
        return [
            'contato_setor' => [
                'nome' => 'Contato do setor',
                'slug' => 'contato_setor',
                'efeito' => 'leitura',
                'resumo' => 'Passa telefone, e-mail, WhatsApp e horário do setor responsável.',
                'descricao_llm' =>
                    'Devolve os dados de contato oficiais do setor responsável: telefone, e-mail, '
                    . 'WhatsApp e horário de atendimento. Use quando a pessoa perguntar como falar com '
                    . 'um setor, ou quando você não souber responder e o caminho for indicar quem sabe. '
                    . 'Os dados saem SEMPRE desta ferramenta: nunca invente telefone nem e-mail, e '
                    . 'mencione o horário de atendimento junto do telefone.',
                'parametros' => [
                    ['nome' => 'setor', 'tipo' => 'enum', 'obrigatorio' => 1, 'fonte' => 'setores',
                     'descricao' => 'Setor responsável pelo assunto. Se não souber qual, escolha o de atendimento geral.'],
                ],
            ],

            'transferir_atendimento' => [
                'nome' => 'Transferir para atendente',
                'slug' => 'transferir_atendimento',
                'efeito' => 'escrita',
                'resumo' => 'Passa a conversa para uma pessoa, ao vivo, na mesma janela.',
                'descricao_llm' =>
                    'Transfere a conversa para um atendente humano, que passa a responder nesta mesma '
                    . 'janela. Use quando a pessoa pedir explicitamente para falar com alguém, ou quando '
                    . 'a dúvida exigir decisão humana que você não pode tomar. A ferramenta informa se há '
                    . 'atendente disponível: se não houver, ela avisa, e aí ofereça registrar um chamado '
                    . 'ou passe o contato do setor. NUNCA prometa transferência antes de chamar esta '
                    . 'ferramenta e receber a confirmação.',
                'parametros' => [
                    ['nome' => 'setor', 'tipo' => 'enum', 'obrigatorio' => 0, 'fonte' => 'setores',
                     'descricao' => 'Setor responsável pelo assunto, se der para identificar. Deixe vazio se não souber.'],
                    ['nome' => 'motivo', 'tipo' => 'string', 'obrigatorio' => 0,
                     'descricao' => 'Resumo curto do que a pessoa precisa, para o atendente já entrar sabendo.'],
                ],
            ],

            'abrir_chamado' => [
                'nome' => 'Registrar chamado',
                'slug' => 'abrir_chamado',
                'efeito' => 'escrita',
                'resumo' => 'Registra a dúvida e avisa o responsável por e-mail. Funciona 24/7.',
                'descricao_llm' =>
                    'Registra a dúvida da pessoa para que o setor responsável retorne depois, e avisa '
                    . 'esse setor por e-mail. Use fora do horário de atendimento, quando não houver '
                    . 'atendente disponível, ou quando a resposta depender de alguém que não está online. '
                    . 'Peça um e-mail ou telefone para retorno ANTES de chamar: sem contato não há como '
                    . 'responder à pessoa. Informe o número de protocolo que a ferramenta devolver, e não '
                    . 'prometa prazo de retorno.',
                'parametros' => [
                    ['nome' => 'setor', 'tipo' => 'enum', 'obrigatorio' => 1, 'fonte' => 'setores',
                     'descricao' => 'Setor que deve responder. Se não souber qual, escolha o de atendimento geral.'],
                    ['nome' => 'assunto', 'tipo' => 'string', 'obrigatorio' => 1,
                     'descricao' => 'Resumo do pedido em uma linha.'],
                    ['nome' => 'descricao', 'tipo' => 'string', 'obrigatorio' => 0,
                     'descricao' => 'Detalhes que ajudem quem for responder.'],
                    ['nome' => 'contato', 'tipo' => 'string', 'obrigatorio' => 1,
                     'descricao' => 'E-mail ou telefone informado pela pessoa para o retorno.'],
                ],
            ],

            'lead' => [
                'nome' => 'Registrar contato (lead)',
                'slug' => 'registrar_lead',
                'efeito' => 'escrita',
                'resumo' => 'Guarda o contato de quem demonstrou interesse e entrega ao CRM.',
                'descricao_llm' =>
                    'Registra o contato de alguém interessado, para a equipe retornar. Use quando a '
                    . 'pessoa demonstrar interesse concreto e concordar em deixar os dados. Peça os dados '
                    . 'de forma natural, no meio da conversa, e só depois de ela ter obtido o que veio '
                    . 'buscar — pedir cadastro antes de ajudar afasta. Diga para que servem os dados '
                    . 'antes de registrar.',
                'parametros' => [
                    ['nome' => 'nome', 'tipo' => 'string', 'obrigatorio' => 0, 'descricao' => 'Nome da pessoa.'],
                    ['nome' => 'email', 'tipo' => 'string', 'obrigatorio' => 0, 'descricao' => 'E-mail para retorno.'],
                    ['nome' => 'telefone', 'tipo' => 'string', 'obrigatorio' => 0, 'descricao' => 'Telefone ou WhatsApp.'],
                    ['nome' => 'interesse', 'tipo' => 'string', 'obrigatorio' => 0,
                     'descricao' => 'O que a pessoa procura (curso, produto, serviço).'],
                ],
            ],
        ];
    }

    /** Já existe alguma ferramenta deste tipo embutido? */
    public static function jaExiste(string $tipo): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM ferramentas WHERE tipo = :t LIMIT 1');
        $stmt->execute(['t' => $tipo]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Cria a ferramenta a partir do modelo e devolve o id.
     *
     * Nasce **ativa mas sem vínculo a agente nenhum**: aparece na lista pronta
     * para uso, e nada passa a acontecer sozinho. Ligar a um agente continua
     * sendo uma decisão explícita de quem administra.
     */
    public static function instanciar(string $tipo): int
    {
        $modelo = self::embutidas()[$tipo] ?? null;

        if ($modelo === null) {
            throw new \RuntimeException("Tipo embutido desconhecido: {$tipo}.");
        }

        $pdo = Database::connection();
        $agora = now();
        $slug = self::slugLivre($modelo['slug']);

        $pdo->prepare(
            'INSERT INTO ferramentas (slug, nome, descricao_llm, tipo, efeito, ativo, criado_em, editado_em)
             VALUES (:slug, :nome, :descricao, :tipo, :efeito, 1, :agora, :agora)'
        )->execute([
            'slug' => $slug,
            'nome' => $modelo['nome'],
            'descricao' => $modelo['descricao_llm'],
            'tipo' => $tipo,
            'efeito' => $modelo['efeito'],
            'agora' => $agora,
        ]);

        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO ferramenta_parametros (ferramenta_id, nome, tipo, descricao_llm, obrigatorio, enum_fonte, ordem)
             VALUES (:f, :nome, :tipo, :descricao, :obrig, :fonte, :ordem)'
        );

        foreach ($modelo['parametros'] as $ordem => $p) {
            $stmt->execute([
                'f' => $id,
                'nome' => $p['nome'],
                'tipo' => $p['tipo'],
                'descricao' => $p['descricao'],
                'obrig' => $p['obrigatorio'],
                'fonte' => $p['fonte'] ?? null,
                'ordem' => $ordem,
            ]);
        }

        return $id;
    }

    /** Sufixo numérico quando o slug preferido já está em uso. */
    private static function slugLivre(string $base): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT 1 FROM ferramentas WHERE slug = :s');

        $slug = $base;
        $n = 2;

        $stmt->execute(['s' => $slug]);

        while ($stmt->fetchColumn()) {
            $slug = $base . '-' . $n++;
            $stmt->execute(['s' => $slug]);
        }

        return $slug;
    }

    /**
     * Semeia as embutidas numa instalação nova.
     *
     * Só cria o que ainda não existe, e nunca vincula a agente: instância nova
     * nasce com as quatro visíveis na lista, e nenhuma delas em uso.
     *
     * @return list<string> nomes criados
     */
    public static function semear(): array
    {
        $criadas = [];

        foreach (array_keys(self::embutidas()) as $tipo) {
            if (!self::jaExiste($tipo)) {
                self::instanciar($tipo);
                $criadas[] = $tipo;
            }
        }

        return $criadas;
    }
}
