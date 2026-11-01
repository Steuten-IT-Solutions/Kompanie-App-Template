<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\Sql\Schema;
use KompanieApp\Core\Storage\UserData;

/**
 * Auskunft nach Art. 15 DSGVO: alle gespeicherten Daten einer Person als JSON, für sie selbst (Konto → Meine Daten) und
 * für alle, die ihr Konto verwalten (MemberService::guardAccount) und alle darin enthaltenen Bereiche sehen dürfen
 * (OTHERS_REQUIRE), z. B. für eine Anfrage per Brief.
 *
 *   {format: "kompanie-app-personal-data", formatVersion: 1, created, person: {...}, data: {tabelle: [zeilen]}}
 *
 * Die Tabellen kommen aus UserData::tables(), derselben Liste wie beim Löschen; dazu Push-Abos (und wartende
 * Benachrichtigungen), Ämter und für sie beendete Amtszeiten, Zeilen in Listen,
 * eigene Änderungen am Schwarzen Brett, eigene Bilder (Signatur, Entwürfe) und Anhänge von Entwürfen (Angaben zur Datei).
 * Ausgelassen:
 * - Schlüssel (Push: p256dh, auth), Pseudonyme (vote_ref) und Ablageorte (Anhänge: file); die Fassungen des Fotos (es
 *   steht einmal unter person.photo); Bildschirmfotos wartender Fehlermeldungen (können Daten anderer zeigen), ihr Text
 *   nur beim eigenen Export. Eigene Bilder (Signatur, Entwürfe) sind mit Inhalt dabei.
 * - Antworten in anonymen Umfragen und Entwürfe (Bilder, Anhänge) nur beim eigenen Export: Sonst sähe die Verwaltung,
 *   wer was geantwortet hat, und was in der App nur die Person selbst sieht.
 * - Nutzungsauswertung (activity*) nur beim eigenen Export und für Admins mit view_activity, die sie ohnehin sehen.
 * Was die Person für andere eingetragen hat (created_by, updated_by), gehört nicht dazu.
 */
final class PersonalDataExport
{
    public const FORMAT = 'kompanie-app-personal-data';
    public const FORMAT_VERSION = 1;
    /**
     * Spalten je Tabelle, die nie in die Auskunft gehören (Schlüssel, Pseudonyme, Bildschirmfotos); je Tabelle, damit eine
     * gleich benannte Spalte anderswo nicht still fehlt. member_photos steht ganz unter person.photo.
     */
    private const DROP_COLUMNS = ['poll_answers' => ['vote_ref'], 'push_subscriptions' => ['p256dh', 'auth'], 'bug_reports' => ['screenshot'], 'attachments' => ['file']];
    private const ACTIVITY_TABLES = ['activity', 'activity_last'];
    /**
     * Rechte für die Auskunft über andere: Sie enthält Punkte, Strafen, Dienstgrade, Titel, Anwesenheit, namentliche
     * Umfragen, E-Mails, Listen, Änderungen am Schwarzen Brett und Ämter; wer davon etwas in der App nicht sehen darf, bekommt es auch so nicht (in der Regel: Admins).
     */
    public const OTHERS_REQUIRE = [
        Capability::CreateUsers, Capability::ViewPersonal, Capability::ManagePoints, Capability::ManagePenalties,
        Capability::ManageRanks, Capability::ManageTitles, Capability::ManageAttendance, Capability::ManagePolls, Capability::SendMail,
        Capability::ManageLists, Capability::PostBoard, Capability::ViewOffices,
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed> */
    public function export(User $actor, int $userId): array
    {
        if ($actor->preview) {
            throw ApiError::forbidden('In der Vorschau gibt es keinen Export.');
        }
        $self = $actor->id === $userId;
        if (!self::mayExport($actor, $userId)) {
            throw ApiError::forbidden('Die Auskunft über andere enthält alle Bereiche (Punkte, Strafen, Anwesenheit, Umfragen, E-Mails, persönliche Angaben); dafür fehlen dir Rechte.');
        }
        $target = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        // Prüft für andere guardAccount (z. B. Konten von Admins nur für Admins).
        $profile = $this->app->members()->profile($userId, $actor);
        unset($profile['can_change_login'], $profile['can_export']);
        $profile = $this->app->members()->personal($userId) + $profile;

        $data = [];
        foreach (UserData::tables() as $table => $column) {
            if (in_array($table, self::ACTIVITY_TABLES, true) && !$self && !$actor->can(Capability::ViewActivity)) {
                continue;
            }
            if ($table === 'member_photos') {
                continue; // unter person.photo
            }
            $sql = 'SELECT * FROM {' . $table . '} WHERE ' . $column . ' = ?';
            $rows = $this->rows($table, $sql, [$userId]);
            if ($table === 'poll_answers' && $self && ($secret = PollService::existingSecret($this->app->settings)) !== null) {
                // Eigene anonyme Antworten (über das Pseudonym; namentliche kamen schon über user_id); nur die Person selbst
                // bekommt sie.
                $rows = [...$rows, ...$this->rows('poll_answers', 'SELECT * FROM {poll_answers} WHERE vote_ref = ? AND user_id IS NULL', [PollService::pseudonym($secret, $userId)])];
            }
            if ($table === 'bug_reports' && !$self) {
                // Wartende Fehlermeldungen sieht in der App niemand (sie gehen an die Betreuer); ihr Text kann Daten anderer
                // enthalten: für andere nur, dass es sie gibt.
                $rows = array_map(static fn (array $r): array => array_diff_key($r, ['report' => 1]), $rows);
            }
            if ($rows !== []) {
                $data[$table] = $rows;
            }
        }
        foreach ([
            'push_subscriptions' => 'SELECT * FROM {push_subscriptions} WHERE user_id = ?',
            'office_holders' => 'SELECT * FROM {office_holders} WHERE user_id = ?',
            // Amtszeiten, die für sie beendet wurden (UserData::purge löst den Verweis)
            'office_terms' => 'SELECT * FROM {office_terms} WHERE ended_for = ?',
            // Noch nicht zugestellte Benachrichtigungen an ihre Geräte: nur in der eigenen Auskunft (wie die Push-Adresse)
            ...($self ? ['push_queue' => 'SELECT q.* FROM {push_queue} q JOIN {push_subscriptions} s ON s.id = q.subscription_id WHERE s.user_id = ?'] : []),
            'list_rows' => 'SELECT * FROM {list_rows} WHERE user_id = ?',
            // Änderungen am Schwarzen Brett, die sie gemacht hat (bleiben beim Löschen als Verlauf der Nachricht)
            'board_message_revisions' => 'SELECT * FROM {board_message_revisions} WHERE user_id = ?',
            // Entwürfe (Bilder, Anhänge) sieht in der App nur die Person selbst (RichImageService, AttachmentService::canView):
            // für andere nur die Signatur.
            'rich_images' => $self
                ? "SELECT * FROM {rich_images} WHERE owner_type IN ('signature', 'draft') AND owner_id = ?"
                : "SELECT * FROM {rich_images} WHERE owner_type = 'signature' AND owner_id = ?",
            // Anhänge noch nicht gesendeter Entwürfe (Angaben zur Datei; gehören ihr wie beim Löschen, AttachmentService::deleteDraftsOf)
            ...($self ? ['attachments' => "SELECT * FROM {attachments} WHERE owner_type = 'draft' AND owner_id = ?"] : []),
        ] as $table => $sql) {
            $rows = $this->rows($table, $sql, [$userId]);
            if ($table === 'push_subscriptions' && !$self) {
                // Die Adresse eines Abos ist ein Zugang beim Push-Dienst: nur in der eigenen Auskunft.
                $rows = array_map(static fn (array $r): array => array_diff_key($r, ['endpoint' => 1, 'endpoint_hash' => 1]), $rows);
            }
            if ($rows !== []) {
                $data[$table] = $rows;
            }
        }

        // „Zuletzt gesehen“ der Nutzungsauswertung (über das Pseudonym, ohne es zu nennen), wie activity nur für sie selbst
        // und wer die Nutzung ohnehin sieht.
        $activitySecret = ActivityService::existingSecret($this->app->settings);
        if ($activitySecret !== null && ($self || $actor->can(Capability::ViewActivity))) {
            $seen = $this->app->db->select('SELECT area, at FROM {activity_seen} WHERE token = ? ORDER BY area', [ActivityService::token($activitySecret, $userId)]);
            if ($seen !== []) {
                $data['activity_seen'] = $seen;
            }
        }

        $photo = $this->app->memberPhotos()->repository();
        // Größte vorhandene Fassung (ältere Fotos haben kein Original und keine 1024er).
        $jpeg = $photo->original($userId) ?? $photo->image($userId, 1024) ?? $photo->image($userId, 480) ?? $photo->image($userId, 128);
        return [
            'format' => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'created' => gmdate('c', $this->app->time()->timestamp()),
            'kompanie' => $this->app->config->name(),
            'person' => ['id' => $userId] + $profile + [
                'level' => $target->role,
                'extra_roles' => $this->app->roles()->extraRolesOf($target),
                // persönlich vergebene bzw. entzogene Rechte (Ausnahmen zur Rolle)
                'overrides' => $this->app->roles()->overridesOf($userId),
                'birthday' => $this->app->birthdays()->of($userId),
                'join_year' => $this->app->joinYears()->of($userId),
                'former' => $this->app->formerMembers()->of($userId),
                // vom Adapter: angelegt am, letzte Anmeldung, Sitzungen (ohne Schlüssel)
                'account' => $this->app->users->accountData($userId),
                'photo' => $jpeg !== null ? ['type' => 'image/jpeg', 'base64' => base64_encode($jpeg)] : null,
            ],
            'data' => $data,
        ];
    }

    /** Darf $actor die Auskunft über $userId abrufen (ohne guardAccount, das prüft export über MemberService::profile)? */
    public static function mayExport(User $actor, int $userId): bool
    {
        return !$actor->preview && ($actor->id === $userId || self::mayExportOthers($actor));
    }

    public static function mayExportOthers(User $actor): bool
    {
        foreach (self::OTHERS_REQUIRE as $cap) {
            if (!$actor->can($cap)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Zeilen ohne die DROP_COLUMNS der Tabelle. Fehler gehen nach oben: Eine Auskunft, der still eine Tabelle fehlt, wäre falsch (die
     * Tabellen legen die Migrationen an, sie fehlen nicht).
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $sql, array $params): array
    {
        $drop = array_flip(self::DROP_COLUMNS[$table] ?? []);
        if ($drop !== [] && str_starts_with($sql, 'SELECT * ')) {
            // Ausgelassene Spalten gar nicht erst laden (z. B. Bildschirmfotos wartender Fehlermeldungen, mehrere MB).
            $columns = array_values(array_diff(Schema::columns($this->app->db, $table), array_keys($drop)));
            $sql = 'SELECT ' . implode(', ', $columns) . ' ' . substr($sql, strlen('SELECT * '));
        }
        return array_map(static fn (array $r): array => array_diff_key($r, $drop), $this->app->db->select($sql, $params));
    }
}
