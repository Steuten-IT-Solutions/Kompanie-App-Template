<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Port\UserDirectory;

/**
 * Rollen der App: Bezeichnung, Rechte und Punktefaktor – die einzige Stelle, die das sagt (Issue #28).
 *
 * Der Admin legt Rollen in der App an (Einstellungen → Rollen). Eine neue Installation startet mit
 * den Vorlagen aus Auth\Roles und den Bezeichnungen aus kompanie.json; danach gilt allein die App.
 * Gespeichert als eine Einstellung: Die Einstellungen lädt jede Anfrage ohnehin auf einmal, die Rechte
 * eines Benutzers kosten so keine eigene Abfrage.
 *
 * Eine Person hat genau eine Rolle. Der Punktefaktor (z. B. 0,5 für junge Mitglieder) gilt beim
 * Buchen und wird mit dem Eintrag gespeichert; eine spätere Änderung ändert alte Einträge nicht.
 */
final class RoleService
{
    public const SETTING = 'roles';
    public const LABEL_MAX = 30;
    public const MAX_ROLES = 30;
    public const FACTOR_MAX = 10.0;

    /** @var array<string, array{key: string, label: string, capabilities: string[], points_factor: float}>|null */
    private ?array $roles = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly KompanieConfig $config,
        private readonly UserDirectory $users,
    ) {
    }

    /**
     * Alle Rollen in der Reihenfolge der Anzeige (guest zuerst, admin zuletzt).
     *
     * @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float}>
     */
    public function all(): array
    {
        if ($this->roles === null) {
            $saved = $this->settings->get(self::SETTING);
            $this->roles = is_array($saved) && $saved !== [] ? self::normalize($saved) : $this->templates();
        }
        return $this->roles;
    }

    /** @return array{key: string, label: string, capabilities: string[], points_factor: float}|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Rechte einer Rolle. admin: alle; unbekannte (z. B. gelöschte) Rollen: keine, wie Gast.
     *
     * @return Capability[]
     */
    public function capabilities(string $key): array
    {
        if ($key === Roles::ADMIN) {
            return Capability::cases();
        }
        $role = $this->find($key);
        if ($role === null) {
            return [];
        }
        $out = [];
        foreach ($role['capabilities'] as $c) {
            $cap = Capability::tryFrom($c);
            if ($cap !== null && in_array($cap, Roles::GRANTABLE, true)) {
                $out[] = $cap;
            }
        }
        return $out;
    }

    public function label(string $key): string
    {
        return $this->find($key)['label'] ?? $this->config->roleLabel($key);
    }

    public function pointsFactor(string $key): float
    {
        return $this->find($key)['points_factor'] ?? 1.0;
    }

    /**
     * Für /config: Schlüssel, Bezeichnung und Punktefaktor (die Bezeichnungen braucht die App überall).
     *
     * @return array<int, array{key: string, label: string, points_factor: float}>
     */
    public function publicList(): array
    {
        return array_values(array_map(
            static fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'points_factor' => $r['points_factor']],
            $this->all(),
        ));
    }

    /**
     * Für Einstellungen → Rollen: mit Rechten, Anzahl der Personen und was sich ändern lässt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        $counts = [];
        foreach ($this->users->list(true) as $u) {
            $counts[$u->role] = ($counts[$u->role] ?? 0) + 1;
        }
        $out = [];
        foreach ($this->all() as $key => $r) {
            $out[] = [
                'key' => $key,
                'label' => $r['label'],
                'capabilities' => array_map(static fn (Capability $c): string => $c->value, $this->capabilities($key)),
                'points_factor' => $r['points_factor'],
                'users' => $counts[$key] ?? 0,
                'system' => Roles::isSystem($key),
                'deletable' => !Roles::isSystem($key) && $key !== Roles::MEMBER,
            ];
        }
        return $out;
    }

    /** Einmalig: die Vorlagen speichern, damit spätere Änderungen an kompanie.json nichts mehr verschieben. */
    public function initialize(): void
    {
        if (!is_array($this->settings->get(self::SETTING))) {
            $this->save($this->templates());
        }
    }

    /**
     * @param array<string, mixed> $in label, capabilities, points_factor
     * @return array{key: string, label: string, capabilities: string[], points_factor: float}
     */
    public function create(array $in): array
    {
        $roles = $this->all();
        if (count($roles) >= self::MAX_ROLES) {
            throw ApiError::invalid(sprintf('Mehr als %d Rollen sind nicht möglich.', self::MAX_ROLES));
        }
        $role = [
            'key' => $this->newKey(),
            'label' => $this->validLabel($in['label'] ?? null, null),
            'capabilities' => self::validCapabilities($in['capabilities'] ?? []),
            'points_factor' => array_key_exists('points_factor', $in) ? self::validFactor($in['points_factor']) : 1.0,
        ];
        // Neue Rollen vor admin einordnen.
        $admin = $roles[Roles::ADMIN] ?? null;
        unset($roles[Roles::ADMIN]);
        $roles[$role['key']] = $role;
        if ($admin !== null) {
            $roles[Roles::ADMIN] = $admin;
        }
        $this->save($roles);
        return $role;
    }

    /**
     * Nur mitgeschickte Felder ändern. guest und admin: nur die Bezeichnung.
     *
     * @param array<string, mixed> $in label, capabilities, points_factor
     * @return array{key: string, label: string, capabilities: string[], points_factor: float}
     */
    public function update(string $key, array $in): array
    {
        $roles = $this->all();
        if (!isset($roles[$key])) {
            throw ApiError::notFound('Rolle nicht gefunden.');
        }
        $role = $roles[$key];
        if (array_key_exists('label', $in)) {
            $role['label'] = $this->validLabel($in['label'], $key);
        }
        $fixed = Roles::isSystem($key);
        if (array_key_exists('capabilities', $in)) {
            if ($fixed) {
                throw ApiError::invalid('Die Rechte von Gast und Admin lassen sich nicht ändern.');
            }
            $role['capabilities'] = self::validCapabilities($in['capabilities']);
        }
        if (array_key_exists('points_factor', $in)) {
            if ($fixed) {
                throw ApiError::invalid('Gast und Admin haben keinen eigenen Punktefaktor.');
            }
            $role['points_factor'] = self::validFactor($in['points_factor']);
        }
        $roles[$key] = $role;
        $this->save($roles);
        return $role;
    }

    /**
     * Löscht eine Rolle. Sind ihr noch Personen zugeordnet, bekommen sie $replacement; ohne Ersatz → 400.
     */
    public function delete(string $key, ?string $replacement): void
    {
        $roles = $this->all();
        if (!isset($roles[$key])) {
            throw ApiError::notFound('Rolle nicht gefunden.');
        }
        if (Roles::isSystem($key) || $key === Roles::MEMBER) {
            throw ApiError::invalid('Diese Rolle lässt sich nicht löschen.');
        }
        $assigned = array_filter($this->users->list(true), static fn (User $u): bool => $u->role === $key);
        if ($assigned !== []) {
            if ($replacement === null || $replacement === '') {
                throw ApiError::invalid(sprintf('Dieser Rolle sind noch %d Personen zugeordnet. Bitte eine Rolle wählen, die sie stattdessen bekommen.', count($assigned)));
            }
            if ($replacement === $key || !isset($roles[$replacement]) || $replacement === Roles::ADMIN) {
                throw ApiError::invalid('Ungültige Ersatzrolle.');
            }
            foreach ($assigned as $u) {
                try {
                    $this->users->setRole($u->id, $replacement);
                } catch (\DomainException $e) {
                    throw ApiError::invalid($u->name . ': ' . $e->getMessage());
                }
            }
        }
        unset($roles[$key]);
        $this->save($roles);
    }

    /**
     * Darf $actor einer Person diese Rolle geben bzw. ihre Rolle ändern? Admins immer; wer sonst Rollen
     * vergeben darf, nur Rollen, deren Rechte er selbst hat (keine Rechte weitergeben, die man nicht hat).
     */
    public function canHandle(User $actor, string $key): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        if ($key === Roles::ADMIN) {
            return false;
        }
        foreach ($this->capabilities($key) as $cap) {
            if (!$actor->can($cap)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, array{key: string, label: string, capabilities: string[], points_factor: float}> $roles */
    private function save(array $roles): void
    {
        $this->settings->set(self::SETTING, array_values($roles));
        $this->roles = $roles;
    }

    /** @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float}> */
    private function templates(): array
    {
        $out = [];
        foreach (Roles::TEMPLATES as $key => $caps) {
            $out[$key] = ['key' => $key, 'label' => $this->config->roleLabel($key), 'capabilities' => $caps, 'points_factor' => 1.0];
        }
        return $out;
    }

    /**
     * Gespeicherte Liste prüfen; guest und admin sind immer da (guest zuerst, admin zuletzt).
     *
     * @param array<int|string, mixed> $saved
     * @return array<string, array{key: string, label: string, capabilities: string[], points_factor: float}>
     */
    private function normalize(array $saved): array
    {
        $out = [];
        foreach ($saved as $r) {
            if (!is_array($r) || !is_string($r['key'] ?? null) || $r['key'] === '' || isset($out[$r['key']])) {
                continue;
            }
            $key = $r['key'];
            $out[$key] = [
                'key' => $key,
                'label' => is_string($r['label'] ?? null) && $r['label'] !== '' ? $r['label'] : $this->config->roleLabel($key),
                'capabilities' => Roles::isSystem($key) ? [] : array_values(array_filter((array) ($r['capabilities'] ?? []), 'is_string')),
                'points_factor' => Roles::isSystem($key) ? 1.0 : (is_numeric($r['points_factor'] ?? null) ? (float) $r['points_factor'] : 1.0),
            ];
        }
        $guest = $out[Roles::GUEST] ?? ['key' => Roles::GUEST, 'label' => $this->config->roleLabel(Roles::GUEST), 'capabilities' => [], 'points_factor' => 1.0];
        $admin = $out[Roles::ADMIN] ?? ['key' => Roles::ADMIN, 'label' => $this->config->roleLabel(Roles::ADMIN), 'capabilities' => [], 'points_factor' => 1.0];
        unset($out[Roles::GUEST], $out[Roles::ADMIN]);
        return [Roles::GUEST => $guest] + $out + [Roles::ADMIN => $admin];
    }

    /** Fester, kurzer Schlüssel für neue Rollen (r1, r2, …); die Bezeichnung lässt sich jederzeit ändern. */
    private function newKey(): string
    {
        $roles = $this->all();
        for ($n = 1; ; $n++) {
            $key = 'r' . $n;
            if (!isset($roles[$key])) {
                return $key;
            }
        }
    }

    private function validLabel(mixed $raw, ?string $key): string
    {
        $label = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', $raw)) : '';
        if ($label === '') {
            throw ApiError::invalid('Bitte einen Namen für die Rolle angeben.');
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw ApiError::invalid(sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::LABEL_MAX));
        }
        foreach ($this->all() as $k => $r) {
            if ($k !== $key && mb_strtolower($r['label']) === mb_strtolower($label)) {
                throw ApiError::invalid('Eine Rolle mit diesem Namen gibt es schon.');
            }
        }
        return $label;
    }

    /** @return string[] */
    private static function validCapabilities(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw ApiError::invalid('Rechte als Liste angeben.');
        }
        $out = [];
        foreach ($raw as $c) {
            $cap = is_string($c) ? Capability::tryFrom($c) : null;
            if ($cap === null || !in_array($cap, Roles::GRANTABLE, true)) {
                throw ApiError::invalid('Unbekanntes oder nicht vergebbares Recht: ' . (is_scalar($c) ? (string) $c : '?') . '.');
            }
            $out[$cap->value] = $cap->value;
        }
        // Verwalten setzt den Mitgliederbereich voraus (sonst sähe die Person ihre Mitglieder nicht).
        if ($out !== [] && !isset($out[Capability::Member->value])) {
            throw ApiError::invalid('Rollen mit Rechten brauchen auch den Mitgliederbereich.');
        }
        return array_values($out);
    }

    private static function validFactor(mixed $raw): float
    {
        $v = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        if (!is_numeric($v)) {
            throw ApiError::invalid('Der Punktefaktor muss eine Zahl sein, z. B. 0,5.');
        }
        $f = round((float) $v, 2);
        if ($f < 0 || $f > self::FACTOR_MAX) {
            throw ApiError::invalid(sprintf('Der Punktefaktor muss zwischen 0 und %s liegen.', str_replace('.', ',', (string) self::FACTOR_MAX)));
        }
        return $f;
    }
}
