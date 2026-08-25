<?php

declare(strict_types=1);

namespace SimpleAIman\Tools;

use RuntimeException;

/**
 * Decide se uma URL pode ser chamada.
 *
 * Uma ferramenta HTTP configurável é, na prática, um cliente HTTP colocado ao
 * alcance de quem conversa com o bot. Sem esta guarda, o produto vira uma
 * máquina de SSRF: bastaria alguém convencer o agente a chamar
 * `http://169.254.169.254/` para ler credenciais da instância, ou varrer a
 * rede interna do cliente pedindo `http://192.168.0.1/`.
 *
 * Três camadas, e a ordem importa:
 *
 *  1. **Allowlist de hosts** vinda do `.env`, nunca de campo editável no
 *     admin — quem edita o admin não deveria poder ampliar o alcance de rede
 *     do servidor.
 *  2. **Só HTTPS.** Token de API em HTTP é token vazado.
 *  3. **Bloqueio de IP interno APÓS resolver o DNS.** Checar só o nome do
 *     host não adianta: `evil.com` pode apontar para `127.0.0.1`. É o ataque
 *     de DNS rebinding, e ele passa por qualquer validação textual.
 */
final class UrlGuard
{
    /**
     * Faixas privadas, de loopback, link-local e reservadas.
     *
     * A `169.254.169.254` é a mais perigosa da lista: é o endpoint de
     * metadados de AWS, GCP e Azure, e responde credenciais em texto puro
     * para quem conseguir fazer o servidor pedir.
     */
    private const FAIXAS_BLOQUEADAS = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['0.0.0.0', 8],
        ['100.64.0.0', 10],
        ['192.0.0.0', 24],
        ['198.18.0.0', 15],
    ];

    /** @param list<string> $hostsPermitidos */
    public function __construct(private readonly array $hostsPermitidos)
    {
    }

    public static function doAmbiente(): self
    {
        return new self(TOOLS_HOSTS_PERMITIDOS);
    }

    /**
     * @throws RuntimeException com motivo legível para o log do admin
     */
    public function verificar(string $url): void
    {
        $partes = parse_url($url);

        if (!is_array($partes) || empty($partes['host'])) {
            throw new RuntimeException("URL inválida: {$url}");
        }

        $esquema = strtolower((string) ($partes['scheme'] ?? ''));

        if ($esquema !== 'https') {
            throw new RuntimeException(
                "Só HTTPS é permitido (recebido: {$esquema}). Token de API em HTTP é token vazado."
            );
        }

        $host = strtolower((string) $partes['host']);

        if ($this->hostsPermitidos === []) {
            throw new RuntimeException(
                'Nenhum host permitido configurado. Preencha TOOLS_HOSTS_PERMITIDOS no .env — '
                . 'sem allowlist, toda chamada externa fica bloqueada por padrão.'
            );
        }

        if (!$this->hostPermitido($host)) {
            throw new RuntimeException(
                "O host {$host} não está em TOOLS_HOSTS_PERMITIDOS no .env."
            );
        }

        // Resolve DEPOIS de aprovar o nome. Um host permitido que aponte para
        // endereço interno ainda é ataque — e é o caso do DNS rebinding.
        foreach ($this->resolver($host) as $ip) {
            if ($this->ehInterno($ip)) {
                throw new RuntimeException(
                    "O host {$host} resolve para {$ip}, um endereço interno. Chamada bloqueada."
                );
            }
        }
    }

    private function hostPermitido(string $host): bool
    {
        foreach ($this->hostsPermitidos as $permitido) {
            $permitido = strtolower(trim($permitido));

            if ($permitido === '') {
                continue;
            }

            if ($host === $permitido) {
                return true;
            }

            // Subdomínio: "api.crm.com" casa com ".crm.com", mas
            // "evilcrm.com" NÃO casa — o ponto inicial é o que impede o
            // sufixo malicioso de passar.
            if (str_starts_with($permitido, '.') && str_ends_with($host, $permitido)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function resolver(string $host): array
    {
        // Host que já é IP não precisa de DNS, mas precisa da checagem.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $tipo => $constante) {
            $registros = @dns_get_record($host, $constante) ?: [];

            foreach ($registros as $r) {
                $ip = $r['ip'] ?? $r['ipv6'] ?? null;

                if ($ip !== null) {
                    $ips[] = (string) $ip;
                }
            }
        }

        if ($ips === []) {
            throw new RuntimeException("Não foi possível resolver o host {$host}.");
        }

        return $ips;
    }

    private function ehInterno(string $ip): bool
    {
        // IPv6: barra loopback, link-local e a faixa privada fc00::/7, além
        // do IPv4 mapeado (::ffff:127.0.0.1), que é rota conhecida de bypass.
        if (str_contains($ip, ':')) {
            $normalizado = strtolower($ip);

            if ($normalizado === '::1' || str_starts_with($normalizado, 'fe80:')
                || str_starts_with($normalizado, 'fc') || str_starts_with($normalizado, 'fd')) {
                return true;
            }

            if (str_starts_with($normalizado, '::ffff:')) {
                return $this->ehInterno(substr($normalizado, 7));
            }

            return false;
        }

        $numero = ip2long($ip);

        if ($numero === false) {
            return true;   // não sei o que é: bloqueia
        }

        foreach (self::FAIXAS_BLOQUEADAS as [$base, $bits]) {
            $mascara = -1 << (32 - $bits);

            if ((ip2long($base) & $mascara) === ($numero & $mascara)) {
                return true;
            }
        }

        return false;
    }
}
