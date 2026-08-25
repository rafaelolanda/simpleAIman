<?php

declare(strict_types=1);

namespace SimpleAIman\Rag;

use Database;
use PDO;

/**
 * Procura a pergunta do visitante entre as FAQ curadas.
 *
 * Casamento acima do limiar de confiança **curto-circuita o RAG**: a resposta
 * escrita pelo cliente é devolvida palavra por palavra, sem passar pelo
 * modelo. Três ganhos, e o terceiro é o que mais importa:
 *
 *  1. Barato — nenhuma geração, só um embedding da pergunta.
 *  2. Rápido — corta ~1 segundo do turno.
 *  3. **Não erra.** Cada geração é um sorteio; texto fixo não escorrega.
 *     Foi assim que apareceu "encarecesse o seu atendimento" numa frase que
 *     o modelo recompunha a cada recusa. Para as perguntas mais comuns, a
 *     resposta certa é a que um humano escreveu.
 *
 * Abaixo do limiar de confiança, a FAQ não some: entra como mais um candidato
 * no contexto do RAG, e o modelo decide se usa.
 */
final class FaqBusca
{
    /**
     * @return array{
     *   faq_id: int, pergunta: string, resposta: string, score: float,
     *   setor_id: int|null, direto: bool
     * }|null
     */
    public function melhor(string $pergunta, array $vetorConsulta, float $limiarDireto = 0.85): ?array
    {
        $pergunta = trim($pergunta);

        if ($pergunta === '' || $vetorConsulta === []) {
            return null;
        }

        $dimensao = count($vetorConsulta);

        $normaConsulta = 0.0;

        foreach ($vetorConsulta as $x) {
            $normaConsulta += $x * $x;
        }

        $normaConsulta = sqrt($normaConsulta) ?: 1.0;

        $q = [];

        foreach ($vetorConsulta as $i => $x) {
            $q[$i + 1] = $x;
        }

        $stmt = Database::connection()->prepare(
            'SELECT f.id, f.pergunta, f.resposta, f.setor_id, e.vetor, e.norma
             FROM faq f JOIN faq_embeddings e ON e.faq_id = f.id
             WHERE f.ativo = 1 AND e.dimensoes = :dim'
        );
        $stmt->bindValue('dim', $dimensao, PDO::PARAM_INT);
        $stmt->execute();

        $melhor = null;

        while (($linha = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $v = unpack('f*', (string) $linha['vetor']);

            if ($v === false) {
                continue;
            }

            $produto = 0.0;

            for ($i = 1; $i <= $dimensao; $i++) {
                $produto += $q[$i] * $v[$i];
            }

            $score = $produto / ($normaConsulta * ((float) $linha['norma'] ?: 1.0));

            if ($melhor === null || $score > $melhor['score']) {
                $melhor = [
                    'faq_id' => (int) $linha['id'],
                    'pergunta' => (string) $linha['pergunta'],
                    'resposta' => (string) $linha['resposta'],
                    'setor_id' => $linha['setor_id'] !== null ? (int) $linha['setor_id'] : null,
                    'score' => $score,
                    'direto' => false,
                ];
            }
        }

        if ($melhor === null) {
            return null;
        }

        // O limiar do curto-circuito é ALTO de propósito e é absoluto, não
        // relativo: responder com texto curado a uma pergunta que não era
        // aquela é pior que gerar uma resposta mediana. Na dúvida, o modelo
        // decide com a FAQ apenas como contexto.
        $melhor['direto'] = $melhor['score'] >= $limiarDireto;

        return $melhor;
    }

    /** Quantas FAQ estão disponíveis para busca. */
    public static function total(): int
    {
        return (int) Database::connection()->query(
            'SELECT COUNT(*) FROM faq f JOIN faq_embeddings e ON e.faq_id = f.id WHERE f.ativo = 1'
        )->fetchColumn();
    }
}
