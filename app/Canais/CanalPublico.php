<?php

declare(strict_types=1);

namespace SimpleAIman\Canais;

use Database;
use PDO;

/**
 * Guardas do canal público — o widget que roda no site de terceiro.
 *
 * A premissa que define o desenho inteiro: **o token não é segredo**. Ele vai
 * dentro de um `<script>` na página, visível para qualquer um que aperte
 * Ctrl+U. Tratá-lo como senha seria autoengano. Ele identifica qual canal
 * responde; quem autoriza é a lista de domínios.
 *
 * E autorizar não basta. Um endpoint público que chama a LLM é uma torneira
 * ligada na conta do provedor: sem limite de uso, um único visitante mal
 * intencionado (ou um bot de scraping) esvazia a cota em minutos. Por isso o
 * limite mora aqui e não numa camada opcional.
 */
final class CanalPublico
{
    /** @var array<string, mixed> */
    public readonly array $canal;

    /** @var array<string, mixed> */
    private readonly array $opcoes;

    /** @param array<string, mixed> $canal */
    private function __construct(array $canal)
    {
        $this->canal = $canal;
        $this->opcoes = json_para_array($canal['config'] ?? null);
    }

    /**
     * Resolve o token em um canal ativo, ou null.
     *
     * O slug É o token: ele já é único, já é legível e já não é segredo.
     * Guardar uma segunda coluna só para repetir a mesma função seria mais uma
     * coisa para manter em sincronia.
     */
    public static function porToken(string $token): ?self
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $stmt = Database::connection()->prepare(
            "SELECT * FROM canais WHERE slug = :slug AND ativo = 1 AND tipo = 'web'"
        );
        $stmt->execute(['slug' => $token]);

        $canal = $stmt->fetch(PDO::FETCH_ASSOC);

        return $canal ? new self($canal) : null;
    }

    /**
     * A origem que fez a requisição está autorizada?
     *
     * Sem lista configurada o canal só responde à própria instalação. É
     * restritivo de propósito: um canal recém-criado que aceitasse o mundo
     * inteiro seria uma janela aberta que ninguém lembraria de fechar.
     */
    public function origemPermitida(?string $origem): bool
    {
        if ($origem === null || $origem === '') {
            // Requisição de mesma origem não manda Origin. Aceita — a página
            // que a fez está no nosso próprio domínio.
            return true;
        }

        $host = parse_url($origem, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        foreach ($this->dominios() as $permitido) {
            // Subdomínio conta: quem libera "exemplo.com.br" está pensando no
            // site, e o site costuma ter www e às vezes loja, blog, etc.
            if ($host === $permitido || str_ends_with($host, '.' . $permitido)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function dominios(): array
    {
        $lista = $this->opcoes['dominios'] ?? [];

        if (is_string($lista)) {
            $lista = preg_split('/[\s,;]+/', $lista) ?: [];
        }

        $limpos = [];

        foreach ((array) $lista as $d) {
            $d = strtolower(trim((string) $d));
            // Aceita que alguém cole a URL inteira em vez do domínio: é o
            // erro mais provável de quem preenche esse campo uma vez só.
            $d = (string) preg_replace('#^https?://#', '', $d);
            $d = trim(explode('/', $d)[0]);

            if ($d !== '') {
                $limpos[] = $d;
            }
        }

        return $limpos;
    }

    public function agenteId(): int
    {
        $id = (int) ($this->canal['agente_id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        return (int) Database::connection()
            ->query('SELECT agente_padrao_id FROM config WHERE id = 1')
            ->fetchColumn();
    }

    /**
     * O agente deste canal responde com IA ou é roteador?
     *
     * O canal precisa saber disso antes de aplicar o limite de uso: a cota
     * existe para proteger a conta do provedor, e um roteador não faz chamada
     * nenhuma a provedor.
     */
    public function modoDoAgente(): string
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.modo FROM agentes a WHERE a.id = :id'
        );
        $stmt->execute(['id' => $this->agenteId()]);

        return (string) ($stmt->fetchColumn() ?: 'ia');
    }

    /**
     * Teto de flood para o roteador.
     *
     * O roteador não custa token, então a cota diária — que é proteção de
     * gasto — não se aplica. Mas o endpoint continua público, e um laço
     * automatizado ainda consome banco e processo: sobra a proteção contra
     * enxurrada, e só ela.
     */
    private const LIMITE_ROTEADOR_MINUTO = 20;

    public function limitePorMinuto(): int
    {
        return max(1, (int) ($this->opcoes['limite_minuto'] ?? 6));
    }

    public function limitePorDia(): int
    {
        return max(1, (int) ($this->opcoes['limite_dia'] ?? 60));
    }

    /**
     * O visitante estourou a cota deste canal?
     *
     * Conta as perguntas já gravadas em vez de manter um contador próprio: o
     * dado já existe, sobrevive a reinício e não inventa mais uma tabela para
     * expurgar depois. O custo é uma contagem indexada por IP, barata perto de
     * uma chamada à LLM.
     *
     * @param bool $semCustoDeLlm roteador: pula a cota diária (que é proteção
     *                             de gasto) e mantém só a de flood
     * @return 'ok'|'minuto'|'dia'
     */
    public function estadoDoLimite(?string $ip, bool $semCustoDeLlm = false): string
    {
        if ($ip === null || $ip === '') {
            return 'ok';
        }

        $pdo = Database::connection();

        // Escopo do CANAL, nao do IP puro. Sem o filtro, a contagem somava as
        // conversas do playground do admin (canal_id nulo) e as dos demais
        // canais -- um dia de testes no painel deixaria o widget do site
        // recusando visitante de verdade, e um canal movimentado gastaria a
        // cota do outro. Achado testando: o primeiro turno real pelo endpoint
        // publico foi recusado por mensagens que nao eram dele.
        $sql = "SELECT COUNT(*) FROM mensagens m
                JOIN conversas c ON c.id = m.conversa_id
                WHERE c.ip = :ip AND c.canal_id = :canal AND m.autor_tipo = 'usuario'
                  AND m.criado_em >= :desde";

        $stmt = $pdo->prepare($sql);

        $canalId = (int) $this->canal['id'];

        $stmt->execute(['ip' => $ip, 'canal' => $canalId, 'desde' => date('Y-m-d H:i:s', time() - 60)]);

        $tetoMinuto = $semCustoDeLlm ? self::LIMITE_ROTEADOR_MINUTO : $this->limitePorMinuto();

        if ((int) $stmt->fetchColumn() >= $tetoMinuto) {
            return 'minuto';
        }

        // Sem custo de LLM não há cota diária: barrar alguém de navegar um
        // menu que não gasta nada seria recusar atendimento à toa.
        if ($semCustoDeLlm) {
            return 'ok';
        }

        $stmt->execute(['ip' => $ip, 'canal' => $canalId, 'desde' => date('Y-m-d H:i:s', time() - 86400)]);

        if ((int) $stmt->fetchColumn() >= $this->limitePorDia()) {
            return 'dia';
        }

        return 'ok';
    }

    /**
     * Texto que o visitante vê ao bater no limite.
     *
     * Nunca diz "limite de requisições" nem cita cota: para quem está do outro
     * lado isso é jargão de infraestrutura, e a regra do projeto é que erro
     * técnico não chega ao chat. Diz o que fazer.
     */
    public static function mensagemDeLimite(string $estado): string
    {
        return $estado === 'minuto'
            ? 'Vamos com calma — me dê um instante para acompanhar. Pode repetir sua pergunta em alguns segundos?'
            // Sem promessa de captar contato: esta frase só aparece quando NÃO
            // há setor cadastrado, ou seja, quando não existe caminho nenhum.
            // Prometer o que não se cumpre foi exatamente o beco anterior.
            : 'Conversamos bastante hoje e preciso dar uma pausa por aqui. Tente novamente amanhã.';
    }

    public function saudacao(): string
    {
        $texto = trim((string) ($this->opcoes['saudacao'] ?? ''));

        return $texto !== '' ? $texto : 'Olá! Como posso ajudar?';
    }

    public function titulo(): string
    {
        $texto = trim((string) ($this->opcoes['titulo'] ?? ''));

        return $texto !== '' ? $texto : (string) $this->canal['nome'];
    }

    public function cor(): string
    {
        $cor = trim((string) ($this->opcoes['cor'] ?? ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) === 1 ? $cor : '#2563eb';
    }
}
