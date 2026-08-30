<?php

declare(strict_types=1);

namespace SimpleAIman\Jobs;

use Database;
use SimpleAIman\Canais\Anexos;
use PDO;

/**
 * Anonimização e expurgo do conteúdo das conversas.
 *
 * Trabalha em dois estágios porque os dados de uma conversa não têm o mesmo
 * valor nem o mesmo risco:
 *
 *   anonimizar — mascara CPF, e-mail e telefone no texto e apaga o IP. O que
 *   sobra segue servindo à curadoria: "quais cursos vcs tem" não tem dado
 *   pessoal nenhum e é justamente o insumo da lista de perguntas sem resposta.
 *
 *   expurgar — apaga o conteúdo. Preserva conversa, fontes citadas e métricas,
 *   que não têm dado pessoal e têm valor longo: quantas conversas houve, quais
 *   documentos respondem de fato, custo e latência.
 *
 * Apagar em bloco jogaria fora as duas últimas junto com a primeira.
 */
final class Retencao
{
    /**
     * Executa os dois estágios conforme a configuração.
     *
     * @param callable(string): void|null $log
     * @return array{anonimizadas: int, expurgadas: int}
     */
    public function executar(?callable $log = null): array
    {
        $log ??= static fn (string $m): null => null;

        $config = Database::connection()->query('SELECT * FROM config WHERE id = 1')->fetch(PDO::FETCH_ASSOC) ?: [];

        $diasAnonimizar = (int) ($config['anonimizacao_conversas_dias'] ?? 0);
        $diasExpurgar = (int) ($config['retencao_conversas_dias'] ?? 0);

        // Zero é DESLIGADO nos dois. Nasce assim de propósito: apagar dado de
        // gente por padrão seria surpresa ruim numa instalação nova.
        $placar = ['anonimizadas' => 0, 'expurgadas' => 0];

        if ($diasAnonimizar > 0) {
            $placar['anonimizadas'] = $this->anonimizar($diasAnonimizar, $log);
        }

        if ($diasExpurgar > 0) {
            $placar['expurgadas'] = $this->expurgar($diasExpurgar, $log);
        }

        return $placar;
    }

    /** @param callable(string): void $log */
    private function anonimizar(int $diasPadrao, callable $log): int
    {
        $pdo = Database::connection();

        // Conversas ainda não anonimizadas cujo prazo venceu. O prazo pode ser
        // por canal: no WhatsApp a pessoa volta semanas depois e espera
        // continuidade, então apagar cedo faz o agente perder o contexto de
        // uma conversa que, para ela, é a mesma.
        $stmt = $pdo->prepare(
            "SELECT c.id
             FROM conversas c
             LEFT JOIN canais ca ON ca.id = c.canal_id
             WHERE c.anonimizada_em IS NULL
               AND julianday(:agora) - julianday(c.editado_em)
                   >= CASE WHEN COALESCE(ca.retencao_dias, 0) > 0
                           THEN ca.retencao_dias ELSE CAST(:padrao AS INTEGER) END
             LIMIT 500"
        );
        $stmt->execute(['agora' => now(), 'padrao' => $diasPadrao]);

        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($ids === []) {
            return 0;
        }

        $seleciona = $pdo->prepare('SELECT id, conteudo FROM mensagens WHERE conversa_id = :c');
        $atualiza = $pdo->prepare('UPDATE mensagens SET conteudo = :t WHERE id = :id');
        $arquivos = 0;

        foreach ($ids as $conversaId) {
            $seleciona->execute(['c' => $conversaId]);

            foreach ($seleciona->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $limpo = self::mascarar((string) $m['conteudo']);

                if ($limpo !== $m['conteudo']) {
                    $atualiza->execute(['t' => $limpo, 'id' => (int) $m['id']]);
                }
            }

            // Anexo não se mascara.
            //
            // O resto desta função troca CPF por asteriscos e segue com o texto
            // servindo para análise. Uma foto de documento não tem esse meio
            // termo: ela É o identificador. Anonimizar a conversa e deixar a
            // imagem no disco seria dizer que anonimizou sem ter anonimizado.
            $arquivos += Anexos::apagarDaConversa($conversaId);

            // O IP também identifica. Some junto.
            // Nome e contato de quem conversou identificam mais que o IP, e
            // nao ha como mascara-los pela metade: um nome com asteriscos no
            // meio nao serve a analise nenhuma e continua identificando.
            $pdo->prepare(
                'UPDATE conversas SET ip = NULL, contato_nome = NULL, contato_valor = NULL,
                        anonimizada_em = :agora WHERE id = :id'
            )->execute(['agora' => now(), 'id' => $conversaId]);
        }

        $log(count($ids) . ' conversa(s) anonimizada(s)'
            . ($arquivos > 0 ? ", {$arquivos} anexo(s) apagado(s)." : '.'));

        return count($ids);
    }

    /** @param callable(string): void $log */
    private function expurgar(int $diasPadrao, callable $log): int
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT c.id
             FROM conversas c
             LEFT JOIN canais ca ON ca.id = c.canal_id
             WHERE c.expurgada_em IS NULL
               AND julianday(:agora) - julianday(c.editado_em)
                   >= CASE WHEN COALESCE(ca.retencao_dias, 0) > 0
                           THEN ca.retencao_dias ELSE CAST(:padrao AS INTEGER) END
             LIMIT 500"
        );
        $stmt->execute(['agora' => now(), 'padrao' => $diasPadrao]);

        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($ids === []) {
            return 0;
        }

        $arquivos = 0;

        foreach ($ids as $conversaId) {
            // Conteúdo apagado, LINHA preservada: as fontes citadas
            // (mensagem_fontes) referenciam a mensagem, e são elas que dizem
            // quais documentos respondem de fato. Apagar a mensagem levaria
            // essa informação junto, por cascata.
            $pdo->prepare(
                "UPDATE mensagens SET conteudo = '[conteúdo expurgado]' WHERE conversa_id = :c"
            )->execute(['c' => $conversaId]);

            // O arquivo em disco nao e alcancado por UPDATE nenhum. Sem esta
            // linha o banco diria "expurgada" com a foto inteira no disco — e a
            // resposta a um titular seria falsa.
            $arquivos += Anexos::apagarDaConversa($conversaId);

            $pdo->prepare(
                'UPDATE conversas SET ip = NULL, contato_nome = NULL, contato_valor = NULL,
                        expurgada_em = :agora WHERE id = :id'
            )->execute(['agora' => now(), 'id' => $conversaId]);

            // Parâmetros de ferramenta também carregam o que a pessoa disse.
            $pdo->prepare(
                "UPDATE ferramenta_execucoes SET params = '{}', resposta = NULL WHERE conversa_id = :c"
            )->execute(['c' => $conversaId]);
        }

        $log(count($ids) . ' conversa(s) expurgada(s)'
            . ($arquivos > 0 ? ", {$arquivos} anexo(s) apagado(s)." : '.'));

        return count($ids);
    }

    /**
     * Mascara identificadores dentro de um texto livre.
     *
     * A ordem importa e o CPF vem primeiro, com validação de verdade: onze
     * dígitos podem ser CPF ou telefone, e adivinhar pelo formato erraria nos
     * dois sentidos. Conferir os dígitos verificadores resolve a ambiguidade
     * sem heurística.
     */
    public static function mascarar(string $texto): string
    {
        // CPF — só mascara o que realmente é CPF válido.
        $texto = preg_replace_callback(
            '/\b\d{3}[.\s]?\d{3}[.\s]?\d{3}[-.\s]?\d{2}\b/',
            static function (array $m): string {
                $valido = cpf_normalizar($m[0]);

                return $valido !== null ? cpf_mascarar($valido) : $m[0];
            },
            $texto
        ) ?? $texto;

        // E-mail: preserva a primeira letra e o domínio, o suficiente para o
        // histórico continuar legível sem identificar a pessoa.
        $texto = preg_replace_callback(
            '/\b([\w.+-])[\w.+-]*@([\w-]+\.[\w.-]+)\b/u',
            static fn (array $m): string => $m[1] . '***@' . $m[2],
            $texto
        ) ?? $texto;

        // Telefone brasileiro, com ou sem DDD e formatação. Sem  nas pontas:
        // ela não casa antes de "+" nem de "(", e sobrava lixo do tipo
        // "+(telefone removido)". As âncoras de dígito fazem o mesmo serviço
        // sem esse efeito.
        $texto = preg_replace(
            '/(?<!\d)(?:\+?55[\s.-]?)?\(?\d{2}\)?[\s.-]?9?\d{4}[\s.-]?\d{4}(?!\d)/',
            '(telefone removido)',
            $texto
        ) ?? $texto;

        return $texto;
    }

    /**
     * Apaga tudo que identifica uma pessoa, em todas as tabelas.
     *
     * Existe porque o direito de exclusão da LGPD não se cumpre caçando o
     * dado à mão em cinco tabelas — e porque quem pede exclusão costuma
     * pedir uma vez só, sem paciência para um "vamos verificar".
     *
     * @return array<string, int> quantos registros foram tocados por tabela
     * Com $simular = true nada e alterado: so conta o que seria atingido. A
     * exclusao e irreversivel e a busca usa LIKE, entao mostrar o alcance
     * antes evita que um telefone digitado errado leve conversa de terceiro
     * junto.
     *
     */
    public static function apagarPessoa(string $identificador, bool $simular = false): array
    {
        $identificador = trim($identificador);

        if ($identificador === '') {
            return [];
        }

        $pdo = Database::connection();
        $cpf = cpf_normalizar($identificador);
        $digitos = preg_replace('/\D+/', '', $identificador) ?? '';

        $resultado = ['leads' => 0, 'conversas' => 0, 'mensagens' => 0, 'chamados' => 0, 'anexos' => 0];

        // 1. Leads que casam por e-mail, telefone ou CPF. O telefone tambem
        //    e normalizado dos dois lados: o que a pessoa digitou no chat
        //    raramente tem a mesma formatacao do que o agente gravou.
        $telLimpo = "REPLACE(REPLACE(REPLACE(REPLACE(telefone,"
            . "'(',''),')',''),'-',''),' ','')";

        $stmt = $pdo->prepare(
            'SELECT id, conversa_id FROM leads
             WHERE (email <> \'\' AND LOWER(email) = LOWER(:email))
                OR (telefone <> \'\' AND :tel <> \'\' AND ' . $telLimpo . ' = :tel)
                OR (campos_extra IS NOT NULL AND :cpf <> \'\' AND campos_extra LIKE :cpfLike)'
        );
        $stmt->execute([
            'email' => $identificador,
            'tel' => strlen($digitos) >= 8 ? $digitos : '',
            'cpf' => (string) $cpf,
            'cpfLike' => '%' . (string) $cpf . '%',
        ]);

        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conversas = [];

        foreach ($leads as $l) {
            if ($l['conversa_id'] !== null) {
                $conversas[] = (int) $l['conversa_id'];
            }
        }

        // 2. Conversas onde o identificador aparece no texto ou é o
        //    `externo_id` — que no WhatsApp é o próprio telefone.
        // Telefone e CPF aparecem no texto FORMATADOS: "(54) 99123-4567",
        // "529.982.247-25". Um LIKE pelos digitos crus nao acha nenhum dos
        // dois. Como o SQLite nao tem regexp, normalizar os dois lados
        // resolve: tira a pontuacao do conteudo antes de comparar.
        $limpo = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(m.conteudo,"
            . "'(',''),')',''),'-',''),' ',''),'.',''),'/','')";

        // O WHERE e montado em PHP, com placeholder so para valor. Passar o
        // liga-desliga como parametro custou um bug: PDO manda inteiro como
        // TEXTO e no SQLite '1' = 1 e falso, entao a condicao nunca valia.
        $condicoes = [];
        $valores = [];

        if ($digitos !== '') {
            // Vale como externo_id: no WhatsApp ele e o proprio telefone.
            $condicoes[] = 'c.externo_id = :externo';
            $valores['externo'] = $digitos;
        }

        // O contato anotado na conversa tambem identifica, e nao aparece em
        // mensagem nenhuma: no WhatsApp ele vem do perfil, e no widget foi
        // digitado numa frase que a anonimizacao ja pode ter mascarado. Sem
        // esta condicao, o titular que pede exclusao pelo proprio telefone nao
        // acharia a conversa onde ele e justamente o contato.
        $condicoes[] = "COALESCE(c.contato_valor, '') <> '' AND (
            LOWER(c.contato_valor) = LOWER(:contatoExato)
            OR REPLACE(REPLACE(REPLACE(REPLACE(c.contato_valor,
               '(',''),')',''),'-',''),' ','') = :contatoDigitos
        )";
        $valores['contatoExato'] = $identificador;
        $valores['contatoDigitos'] = $digitos !== '' ? $digitos : '__sem_digitos__';

        if (strlen($digitos) >= 8) {
            // Abaixo de 8 digitos a busca deixa de identificar alguem e passa
            // a varrer numero solto no meio de frase.
            $condicoes[] = $limpo . ' LIKE :digitosLike';
            $valores['digitosLike'] = '%' . $digitos . '%';
        } else {
            $condicoes[] = 'm.conteudo LIKE :busca';
            $valores['busca'] = '%' . $identificador . '%';
        }

        $stmt = $pdo->prepare(
            'SELECT DISTINCT c.id FROM conversas c
             LEFT JOIN mensagens m ON m.conversa_id = c.id
             WHERE ' . implode(' OR ', $condicoes)
        );
        $stmt->execute($valores);

        $conversas = array_unique(array_merge($conversas, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));

        foreach ($leads as $l) {
            if (!$simular) {
                $pdo->prepare('DELETE FROM leads WHERE id = :id')->execute(['id' => (int) $l['id']]);
            }

            $resultado['leads']++;
        }

        foreach ($conversas as $conversaId) {
            $n = $pdo->prepare('SELECT COUNT(*) FROM mensagens WHERE conversa_id = :c');
            $n->execute(['c' => $conversaId]);
            $resultado['mensagens'] += (int) $n->fetchColumn();

            $c = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE conversa_id = :c');
            $c->execute(['c' => $conversaId]);
            $resultado['chamados'] += (int) $c->fetchColumn();

            // Contado na simulação e somado de novo na execução: aqui é o
            // alcance previsto, lá é o que sumiu de fato do disco. Numa
            // simulação o laço para antes de apagar, então não dobra.
            if ($simular) {
                $a = $pdo->prepare(
                    'SELECT COUNT(*) FROM mensagem_anexos a JOIN mensagens m ON m.id = a.mensagem_id
                     WHERE m.conversa_id = :c AND a.removido_em IS NULL'
                );
                $a->execute(['c' => $conversaId]);
                $resultado['anexos'] += (int) $a->fetchColumn();
            }

            $resultado['conversas']++;

            if ($simular) {
                continue;
            }

            $pdo->prepare("UPDATE mensagens SET conteudo = '[apagado a pedido]' WHERE conversa_id = :c")
                ->execute(['c' => $conversaId]);
            $pdo->prepare("UPDATE ferramenta_execucoes SET params = '{}', resposta = NULL WHERE conversa_id = :c")
                ->execute(['c' => $conversaId]);

            // `externo_id` some junto: no WhatsApp ele É o telefone, e mantê-lo
            // conservaria justamente o identificador que se pediu para apagar.
            $pdo->prepare(
                'UPDATE conversas SET ip = NULL, externo_id = NULL,
                        contato_nome = NULL, contato_valor = NULL,
                        expurgada_em = :agora, anonimizada_em = :agora WHERE id = :id'
            )->execute(['agora' => now(), 'id' => $conversaId]);

            $pdo->prepare("UPDATE chamados SET contato = '[apagado]', descricao = NULL WHERE conversa_id = :c")
                ->execute(['c' => $conversaId]);

            // Pedido do titular e o caso em que errar custa mais caro: ele
            // perguntou, foi respondido que apagamos, e o arquivo continuaria la.
            $resultado['anexos'] += Anexos::apagarDaConversa($conversaId);
        }

        return $resultado;
    }
}
