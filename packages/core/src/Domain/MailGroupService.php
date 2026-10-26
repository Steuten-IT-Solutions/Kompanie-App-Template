<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;

/**
 * Mailgruppen für die E-Mails aus der Verwaltung (Bereich mail, Recht send_mail).
 *
 * - Zwei Standardgruppen berechnet die App beim Senden (MailingService::resolve): „Alle Mitglieder“ sind alle Konten,
 *   deren Grundrolle den Mitgliederbereich hat (auch Admins und eigene Rollen wie Spieß, nicht Anwärter), „Alle Konten“
 *   alle außer Gästen. Sie lassen sich nur umbenennen. Die Rolle „Mitglied“ allein erreicht nur Konten mit genau dieser
 *   Grundrolle; dafür gibt es die Standardgruppen.
 * - Eigene Gruppen bestehen wie ein Empfängerfeld aus Rollen, Konten und freien Adressen und werden ebenfalls erst beim
 *   Senden aufgelöst. Ehemalige (Rolle Gast, FormerMemberService) bekommen so nie eine Mail, auch wenn sie noch in einer
 *   Gruppe eingetragen sind.
 * - Gespeichert als Einstellung SETTING (damit in der Datensicherung): {labels: {members, all}, groups: [{id, label,
 *   roles, users, emails}]}. Die id bleibt beim Umbenennen gleich, gespeicherte Auswahlen und Entwürfe bleiben gültig.
 */
final class MailGroupService
{
    public const SETTING = 'mail_groups';
    public const MEMBERS = 'members';
    public const ALL = 'all';
    /** Standardgruppen => Bezeichnung, solange niemand sie umbenennt */
    public const STANDARD = [self::MEMBERS => 'Alle Mitglieder', self::ALL => 'Alle Konten'];
    public const LABEL_MAX = 60;
    public const MAX_GROUPS = 100;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Alle Gruppen: Standardgruppen zuerst, dann die eigenen nach Bezeichnung.
     *
     * @return list<array{key: string, label: string, standard: bool, roles: list<string>, users: list<int>, emails: list<string>}>
     */
    public function all(): array
    {
        $stored = $this->stored();
        $out = [];
        foreach (self::STANDARD as $key => $label) {
            $out[] = ['key' => $key, 'label' => $stored['labels'][$key] ?? $label, 'standard' => true, 'roles' => [], 'users' => [], 'emails' => []];
        }
        $own = $stored['groups'];
        usort($own, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        foreach ($own as $g) {
            $out[] = ['key' => $g['id'], 'label' => $g['label'], 'standard' => false, 'roles' => $g['roles'], 'users' => $g['users'], 'emails' => $g['emails']];
        }
        return $out;
    }

    /** @return array{key: string, label: string, standard: bool, roles: list<string>, users: list<int>, emails: list<string>}|null */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $g) {
            if ($g['key'] === $key) {
                return $g;
            }
        }
        return null;
    }

    /**
     * Eigene Gruppe anlegen.
     *
     * @param array<string, mixed> $in label, roles, users, emails
     */
    public function create(array $in): string
    {
        $stored = $this->stored();
        if (count($stored['groups']) >= self::MAX_GROUPS) {
            throw ApiError::invalid(sprintf('Höchstens %d Mailgruppen.', self::MAX_GROUPS));
        }
        $label = $this->validLabel($in['label'] ?? null, null);
        $content = $this->app->mailings()->validField('Gruppe', $in, false);
        $id = 'g' . bin2hex(random_bytes(6));
        $stored['groups'][] = ['id' => $id, 'label' => $label] + $content;
        $this->save($stored);
        return $id;
    }

    /**
     * Gruppe ändern: Standardgruppen nur umbenennen, eigene auch im Inhalt.
     *
     * @param array<string, mixed> $in label, roles, users, emails
     */
    public function update(string $key, array $in): void
    {
        $stored = $this->stored();
        if (isset(self::STANDARD[$key])) {
            if (array_intersect(['roles', 'users', 'emails'], array_keys($in)) !== []) {
                throw ApiError::invalid('Wer zu einer Standardgruppe gehört, bestimmt die App. Sie lässt sich nur umbenennen.');
            }
            $stored['labels'][$key] = $this->validLabel($in['label'] ?? null, $key);
            $this->save($stored);
            return;
        }
        foreach ($stored['groups'] as $i => $g) {
            if ($g['id'] === $key) {
                $label = array_key_exists('label', $in) ? $this->validLabel($in['label'], $key) : $g['label'];
                // Was nicht mitkommt, bleibt; Ehemalige und gelöschte Rollen fallen dabei weg (MailingService::groups).
                $current = array_values(array_filter($this->app->mailings()->groups(), static fn (array $c): bool => $c['key'] === $key))[0];
                $content = $this->app->mailings()->validField('Gruppe', $in + ['roles' => $current['roles'], 'users' => $current['users'], 'emails' => $current['emails']], false);
                $stored['groups'][$i] = ['id' => $key, 'label' => $label] + $content;
                $this->save($stored);
                return;
            }
        }
        throw ApiError::notFound('Diese Mailgruppe gibt es nicht (mehr).');
    }

    public function delete(string $key): void
    {
        if (isset(self::STANDARD[$key])) {
            throw ApiError::invalid('Standardgruppen lassen sich nicht löschen, nur umbenennen.');
        }
        $stored = $this->stored();
        $left = array_values(array_filter($stored['groups'], static fn (array $g): bool => $g['id'] !== $key));
        if (count($left) === count($stored['groups'])) {
            throw ApiError::notFound('Diese Mailgruppe gibt es nicht (mehr).');
        }
        $stored['groups'] = $left;
        $this->save($stored);
    }

    /** Bezeichnung prüfen: nicht leer, nicht länger als LABEL_MAX, keine andere Gruppe heißt so. */
    private function validLabel(mixed $value, ?string $self): string
    {
        $label = is_string($value) ? Text::line($value, self::LABEL_MAX) : '';
        if ($label === '') {
            throw ApiError::invalid('Bitte eine Bezeichnung eingeben.');
        }
        foreach ($this->all() as $g) {
            if ($g['key'] !== $self && mb_strtolower($g['label']) === mb_strtolower($label)) {
                throw ApiError::invalid('Eine Mailgruppe „' . $g['label'] . '“ gibt es schon.');
            }
        }
        return $label;
    }

    /**
     * Gespeicherter Stand, bereinigt (fehlende oder beschädigte Einträge fallen weg).
     *
     * @return array{labels: array<string, string>, groups: list<array{id: string, label: string, roles: list<string>, users: list<int>, emails: list<string>}>}
     */
    private function stored(): array
    {
        $raw = $this->app->settings->get(self::SETTING);
        $raw = is_array($raw) ? $raw : [];
        $labels = [];
        foreach (array_keys(self::STANDARD) as $key) {
            $label = $raw['labels'][$key] ?? null;
            if (is_string($label) && $label !== '') {
                $labels[$key] = $label;
            }
        }
        $groups = [];
        foreach (is_array($raw['groups'] ?? null) ? $raw['groups'] : [] as $g) {
            if (!is_array($g) || !is_string($g['id'] ?? null) || !is_string($g['label'] ?? null)) {
                continue;
            }
            $groups[] = [
                'id' => $g['id'],
                'label' => $g['label'],
                'roles' => array_values(array_filter((array) ($g['roles'] ?? []), 'is_string')),
                'users' => array_values(array_filter((array) ($g['users'] ?? []), 'is_int')),
                'emails' => array_values(array_filter((array) ($g['emails'] ?? []), 'is_string')),
            ];
        }
        return ['labels' => $labels, 'groups' => $groups];
    }

    /** @param array{labels: array<string, string>, groups: list<array<string, mixed>>} $stored */
    private function save(array $stored): void
    {
        $this->app->settings->set(self::SETTING, $stored);
    }
}
