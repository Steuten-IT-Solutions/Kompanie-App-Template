<?php

declare(strict_types=1);

namespace KompanieApp\Core\ChangeLog;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\Request;

/**
 * Welche Routen das Änderungsprotokoll erfasst und wie sie ihre Einträge laden (Schlüssel „METHODE /pfad“ wie in der
 * Routentabelle von Http\Api): Mitglieder und Konten, Rollen und Rechte, Einstellungen, Updates und Sicherungen sowie
 * Anlegen, Ändern und Löschen in allen Bereichen. Was keine Daten ändert oder absichtlich fehlt, steht in NOT_RECORDED;
 * ein Test prüft, dass jede ändernde Route in einer der beiden Listen steht.
 *
 * Einträge aus eigenen Tabellen lädt table() als Zeile; nur Spalten mit Anzeigenamen kommen ins Protokoll (so landen
 * technische Spalten, Dateien und Bilder nicht darin). Einstellungen vergleichen die Antwort der passenden GET-Route, die
 * Geheimes nie enthält (nur „gesetzt“).
 */
final class Subjects
{
    /**
     * Ändernde Routen, die nicht ins Protokoll kommen, mit Grund. Jede andere ändernde Route braucht einen Eintrag in der
     * Liste der erfassten (Test ChangeLogTest).
     */
    public const NOT_RECORDED = [
        // Anmelden, Abmelden; Anfragen und Meldungen ohne Konto oder an die Admins (die Bearbeitung wird erfasst)
        'POST /auth/login', 'POST /auth/logout', 'POST /registration', 'POST /registration/confirm', 'POST /registration/status', 'POST /reports',
        // Benachrichtigungen auf einem Gerät (zählen auch nicht als Nutzung)
        'POST /me/push/status', 'POST /push/status', 'POST /me/push', 'POST /push', 'PUT /me/push', 'PUT /push', 'DELETE /me/push', 'DELETE /push',
        'POST /me/push/test', 'POST /push/test',
        // Abstimmen in Umfragen (absichtlich nicht, auch nicht bei offenen Umfragen)
        'PUT /polls/{id}/vote', 'DELETE /polls/{id}/vote',
        // Teile eines Uploads; erfasst wird der Eintrag, der daraus entsteht (Video, Update, Bild in einem Text)
        'POST /gallery/video-uploads', 'POST /gallery/video-uploads/chunk', 'POST /gallery/video-uploads/cancel', 'POST /attachments', 'POST /rich-images',
        'POST /admin/update/upload', 'POST /admin/update/upload/chunk', 'POST /admin/update/upload/check', 'POST /admin/update/upload/cancel',
        // Prüfen, Testen, Vorschau: ändert keine Daten
        'POST /mails/preview', 'POST /admin/mail/check', 'POST /admin/mail/test', 'POST /admin/calendar/test', 'POST /admin/news/test',
        'POST /admin/update/check', 'POST /admin/backup/encryption/verify',
        // Abgleich der Galerie mit Google Drive (läuft auch von selbst) und neuer Versuch fehlgeschlagener Uploads
        'POST /admin/gallery/sync', 'POST /admin/gallery/retry',
    ];

    /** Stand eines Eintrags der Anwesenheit (AttendanceService) */
    private const ATTENDANCE = ['present' => 'anwesend', 'excused' => 'entschuldigt', 'absent' => 'fehlt'];

    /** Persönliche Angaben eines Mitglieds (nur mit view_personal sichtbar) */
    private const PERSONAL = ['E-Mail', 'Handynummer', 'Adresse', 'Geburtsdatum'];

    /**
     * Felder der Einstellungen mit Anzeigenamen: GET-Pfad => Schlüssel der Antwort (verschachtelt mit Punkt) => Anzeigename.
     * Was hier fehlt, steht mit Grund in settingsIgnored() (Test ChangeLogTest).
     */
    public const SETTINGS_FIELDS = [
        '/admin/settings' => [
            'google.client_id' => 'Google-Client-ID', 'google.connected' => 'Mit Google verbunden', 'google.email' => 'Google-Konto',
            'google.service_account_email' => 'Dienstkonto (Adresse)', 'calendar.choice' => 'Quelle des Kalenders', 'calendar.source' => 'Kalender wirksam aus',
            'calendar.calendar_ids' => 'Kalender-IDs', 'calendar.cache_minutes' => 'Kalender zwischenspeichern (Minuten)', 'gallery.root' => 'Galerie-Ordner',
            'gallery.source' => 'Quelle der Galerie', 'gallery.store' => 'Ablage hochgeladener Bilder', 'gallery.downloads' => 'Bilder herunterladen erlaubt', 'gallery.submissions' => 'Einreichen durch Mitglieder erlaubt',
            'news.source' => 'Quelle der News', 'news.url' => 'Adresse der News', 'legal.imprint_url' => 'Impressum', 'legal.privacy_url' => 'Datenschutzerklärung',
        ],
        '/admin/mail' => [
            'from' => 'Absenderadresse', 'from_name' => 'Absendername', 'host' => 'SMTP-Server', 'port' => 'Port', 'secure' => 'Verschlüsselung',
            'user' => 'SMTP-Benutzer', 'batch_size' => 'Mails je Durchgang', 'batch_pause' => 'Pause zwischen Durchgängen (Sekunden)',
        ],
        '/admin/registration' => ['enabled' => 'Anfragen möglich', 'default_role' => 'Rolle für neue Konten', 'email_confirmation' => 'E-Mail-Adresse bestätigen'],
        '/admin/push' => ['reminderHour' => 'Erinnerung um (Uhr)', 'birthdays.enabled' => 'Geburtstage melden', 'birthdays.hour' => 'Geburtstage um (Uhr)'],
        '/admin/birthdays' => ['upcoming_days' => 'Tage im Voraus'],
        '/admin/navigation' => [
            'navigation' => 'Menü', 'names' => 'Namen der Bereiche', 'start' => 'Startseite', 'show_labels' => 'Beschriftungen zeigen',
            'intern_order' => 'Reihenfolge Intern', 'admin_order' => 'Reihenfolge Verwaltung',
        ],
        '/admin/maintenance' => ['on' => 'Wartungsmodus an', 'reason' => 'Grund'],
        '/admin/backup/drive' => ['enabled' => 'Automatisch sichern'],
        '/admin/backup/server' => ['enabled' => 'Automatisch sichern'],
        '/admin/former/retention' => ['years' => 'Frist (Jahre)'],
        '/admin/website' => ['home_page_title' => 'Startseite der Webseite', 'entry.state' => 'Einstiegsdatei'],
    ];

    /** Felder, deren Wert der Schlüssel einer Rolle ist (in der Anzeige ihr Name) */
    private const ROLE_FIELDS = ['Rolle für neue Konten'];

    /** Lesbare Werte von Auswahlfeldern der Einstellungen (Feld => Wert => Text) */
    private const VALUE_LABELS = [
        'Quelle des Kalenders' => ['' => 'nicht gewählt', 'app' => 'in der App', 'google' => 'Google Kalender', 'ics' => 'iCal-Adresse'],
        'Kalender wirksam aus' => ['none' => 'keine Quelle', 'app' => 'in der App', 'google' => 'Google Kalender', 'ics' => 'iCal-Adresse'],
        'Quelle der Galerie' => ['drive' => 'Google Drive', 'server' => 'nur dieser Server'],
        'Ablage hochgeladener Bilder' => ['server' => 'dieser Server', 'drive' => 'Google Drive'],
        'Quelle der News' => ['none' => 'keine', 'site' => 'Beiträge der Webseite', 'wordpress' => 'WordPress-Webseite', 'rss' => 'Feed', 'app' => 'in der App'],
        'Verschlüsselung' => ['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'keine'],
        'Kanal' => ['stable' => 'stabil', 'early' => 'Vorabversionen'],
        'Einstiegsdatei' => ['installed' => 'angelegt', 'missing' => 'fehlt', 'outdated' => 'veraltet', 'occupied' => 'andere Startseite im Ordner', 'not_writable' => 'Ordner nicht beschreibbar', 'root' => 'nicht möglich'],
    ];

    /** @var array<string, Subject>|null */
    private ?array $map = null;

    /** @var array<int, string> Namen der Konten (gilt für einen Stand, reset) */
    private array $names = [];

    /** @var array<string, string> Bezeichnungen aus anderen Tabellen (gilt für einen Stand, reset) */
    private array $lookups = [];

    /** @param \Closure(string): mixed $get Antwort einer GET-Route der API (ohne Prüfung und Erfassung) */
    public function __construct(private readonly App $app, private readonly \Closure $get)
    {
    }

    public function for(string $method, string $path): ?Subject
    {
        return ($this->map ??= $this->build())[$method . ' ' . $path] ?? null;
    }

    /**
     * Einstellung außerhalb der Routen (z. B. der WordPress-Einstellungsseite) für Http\Api::track().
     *
     * @param \Closure(): array<string, mixed> $read Anzeigename => Wert
     */
    public function custom(string $label, \Closure $read): Subject
    {
        return $this->values($label, $read);
    }

    /** Vor jedem Stand (vorher, nachher): gemerkte Namen und Bezeichnungen vergessen. */
    public function reset(): void
    {
        $this->names = [];
        $this->lookups = [];
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
        $general = $this->generalSettings();
        $map = [
            // Mitglieder und Konten
            'POST /users' => $newMembers,
            'POST /users/bulk' => $newMembers,
            'POST /members/import' => $newMembers,
            'POST /members/former' => $newMembers,
            'POST /members/former/import' => $newMembers,
            // Freigabe: neues Konto oder ein vorhandenes Mitglied ohne Zugang bekommt den Zugang (user_id)
            'POST /registrations/{id}/approve' => new Subject($newMembers->load, Subject::fromBody('user_id'), $newMembers->all),
            'POST /registrations/{id}/reject' => $this->table($this->spec('Anfrage', 'registration_requests', fn (array $r): string => trim($r['first_name'] . ' ' . $r['last_name']), [
                'first_name' => 'Vorname', 'last_name' => 'Nachname', 'email' => 'E-Mail', 'login' => 'Benutzername', 'message' => 'Nachricht',
                'status' => 'Status', 'reason' => 'Begründung',
            ], personal: ['email'])),
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
            'PUT /members/{id}/rank' => $member,
            'PUT /members/{id}/titles' => $member,
            'PUT /members/{id}/companies' => $member,
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
            'PUT /me/mail' => $self,
            'PUT /me/signature' => $self,
            'DELETE /me/signature' => $self,
            // Rollen
            'POST /admin/roles' => $role,
            'PATCH /admin/roles/{key}' => $role,
            'DELETE /admin/roles/{key}' => $role,
            // Einstellungen: nur Felder mit Anzeigenamen (SETTING_FIELDS); Geheimes nur als „geändert“ (secrets)
            'PATCH /admin/settings' => $general,
            'PATCH /admin/mail' => $this->settings('E-Mail', '/admin/mail', ['SMTP-Passwort' => fn (): mixed => $this->app->settings->get('smtp_password', '')]),
            'PUT /admin/registration' => $this->settings('Zugang beantragen', '/admin/registration'),
            'PATCH /admin/push' => $this->settings('Benachrichtigungen', '/admin/push'),
            'PATCH /admin/birthdays' => $this->settings('Geburtstage', '/admin/birthdays'),
            'PUT /admin/navigation' => $this->settings('Navigation', '/admin/navigation'),
            'PATCH /admin/website' => $this->settings('Webseite', '/admin/website'),
            'POST /admin/website/entry' => $this->settings('Webseite', '/admin/website'),
            'PUT /admin/features' => $this->values('Bereiche', function (): array {
                $out = [];
                $data = ($this->get)('/admin/features');
                foreach (is_array($data) ? $data : [] as $f) {
                    if (is_array($f) && is_string($f['label'] ?? null)) {
                        $out[$f['label']] = (bool) ($f['enabled'] ?? false);
                    }
                }
                return $out;
            }),
            'POST /admin/maintenance' => $this->settings('Wartungsmodus', '/admin/maintenance'),
            'PATCH /admin/update' => $this->values('Updates', function (): array {
                $data = ($this->get)('/admin/update');
                $data = is_array($data) ? $data : [];
                $channel = is_string($data['channel'] ?? null) ? $data['channel'] : '';
                return [
                    'Kanal' => self::VALUE_LABELS['Kanal'][$channel] ?? $channel,
                    'Automatisch aktualisieren' => $data['auto'] ?? null,
                    'Statusmeldungen senden' => $this->app->statusReporter()->enabled(),
                ];
            }),
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
            'PUT /attendance/groups' => $this->values('Gruppen der Anwesenheit', function (): array {
                $groups = $this->app->attendance()->groups();
                $labels = array_column($groups, 'label', 'key');
                $out = [];
                foreach ($groups as $g) {
                    $out[$g['label']] = $g['counts_as'] === null ? 'eigene Gruppe' : 'zählt als ' . ($labels[$g['counts_as']] ?? $g['counts_as']);
                }
                return $out;
            }),
            'POST /admin/google/connect' => $general,
            'POST /admin/google/disconnect' => $general,
            'DELETE /admin/error-log' => $this->event('Einstellungen', 'Fehlerprotokoll', 'delete'),
            'DELETE /admin/update/held' => $this->event('Einstellungen', 'Zurückgestelltes Update', 'delete'),
            'POST /admin/backup/server/delete' => $this->event('Sicherung', fn (Request $r): string => 'Sicherung ' . (is_string($r->input('id')) ? $r->input('id') : ''), 'delete'),
            // Updates und Sicherungen (ohne vergleichbaren Stand)
            'POST /admin/update/install' => $this->event('Einstellungen', static fn (Request $r): string => 'Update auf Version ' . (is_string($r->input('version')) ? $r->input('version') : '') . ' eingespielt', 'event'),
            'POST /admin/update/upload/install' => $this->event('Einstellungen', 'Hochgeladenes Update eingespielt', 'event'),
            'POST /admin/update/held' => $this->event('Einstellungen', 'Zurückgestelltes Update eingespielt', 'event'),
            'POST /admin/update/rollback' => $this->event('Einstellungen', static fn (Request $r): string => 'Zurück auf Version ' . (is_string($r->input('version')) ? $r->input('version') : ''), 'event'),
            'POST /admin/backup' => $this->event('Einstellungen', 'Sicherung aus einer Datei wiederhergestellt', 'event'),
            'POST /admin/backup/server/restore' => $this->event('Einstellungen', static fn (Request $r): string => 'Sicherung ' . (is_string($r->input('id')) ? $r->input('id') . ' ' : '') . 'vom Server wiederhergestellt', 'event'),
            'POST /admin/backup/server/create' => $this->event('Einstellungen', 'Sicherung auf dem Server erstellt', 'event'),
            'POST /admin/backup/drive/upload' => $this->event('Einstellungen', 'Sicherung in Google Drive hochgeladen', 'event'),
            'POST /admin/setup/done' => $this->event('Einstellungen', 'Ersteinrichtung abgeschlossen', 'event'),
        ];
        // Anlegen, Ändern und Löschen in den Bereichen
        foreach ($this->areas() as $key => $subject) {
            $map[$key] = $subject;
        }
        return $map;
    }

    /**
     * Anlegen, Ändern und Löschen in den Bereichen. Jeder Eintrag einer Tabelle hat eine Beschreibung (spec), die für alle
     * seine Routen gilt; Anlegen erkennt neue Zeilen über den Vergleich aller IDs (auch, wenn eine Route mehrere anlegt
     * oder die Adresse die ID eines anderen Eintrags trägt, z. B. die Gruppe einer neuen Amtszeit).
     *
     * @return array<string, Subject>
     */
    private function areas(): array
    {
        $eventCols = ['title' => 'Titel', 'start_date' => 'Beginn', 'start_time' => 'Beginn (Uhrzeit)', 'end_date' => 'Ende', 'end_time' => 'Ende (Uhrzeit)', 'location' => 'Ort', 'description' => 'Beschreibung', 'url' => 'Link'];
        $years = static fn (array $r): string => trim($r['start_year'] . ($r['end_year'] !== null && $r['end_year'] !== '' && $r['end_year'] !== $r['start_year'] ? '–' . $r['end_year'] : ''));
        $holders = fn (string $where, mixed $id): array => $this->people($this->app->db->select('SELECT user_id, name FROM {office_holders} WHERE ' . $where . ' ORDER BY sort, id', [$id]));

        $events = $this->spec('Termin', 'events', 'title', $eventCols);
        $calendar = $this->spec('Termin im Kalender', 'internal_events', 'title', $eventCols);
        $news = $this->spec('News', 'app_news', 'title', ['title' => 'Titel', 'blocks' => ['Inhalt', 'blocks'], 'cover' => ['Titelbild', 'secret']]);
        $ranks = $this->spec('Dienstgrad', 'ranks', 'title', ['title' => 'Bezeichnung', 'min_points' => 'Ab Punkten', 'active' => ['Aktiv', 'bool'], 'badge_version' => ['Abzeichen', 'secret']]);
        $titles = $this->spec('Titel', 'titles', 'title', ['title' => 'Bezeichnung', 'active' => ['Aktiv', 'bool']]);
        $officeGroups = $this->spec('Ämtergruppe', 'office_groups', 'title', ['title' => 'Bezeichnung', 'singular' => 'Einzahl', 'joint_terms' => ['Gemeinsame Amtszeiten', 'bool']]);
        $groupTerms = $this->spec('Amtszeit der Gruppe', 'office_group_terms', fn (array $r): string => $this->lookup('office_groups', 'title', $r['group_id']) . ' ' . $years($r), [
            'kind' => 'Art', 'start_year' => 'Von', 'end_year' => 'Bis', 'note' => 'Notiz', 'image_uid' => ['Bild', 'secret'],
        ], extra: fn (array $r): array => ['Inhaber' => $holders('term_id IN (SELECT id FROM {office_terms} WHERE group_term_id = ?)', $r['id'])]);
        $offices = $this->spec('Amt', 'offices', 'title', ['title' => 'Bezeichnung', 'singular' => 'Einzahl', 'group_id' => ['Gruppe', 'lookup:office_groups.title'], 'title_id' => ['Titel', 'lookup:titles.title']]);
        $officeTerms = $this->spec('Amtszeit', 'office_terms', fn (array $r): string => $this->lookup('offices', 'title', $r['office_id']) . ' ' . $years($r), [
            'kind' => 'Art', 'start_year' => 'Von', 'end_year' => 'Bis', 'note' => 'Notiz', 'honor' => ['Ehrenamt', 'bool'],
        ], extra: fn (array $r): array => ['Inhaber' => $holders('term_id = ?', $r['id'])]);
        $pages = $this->spec('Seite', 'app_pages', 'title', ['title' => 'Titel', 'blocks' => ['Inhalt', 'blocks'], 'audience' => 'Sichtbar für']);
        $sponsors = $this->spec('Sponsor', 'sponsors', 'name', ['name' => 'Name', 'url' => 'Link', 'logo_version' => ['Logo', 'secret']]);
        $companies = $this->spec('Kompanie', 'companies', 'name', [
            'name' => 'Name', 'short_name' => 'Kurzname', 'founded_year' => 'Gegründet', 'description' => 'Beschreibung', 'website_url' => 'Webseite',
            'app_url' => 'App', 'office_group_id' => ['Ämtergruppe', 'lookup:office_groups.title'], 'active' => ['Aktiv', 'bool'], 'logo_version' => ['Logo', 'secret'],
        ], extra: fn (array $r): array => ['Mitglieder' => $this->names('SELECT user_id FROM {member_companies} WHERE company_id = ?', $r['id'])]);
        $lists = $this->spec('Liste', 'lists', 'title', ['title' => 'Titel', 'subtitle' => 'Untertitel', 'list_date' => 'Datum', 'orientation' => 'Format'], extra: fn (array $r): array => [
            'Spalten' => array_map(static fn (array $c): string => (string) ($c['label'] !== null && $c['label'] !== '' ? $c['label'] : $c['field']), $this->app->db->select('SELECT label, field FROM {list_columns} WHERE list_id = ? ORDER BY sort, id', [$r['id']])),
            'Zeilen' => $this->people($this->app->db->select('SELECT user_id, name FROM {list_rows} WHERE list_id = ? ORDER BY sort, id', [$r['id']])),
        ]);
        $documents = $this->spec('Dokument', 'documents', 'title', ['title' => 'Titel', 'category_id' => ['Kategorie', 'lookup:doc_categories.title'], 'doc_date' => 'Datum', 'visibility' => 'Sichtbarkeit', 'ext' => 'Dateityp', 'version' => ['Datei', 'secret']]);
        $docCategories = $this->spec('Kategorie der Dokumente', 'doc_categories', 'title', ['title' => 'Bezeichnung']);
        $board = $this->spec('Nachricht am Brett', 'board_messages', 'title', ['title' => 'Titel', 'blocks' => ['Inhalt', 'blocks'], 'expires_at' => 'Sichtbar bis', 'audience' => 'Für', 'audience_ids' => ['Für (Auswahl)', 'json']]);
        $polls = $this->spec('Umfrage', 'polls', 'title', [
            'title' => 'Titel', 'description' => 'Beschreibung', 'type' => 'Art', 'anonymous' => ['Anonym', 'bool'], 'results' => 'Ergebnisse sichtbar', 'ends_at' => 'Ende', 'closed_at' => 'Beendet',
        ], extra: fn (array $r): array => ['Antworten' => $this->column('SELECT title FROM {poll_options} WHERE poll_id = ? ORDER BY sort, id', $r['id'])]);
        $inventory = $this->spec('Inventar', 'inventory_items', 'title', ['title' => 'Bezeichnung', 'category' => 'Kategorie', 'quantity' => 'Menge', 'unit' => 'Einheit', 'location' => 'Lagerort', 'note' => 'Notiz']);
        $attendance = $this->spec('Anwesenheitsliste', 'attendance_lists', 'title', ['title' => 'Titel', 'event_date' => 'Datum'], extra: function (array $r): array {
            $entries = $this->app->db->select('SELECT user_id, status FROM {attendance_entries} WHERE list_id = ? ORDER BY user_id', [$r['id']]);
            $names = $this->people($entries);
            return ['Einträge' => array_map(static fn (array $e, string $name): string => $name . ': ' . (self::ATTENDANCE[$e['status']] ?? $e['status']), $entries, $names)];
        });
        $points = $this->spec('Punkte', 'points', fn (array $r): string => sprintf('%s Punkte für %s', $r['points'], $this->name((int) $r['user_id'])), [
            'user_id' => ['Mitglied', 'user'], 'points' => 'Punkte', 'reason' => 'Grund', 'entry_date' => 'Datum', 'catalog_id' => ['Aus dem Katalog', 'lookup:point_catalog.title'],
        ], subject: 'user_id');
        $penalties = $this->spec('Strafe', 'penalties', fn (array $r): string => sprintf('%s für %s', self::euro($r['amount_cents']), $this->name((int) $r['user_id'])), [
            'user_id' => ['Mitglied', 'user'], 'amount_cents' => ['Betrag', 'cents'], 'reason' => 'Grund', 'entry_date' => 'Datum', 'status' => 'Status', 'paid_at' => 'Bezahlt am',
        ], subject: 'user_id');
        $catalog = $this->spec('Eintrag im Punktekatalog', 'point_catalog', 'title', ['title' => 'Bezeichnung', 'points' => 'Punkte', 'active' => ['Aktiv', 'bool']]);
        $submissions = $this->spec('Einreichung für die Galerie', 'gallery_submissions', 'name', [
            'name' => 'Datei', 'user_id' => ['Von', 'user'], 'album_id' => ['Album', 'lookup:albums.title'], 'status' => 'Status', 'reason' => 'Begründung',
        ], subject: 'user_id');
        $photos = $this->spec('Bild der Galerie', 'photos', 'name', ['name' => 'Datei', 'album_id' => ['Album', 'lookup:albums.title'], 'is_intern' => ['Intern', 'bool']]);
        $albums = $this->spec('Album', 'albums', 'title', ['title' => 'Titel', 'year' => 'Jahr']);
        $mailings = $this->spec('E-Mail', 'mailings', 'subject', ['subject' => 'Betreff', 'body' => ['Text', 'html'], 'selection' => ['Empfänger', 'json']]);
        $reports = $this->spec('Fehlermeldung', 'report_inbox', static function (array $r): string {
            $report = json_decode((string) $r['report'], true);
            $text = is_array($report) && is_string($report['description'] ?? null) ? $report['description'] : '';
            return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . ' …' : $text;
        }, ['status' => 'Status', 'reason' => 'Begründung'], subject: 'user_id');

        $map = [];
        // Einträge mit Anlegen (POST), Ändern (PATCH bzw. PUT) und Löschen; null = die Route gibt es nicht
        foreach ([
            [$events, 'POST /events', 'PATCH /events/{id}', 'DELETE /events/{id}'],
            [$calendar, 'POST /calendar/entries', 'PATCH /calendar/entries/{id}', 'DELETE /calendar/entries/{id}'],
            [$news, 'POST /news', 'PATCH /news/{id}', 'DELETE /news/{id}'],
            [$ranks, 'POST /ranks', 'PATCH /ranks/{id}', 'DELETE /ranks/{id}'],
            [$titles, 'POST /titles', 'PATCH /titles/{id}', 'DELETE /titles/{id}'],
            [$officeGroups, 'POST /office-groups', 'PATCH /office-groups/{id}', 'DELETE /office-groups/{id}'],
            [$groupTerms, 'POST /office-groups/{id}/terms', 'PATCH /office-group-terms/{id}', 'DELETE /office-group-terms/{id}'],
            [$offices, 'POST /offices', 'PATCH /offices/{id}', 'DELETE /offices/{id}'],
            [$officeTerms, 'POST /offices/{id}/terms', 'PATCH /office-terms/{id}', 'DELETE /office-terms/{id}'],
            [$pages, 'POST /pages', 'PATCH /pages/{id}', 'DELETE /pages/{id}'],
            [$sponsors, 'POST /sponsors', 'PATCH /sponsors/{id}', 'DELETE /sponsors/{id}'],
            [$companies, 'POST /companies', 'PATCH /companies/{id}', 'DELETE /companies/{id}'],
            [$lists, 'POST /lists', 'PUT /lists/{id}', 'DELETE /lists/{id}'],
            [$documents, 'POST /documents', 'PATCH /documents/{id}', 'DELETE /documents/{id}'],
            [$docCategories, 'POST /document-categories', 'PATCH /document-categories/{id}', 'DELETE /document-categories/{id}'],
            [$board, 'POST /board', 'PATCH /board/{id}', 'DELETE /board/{id}'],
            [$polls, 'POST /polls', 'PATCH /polls/{id}', 'DELETE /polls/{id}'],
            [$inventory, 'POST /inventory', 'PATCH /inventory/{id}', 'DELETE /inventory/{id}'],
            [$points, 'POST /points', null, 'DELETE /points/{id}'],
            [$penalties, 'POST /penalties', 'PATCH /penalties/{id}', 'DELETE /penalties/{id}'],
            [$catalog, 'POST /catalog', 'PATCH /catalog/{id}', null],
            [$submissions, 'POST /gallery/submissions', null, 'DELETE /gallery/submissions/{id}'],
            [$photos, 'POST /admin/gallery/albums/{id}/photos', 'PATCH /gallery/photos/{id}', 'DELETE /admin/gallery/photos/{id}'],
            [$albums, 'POST /admin/gallery/albums', 'PATCH /admin/gallery/albums/{id}', 'DELETE /admin/gallery/albums/{id}'],
            [$mailings, 'POST /mails', null, null],
        ] as [$spec, $create, $update, $delete]) {
            if ($create !== null) {
                $map[$create] = $this->table($spec, static fn (): array => [], true);
            }
            foreach ([$update, $delete] as $key) {
                if ($key !== null) {
                    $map[$key] = $this->table($spec);
                }
            }
        }
        // Weitere Änderungen an einem Eintrag (Adresse mit seiner ID)
        foreach ([
            'POST /ranks/{id}/badge' => $ranks, 'DELETE /ranks/{id}/badge' => $ranks,
            'POST /sponsors/{id}/logo' => $sponsors, 'DELETE /sponsors/{id}/logo' => $sponsors,
            'POST /companies/{id}/logo' => $companies, 'DELETE /companies/{id}/logo' => $companies, 'PUT /companies/{id}/members' => $companies,
            'POST /documents/{id}/file' => $documents,
            'POST /lists/{id}/sync-members' => $lists,
            'POST /polls/{id}/close' => $polls,
            'POST /inventory/{id}/move' => $inventory,
            'POST /gallery/review/{id}/approve' => $submissions, 'POST /gallery/review/{id}/reject' => $submissions, 'POST /gallery/review/{id}/remove' => $submissions,
            'POST /reports/review/{id}/close' => $reports, 'POST /reports/review/{id}/forward' => $reports,
        ] as $key => $spec) {
            $map[$key] = $this->table($spec);
        }
        // Mehrere Bilder auf einmal; Kopie einer Liste (die Adresse trägt die ID der Vorlage)
        $map['POST /gallery/photos/move'] = $this->table($photos, Subject::fromBody('ids'));
        $map['POST /gallery/photos/delete'] = $this->table($photos, Subject::fromBody('ids'));
        $map['POST /lists/{id}/copy'] = $this->table($lists, static fn (): array => [], true);
        // Ein Video: als Einreichung oder, wer die Galerie verwaltet, direkt als Bild
        $map['POST /gallery/video-uploads/finish'] = $this->either($submissions, $photos);
        // Anwesenheit: eine Liste je Termin des Kalenders oder eine freie Liste; ohne ID die des Termins
        $map['PUT /attendance'] = $this->table($attendance, function (Request $r, mixed $result): array {
            $ids = Subject::fromBody('id')($r, $result);
            if (is_array($result) && isset($result['id']) && is_int($result['id'])) {
                $ids[] = (string) $result['id'];
            }
            $key = $r->body['event_key'] ?? null;
            if ($ids === [] && is_string($key) && $key !== '') {
                $id = $this->app->db->scalar('SELECT id FROM {attendance_lists} WHERE source = ? AND event_key = ?', ['calendar', trim($key)]);
                if (is_scalar($id)) {
                    $ids[] = (string) $id;
                }
            }
            return array_values(array_unique($ids));
        }, true);
        $map['DELETE /attendance/{id}'] = $this->table($attendance);
        // Ämter: Namen mit Mitgliedern verbinden, Import aus einer CSV-Datei (ohne apply nur die Vorschau, ändert nichts)
        $map['POST /offices/link'] = $this->table($this->spec('Amtsinhaber', 'office_holders', 'name', ['name' => 'Name', 'user_id' => ['Mitglied', 'user']]), function (Request $r): array {
            $name = $r->input('name');
            return is_string($name) && $name !== '' ? $this->column('SELECT id FROM {office_holders} WHERE name = ?', $name) : [];
        });
        // Der Import legt auch Gruppen und Ämter an, die es noch nicht gibt
        $none = static fn (): array => [];
        $map['POST /offices/import'] = $this->combine(['g' => $this->table($officeGroups, $none, true), 'o' => $this->table($offices, $none, true), 't' => $this->table($officeTerms, $none, true)]);
        // Mailgruppen
        $map['POST /mails/groups'] = $this->mailGroup(true);
        $map['PATCH /mails/groups/{key}'] = $this->mailGroup();
        $map['DELETE /mails/groups/{key}'] = $this->mailGroup();
        // Reihenfolgen
        $map['PUT /ranks/order'] = $this->order('Reihenfolge der Dienstgrade', 'ranks', 'title');
        $map['PUT /titles/order'] = $this->order('Reihenfolge der Titel', 'titles', 'title');
        $map['PUT /office-groups/order'] = $this->order('Reihenfolge der Ämtergruppen', 'office_groups', 'title');
        $map['PUT /offices/order'] = $this->order('Reihenfolge der Ämter', 'offices', 'title', 'group_id, ');
        $map['PUT /pages/order'] = $this->order('Reihenfolge der Seiten', 'app_pages', 'title');
        $map['PUT /sponsors/order'] = $this->order('Reihenfolge der Sponsoren', 'sponsors', 'name');
        $map['PUT /companies/order'] = $this->order('Reihenfolge der Kompanien', 'companies', 'name');
        $map['PUT /document-categories/order'] = $this->order('Reihenfolge der Kategorien', 'doc_categories', 'title');
        $map['PUT /catalog/order'] = $this->order('Reihenfolge des Punktekatalogs', 'point_catalog', 'title');
        $map['PUT /admin/roles/order'] = $this->values('Reihenfolge der Rollen', fn (): array => ['Reihenfolge' => array_values(array_map(static fn (array $r): string => (string) $r['label'], $this->app->roles()->all()))]);
        return $map;
    }

    /**
     * Beschreibung der Einträge einer Tabelle.
     *
     * @param string|\Closure(array<string, mixed>): string $label Spalte oder Funktion für die Bezeichnung
     * @param array<string, string|array{0: string, 1: string}> $columns Spalte => Anzeigename oder [Anzeigename, Art]; Arten:
     *        bool, user (Konto-ID), cents, html, blocks (Inhalt aus Blöcken, mit body), json (Liste), lookup:tabelle.spalte,
     *        secret (nur „geändert“)
     * @param list<string> $personal Spalten mit persönlichen Angaben
     * @param string|null $subject Spalte mit dem Konto, um das es geht
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $extra weitere Felder aus anderen Tabellen
     * @return array{type: string, table: string, label: string|\Closure, columns: array<string, string|array{0: string, 1: string}>, personal: list<string>, subject: ?string, extra: ?\Closure}
     */
    private function spec(string $type, string $table, string|\Closure $label, array $columns, array $personal = [], ?string $subject = null, ?\Closure $extra = null): array
    {
        return ['type' => $type, 'table' => $table, 'label' => $label, 'columns' => $columns, 'personal' => $personal, 'subject' => $subject, 'extra' => $extra];
    }

    /**
     * Eine Zeile aus einer Tabelle der App; nur Spalten mit Anzeigenamen kommen ins Protokoll.
     *
     * @param array{type: string, table: string, label: string|\Closure, columns: array<string, string|array{0: string, 1: string}>, personal: list<string>, subject: ?string, extra: ?\Closure} $spec
     * @param (\Closure(Request, mixed): list<string>)|null $ids Standard: die ID in der Adresse und in der Antwort
     * @param bool $all neue Zeilen über den Vergleich aller IDs vorher und nachher erkennen
     */
    private function table(array $spec, ?\Closure $ids = null, bool $all = false): Subject
    {
        $table = $spec['table'];
        $load = function (string $id) use ($spec, $table): ?Item {
            $row = $this->app->db->selectOne('SELECT * FROM {' . $table . '} WHERE id = ?', [$id]);
            return $row === null ? null : $this->rowItem($spec, $row);
        };
        // Viele auf einmal (z. B. Punkte für alle): eine Abfrage je 200 IDs, Namen der Konten zusammen
        $loadMany = function (array $ids) use ($spec, $table): array {
            $rows = [];
            foreach (array_chunk($ids, 200) as $chunk) {
                array_push($rows, ...$this->app->db->select('SELECT * FROM {' . $table . '} WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk));
            }
            $users = [];
            foreach ($spec['columns'] as $col => $def) {
                if (is_array($def) && $def[1] === 'user') {
                    array_push($users, ...array_map('intval', array_column($rows, $col)));
                }
            }
            $this->prefetch($users);
            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row['id']] = $this->rowItem($spec, $row);
            }
            return $out;
        };
        $allIds = $all ? fn (): array => $this->column('SELECT id FROM {' . $table . '}') : null;
        return new Subject($load, $ids ?? Subject::fromPath(), $allIds, loadMany: $loadMany);
    }

    /**
     * @param array{type: string, table: string, label: string|\Closure, columns: array<string, string|array{0: string, 1: string}>, personal: list<string>, subject: ?string, extra: ?\Closure} $spec
     * @param array<string, mixed> $row
     */
    private function rowItem(array $spec, array $row): Item
    {
        $id = (string) $row['id'];
        $fields = [];
        $personalLabels = [];
        $secret = [];
        foreach ($spec['columns'] as $col => $def) {
            [$name, $kind] = is_array($def) ? $def : [$def, 'text'];
            $fields[$name] = $this->format($row[$col] ?? null, $kind, $row);
            if (in_array($col, $spec['personal'], true)) {
                $personalLabels[] = $name;
            }
            if ($kind === 'secret') {
                $secret[] = $name;
            }
        }
        if ($spec['extra'] !== null) {
            $fields += ($spec['extra'])($row);
        }
        $label = $spec['label'];
        $text = is_string($label) ? (string) ($row[$label] ?? '') : $label($row);
        $text = trim(strip_tags($text));
        $subject = $spec['subject'];
        return new Item($spec['type'], $id, $text !== '' ? $text : '#' . $id, $fields, $personalLabels, $secret, $subject !== null && isset($row[$subject]) && (int) $row[$subject] > 0 ? (int) $row[$subject] : null);
    }

    /**
     * Neue Einträge in einer von zwei Tabellen (IDs mit Vorsilbe a: bzw. b:).
     *
     * @param array{type: string, table: string, label: string|\Closure, columns: array<string, string|array{0: string, 1: string}>, personal: list<string>, subject: ?string, extra: ?\Closure} $a
     * @param array{type: string, table: string, label: string|\Closure, columns: array<string, string|array{0: string, 1: string}>, personal: list<string>, subject: ?string, extra: ?\Closure} $b
     */
    private function either(array $a, array $b): Subject
    {
        $sa = $this->table($a);
        $sb = $this->table($b);
        return new Subject(
            static fn (string $id): ?Item => str_starts_with($id, 'a:') ? ($sa->load)(substr($id, 2)) : (str_starts_with($id, 'b:') ? ($sb->load)(substr($id, 2)) : null),
            static fn (): array => [],
            fn (): array => [
                ...array_map(static fn (string $id): string => 'a:' . $id, $this->column('SELECT id FROM {' . $a['table'] . '}')),
                ...array_map(static fn (string $id): string => 'b:' . $id, $this->column('SELECT id FROM {' . $b['table'] . '}')),
            ],
        );
    }

    /** Reihenfolge der Einträge einer Tabelle (Spalte sort) als Einstellung. */
    private function order(string $label, string $table, string $column, string $groupBy = ''): Subject
    {
        return $this->values($label, fn (): array => ['Reihenfolge' => $this->column('SELECT ' . $column . ' FROM {' . $table . '} ORDER BY ' . $groupBy . 'sort, id')]);
    }

    /**
     * Erste Spalte einer Abfrage als Texte.
     *
     * @return list<string>
     */
    private function column(string $sql, mixed ...$params): array
    {
        return array_values(array_map(static fn (array $r): string => (string) reset($r), $this->app->db->select($sql, $params)));
    }

    /**
     * Namen der Konten aus einer Abfrage (erste Spalte: Konto-ID).
     *
     * @return list<string>
     */
    private function names(string $sql, mixed ...$params): array
    {
        $names = $this->people(array_map(static fn (string $id): array => ['user_id' => (int) $id], $this->column($sql, ...$params)));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
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
        $fields['Dienstgrad'] = $this->column('SELECT r.title FROM {member_ranks} m JOIN {ranks} r ON r.id = m.rank_id WHERE m.user_id = ?', $id)[0] ?? null;
        $fields['Titel'] = $this->column('SELECT t.title FROM {member_titles} m JOIN {titles} t ON t.id = m.title_id WHERE m.user_id = ? ORDER BY t.sort, t.id', $id);
        $fields['Kompanien'] = $this->column('SELECT c.name FROM {member_companies} m JOIN {companies} c ON c.id = m.company_id WHERE m.user_id = ? ORDER BY c.sort, c.id', $id);
        $fields['Mails vom Brett'] = $this->app->boardMail()->wants($id);
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

    /** @param bool $all neue Gruppen über den Vergleich aller Schlüssel erkennen */
    private function mailGroup(bool $all = false): Subject
    {
        $load = function (string $key): ?Item {
            $g = $this->app->mailGroups()->find($key);
            if ($g === null) {
                return null;
            }
            return new Item('Mailgruppe', $key, $g['label'], ['Bezeichnung' => $g['label'], 'Rollen' => $g['roles'], 'Mitglieder' => array_map($this->name(...), $g['users']), 'Adressen' => $g['emails']], ['Adressen']);
        };
        $keys = $all ? fn (): array => array_map(static fn (array $g): string => (string) $g['key'], $this->app->mailGroups()->all()) : null;
        return new Subject($load, $all ? static fn (): array => [] : Subject::fromPath('key'), $keys);
    }

    /**
     * Allgemeine Einstellungen (Google-Zugang, Kalender, Galerie, News, Rechtliches); bei der Galerie als Quelle nur der
     * Server verschwinden die Alben aus Google Drive (GalleryService::reset), sie kommen als gelöscht hinein.
     */
    private function generalSettings(): Subject
    {
        $settings = $this->settings('Allgemeine Einstellungen', '/admin/settings', [
            'Google-Clientschlüssel' => fn (): mixed => $this->oauthPart('client_secret'),
            'Google-Verbindung' => fn (): mixed => $this->oauthPart('refresh_token'),
            'Dienstkonto' => fn (): mixed => $this->app->settings->get(\KompanieApp\Core\Domain\DriveClient::SETTING, ''),
            'iCal-Adresse' => fn (): mixed => $this->app->settings->get('calendar_ics_url', ''),
            'Google-API-Schlüssel' => fn (): mixed => $this->app->settings->get('google_api_key', ''),
        ]);
        $albums = $this->table($this->spec('Album', 'albums', 'title', ['title' => 'Titel', 'year' => 'Jahr']), fn (): array => $this->column('SELECT id FROM {albums} WHERE source = ?', 'drive'));
        return $this->combine(['s' => $settings, 'a' => $albums]);
    }

    private function oauthPart(string $key): string
    {
        $o = $this->app->settings->get(\KompanieApp\Core\Domain\DriveClient::OAUTH_SETTING);
        return is_array($o) && is_string($o[$key] ?? null) ? $o[$key] : '';
    }

    /**
     * Mehrere Arten in einer Route (IDs mit Vorsilbe je Teil, z. B. s: und a:).
     *
     * @param array<string, Subject> $parts Vorsilbe => Subject
     */
    private function combine(array $parts): Subject
    {
        $split = static function (string $id) use ($parts): ?array {
            $pos = strpos($id, ':');
            $prefix = $pos === false ? '' : substr($id, 0, $pos);
            return isset($parts[$prefix]) ? [$parts[$prefix], substr($id, (int) $pos + 1)] : null;
        };
        $hasAll = array_filter($parts, static fn (Subject $p): bool => $p->all !== null) !== [];
        return new Subject(
            static function (string $id) use ($split): ?Item {
                $p = $split($id);
                return $p === null ? null : ($p[0]->load)($p[1]);
            },
            static function (Request $r, mixed $result) use ($parts): array {
                $out = [];
                foreach ($parts as $prefix => $p) {
                    foreach (($p->ids)($r, $result) as $id) {
                        $out[] = $prefix . ':' . $id;
                    }
                }
                return $out;
            },
            $hasAll ? static function () use ($parts): array {
                $out = [];
                foreach ($parts as $prefix => $p) {
                    foreach ($p->all !== null ? ($p->all)() : [] as $id) {
                        $out[] = $prefix . ':' . $id;
                    }
                }
                return $out;
            } : null,
            loadMany: static function (array $ids) use ($parts): array {
                $by = [];
                foreach ($ids as $id) {
                    $pos = strpos($id, ':');
                    if ($pos !== false) {
                        $by[substr($id, 0, $pos)][] = substr($id, $pos + 1);
                    }
                }
                $out = [];
                foreach ($by as $prefix => $list) {
                    if (!isset($parts[$prefix])) {
                        continue;
                    }
                    foreach ($parts[$prefix]->loadAll($list) as $id => $item) {
                        if ($item !== null) {
                            $out[$prefix . ':' . $id] = $item;
                        }
                    }
                }
                return $out;
            },
        );
    }

    /**
     * Einstellungen aus der Antwort der GET-Route (verschachtelte Schlüssel mit Punkt): nur Felder aus $fields, mit
     * Anzeigenamen aus SETTINGS_FIELDS (Zähler, Zustände und Hinweise fehlen absichtlich). $secrets: Werte, die nie ins Protokoll kommen; ihr
     * Vergleich geht über eine Prüfsumme, im Eintrag steht nur „geändert“.
     *
     * @param array<string, \Closure(): mixed> $secrets Anzeigename => Wert
     */
    private function settings(string $label, string $getPath, array $secrets = []): Subject
    {
        return $this->values($label, function () use ($getPath, $secrets): array {
            $data = ($this->get)($getPath);
            $flat = is_array($data) ? self::flatten($data) : [];
            $out = [];
            foreach (self::SETTINGS_FIELDS[$getPath] ?? [] as $key => $name) {
                $v = $flat[$key] ?? null;
                if (is_string($v) && in_array($name, self::ROLE_FIELDS, true)) {
                    $v = $this->app->roles()->label($v);
                }
                $out[$name] = is_string($v) && isset(self::VALUE_LABELS[$name][$v]) ? self::VALUE_LABELS[$name][$v] : $v;
            }
            foreach ($secrets as $name => $read) {
                $v = $read();
                $out[$name] = is_string($v) && trim($v) !== '' ? hash('sha256', $v) : null;
            }
            return $out;
        }, array_keys($secrets));
    }

    /**
     * Schlüssel der GET-Routen der Einstellungen, die nicht ins Protokoll kommen (Zustand, Zähler, Hinweise, feste Listen
     * oder als Geheimnis erfasst). Ein Test prüft, dass jeder andere Schlüssel einen Anzeigenamen hat.
     *
     * @return array<string, list<string>> GET-Pfad => Schlüssel (Präfix mit Punkt am Ende gilt für alle darunter)
     */
    public static function settingsIgnored(): array
    {
        return [
            '/admin/settings' => [
                'google.redirect_uri', 'google.client_secret_set', 'google.last_result', 'google.calendar_access',
                'calendar.connection_needs_calendar_access', 'calendar.ics_url_set', 'calendar.google_api_key_set', 'gallery.configured',
                'gallery.media_bytes', 'gallery.service_account_email', 'gallery.oauth_connected', 'gallery.oauth_email', 'gallery.calendar_access',
                'gallery.status.', 'news.sources',
            ],
            '/admin/mail' => ['from_name_default', 'password_set', 'configured', 'unavailable', 'server_max_size', 'pending'],
            '/admin/registration' => ['pending', 'link'],
            '/admin/push' => ['supported', 'missing', 'accounts', 'guests', 'queued', 'log.', 'devices', 'subscriptions', 'counts'],
            '/admin/navigation' => ['targets', 'icons', 'max_menu_items', 'label_max'],
            '/admin/maintenance' => ['since', 'by', 'log'],
            '/admin/backup/drive' => ['available', 'access', 'encrypted', 'email', 'folder_url', 'last_ok', 'last_try', 'error', 'last_upload', 'last_upload.', 'every_days', 'keep'],
            '/admin/backup/server' => ['available', 'can_restore', 'last_ok', 'last_try', 'error', 'every_days', 'keep', 'files'],
            '/admin/former/retention' => ['due', 'soon'],
            // Startseite als Titel (home_page_title), Liste und Hinweise ändern sich nicht durch die Route
            '/admin/website' => ['home_page_id', 'home_page_missing', 'pages', 'pages_enabled', 'url', 'entry.file', 'entry.folder', 'entry.content', 'entry.message'],
        ];
    }

    /** @param \Closure(): mixed $read */
    private function value(string $label, string $field, \Closure $read): Subject
    {
        return $this->values($label, static fn (): array => [$field => $read()]);
    }

    /**
     * @param \Closure(): array<string, mixed> $read
     * @param list<string> $secret Felder, deren Werte nie ins Protokoll kommen
     */
    private function values(string $label, \Closure $read, array $secret = []): Subject
    {
        return new Subject(static fn (): Item => new Item('Einstellungen', '', $label, $read(), [], $secret), static fn (): array => ['']);
    }

    /**
     * Etwas ohne vergleichbaren Stand (geleert, entfernt, eingerichtet). Mit der Aktion event ist $label der ganze Satz
     * (z. B. „Sicherung wiederhergestellt“).
     *
     * @param string|\Closure(Request): string $label
     * @param 'create'|'update'|'delete'|'event' $action
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

    /** @param array<string, mixed> $row */
    private function format(mixed $v, string $kind, array $row): mixed
    {
        if ($kind === 'blocks') {
            // Inhalt aus Blöcken als reiner Text (ältere Einträge: nur body)
            $text = $this->app->blocks()->plain(Blocks::decode($v, (string) ($row['body'] ?? '')), true);
            return trim((string) preg_replace('/\s+/u', ' ', $text)) ?: null;
        }
        if ($v === null || $v === '') {
            return null;
        }
        if (str_starts_with($kind, 'lookup:')) {
            [$table, $column] = explode('.', substr($kind, 7), 2);
            return $this->lookup($table, $column, $v);
        }
        return match ($kind) {
            'bool' => (bool) (int) $v,
            'user' => $this->name((int) $v),
            'cents' => self::euro($v),
            'html' => self::plain((string) $v),
            'json' => self::jsonList($v),
            default => is_scalar($v) ? (string) $v : null,
        };
    }

    /** Liste oder Objekt aus JSON als Text („a, b“ bzw. „schlüssel: wert“) */
    private static function jsonList(mixed $v): ?string
    {
        $data = is_string($v) ? json_decode($v, true) : null;
        if (!is_array($data)) {
            return is_scalar($v) ? (string) $v : null;
        }
        $parts = [];
        foreach ($data as $k => $x) {
            $text = is_array($x) ? (string) json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($x) ? ($x ? 'ja' : 'nein') : (string) $x);
            $parts[] = is_int($k) ? $text : $k . ': ' . $text;
        }
        return $parts === [] ? null : implode(', ', $parts);
    }

    private function name(int $id): string
    {
        if (!isset($this->names[$id])) {
            $this->prefetch([$id]);
        }
        return $this->names[$id] ?? 'gelöschtes Konto';
    }

    /**
     * Namen mehrerer Konten mit einem Abruf merken (statt einem je Person).
     *
     * @param list<int> $ids
     */
    private function prefetch(array $ids): void
    {
        $missing = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0 && !isset($this->names[$id]))));
        if ($missing === []) {
            return;
        }
        foreach ($this->app->users->findMany($missing) as $id => $user) {
            $this->names[(int) $id] = $user->name;
        }
        foreach ($missing as $id) {
            $this->names[$id] ??= 'gelöschtes Konto';
        }
    }

    /**
     * Mitglieder oder freie Namen (Zeilen mit user_id und name), z. B. Inhaber eines Amts, Zeilen einer Liste.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function people(array $rows): array
    {
        $this->prefetch(array_map(static fn (array $r): int => (int) ($r['user_id'] ?? 0), $rows));
        return array_map(fn (array $r): string => (int) ($r['user_id'] ?? 0) > 0 ? $this->name((int) $r['user_id']) : (string) ($r['name'] ?? ''), $rows);
    }

    private function lookup(string $table, string $column, mixed $id): string
    {
        if ($id === null || $id === '' || !is_scalar($id)) {
            return '';
        }
        $key = $table . '.' . $column . '.' . $id;
        if (!isset($this->lookups[$key])) {
            $v = $this->app->db->scalar('SELECT ' . $column . ' FROM {' . $table . '} WHERE id = ?', [$id]);
            $this->lookups[$key] = is_scalar($v) ? (string) $v : '';
        }
        return $this->lookups[$key];
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
