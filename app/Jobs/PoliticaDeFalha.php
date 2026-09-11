<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Database;
use SimpleAIman\Llm\ErroAgente;

/**
 * O que fazer com um job que falhou: pausar e tentar de novo, ou desistir.
 *
 * Até 11/09/2026 só a cota pausava. Todo o resto — inclusive o 503 de
 * sobrecarga que o plano gratuito do Gemini devolve com frequência ("This model
 * is currently experiencing high demand. Spikes in demand are usually
 * temporary") — ia direto para `erro`. Na indexação, isso queria dizer que um
 * único 503 no meio de um arquivo grande derrubava o documento inteiro, que só
 * precisava esperar.
 *
 * `decidir()` é pura, sem banco, para que a política possa ser testada sozinha.
 */
final class PoliticaDeFalha
{
    /** Falhas passageiras SEGUIDAS antes de desistir de uma indexação. */
    public const MAX_PASSAGEIRAS = 6;

    /** Erros que costumam passar sozinhos. */
    private const PASSAGEIROS = ['provedor_indisponivel', 'provedor_timeout'];

    /**
     * Conta a falha, quando couber, e decide.
     *
     * @param array<string, mixed> $job linha de `jobs`, como Queue::proximo() devolve
     *
     * @return array{acao: 'pausar'|'falhar', segundos: int, motivo: string}
     */
    public static function aplicar(array $job, ErroAgente $erro): array
    {
        $tipo = (string) ($job['tipo'] ?? '');

        $seguidas = $tipo === 'ingestao' && in_array($erro->codigo, self::PASSAGEIROS, true)
            ? self::contarPassageira((int) $job['id'])
            : 0;

        return self::decidir($tipo, $erro->codigo, $seguidas);
    }

    /**
     * A política, sem efeito colateral.
     *
     * - Cota: pausa sempre, sem teto. É o normal numa indexação grande no plano
     *   gratuito, e a janela de cota sempre vira.
     * - 503 e timeout NA INDEXAÇÃO: pausa com espera crescente — 60 s, 120 s,
     *   180 s... — até MAX_PASSAGEIRAS falhas seguidas. O teto existe porque
     *   "passageiro" que não passa é modelo fora do ar, e o admin precisa saber.
     * - No WhatsApp, não: a pessoa já recebeu "tente de novo" pelo `catch` de
     *   `Worker::responderWhatsapp()`, e uma nova passada bateria no dedup por
     *   `wamid` e seria descartada. Desistir é o certo — ver ARQUITETURA §10.3.
     * - O resto (autenticação, configuração) não passa sozinho: desiste.
     *
     * @param int $passageirasSeguidas incluindo a falha atual; 0 quando não se aplica
     *
     * @return array{acao: 'pausar'|'falhar', segundos: int, motivo: string}
     */
    public static function decidir(string $tipo, string $codigo, int $passageirasSeguidas): array
    {
        if ($codigo === 'provedor_cota') {
            return [
                'acao' => 'pausar',
                'segundos' => 90,
                'motivo' => 'Limite de requisições do provedor. Retomando automaticamente.',
            ];
        }

        if (
            $tipo === 'ingestao'
            && in_array($codigo, self::PASSAGEIROS, true)
            && $passageirasSeguidas > 0
            && $passageirasSeguidas < self::MAX_PASSAGEIRAS
        ) {
            $segundos = min(600, 60 * $passageirasSeguidas);

            return [
                'acao' => 'pausar',
                'segundos' => $segundos,
                'motivo' => sprintf(
                    'Fornecedor instável (%s). Nova tentativa em %d s — falha %d de %d seguidas.',
                    $codigo,
                    $segundos,
                    $passageirasSeguidas,
                    self::MAX_PASSAGEIRAS
                ),
            ];
        }

        return ['acao' => 'falhar', 'segundos' => 0, 'motivo' => ''];
    }

    /**
     * Soma mais uma falha passageira seguida no progresso do job.
     *
     * Não usa `jobs.tentativas`: aquele conta TODA passada, inclusive as pausas
     * de cota. Numa indexação grande no plano gratuito a cota pausa várias
     * vezes, e um único 503 depois disso encontraria o contador estourado.
     *
     * "Seguidas" sai de graça: a indexação regrava o progresso inteiro a cada
     * grupo que dá certo (`Worker::ingerir()`), sem esta chave — então o
     * contador zera sozinho assim que um grupo passa. Sem isso, seis 503
     * espalhados por uma indexação de horas derrubariam um arquivo que avançava.
     *
     * Lê o progresso do BANCO, não o do job em memória. Este pode estar
     * defasado — a indexação atualiza `feitos` ao longo da execução —, e
     * regravá-lo apagaria o avanço registrado.
     */
    private static function contarPassageira(int $jobId): int
    {
        $stmt = Database::connection()->prepare('SELECT progresso FROM jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);

        $valor = $stmt->fetchColumn();
        $progresso = json_para_array($valor === false || $valor === null ? null : (string) $valor);
        $progresso['passageiras'] = (int) ($progresso['passageiras'] ?? 0) + 1;

        Queue::progresso($jobId, $progresso);

        return $progresso['passageiras'];
    }
}
