<?php

declare(strict_types=1);

namespace SimpleAIman\Llm;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;

/**
 * Exercita um provedor de ponta a ponta e devolve o resultado estruturado.
 *
 * Uma classe só, consumida pelo CLI (`bin/testar-provedor.php`) e pelo botão
 * "testar" da tela de provedores. Se fossem dois códigos, um deles ficaria
 * desatualizado — e seria justamente o que alguém usa às 23h tentando
 * descobrir por que o bot parou.
 */
final class DiagnosticoProvedor
{
    public function __construct(private readonly ProviderFactory $fabrica)
    {
    }

    /**
     * @return list<array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string}>
     */
    public function executar(): array
    {
        return [
            $this->testarChat(),
            $this->testarVelocidade(),
            $this->testarFerramenta(),
            $this->testarStreaming(),
            $this->testarEmbeddings(),
        ];
    }

    /** @return array<string, mixed> */
    private function opcoes(): array
    {
        return ['max_tokens' => 2000, 'temperatura' => 0.2, 'reasoning_effort' => 'none'];
    }

    /** @param array<int, array<string, string>> $registro */
    private function ferramentaDeTeste(array &$registro): Tool
    {
        return Tool::make(
            'simular_mensalidade',
            'Calcula o valor da mensalidade de um curso. Use sempre que perguntarem preço.'
        )
            ->addProperty(new ToolProperty('curso', PropertyType::STRING, 'Nome do curso', true))
            ->addProperty(new ToolProperty('turno', PropertyType::STRING, 'manha ou noite', true))
            ->setCallable(function (string $curso, string $turno) use (&$registro): string {
                $registro[] = ['curso' => $curso, 'turno' => $turno];
                return json_encode(['curso' => $curso, 'turno' => $turno, 'valor' => 1250.00]);
            });
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function ok(string $item, string $detalhe): array
    {
        return ['item' => $item, 'ok' => true, 'detalhe' => $detalhe, 'sugestao' => '', 'publica' => ''];
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function falha(string $item, ErroAgente $e): array
    {
        return [
            'item' => $item,
            'ok' => false,
            'detalhe' => $e->paraLog(),
            'sugestao' => $e->sugestaoAdmin(),
            // Exposta para conferência: é o que o visitante veria no widget.
            // Vê-la lado a lado com o detalhe técnico é como se confirma que
            // nada de infraestrutura vazou para o chat.
            'publica' => $e->mensagemPublica(),
        ];
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function testarChat(): array
    {
        $item = 'Chat';

        try {
            $r = Agent::make()
                ->setAiProvider($this->fabrica->chat($this->opcoes()))
                ->chat(new UserMessage('Responda apenas: ok'))
                ->getMessage();

            $texto = trim((string) $r->getContent());

            // Modelo pensante com orçamento curto devolve 200 com conteúdo
            // VAZIO. Sem esta checagem o sintoma seria "o bot não respondeu",
            // sem erro nenhum no log.
            if ($texto === '') {
                throw new ErroAgente('resposta_vazia', 'Provedor respondeu 200 com conteúdo vazio.');
            }

            return $this->ok($item, "respondeu: \"{$texto}\"");
        } catch (ErroAgente $e) {
            return $this->falha($item, $e);
        } catch (Throwable $e) {
            return $this->falha($item, ErroAgente::deProvedor($e, 'chat'));
        }
    }

    /**
     * Velocidade real do modelo, em tokens por segundo.
     *
     * Existe porque a diferença entre modelos é BRUTAL e muda com o tempo:
     * medido em 2026-08-25, o `gemini-3.5-flash-lite` entregava 2 tokens por
     * segundo enquanto o `gemini-3.6-flash` entregava 103 — cinquenta vezes
     * mais rápido, no mesmo minuto e com a mesma chave. O modelo estava
     * degradado, não a nossa busca (que leva ~650 ms e não varia).
     *
     * Sem este número, "o chat está lento" vira caça a fantasma no código.
     * Com ele, a resposta aparece em dois cliques e a correção é trocar o
     * modelo do agente.
     *
     * @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string}
     */
    private function testarVelocidade(): array
    {
        $item = 'Velocidade';

        try {
            // Prompt propositalmente parecido com um turno real: contexto de
            // RAG mais uma pergunta. Medir com "oi" daria número otimista.
            $contexto = str_repeat('Trecho do edital com prazos, valores e regras da instituição. ', 40);

            $inicio = microtime(true);

            $resposta = Agent::make()
                ->setAiProvider($this->fabrica->chat($this->opcoes()))
                ->setInstructions($contexto)
                ->chat(new UserMessage('Em duas frases, o que é um vestibular?'))
                ->getMessage();

            $segundos = max(0.001, microtime(true) - $inicio);
            $uso = $resposta->getUsage();
            $saida = $uso !== null ? $uso->outputTokens + $uso->reasoningTokens : 0;

            if ($saida === 0) {
                throw new ErroAgente('resposta_vazia', 'O modelo não produziu tokens de saída.');
            }

            $porSegundo = $saida / $segundos;

            $detalhe = sprintf(
                '%.1f tokens/s · %.1fs para %d tokens',
                $porSegundo,
                $segundos,
                $saida,
            );

            // Abaixo de ~20 tok/s o atendimento fica visivelmente arrastado:
            // uma resposta comum de 200 tokens passaria de 10 segundos.
            if ($porSegundo < 20) {
                throw new ErroAgente(
                    'provedor_indisponivel',
                    $detalhe . ' — muito lento para atendimento.'
                );
            }

            return $this->ok($item, $detalhe);
        } catch (ErroAgente $e) {
            return $this->falha($item, $e);
        } catch (Throwable $e) {
            return $this->falha($item, ErroAgente::deProvedor($e, 'velocidade'));
        }
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function testarFerramenta(): array
    {
        $item = 'Ciclo de ferramenta';
        $registro = [];

        try {
            Agent::make()
                ->setAiProvider($this->fabrica->chat($this->opcoes()))
                ->addTool($this->ferramentaDeTeste($registro))
                ->chat(new UserMessage('Quanto custa o curso de Direito no turno da noite?'))
                ->getMessage();

            if ($registro === []) {
                throw new ErroAgente('ferramenta_falhou', 'O modelo não chamou a ferramenta.');
            }

            return $this->ok(
                $item,
                'chamada com ' . json_encode($registro[0], JSON_UNESCAPED_UNICODE) . ' e resposta montada sobre o resultado'
            );
        } catch (ErroAgente $e) {
            return $this->falha($item, $e);
        } catch (Throwable $e) {
            return $this->falha($item, ErroAgente::deProvedor($e, 'ferramenta'));
        }
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function testarStreaming(): array
    {
        $item = 'Streaming com ferramenta';
        $registro = [];

        try {
            $handler = Agent::make()
                ->setAiProvider($this->fabrica->chat($this->opcoes()))
                ->addTool($this->ferramentaDeTeste($registro))
                ->stream(new UserMessage('Quanto custa Medicina no turno da manhã?'));

            $pedacos = 0;

            foreach ($handler->events() as $evento) {
                if (is_string($evento)) {
                    $pedacos++;
                } elseif (is_object($evento) && property_exists($evento, 'content') && is_string($evento->content)) {
                    $pedacos++;
                }
            }

            if ($pedacos < 2) {
                throw new ErroAgente('provedor_indisponivel', "Stream veio em {$pedacos} pedaço(s) — não é streaming real.");
            }

            return $this->ok(
                $item,
                "{$pedacos} pedaços" . ($registro !== [] ? ', com ferramenta executada no meio' : '')
            );
        } catch (ErroAgente $e) {
            return $this->falha($item, $e);
        } catch (Throwable $e) {
            return $this->falha($item, ErroAgente::deProvedor($e, 'streaming'));
        }
    }

    /** @return array{item: string, ok: bool, detalhe: string, sugestao: string, publica: string} */
    private function testarEmbeddings(): array
    {
        $item = 'Embeddings';

        try {
            $doc = $this->fabrica->embeddings(ProviderFactory::TAREFA_INDEXAR)->embedText('matrícula do curso de Direito');
            $qry = $this->fabrica->embeddings(ProviderFactory::TAREFA_CONSULTAR)->embedText('matrícula do curso de Direito');

            $esperado = $this->fabrica->dimensoes();

            if ($esperado > 0 && count($doc) !== $esperado) {
                throw new ErroAgente(
                    'configuracao',
                    'Provedor devolveu ' . count($doc) . " dimensões, mas `provedores.dimensoes` diz {$esperado}."
                );
            }

            // Vetores idênticos significam task_type ignorado — e usar o mesmo
            // espaço ao indexar e ao consultar custa recall de graça.
            $iguais = true;
            for ($i = 0, $n = min(50, count($doc)); $i < $n; $i++) {
                if (abs($doc[$i] - $qry[$i]) > 1e-9) {
                    $iguais = false;
                    break;
                }
            }

            if ($iguais) {
                throw new ErroAgente(
                    'configuracao',
                    'O provedor aceitou task_type mas devolveu vetores idênticos ao indexar e ao consultar.'
                );
            }

            return $this->ok($item, count($doc) . ' dimensões · task_type aplicado');
        } catch (ErroAgente $e) {
            return $this->falha($item, $e);
        } catch (Throwable $e) {
            return $this->falha($item, ErroAgente::deProvedor($e, 'embeddings'));
        }
    }
}
