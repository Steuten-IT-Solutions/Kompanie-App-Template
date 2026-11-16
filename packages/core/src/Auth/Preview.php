<?php

declare(strict_types=1);

namespace KompanieApp\Core\Auth;

use KompanieApp\Core\Domain\RoleService;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\AuthContext;
use KompanieApp\Core\Port\UserDirectory;

/**
 * Vorschau „App als Rolle ansehen“ (Issue #63): Ein Admin sieht die App mit den Rechten einer Rolle, mit den Rechten
 * einer bestimmten Person oder wie ohne Anmeldung, ohne ein anderes Konto.
 *
 * Ohne Zustand auf dem Server: Die App schickt während der Vorschau bei jeder API-Anfrage den Header
 * X-Kompanie-Preview (Wert: Schlüssel der Rolle, @person:<ID> für eine Person oder @public für „ohne Anmeldung“); Bilder, Dokumente und Abzeichen,
 * die der Browser ohne eigene Header lädt, tragen ihn als Parameter ?vorschau=… in der Adresse (Environment-Adressen
 * über PreviewEnvironment). Die Adapter reichen den Wert je Anfrage weiter (begin()); sonst steckt die Vorschau nur
 * hier, damit beide Varianten sich gleich verhalten.
 *
 * - Die Vorschau gilt nur, wenn die tatsächlich angemeldete Person Admin ist und es die Rolle bzw. die Person gibt (nicht
 *   Admin selbst, keine andere Person, die Admin ist). Sonst bleibt alles wie ohne Vorschau (keine zusätzlichen Rechte);
 *   /config meldet dann preview: null, und die App beendet die Vorschau.
 * - Als Rolle: dieselbe Person (ID, Name, Benutzername – persönliche Bereiche zeigen die eigenen Daten des Admins), aber
 *   mit dieser Grundrolle, ohne Zusatzrollen, und mit genau deren Rechten.
 * - Als Person: ebenso dieselbe Person (persönliche Bereiche wie Mails, Punkte oder Konto zeigen weiter die eigenen Daten
 *   des Admins, nie die der Person), aber mit der Grundrolle der Person und genau ihren Rechten, wie das
 *   Benutzerverzeichnis sie ausrechnet: Grundrolle, Zusatzrollen und Ausnahmen zusammen. Nie mehr als die Rechte des
 *   Admins selbst.
 * - @public: wie ohne Anmeldung (Gast ohne Konto).
 * - Nur ansehen: Trägt eine Anfrage den Header oder Parameter (auch ungültig oder von Nicht-Admins), lehnt die API jede
 *   ändernde Anfrage ab (403 preview_readonly), außer dem Abmelden (Api::PREVIEW_OPEN).
 */
final class Preview implements AuthContext
{
    public const HEADER = 'X-Kompanie-Preview';
    /** Parameter in Adressen von Bildern, Dokumenten und Abzeichen */
    public const QUERY = 'vorschau';
    /** „App ohne Anmeldung ansehen“ */
    public const PUBLIC = '@public';
    /** „App als Person ansehen“: Präfix vor der ID des Kontos */
    public const PERSON = '@person:';
    public const MAX_LENGTH = 32;

    /** Angefragter Wert (Header oder Parameter); null = keine Vorschau angefragt. */
    private ?string $requested = null;

    /** @var array{0: string, 1: User}|null zuletzt ersetzter Benutzer (Schlüssel aus Konto, Rolle) */
    private ?array $substitute = null;

    /** @var array{0: int, 1: User|null}|null zuletzt nachgeschlagene Person der Vorschau (ID, Konto) */
    private ?array $person = null;

    /**
     * @param \Closure(): RoleService $roles
     * @param (\Closure(): UserDirectory)|null $users Konten für „als Person“; ohne gibt es nur die Vorschau als Rolle
     */
    public function __construct(private readonly AuthContext $real, private readonly \Closure $roles, private readonly ?\Closure $users = null)
    {
    }

    /** Wert der Vorschau für eine Person. */
    public static function forPerson(int $id): string
    {
        return self::PERSON . $id;
    }

    /** Wert aus Header bzw. Parameter der Anfrage (der Header zählt zuerst); leer = keine Vorschau. */
    public static function valueFrom(mixed $header, mixed $query = null): ?string
    {
        foreach ([$header, $query] as $v) {
            if (is_string($v) && trim($v) !== '') {
                return mb_substr(trim($v), 0, self::MAX_LENGTH);
            }
        }
        return null;
    }

    /** Vorschau für die laufende Anfrage setzen (null = keine); gibt den bisherigen Wert zurück. */
    public function begin(?string $value): ?string
    {
        $previous = $this->requested;
        $this->requested = self::valueFrom($value);
        $this->person = null;
        return $previous;
    }

    /** Fragt die laufende Anfrage eine Vorschau an (gültig oder nicht)? Dann ist sie nur lesend. */
    public function requested(): bool
    {
        return $this->requested !== null;
    }

    /** Die tatsächlich angemeldete Person, ohne Vorschau. */
    public function realUser(): ?User
    {
        return $this->real->currentUser();
    }

    /** Wirksame Vorschau: Schlüssel der Rolle, PUBLIC oder PERSON mit ID; null ohne (gültige) Vorschau. */
    public function role(): ?string
    {
        $value = $this->requested;
        if ($value === null) {
            return null;
        }
        $real = $this->real->currentUser();
        if ($real === null || !$real->isAdmin()) {
            return null;
        }
        if ($value === self::PUBLIC) {
            return $value;
        }
        if (str_starts_with($value, self::PERSON)) {
            return $this->person($real) !== null ? $value : null;
        }
        return $value !== Roles::ADMIN && ($this->roles)()->exists($value) ? $value : null;
    }

    /**
     * Konto der Person, deren Ansicht die laufende Anfrage zeigt; null ohne gültige Vorschau als Person (keine Zahl,
     * unbekannt, Admin oder der Admin selbst).
     */
    private function person(User $real): ?User
    {
        $value = (string) $this->requested;
        $rest = substr($value, strlen(self::PERSON));
        if ($this->users === null || !str_starts_with($value, self::PERSON) || preg_match('/^[1-9]\d{0,9}$/', $rest) !== 1) {
            return null;
        }
        $id = (int) $rest;
        if ($this->person === null || $this->person[0] !== $id) {
            $target = ($this->users)()->find($id);
            $this->person = [$id, $target !== null && $id !== $real->id && !$target->isAdmin() ? $target : null];
        }
        return $this->person[1];
    }

    public function active(): bool
    {
        return $this->role() !== null;
    }

    /** Wer die Anfrage stellt – in der Vorschau die Person mit der gewählten Rolle bzw. niemand (@public). */
    public function currentUser(): ?User
    {
        $real = $this->real->currentUser();
        $role = $this->requested === null ? null : $this->role();
        if ($role === null || $real === null) {
            return $real;
        }
        if ($role === self::PUBLIC) {
            return null;
        }
        $key = $real->id . ':' . $role;
        if ($this->substitute === null || $this->substitute[0] !== $key) {
            $person = str_starts_with($role, self::PERSON) ? $this->person($real) : null;
            if ($person !== null) {
                // Rechte der Person (Grundrolle, Zusatzrollen, Ausnahmen), aber nie mehr als die des Admins selbst.
                $caps = array_values(array_filter($person->capabilities, static fn (Capability $c): bool => $real->can($c)));
                $this->substitute = [$key, new User($real->id, $real->name, $real->login, $person->role, $caps, $real->firstName, $real->lastName, true)];
            } else {
                // Nur die Rechte der Rolle selbst, nicht capabilitiesFor (das nähme gespeicherte Zusatzrollen des Kontos dazu).
                $this->substitute = [$key, new User($real->id, $real->name, $real->login, $role, ($this->roles)()->capabilities($role), $real->firstName, $real->lastName, true)];
            }
        }
        return $this->substitute[1];
    }

    /**
     * Für /config: die wirksame Vorschau samt Bezeichnung (Banner der App), sonst null. Als Person zusätzlich deren ID
     * (person) und Rollen (roles: Grundrolle und Zusatzrollen, Bezeichnungen).
     *
     * @return array{role: string, label: string, person?: int, roles?: string[]}|null
     */
    public function info(): ?array
    {
        $role = $this->role();
        $real = $this->real->currentUser();
        if ($role === null || $real === null) {
            return null;
        }
        $person = str_starts_with($role, self::PERSON) ? $this->person($real) : null;
        if ($person !== null) {
            $roles = ($this->roles)();
            return [
                'role' => $role,
                'label' => $person->name,
                'person' => $person->id,
                'roles' => array_map($roles->label(...), [$person->role, ...$roles->extraRolesOf($person)]),
            ];
        }
        return ['role' => $role, 'label' => $role === self::PUBLIC ? 'ohne Anmeldung' : ($this->roles)()->label($role)];
    }

    /** Adresse einer Datei (Bild, Dokument, Abzeichen) mit der Vorschau als Parameter, damit sie mit deren Rechten kommt. */
    public function url(string $url): string
    {
        $role = $this->role();
        if ($role === null) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . self::QUERY . '=' . rawurlencode($role);
    }

    /** Cache-Control für Dateien: in der Vorschau nie speichern (die Antwort gilt nur für diese Ansicht). */
    public function cacheControl(string $normal): string
    {
        return $this->active() ? 'no-store' : $normal;
    }

    /** Fehler für ändernde Anfragen in der Vorschau. */
    public static function readOnlyError(): ApiError
    {
        return new ApiError(403, 'preview_readonly', 'In der Vorschau kannst du nichts ändern.');
    }
}
