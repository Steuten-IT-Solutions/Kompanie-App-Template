<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

/** Eine API-Anfrage, unabhängig davon, ob sie aus WordPress oder dem eigenen Front-Controller kommt. */
final class Request
{
    /**
     * @param array<string, string> $params Pfad-Parameter, z. B. ['id' => '5']
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body JSON-Inhalt
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public array $params = [],
        /** Kennung des Anfragenden (z. B. die IP-Adresse), nur für Begrenzungen; leer, wenn unbekannt */
        public readonly string $client = '',
        /** Header X-Kompanie-Preview der Anfrage (Vorschau „App als Rolle ansehen“, Issue #63); sonst gilt ?vorschau=… */
        public readonly ?string $preview = null,
        /** Header X-Kompanie-Lang: Sprache der App (Plan „Mehrsprachigkeit“); null ohne Angabe */
        public readonly ?string $locale = null,
        /**
         * Zugriffstoken für KI-Assistenten aus dem Header Authorization: Bearer … (Domain\AccessTokenService); null ohne.
         * Die Adapter lesen es mit bearerFrom().
         */
        public readonly ?string $bearer = null,
        /**
         * Weitere Header, die der Kern braucht (Namen klein geschrieben, siehe HEADERS), z. B. für den MCP-Endpunkt
         * (Mcp\McpServer); fehlende fehlen.
         *
         * @var array<string, string>
         */
        public readonly array $headers = [],
    ) {
    }

    /** Header, die die Adapter in $headers weiterreichen */
    public const HEADERS = ['origin', 'mcp-protocol-version', 'mcp-method', 'mcp-name'];

    /**
     * Header aus HEADERS über eine Funktion des Adapters lesen (Name → Wert oder null).
     *
     * @param callable(string): mixed $read
     * @return array<string, string>
     */
    public static function headersFrom(callable $read): array
    {
        $out = [];
        foreach (self::HEADERS as $name) {
            $value = $read($name);
            if (is_string($value) && $value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** Header aus HEADERS; null, wenn er fehlt */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Token aus dem Wert des Headers Authorization („Bearer …“); null bei anderem oder fehlendem Header. */
    public static function bearerFrom(?string $authorization): ?string
    {
        if ($authorization === null || preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public function id(): int
    {
        return (int) ($this->params['id'] ?? 0);
    }

    /** Wert aus dem JSON-Inhalt; null, wenn nicht vorhanden. */
    public function input(string $key): mixed
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) && $this->body[$key] !== null;
    }

    public function queryFlag(string $key): bool
    {
        $v = $this->query[$key] ?? null;
        return $v !== null && $v !== '' && $v !== '0' && $v !== 'false';
    }
}
