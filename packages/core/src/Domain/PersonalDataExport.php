<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\UserData;

/**
 * Auskunft nach Art. 15 DSGVO: alle gespeicherten Daten einer Person als JSON, für sie selbst (Konto → Meine Daten) und
 * für alle, die ihr Konto verwalten und ihre persönlichen Angaben sehen dürfen (create_users mit
 * MemberService::guardAccount und view_personal), z. B. für eine Anfrage per Brief.
 *
 *   {format: "kompanie-app-personal-data", formatVersion: 1, created, person: {...}, data: {tabelle: [zeilen]}}
 *
 * Die Tabellen kommen aus UserData::tables(), derselben Liste wie beim Löschen; dazu Push-Abos, Ämter, Zeilen in Listen,
 * eigene Änderungen am Schwarzen Brett, eigene Bilder (Signatur, Entwürfe) und Anhänge von Entwürfen (Angaben zur Datei).
 * Ausgelassen:
 * - Schlüssel (Push: p256dh, auth) und Pseudonyme (vote_ref); die Fassungen des Fotos (es steht einmal unter
 *   person.photo); Bildschirmfotos wartender Fehlermeldungen (können Daten anderer zeigen). Eigene Bilder (Signatur,
 *   Entwürfe) sind mit Inhalt dabei.
 * - Antworten in anonymen Umfragen nur beim eigenen Export: Sonst sähe die Verwaltung, wer was geantwortet hat.
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
    private const DROP_COLUMNS = ['poll_answers' => ['vote_ref'], 'push_subscriptions' => ['p256dh', 'auth'], 'bug_reports' => ['screenshot']];
    private const ACTIVITY_TABLES = ['activity', 'activity_last'];

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
        // Die Auskunft enthält Adresse, Handynummer, E-Mail und Geburtsdatum: für andere nur mit view_personal.
        if (!$self && (!$actor->can(Capability::CreateUsers) || !$actor->can(Capability::ViewPersonal))) {
            throw ApiError::forbidden('Die Daten dieser Person darfst du nicht exportieren.');
        }
        $target = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        // Prüft für andere guardAccount (z. B. Konten von Admins nur für Admins).
        $profile = $this->app->members()->profile($userId, $actor);
        unset($profile['can_change_login']);
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
            if ($rows !== []) {
                $data[$table] = $rows;
            }
        }
        foreach ([
            'push_subscriptions' => 'SELECT * FROM {push_subscriptions} WHERE user_id = ?',
            'office_holders' => 'SELECT * FROM {office_holders} WHERE user_id = ?',
            'list_rows' => 'SELECT * FROM {list_rows} WHERE user_id = ?',
            // Änderungen am Schwarzen Brett, die sie gemacht hat (bleiben beim Löschen als Verlauf der Nachricht)
            'board_message_revisions' => 'SELECT * FROM {board_message_revisions} WHERE user_id = ?',
            'rich_images' => "SELECT * FROM {rich_images} WHERE owner_type IN ('signature', 'draft') AND owner_id = ?",
            // Anhänge noch nicht gesendeter Entwürfe (Angaben zur Datei; gehören ihr wie beim Löschen, AttachmentService::deleteDraftsOf)
            'attachments' => "SELECT * FROM {attachments} WHERE owner_type = 'draft' AND owner_id = ?",
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
                'birthday' => $this->app->birthdays()->of($userId),
                'join_year' => $this->app->joinYears()->of($userId),
                'former' => $this->app->formerMembers()->of($userId),
                'photo' => $jpeg !== null ? ['type' => 'image/jpeg', 'base64' => base64_encode($jpeg)] : null,
            ],
            'data' => $data,
        ];
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
        return array_map(static fn (array $r): array => array_diff_key($r, $drop), $this->app->db->select($sql, $params));
    }
}
