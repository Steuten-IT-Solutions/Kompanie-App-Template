<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\Auth\Capability;

/**
 * Eine Route der API.
 *
 * Pfad-Platzhalter: {id} nur Ziffern, {key} Buchstaben, Ziffern, _ und -.
 */
final class Route
{
    public const PUBLIC = 'public';
    public const LOGIN = 'login';

    /** @var \Closure(Request): (Response|array|null) */
    public readonly \Closure $handler;

    /**
     * @param string|Capability $access PUBLIC, LOGIN oder ein nötiges Recht
     * @param string|null $feature Bereich aus kompanie.json; ist er aus, gibt es die Route nicht
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string|Capability $access,
        callable $handler,
        public readonly ?string $feature = null,
        public readonly string $denyMessage = 'Dafür fehlt dir die Berechtigung.',
    ) {
        $this->handler = \Closure::fromCallable($handler);
    }

    /** Regulärer Ausdruck mit benannten Gruppen, z. B. #^/events/(?P<id>\d+)$# */
    public function regex(): string
    {
        // preg_quote maskiert { und }; die Platzhalter werden danach ersetzt.
        $re = preg_replace_callback(
            '/\\\\\{(id|key)\\\\\}/',
            static fn (array $m): string => $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<key>[A-Za-z0-9_-]+)',
            preg_quote($this->path, '#'),
        );
        return '#^' . $re . '$#';
    }

    /** @return array<string, string>|null Pfad-Parameter, wenn die Route passt */
    public function match(string $method, string $path): ?array
    {
        if (strtoupper($method) !== $this->method || !preg_match($this->regex(), $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
