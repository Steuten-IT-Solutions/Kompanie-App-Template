<?php

declare(strict_types=1);

namespace KompanieApp\Core\ChangeLog;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\Request;

/**
 * Welche Routen das Änderungsprotokoll erfasst und wie sie ihre Einträge laden (Schlüssel „METHODE /pfad“ wie in der
 * Routentabelle von Http\Api). Stufe 1: Mitglieder und Konten, Rollen und Rechte, Einstellungen und jedes Löschen in allen
 * Bereichen. Abstimmungen und Push-Abos fehlen absichtlich (ChangeLogService).
 *
 * Einträge aus eigenen Tabellen lädt table() als Zeile; nur Spalten mit Anzeigenamen kommen ins Protokoll (so landen
 * technische Spalten, Dateien und Bilder nicht darin). Einstellungen vergleichen die Antwort der passenden GET-Route, die
 * Geheimes nie enthält (nur „gesetzt“).
 */
final class Subjects
{
    /** Persönliche Angaben eines Mitglieds (nur mit view_personal sichtbar) */
    private const PERSONAL = ['E-Mail', 'Handynummer', 'Adresse', 'Geburtsdatum'];

    /** @var array<string, Subject>|null */
    private ?array $map = null;

    /** @param \Closure(string): mixed $get Antwort einer GET-Route der API (ohne Prüfung und Erfassung) */
    public function __construct(private readonly App $app, private readonly \Closure $get)
    {
    }

    public function for(string $method, string $path): ?Subject
    {
        return ($this->map ??= $this->build())[$method . ' ' . $path] ?? null;
    }

    /** @return list<string> alle erfassten Routen („METHODE /pfad“), z. B. für Tests */
    public function keys(): array
    {
        return array_keys($this->map ??= $this->build());
    }

    /** @return array<string, Subject> */
    private function build(): array
    {
        $member = $this->member();
        $self = $this->member(self: true);
        $newMembers = $this->member(all: true);
        $password = $this->member(event: 'Passwort');
        $ownPassword = $this->member(self: true, event: 'Passwort');
        $role = $this->role();
        $map = [
            // Mitglieder und Konten
            'POST /users' => $newMembers,
            'POST /users/bulk' => $newMembers,
            'POST /members/import' => $newMembers,
            'POST /members/former' => $newMembers,
            'POST /members/former/import' => $newMembers,
            'POST /registrations/{id}/approve' => $newMembers,
            'POST /registrations/{id}/reject' => $this->table('Anfrage', 'registration_requests', fn (array $r): string => trim($r['first_name'] . ' ' . $r['last_name']), [
                'first_name' => 'Vorname', 'last_name' => 'Nachname', 'email' => 'E-Mail', 'login' => 'Benutzername', 'message' => 'Nachricht',
                'status' => 'Status', 'reason' => 'Begründung',
            ], personal: ['email']),
            'PATCH /members/{id}/account' => $member,
            'PUT /members/{id}/personal' => $member,
            'PATCH /members/{id}/role' => $member,
            'PUT /members/{id}/rights' => $member,
            'POST /members/{id}/promote' => $member,
            'DELETE /members/{id}' => $member,
            'PUT /members/{id}/photo' => $member,
            'DELETE /members/{id}/photo' => $member,
            'POST /members/{id}/password' => $password,
            'PUT /members/{id}/join-year' => $member,
            'DELETE /members/{id}/join-year' => $member,
            'PUT /members/{id}/birthday' => $member,
            'DELETE /members/{id}/birthday' => $member,
            'PUT /members/{id}/former' => $member,
            'PATCH /members/{id}/former' => $member,
            'DELETE /members/{id}/former' => $member,
            // Eigenes Konto
            'PATCH /me/account' => $self,
            'POST /me/password' => $ownPassword,
            'PUT /me/birthday' => $self,
            'DELETE /me/birthday' => $self,
            'PUT /me/join-year' => $self,
            'DELETE /me/join-year' => $self,
            'PUT /me/signature' => $self,
            'DELETE /me/signature' => $self,
            // Rollen
            'POST /admin/roles' => $role,
            'PATCH /admin/roles/{key}' => $role,
            'DELETE /admin/roles/{key}' => $role,
            // Einstellungen
            'PATCH /admin/settings' => $this->settings('Allgemeine Einstellungen', '/admin/settings'),
            'PATCH /admin/mail' => $this->settings('E-Mail', '/admin/mail'),
            'PUT /admin/registration' => $this->settings('Zugang beantragen', '/admin/registration'),
            'PATCH /admin/push' => $this->settings('Benachrichtigungen', '/admin/push', ['subscriptions', 'counts', 'devices']),
            'PATCH /admin/birthdays' => $this->settings('Geburtstage', '/admin/birthdays'),
            'PUT /admin/navigation' => $this->settings('Navigation', '/admin/navigation'),
            'PUT /admin/features' => $this->settings('Bereiche', '/admin/features'),
            'POST /admin/maintenance' => $this->settings('Wartungsmodus', '/admin/maintenance', ['log']),
            'PATCH /admin/update' => $this->settings('Updates', '/admin/update'),
            'PUT /admin/backup/drive' => $this->settings('Sicherung in Google Drive', '/admin/backup/drive'),
            'PUT /admin/backup/server' => $this->settings('Sicherung auf dem Server', '/admin/backup/server'),
            'PUT /admin/backup/encryption' => $this->event('Einstellungen', 'Verschlüsselung der Sicherung', 'update'),
            'DELETE /admin/backup/encryption' => $this->event('Einstellungen', 'Verschlüsselung der Sicherung', 'delete'),
            'PUT /admin/former/retention' => $this->settings('Aufbewahrungsfrist für Ehemalige', '/admin/former/retention'),
            'PUT /admin/activity/settings' => $this->value('Nutzung', 'Erfassung je Person', fn (): bool => $this->app->activity()->personal()),
            'PUT /admin/changes/settings' => $this->values('Änderungsprotokoll', fn (): array => [
                'Änderungen protokollieren' => $this->app->changeLog()->enabled(),
                'Frist (Tage)' => $this->app->changeLog()->keepDays(),
            ]),
            'PUT /attendance/groups' => $this->settings('Gruppen der Anwesenheit', '/attendance/groups'),
            'POST /admin/google/connect' => $this->settings('Google-Zugang', '/admin/settings'),
            'POST /admin/google/disconnect' => $this->settings('Google-Zugang', '/admin/settings'),
            'DELETE /admin/error-log' => $this->event('Einstellungen', 'Fehlerprotokoll', 'delete'),
            'DELETE /admin/update/held' => $this->event('Einstellungen', 'Zurückgestelltes Update', 'delete'),
            'POST /admin/backup/server/delete' => $this->event('Sicherung', fn (Request $r): string => 'Sicherung ' . (is_string($r->input('id')) ? $r->input('id') : ''), 'delete'),
        ];
        // Löschen in den Bereichen
        foreach ($this->deletions() as $key => $subject) {
            $map[$key] = $subject;
        }
        return $map;
    }

    /**
     * Löschen in allen Bereichen (Stufe 1). Die Spalten gelten auch für Anlegen und Ändern, wenn die Bereiche dazukommen.
     *
     * @return array<string, Subject>
     */
    private function deletions(): array
    {
        $event = ['title' => 'Titel', 'start_date' => 'Beginn', 'start_time' => 'Beginn (Uhrzeit)', 'end_date' => 'Ende', 'end_time' => 'Ende (Uhrzeit)', 'location' => 'Ort', 'description' => 'Beschreibung', 'url' => 'Link'];
        $years = static fn (array $r): string => trim($r['start_year'] . ($r['end_year'] !== null && $r['end_year'] !== '' && $r['end_year'] !== $r['start_year'] ? '–' . $r['end_year'] : ''));
        return [
            'DELETE /events/{id}' => $this->table('Termin', 'events', 'title', $event),
            'DELETE /calendar/entries/{id}' => $this->table('Termin im Kalender', 'internal_events', 'title', $event),
            'DELETE /news/{id}' => $this->table('News', 'app_news', 'title', ['title' => 'Titel', 'body' => ['Text', 'html']]),
            'DELETE /ranks/{id}' => $this->table('Dienstgrad', 'ranks', 'title', ['title' => 'Bezeichnung', 'min_points' => 'Ab Punkten', 'active' => ['Aktiv', 'bool']]),
            'DELETE /ranks/{id}/badge' => $this->table('Dienstgrad', 'ranks', 'title', ['badge_version' => ['Abzeichen', 'secret']]),
            'DELETE /titles/{id}' => $this->table('Titel', 'titles', 'title', ['title' => 'Bezeichnung', 'active' => ['Aktiv', 'bool']]),
            'DELETE /office-groups/{id}' => $this->table('Ämtergruppe', 'office_groups', 'title', ['title' => 'Bezeichnung', 'singular' => 'Einzahl', 'joint_terms' => ['Gemeinsame Amtszeiten', 'bool']]),
            'DELETE /office-group-terms/{id}' => $this->table('Amtszeit der Gruppe', 'office_group_terms', fn (array $r): string => $this->lookup('office_groups', 'title', $r['group_id']) . ' ' . $years($r), ['start_year' => 'Von', 'end_year' => 'Bis', 'note' => 'Notiz']),
            'DELETE /offices/{id}' => $this->table('Amt', 'offices', 'title', ['title' => 'Bezeichnung', 'singular' => 'Einzahl']),
            'DELETE /office-terms/{id}' => $this->table('Amtszeit', 'office_terms', fn (array $r): string => $this->lookup('offices', 'title', $r['office_id']) . ' ' . $years($r), ['start_year' => 'Von', 'end_year' => 'Bis', 'note' => 'Notiz', 'honor' => ['Ehrenamt', 'bool']]),
            'DELETE /pages/{id}' => $this->table('Seite', 'app_pages', 'title', ['title' => 'Titel', 'body' => ['Text', 'html'], 'audience' => 'Sichtbar für']),
            'DELETE /sponsors/{id}' => $this->table('Sponsor', 'sponsors', 'name', ['name' => 'Name', 'url' => 'Link']),
            'DELETE /sponsors/{id}/logo' => $this->table('Sponsor', 'sponsors', 'name', ['logo_version' => ['Logo', 'secret']]),
            'DELETE /companies/{id}' => $this->table('Kompanie', 'companies', 'name', ['name' => 'Name', 'short_name' => 'Kurzname', 'founded_year' => 'Gegründet', 'description' => 'Beschreibung', 'website_url' => 'Webseite', 'app_url' => 'App']),
            'DELETE /companies/{id}/logo' => $this->table('Kompanie', 'companies', 'name', ['logo_version' => ['Logo', 'secret']]),
            'DELETE /lists/{id}' => $this->table('Liste', 'lists', 'title', ['title' => 'Titel', 'subtitle' => 'Untertitel', 'list_date' => 'Datum']),
            'DELETE /documents/{id}' => $this->table('Dokument', 'documents', 'title', ['title' => 'Titel', 'doc_date' => 'Datum', 'visibility' => 'Sichtbarkeit', 'ext' => 'Dateityp']),
            'DELETE /document-categories/{id}' => $this->table('Kategorie der Dokumente', 'doc_categories', 'title', ['title' => 'Bezeichnung']),
            'DELETE /board/{id}' => $this->table('Nachricht am Brett', 'board_messages', 'title', ['title' => 'Titel', 'body' => ['Text', 'html'], 'expires_at' => 'Sichtbar bis']),
            'DELETE /polls/{id}' => $this->table('Umfrage', 'polls', 'title', ['title' => 'Titel', 'description' => 'Beschreibung', 'anonymous' => ['Anonym', 'bool'], 'ends_at' => 'Ende']),
            'DELETE /inventory/{id}' => $this->table('Inventar', 'inventory_items', 'title', ['title' => 'Bezeichnung', 'category' => 'Kategorie', 'quantity' => 'Menge', 'unit' => 'Einheit', 'location' => 'Lagerort', 'note' => 'Notiz']),
            'DELETE /attendance/{id}' => $this->table('Anwesenheitsliste', 'attendance_lists', 'title', ['title' => 'Titel', 'event_date' => 'Datum']),
            'DELETE /points/{id}' => $this->table('Punkte', 'points', fn (array $r): string => sprintf('%s Punkte für %s', $r['points'], $this->name((int) $r['user_id'])), [
                'user_id' => ['Mitglied', 'user'], 'points' => 'Punkte', 'reason' => 'Grund', 'entry_date' => 'Datum',
            ], subject: 'user_id'),
            'DELETE /penalties/{id}' => $this->table('Strafe', 'penalties', fn (array $r): string => sprintf('%s für %s', self::euro($r['amount_cents']), $this->name((int) $r['user_id'])), [
                'user_id' => ['Mitglied', 'user'], 'amount_cents' => ['Betrag', 'cents'], 'reason' => 'Grund', 'entry_date' => 'Datum', 'status' => 'Status',
            ], subject: 'user_id'),
            'DELETE /gallery/submissions/{id}' => $this->table('Einreichung für die Galerie', 'gallery_submissions', 'name', ['name' => 'Datei', 'status' => 'Status']),
            'POST /gallery/photos/delete' => $this->table('Bild der Galerie', 'photos', 'name', ['name' => 'Datei', 'album_id' => ['Album', 'album'], 'is_intern' => ['Intern', 'bool']], ids: Subject::fromBody('ids')),
            'DELETE /admin/gallery/photos/{id}' => $this->table('Bild der Galerie', 'photos', 'name', ['name' => 'Datei', 'album_id' => ['Album', 'album'], 'is_intern' => ['Intern', 'bool']]),
            'DELETE /admin/gallery/albums/{id}' => $this->table('Album', 'albums', 'title', ['title' => 'Titel', 'year' => 'Jahr']),
            'DELETE /mails/groups/{key}' => $this->mailGroup(),
        ];
    }

    /**
     * Eine Zeile aus einer Tabelle der App.
     *
     * @param string|\Closure(array<string, mixed>): string $label Spalte oder Funktion für die Bezeichnung
     * @param array<string, string|array{0: string, 1: string}> $columns Spalte => Anzeigename oder [Anzeigename, Art]; Arten:
     *        bool, user (Konto-ID), cents, html, album, secret (nur „geändert“)
     * @param list<string> $personal Spalten mit persönlichen Angaben
     * @param string|null $subject Spalte mit dem Konto, um das es geht
     * @param (\Closure(Request, mixed): list<string>)|null $ids
     */
    private function table(string $type, string $table, string|\Closure $label, array $columns, array $personal = [], ?string $subject = null, ?\Closure $ids = null): Subject
    {
        $load = function (string $id) use ($type, $table, $label, $columns, $personal, $subject): ?Item {
            $row = $this->app->db->selectOne('SELECT * FROM {' . $table . '} WHERE id = ?', [$id]);
            if ($row === null) {
                return null;
            }
            $fields = [];
            $personalLabels = [];
            $secret = [];
            foreach ($columns as $col => $spec) {
                [$name, $kind] = is_array($spec) ? $spec : [$spec, 'text'];
                $fields[$name] = $this->format($row[$col] ?? null, $kind);
                if (in_array($col, $personal, true)) {
                    $personalLabels[] = $name;
                }
                if ($kind === 'secret') {
                    $secret[] = $name;
                }
            }
            $text = is_string($label) ? (string) ($row[$label] ?? '') : $label($row);
            $text = trim(strip_tags($text));
            return new Item($type, $id, $text !== '' ? $text : '#' . $id, $fields, $personalLabels, $secret, $subject !== null && isset($row[$subject]) ? (int) $row[$subject] : null);
        };
        return new Subject($load, $ids ?? Subject::fromPath());
    }

    /**
     * Ein Mitglied mit Konto, Rollen, Ausnahmen bei den Rechten, persönlichen Angaben, Geburtsdatum, Eintritt, Foto und
     * Angaben als ehemaliges Mitglied.
     *
     * @param bool $self das eigene Konto (/me/…)
     * @param bool $all neue Konten ohne ID in der Adresse (Anlegen, auch mehrere): was nachher dazugekommen ist
     * @param string|null $event geheimes Feld, das sich mit der Anfrage ändert (Passwort)
     */
    private function member(bool $self = false, bool $all = false, ?string $event = null): Subject
    {
        $load = fn (string $id): ?Item => $this->memberItem((int) $id);
        $ids = match (true) {
            $self => function (): array {
                $u = $this->app->authContext->currentUser();
                return $u === null ? [] : [(string) $u->id];
            },
            // Neue Konten nur über den Vergleich aller IDs: Die Adresse kann eine andere ID tragen (Anfrage auf einen Zugang).
            $all => static fn (): array => [],
            default => Subject::fromPath(),
        };
        $allIds = $all ? fn (): array => array_map(static fn ($u): string => (string) $u->id, $this->app->users->list(true)) : null;
        return new Subject($load, $ids, $allIds, $event !== null ? 'update' : null, $event);
    }

    private function memberItem(int $id): ?Item
    {
        $user = $this->app->users->find($id);
        $profile = $user !== null ? $this->app->users->profile($id) : null;
        if ($user === null || $profile === null) {
            return null;
        }
        $roles = $this->app->roles();
        $personal = $this->app->members()->personal($id);
        $fields = [
            'Vorname' => $profile['first_name'] ?? '',
            'Nachname' => $profile['last_name'] ?? '',
            'Benutzername' => $this->app->members()->hasNoAccess($id) ? 'ohne Zugang' : $user->login,
            'Rolle' => $roles->label($user->role),
            'Zusatzrollen' => array_map($roles->label(...), $roles->extraRoles($id, $user->role)),
        ];
        $overrides = $roles->overridesOf($id);
        $fields['Rechte zusätzlich'] = $overrides['grant'];
        $fields['Rechte entzogen'] = $overrides['revoke'];
        $fields['E-Mail'] = $personal['email'];
        $fields['Handynummer'] = $personal['phone'];
        $fields['Adresse'] = is_array($personal['address']) ? implode(', ', array_filter(array_map('strval', $personal['address']), static fn (string $s): bool => trim($s) !== '')) : null;
        $birthday = $this->app->birthdays()->repository()->find($id);
        $fields['Geburtsdatum'] = $birthday === null ? null : sprintf('%02d.%02d.%s', $birthday['day'], $birthday['month'], $birthday['year'] ?? '');
        $join = $this->app->joinYears()->repository()->find($id);
        $fields['Eintritt'] = $join === null ? null : ($join['joined_on'] !== '' ? $join['joined_on'] : (string) $join['year']);
        $former = $this->app->formerMembers()->repository()->find($id);
        $fields['Ehemalig seit'] = $former['left_on'] ?? null;
        $fields['Grund des Ausscheidens'] = $former['reason'] ?? null;
        $photo = $this->app->memberPhotos()->repository()->find($id);
        $fields['Foto'] = $photo['version'] ?? null;
        $signature = $this->app->db->scalar('SELECT content FROM {user_signatures} WHERE user_id = ?', [$id]);
        $fields['Signatur'] = is_string($signature) ? self::plain($signature) : null;
        return new Item('Mitglied', (string) $id, $user->name, $fields, self::PERSONAL, ['Foto'], $id);
    }

    /** Eine Rolle; neue erkennt der Vergleich aller Schlüssel vorher und nachher. */
    private function role(): Subject
    {
        $load = function (string $key): ?Item {
            $r = $this->app->roles()->find($key);
            if ($r === null) {
                return null;
            }
            return new Item('Rolle', $key, $r['label'], [
                'Bezeichnung' => $r['label'],
                'Rechte' => $r['capabilities'],
                'Faktor für Punkte' => $r['points_factor'],
                'Für neue Konten' => $r['new_accounts'],
                'Beförderbar' => $r['promotable'],
            ]);
        };
        return new Subject($load, Subject::fromPath('key'), fn (): array => array_map('strval', array_keys($this->app->roles()->all())));
    }

    private function mailGroup(): Subject
    {
        $load = function (string $key): ?Item {
            $g = $this->app->mailGroups()->find($key);
            if ($g === null) {
                return null;
            }
            return new Item('Mailgruppe', $key, $g['label'], ['Bezeichnung' => $g['label'], 'Rollen' => $g['roles'], 'Mitglieder' => array_map($this->name(...), $g['users']), 'Adressen' => $g['emails']], ['Adressen']);
        };
        return new Subject($load, Subject::fromPath('key'));
    }

    /**
     * Einstellungen: die Antwort der GET-Route, flach (verschachtelte Schlüssel mit Punkt); $skip lässt Schlüssel aus, die
     * sich ohne Zutun ändern (Protokolle, Zähler).
     *
     * @param list<string> $skip
     */
    private function settings(string $label, string $getPath, array $skip = []): Subject
    {
        return $this->values($label, function () use ($getPath, $skip): array {
            $data = ($this->get)($getPath);
            return is_array($data) ? self::flatten(array_diff_key($data, array_flip($skip))) : [];
        });
    }

    /** @param \Closure(): mixed $read */
    private function value(string $label, string $field, \Closure $read): Subject
    {
        return $this->values($label, static fn (): array => [$field => $read()]);
    }

    /** @param \Closure(): array<string, mixed> $read */
    private function values(string $label, \Closure $read): Subject
    {
        return new Subject(static fn (): Item => new Item('Einstellungen', '', $label, $read()), static fn (): array => ['']);
    }

    /**
     * Etwas ohne vergleichbaren Stand (geleert, entfernt, eingerichtet).
     *
     * @param string|\Closure(Request): string $label
     * @param 'create'|'update'|'delete' $action
     */
    private function event(string $type, string|\Closure $label, string $action): Subject
    {
        return new Subject(
            static fn (string $id): Item => new Item($type, '', $id),
            static fn (Request $r): array => [is_string($label) ? $label : $label($r)],
            null,
            $action,
        );
    }

    private function format(mixed $v, string $kind): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        return match ($kind) {
            'bool' => (bool) (int) $v,
            'user' => $this->name((int) $v),
            'cents' => self::euro($v),
            'html' => self::plain((string) $v),
            'album' => $this->lookup('albums', 'title', $v),
            default => is_scalar($v) ? (string) $v : null,
        };
    }

    private function name(int $id): string
    {
        return $this->app->users->find($id)?->name ?? 'gelöschtes Konto';
    }

    private function lookup(string $table, string $column, mixed $id): string
    {
        $v = $id === null || $id === '' ? null : $this->app->db->scalar('SELECT ' . $column . ' FROM {' . $table . '} WHERE id = ?', [$id]);
        return is_scalar($v) ? (string) $v : '';
    }

    private static function euro(mixed $cents): string
    {
        return number_format(((int) $cents) / 100, 2, ',', '.') . ' €';
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v) && !array_is_list($v) && $v !== []) {
                $out += self::flatten($v, $key . '.');
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}
