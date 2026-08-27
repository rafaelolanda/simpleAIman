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

            'busca_web' => [
                'nome' => 'Busca na web (domínio restrito)',
                'slug' => 'busca_web',
                // Tipo `http`: não é embutida de verdade, é um MODELO de
                // chamada externa já montado. A restrição de domínio mora no
                // template, que o modelo não alcança — ele só preenche o termo,
                // e o valor vai percent-encoded. Não é instrução que ele possa
                // ignorar; é estrutura.
                'tipo' => 'http',
                'efeito' => 'leitura',
                'resumo' => 'Procura no site da instituição por um buscador externo. Exige chave de API.',
                'descricao_llm' =>
                    'Procura páginas públicas do site da instituição. Use quando a pergunta for sobre '
                    . 'algo que muda com frequência — edital recém-publicado, notícia, data de evento — '
                    . 'e que provavelmente não está nos documentos já carregados. Para assunto estável '
                    . '(regras, serviços, procedimentos), prefira os documentos: eles são mais confiáveis '
                    . 'e você pode citar a fonte. Cite o link dos resultados que usar.',
                'http' => [
                    'metodo' => 'GET',
                    // TROQUE `SEUDOMINIO.COM.BR`. O `site:` fica FORA do
                    // alcance do modelo de propósito.
                    'url_template' => 'https://api.search.brave.com/res/v1/web/search'
                        . '?q=site%3ASEUDOMINIO.COM.BR+{{params.termo}}&count=5',
                    // O segredo entra por referência ao .env, nunca literal.
                    'headers' => '{"Accept":"application/json","X-Subscription-Token":"{{env.BUSCA_API_KEY}}"}',
                    'auth_tipo' => 'none',
                    'timeout_ms' => 8000,
                    'resposta_caminho' => 'web.results',
                    // O visitante precisa saber que isto não saiu dos
                    // documentos da instituição. Anexado pelo sistema.
                    'aviso_resposta' => 'Esta informação veio de uma busca na web, '
                        . 'não dos documentos oficiais, e deve ser conferida.',
                ],
                'parametros' => [
                    ['nome' => 'termo', 'tipo' => 'string', 'obrigatorio' => 1,
                     'descricao' => 'Palavras-chave da busca. Só os termos, sem "site:" nem operadores.'],
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
                     'descricao' => 'O que a pessoa procura (produto, serviço, assunto).'],
                ],
            ],
        ];
    }

    /**
     * Este modelo já foi instanciado?
     *
     * A pergunta é diferente conforme o modelo:
     *
     * - **Embutida** (`contato_setor`, `lead`…): identidade é o TIPO. Duas
     *   ferramentas de contato de setor na mesma instância não fazem sentido.
     * - **Modelo `http`** (busca web): identidade é o SLUG. Comparar por tipo
     *   diria "já criada" só porque existe alguma outra chamada externa
     *   configurada, que não tem relação nenhuma.
     */
    public static function jaExiste(string $chave): bool
    {
        $modelo = self::embutidas()[$chave] ?? null;

        if ($modelo === null) {
            return false;
        }

        $pdo = Database::connection();

        if (($modelo['tipo'] ?? $chave) === 'http') {
            $stmt = $pdo->prepare('SELECT 1 FROM ferramentas WHERE slug LIKE :s LIMIT 1');
            $stmt->execute(['s' => $modelo['slug'] . '%']);
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM ferramentas WHERE tipo = :t LIMIT 1');
            $stmt->execute(['t' => $chave]);
        }

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

        // Modelos do tipo `http` trazem a chamada inteira pré-montada. É o que
        // transforma "descubra a sintaxe do template e do provedor" em "troque
        // o domínio e ponha a chave no .env".
        $http = $modelo['http'] ?? [];

        $pdo->prepare(
            'INSERT INTO ferramentas
                (slug, nome, descricao_llm, tipo, efeito, ativo,
                 metodo, url_template, headers, corpo_template,
                 auth_tipo, auth_ref, timeout_ms, resposta_caminho, aviso_resposta,
                 criado_em, editado_em)
             VALUES
                (:slug, :nome, :descricao, :tipo, :efeito, 1,
                 :metodo, :url, :headers, :corpo,
                 :auth_tipo, :auth_ref, :timeout, :caminho, :aviso,
                 :agora, :agora)'
        )->execute([
            'slug' => $slug,
            'nome' => $modelo['nome'],
            'descricao' => $modelo['descricao_llm'],
            'tipo' => $modelo['tipo'] ?? $tipo,
            'efeito' => $modelo['efeito'],
            'metodo' => $http['metodo'] ?? 'GET',
            'url' => $http['url_template'] ?? null,
            'headers' => $http['headers'] ?? null,
            'corpo' => $http['corpo_template'] ?? null,
            'auth_tipo' => $http['auth_tipo'] ?? 'none',
            'auth_ref' => $http['auth_ref'] ?? null,
            'timeout' => $http['timeout_ms'] ?? null,
            'caminho' => $http['resposta_caminho'] ?? null,
            'aviso' => $http['aviso_resposta'] ?? null,
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

        foreach (self::embutidas() as $chave => $modelo) {
            // Modelos `http` ficam de fora da semeadura: eles precisam de chave
            // de API e de um domínio editado à mão. Criados na instalação,
            // nasceriam quebrados — e ferramenta quebrada visível é pior que
            // ferramenta ausente, porque o agente tenta usar.
            if (($modelo['tipo'] ?? $chave) === 'http') {
                continue;
            }

            if (!self::jaExiste($chave)) {
                self::instanciar($chave);
                $criadas[] = $chave;
            }
        }

        return $criadas;
    }
}
