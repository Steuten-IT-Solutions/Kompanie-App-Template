<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

use KompanieApp\Core\Domain\AccessTokenService;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Storage\AccessTokenRepository;

/**
 * Wer die Anfrage stellt, wenn sie ein Zugriffstoken für KI-Assistenten trägt (Header Authorization: Bearer …,
 * Domain\AccessTokenService): dann die Person des Zugangs, nie die Anmeldung per Cookie derselben Anfrage. Ohne Token
 * gilt der Adapter (Cookie bzw. Sitzung) unverändert.
 *
 * Wie die Vorschau ohne Zustand über Anfragen hinweg: Die Adapter reichen das Token je Anfrage in Http\Request weiter,
 * Http\Api setzt es mit begin() für genau diese Anfrage.
 *
 * @phpstan-import-type AccessTokenRow from AccessTokenRepository
 */
final class AccessTokenContext implements AuthContext
{
    /** Token der laufenden Anfrage; null = keines */
    private ?string $token = null;

    /** @var array{0: User, 1: AccessTokenRow}|false|null geprüfter Zugang; false = noch nicht geprüft, null = ungültig */
    private array|false|null $resolved = false;

    /** @param \Closure(): AccessTokenService $tokens */
    public function __construct(private readonly AuthContext $real, private readonly \Closure $tokens)
    {
    }

    /**
     * Token für die laufende Anfrage setzen (null = keines); gibt den bisherigen Stand zurück, damit Http\Api ihn danach
     * wiederherstellen kann.
     *
     * @return array{0: ?string, 1: array{0: User, 1: AccessTokenRow}|false|null}
     */
    public function begin(?string $token): array
    {
        $previous = [$this->token, $this->resolved];
        $this->token = $token !== null && $token !== '' ? $token : null;
        $this->resolved = false;
        return $previous;
    }

    /** @param array{0: ?string, 1: array{0: User, 1: AccessTokenRow}|false|null} $state Stand aus begin() */
    public function restore(array $state): void
    {
        [$this->token, $this->resolved] = $state;
    }

    /** Trägt die laufende Anfrage ein Token (gültig oder nicht)? */
    public function active(): bool
    {
        return $this->token !== null;
    }

    /** Umfang des Zugangs der laufenden Anfrage (read, write); null ohne gültiges Token. */
    public function scope(): ?string
    {
        return $this->resolve()[1]['scope'] ?? null;
    }

    /** ID des Zugangs der laufenden Anfrage; null ohne gültiges Token. */
    public function tokenId(): ?int
    {
        return $this->resolve()[1]['id'] ?? null;
    }

    /** Programm des Zugangs der laufenden Anfrage (z. B. Claude, fürs Änderungsprotokoll); null ohne gültiges Token. */
    public function client(): ?string
    {
        $client = $this->resolve()[1]['client'] ?? null;
        return $client === null ? null : ($client !== '' ? $client : 'KI-Assistent');
    }

    public function currentUser(): ?User
    {
        if ($this->token === null) {
            return $this->real->currentUser();
        }
        return $this->resolve()[0] ?? null;
    }

    /** @return array{0: User, 1: AccessTokenRow}|null */
    private function resolve(): ?array
    {
        if ($this->token === null) {
            return null;
        }
        if ($this->resolved === false) {
            $this->resolved = ($this->tokens)()->authenticate($this->token);
        }
        return $this->resolved;
    }
}
