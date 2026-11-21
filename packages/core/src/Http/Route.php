<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\I18n\I18n;

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
    /** Meldung bei fehlendem Recht; erst bei der Anfrage erzeugt, damit sie in deren Sprache erscheint */
    private readonly ?\Closure $deny;

    /**
     * @param string|Capability|Capability[] $access PUBLIC, LOGIN, ein nötiges Recht oder eines von mehreren
     * @param string|null $feature Bereich (Domain\Features); ist er aus, antwortet die Route mit 403 feature_disabled
     * @param (callable(): string)|null $denyMessage Meldung bei fehlendem Recht, z. B. fn () => I18n::t('…')
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string|Capability|array $access,
        callable $handler,
        public readonly ?string $feature = null,
        ?callable $denyMessage = null,
    ) {
        $this->handler = \Closure::fromCallable($handler);
        $this->deny = $denyMessage !== null ? \Closure::fromCallable($denyMessage) : null;
    }

    /** Meldung, wenn das Recht für die Route fehlt (in der Sprache der Anfrage) */
    public function denyMessage(): string
    {
        return $this->deny !== null ? (string) ($this->deny)() : I18n::t('Dafür fehlt dir die Berechtigung.');
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
