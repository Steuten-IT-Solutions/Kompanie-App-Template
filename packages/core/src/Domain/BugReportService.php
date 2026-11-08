<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Update\StatusReporter;

/**
 * Fehlermeldungen aus der App (Issue #64, Wire-Format in docs/FEHLERMELDUNGEN.md).
 *
 * Wer etwas meldet (auch ohne Anmeldung), stimmt im Formular zu, dass die Meldung mit den angezeigten Angaben an die
 * Admins der App geht und von ihnen an die Betreuer der App weitergeleitet werden darf. Unabhängig von der Zustimmung zu
 * den Statusmeldungen: Jede Meldung hat ihre eigene Einwilligung.
 *
 * Ablauf: Formular öffnen (GET /reports/form: Zeitmarke und die Angaben, die der Server beifügt) → absenden
 * (POST /reports) → Eingang in der App (Tabelle report_inbox, nicht in Sicherungen); alle mit review_reports bekommen
 * eine Benachrichtigung → die Admins schließen die Meldung mit Begründung oder leiten sie weiter. Erst Weiterleiten reiht
 * sie in die Warteschlange (Tabelle bug_reports) ein; die Aufgabe bug_reports (stündlich, nach dem Weiterleiten
 * vorgezogen) sendet sie signiert an das Cockpit, das daraus ein Issue in einem privaten Repository macht. Ohne Adresse
 * des Empfängers (reportUrl in update.json) landen Meldungen nur bei den Admins, Weiterleiten geht dann nicht.
 *
 * Wer angemeldet meldet, sieht seine Meldungen mit Stand und Begründung (GET /reports/mine) und bekommt eine
 * Benachrichtigung, wenn sie geschlossen oder weitergeleitet wird. Die Admins sehen den Namen angemeldeter Personen
 * immer; „Meinen Namen mitschicken“ gilt für das Weiterleiten an die Betreuer.
 *
 * Gegen Spam ohne Captcha: verstecktes Feld (Honeypot), Zeitfalle (signierte Zeitmarke beim Öffnen, frühestens nach
 * MIN_SECONDS absendbar), Begrenzung je Anfragendem (IP als Hash, IPv6 je /64) und je Installation, voller Eingang.
 */
final class BugReportService
{
    public const TASK = 'bug_reports';
    public const INTERVAL = 3600;
    /** Installations-ID, die der Empfänger der Fehlermeldungen bestätigt hat (danach kein Geheimnis mehr im Feld key). */
    public const REGISTERED_SETTING = 'bug_reports_registered';

    public const MAX_DESCRIPTION = 2000;
    public const MAX_EXPECTED = 2000;
    public const MAX_SCREENSHOT_BYTES = 2 * 1024 * 1024;
    /** Längste Kante und Fläche eines Bildschirmfotos (Pixel): große Bildschirme ja, Bildbomben nein. */
    public const MAX_EDGE = 10000;
    public const MAX_PIXELS = 40_000_000;
    /** Zeitfalle: frühestens so viele Sekunden nach dem Öffnen des Formulars, spätestens nach MAX_FORM_AGE. */
    public const MIN_SECONDS = 3;
    public const MAX_FORM_AGE = 6 * 3600;
    /** Je Anfragendem (IP-Hash) und Stunde bzw. je Installation und Tag */
    public const CLIENT_RATE = 5;
    public const DAILY_RATE = 30;
    /** Höchstens so viele Meldungen warten auf den Versand. */
    public const MAX_QUEUED = 50;
    /** Nicht zugestellte Meldungen verfallen so viele Tage nach dem Weiterleiten. */
    public const KEEP_DAYS = 7;
    /** Eingang in der App: Stand einer Meldung */
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const FORWARDED = 'forwarded';
    /** Höchstens so viele offene Meldungen im Eingang (danach 503 reports_full). */
    public const MAX_OPEN = 100;
    /** Geschlossene und weitergeleitete Meldungen bleiben so viele Tage in der Liste. */
    public const KEEP_DONE_DAYS = 30;
    public const MAX_REASON = 500;
    /** Benachrichtigungen zum Eingang einmalig auf bestehenden Geräten eingeschaltet. */
    public const SETUP_SETTING = 'report_inbox_setup';
    /** Je Lauf höchstens so viele Meldungen (jede bis 2 MB) */
    private const PER_RUN = 10;
    private const TIMEOUT = 20;
    private const MAX_PAGE = 200;
    private const MAX_AGENT = 400;
    private const MAX_ERROR = 500;
    /** Erkennung am Dateianfang: Typ => [Signatur, Name für die Meldung] */
    private const TYPES = [
        'image/png' => "\x89PNG\r\n\x1a\n",
        'image/jpeg' => "\xFF\xD8\xFF",
        'image/webp' => 'RIFF',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** Ob die Admins Meldungen an die Betreuer weiterleiten können (nur mit Adresse des Empfängers). */
    public function forwardable(): bool
    {
        return $this->url() !== '';
    }

    /**
     * Beim Update einmalig: die neuen Arten der Benachrichtigungen auf den Geräten einschalten, die sie bekommen (wie bei
     * neuen Abos, die alle angebotenen Arten bekommen).
     */
    public function initialize(): void
    {
        if ($this->app->settings->get(self::SETUP_SETTING, false)) {
            return;
        }
        $this->app->settings->set(self::SETUP_SETTING, true);
        foreach ([PushService::TYPE_REPORTS_REVIEW, PushService::TYPE_REPORTS] as $type) {
            try {
                $this->app->push()->offerTypeToExisting($type);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Benachrichtigungen „' . $type . '“ nicht eingeschaltet: ' . $e->getMessage());
            }
        }
    }

    /**
     * Zum Öffnen des Formulars: Zeitmarke für die Zeitfalle und die Angaben, die der Server beifügt (zeigt die App vor
     * dem Absenden an).
     *
     * @return array{token: string, minSeconds: int, maxBytes: int, forward: bool, details: array{version: string, role: string}}
     */
    public function form(?User $user): array
    {
        $now = $this->app->time()->timestamp();
        return [
            'token' => $this->trap()->token($now),
            'minSeconds' => self::MIN_SECONDS,
            'maxBytes' => self::MAX_SCREENSHOT_BYTES,
            // Ob die Admins weiterleiten können (für den Text der Einwilligung)
            'forward' => $this->forwardable(),
            'details' => ['version' => $this->app->env->version(), 'role' => $this->roleLabel($user)],
        ];
    }

    /**
     * Nimmt eine Meldung an und legt sie in den Eingang der Admins. Antwortet erst, wenn sie gespeichert ist.
     *
     * @param array<string, mixed> $in
     * @return array{queued: bool}
     */
    public function submit(?User $user, array $in, string $client): array
    {
        // Honeypot: Menschen sehen das Feld nicht. Antwort wie bei Erfolg, damit Programme nichts lernen.
        if (FormTrap::honeypot($in)) {
            return ['queued' => true];
        }
        $this->trap()->check($in['token'] ?? null, $this->app->time()->timestamp());
        if (($in['consent'] ?? null) !== true) {
            throw ApiError::invalid('Bitte bestätige, dass die Meldung an die Admins und, wenn nötig, an die Betreuer der App gehen darf.');
        }
        $description = self::text($in['description'] ?? null, 0, true);
        if ($description === '') {
            throw ApiError::invalid('Bitte beschreibe kurz, was nicht klappt.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw ApiError::invalid(sprintf('Die Beschreibung darf höchstens %d Zeichen lang sein.', self::MAX_DESCRIPTION));
        }
        $expected = self::text($in['expected'] ?? null, 0, true);
        if (mb_strlen($expected) > self::MAX_EXPECTED) {
            throw ApiError::invalid(sprintf('„Was hast du erwartet?“ darf höchstens %d Zeichen lang sein.', self::MAX_EXPECTED));
        }
        $includeName = ($in['includeName'] ?? false) === true;
        if ($includeName && $user === null) {
            throw ApiError::invalid('Ohne Anmeldung kann kein Name mitgeschickt werden.');
        }
        [$screenshot, $type] = $this->validScreenshot($in['screenshot'] ?? null);

        $this->limit($client);
        $now = $this->app->time()->timestamp();
        $report = [
            'description' => $description,
            'expected' => $expected,
            'page' => self::text($in['page'] ?? null, self::MAX_PAGE, false),
            'version' => $this->app->env->version(),
            'variant' => $this->app->env->variant(),
            'userAgent' => self::text($in['userAgent'] ?? null, self::MAX_AGENT, false),
            'role' => $this->roleLabel($user),
            // Für die Admins immer (angemeldet); an die Betreuer nur mit share_name (forward()).
            'name' => $user?->name,
            'lastError' => self::text($in['lastError'] ?? null, self::MAX_ERROR, false) ?: null,
            'created' => $now,
        ];
        $id = $this->app->db->insert('report_inbox', [
            'uuid' => bin2hex(random_bytes(16)),
            // Wer gemeldet hat: sieht die Meldung unter „Meine Meldungen“; sie wird mit dem Konto gelöscht (UserData).
            'user_id' => $user?->id ?? 0,
            'share_name' => $includeName ? 1 : 0,
            'report' => self::json($report),
            'screenshot' => $screenshot,
            'screenshot_type' => $type,
            'status' => self::OPEN,
            'created_at' => $now,
        ]);
        $this->notifyReviewers($user, (int) $id);
        return ['queued' => true];
    }

    /** Offene Meldungen im Eingang (Zähler in der Verwaltung). */
    public function openCount(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {report_inbox} WHERE status = ?', [self::OPEN]);
    }

    /**
     * Eingang für die Admins (review_reports): offene zuerst, dann die erledigten der letzten KEEP_DONE_DAYS Tage, je
     * die neuesten oben. Ohne Bildschirmfotos (die einzeln über screenshotOf()).
     *
     * @return array{forward: bool, reports: list<array<string, mixed>>}
     */
    public function review(): array
    {
        $rows = $this->app->db->select(
            'SELECT id, user_id, share_name, report, screenshot_type, status, reason, reviewed_by, reviewed_at, created_at FROM {report_inbox}'
            . ' ORDER BY CASE WHEN status = ? THEN 0 ELSE 1 END, id DESC',
            [self::OPEN],
        );
        $users = $this->app->users->findMany(array_values(array_unique(array_filter(array_merge(
            array_map(static fn (array $r): int => (int) $r['user_id'], $rows),
            array_map(static fn (array $r): int => (int) ($r['reviewed_by'] ?? 0), $rows),
        )))));
        return ['forward' => $this->forwardable(), 'reports' => array_map(fn (array $r): array => $this->reviewItem($r, $users), $rows)];
    }

    /** @return array{type: string, data: string} Bildschirmfoto einer Meldung (Base64) */
    public function screenshotOf(int $id): array
    {
        $row = $this->app->db->selectOne('SELECT screenshot, screenshot_type FROM {report_inbox} WHERE id = ?', [$id]);
        if ($row === null || !is_string($row['screenshot']) || $row['screenshot'] === '') {
            throw ApiError::notFound('Zu dieser Meldung gibt es kein Bildschirmfoto (mehr).');
        }
        return ['type' => (string) $row['screenshot_type'], 'data' => $row['screenshot']];
    }

    /**
     * Schließen mit Begründung (Pflicht): z. B. gelöst, kein Fehler, doppelt. Das Bildschirmfoto wird gelöscht; wer
     * angemeldet gemeldet hat, sieht die Begründung und bekommt eine Benachrichtigung.
     *
     * @return array<string, mixed> die Meldung wie in review()
     */
    public function close(User $actor, int $id, mixed $reason): array
    {
        $row = $this->openRow($id);
        $reason = self::text($reason, 0, true);
        if ($reason === '') {
            throw ApiError::invalid('Bitte schreibe kurz, warum die Meldung geschlossen wird.');
        }
        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ApiError::invalid(sprintf('Die Begründung darf höchstens %d Zeichen lang sein.', self::MAX_REASON));
        }
        $this->app->db->update('report_inbox', [
            'status' => self::CLOSED, 'reason' => $reason, 'reviewed_by' => $actor->id, 'reviewed_at' => $this->app->time()->timestamp(),
            'screenshot' => null, 'screenshot_type' => '',
        ], ['id' => $id]);
        $this->notifyReporter($row, sprintf('Deine Fehlermeldung ist erledigt: %s', $reason));
        return $this->item($id);
    }

    /**
     * An die Betreuer weiterleiten: reiht die Meldung in bug_reports ein (der Versand wie bisher); daraus wird beim
     * Cockpit ein Issue. Der Name geht nur mit, wenn die Person es beim Melden wollte.
     *
     * @return array<string, mixed> die Meldung wie in review()
     */
    public function forward(User $actor, int $id): array
    {
        if (!$this->forwardable()) {
            throw new ApiError(409, 'reports_not_forwardable', 'In dieser App ist kein Empfänger für Fehlermeldungen eingerichtet. Weiterleiten geht deshalb nicht.');
        }
        $row = $this->openRow($id);
        if ($this->queued() >= self::MAX_QUEUED) {
            throw new ApiError(503, 'reports_full', 'Zurzeit warten zu viele Meldungen auf den Versand. Bitte versuche es später erneut.');
        }
        $full = $this->app->db->selectOne('SELECT screenshot FROM {report_inbox} WHERE id = ?', [$id]);
        $report = self::decode((string) $row['report']);
        $share = (int) $row['share_name'] === 1 && (int) $row['user_id'] > 0;
        $report['name'] = $share ? ($report['name'] ?? null) : null;
        $now = $this->app->time()->timestamp();
        $db = $this->app->db;
        $db->transaction(function () use ($db, $row, $report, $share, $full, $actor, $id, $now): void {
            $db->insert('bug_reports', [
                'uuid' => (string) $row['uuid'],
                // Nur mit Namen: Dann gehört die Meldung zum Konto und wird mit ihm gelöscht (UserData).
                'user_id' => $share ? (int) $row['user_id'] : 0,
                'report' => self::json($report),
                'screenshot' => $full['screenshot'] ?? null,
                'screenshot_type' => (string) $row['screenshot_type'],
                'attempts' => 0,
                'last_error' => '',
                // Ab dem Weiterleiten zählen die KEEP_DAYS bis zum Verwerfen.
                'created_at' => $now,
            ]);
            $db->update('report_inbox', [
                'status' => self::FORWARDED, 'reviewed_by' => $actor->id, 'reviewed_at' => $now, 'screenshot' => null,
            ], ['id' => $id]);
        });
        try {
            $this->app->scheduler->runSoon(self::TASK);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Versand der Fehlermeldung nicht vorgezogen: ' . $e->getMessage());
        }
        $this->notifyReporter($row, 'Deine Fehlermeldung ist an die Betreuer der App weitergeleitet.');
        return $this->item($id);
    }

    /**
     * Eigene Meldungen (angemeldet) mit Stand und Begründung, die neuesten zuerst.
     *
     * @return list<array{id: int, status: string, description: string, created: int, reason: string|null, reviewed_at: int|null}>
     */
    public function mine(User $user): array
    {
        $out = [];
        foreach ($this->app->db->select('SELECT id, report, status, reason, reviewed_at, created_at FROM {report_inbox} WHERE user_id = ? ORDER BY id DESC', [$user->id]) as $r) {
            $report = self::decode((string) $r['report']);
            $out[] = [
                'id' => (int) $r['id'],
                'status' => (string) $r['status'],
                'description' => (string) ($report['description'] ?? ''),
                'created' => (int) $r['created_at'],
                'reason' => (string) $r['status'] === self::CLOSED ? (string) $r['reason'] : null,
                'reviewed_at' => $r['reviewed_at'] !== null ? (int) $r['reviewed_at'] : null,
            ];
        }
        return $out;
    }

    /** Wartende Meldungen (für Tests und die Statusseite). */
    public function queued(): int
    {
        return (int) $this->app->db->scalar('SELECT COUNT(*) FROM {bug_reports}');
    }

    /**
     * Stündliche Aufgabe, nach dem Weiterleiten vorgezogen: erledigte Meldungen im Eingang nach KEEP_DONE_DAYS löschen,
     * abgelaufene in der Warteschlange verwerfen, dann die ältesten senden.
     * Angenommene (2xx) und endgültig abgelehnte (4xx außer 401, 409 unknown_installation, 429) werden gelöscht, die
     * übrigen beim nächsten Lauf erneut versucht. Bei Netz- oder Serverfehlern hört der Lauf auf (das Cockpit ist
     * dann vermutlich nicht erreichbar).
     */
    public function runTask(): void
    {
        $db = $this->app->db;
        $now = $this->app->time()->timestamp();
        // Eingang: Erledigte nach KEEP_DONE_DAYS löschen (offene bleiben, bis die Admins sie bearbeiten).
        $db->execute('DELETE FROM {report_inbox} WHERE status <> ? AND reviewed_at < ?', [self::OPEN, $now - self::KEEP_DONE_DAYS * 86400]);
        $db->execute('DELETE FROM {bug_reports} WHERE created_at < ?', [$now - self::KEEP_DAYS * 86400]);
        if (!$this->forwardable()) {
            return;
        }
        $failure = null;
        foreach ($db->select('SELECT id FROM {bug_reports} ORDER BY id LIMIT ' . self::PER_RUN) as $row) {
            // Einzeln lesen: höchstens ein Bildschirmfoto im Speicher.
            $r = $db->selectOne('SELECT * FROM {bug_reports} WHERE id = ?', [(int) $row['id']]);
            if ($r === null) {
                continue;
            }
            [$done, $stop, $error] = $this->send($r);
            if ($done) {
                $db->execute('DELETE FROM {bug_reports} WHERE id = ?', [(int) $r['id']]);
                if ($error !== '') {
                    error_log('Kompanie-App: Fehlermeldung verworfen: ' . $error);
                }
                continue;
            }
            $db->update('bug_reports', ['attempts' => (int) $r['attempts'] + 1, 'last_error' => mb_substr($error, 0, 255)], ['id' => (int) $r['id']]);
            $failure ??= $error;
            if ($stop) {
                break;
            }
        }
        if ($failure !== null) {
            throw new \RuntimeException($failure);
        }
    }

    /**
     * Genau das, was an das Cockpit geht (ohne key).
     *
     * @param array<string, mixed> $row Zeile aus bug_reports
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        $report = json_decode((string) $row['report'], true);
        $report = is_array($report) ? $report : [];
        $shot = is_string($row['screenshot'] ?? null) && $row['screenshot'] !== '' ? ['type' => (string) $row['screenshot_type'], 'data' => $row['screenshot']] : null;
        return [
            'format' => 1,
            'installation' => $this->app->statusReporter()->installationId(),
            'kompanie' => (string) ($this->app->packageConfig['kompanie'] ?? ''),
            'sent' => $this->app->time()->timestamp(),
            'nonce' => bin2hex(random_bytes(8)),
            'report' => ['id' => (string) $row['uuid']] + $report,
            'screenshot' => $shot,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: bool, 1: bool, 2: string} erledigt (löschen), Lauf beenden, Fehlermeldung
     */
    private function send(array $row): array
    {
        $s = $this->app->settings;
        $reporter = $this->app->statusReporter();
        $data = $this->payload($row);
        $id = (string) $data['installation'];
        // Vertrauen beim ersten Kontakt wie bei den Statusmeldungen, aber eigener Stand: Das Cockpit lernt das
        // Geheimnis auch, wenn diese Installation keine Statusmeldungen sendet.
        $withKey = $s->get(self::REGISTERED_SETTING) !== $id;
        if ($withKey) {
            $data['key'] = $reporter->secret();
        }
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        unset($data);
        try {
            $res = $this->app->http->request('POST', $this->url(), [
                'Content-Type' => 'application/json',
                StatusReporter::HEADER => StatusReporter::sign($body, $reporter->secret()),
            ], $body, self::TIMEOUT);
        } catch (\Throwable $e) {
            return [false, true, 'Fehlermeldung nicht gesendet: ' . $e->getMessage()];
        }
        $status = (int) $res['status'];
        if ($status >= 200 && $status < 300) {
            if ($withKey) {
                $s->set(self::REGISTERED_SETTING, $id);
            }
            return [true, false, ''];
        }
        $answer = json_decode((string) $res['body'], true);
        $code = is_array($answer) ? (string) ($answer['code'] ?? '') : '';
        $error = 'Fehlermeldung nicht angenommen (HTTP ' . $status . ($code !== '' ? ', ' . $code : '') . ').';
        if ($status === 409 && $code === 'unknown_installation') {
            $s->delete(self::REGISTERED_SETTING);
            return [false, false, $error];
        }
        if ($status === 401 || ($status === 409 && $code === 'replay')) {
            return [false, false, $error];
        }
        if ($status === 429 || $status >= 500) {
            return [false, true, $error];
        }
        // Sonstige 4xx: so nie annehmbar (ungültig, zu groß, Kompanie unbekannt) – verwerfen.
        return [true, false, $error];
    }

    /** Begrenzung je Anfragendem, je Installation und Größe des Eingangs. */
    private function limit(string $client): void
    {
        $cache = $this->app->cache;
        if ($cache->increment('bug_report_c_' . hash('sha256', PushService::clientKey($client)), 3600) > self::CLIENT_RATE
            || $cache->increment('bug_report_day', 86400) > self::DAILY_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Meldungen in kurzer Zeit. Bitte versuche es später erneut.');
        }
        if ($this->openCount() >= self::MAX_OPEN) {
            throw new ApiError(503, 'reports_full', 'Zurzeit können keine weiteren Meldungen angenommen werden. Bitte versuche es später erneut.');
        }
    }

    /** @return array<string, mixed> offene Meldung (ohne Bildschirmfoto) */
    private function openRow(int $id): array
    {
        $row = $this->app->db->selectOne('SELECT id, uuid, user_id, share_name, report, screenshot_type, status FROM {report_inbox} WHERE id = ?', [$id]);
        if ($row === null) {
            throw ApiError::notFound('Meldung nicht gefunden.');
        }
        if ((string) $row['status'] !== self::OPEN) {
            throw new ApiError(409, 'report_done', 'Diese Meldung ist schon bearbeitet.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function item(int $id): array
    {
        $row = $this->app->db->selectOne('SELECT id, user_id, share_name, report, screenshot_type, status, reason, reviewed_by, reviewed_at, created_at FROM {report_inbox} WHERE id = ?', [$id]) ?? [];
        $users = $this->app->users->findMany(array_values(array_filter([(int) ($row['user_id'] ?? 0), (int) ($row['reviewed_by'] ?? 0)])));
        return $this->reviewItem($row, $users);
    }

    /**
     * @param array<string, mixed> $r Zeile aus report_inbox
     * @param array<int, User> $users
     * @return array<string, mixed>
     */
    private function reviewItem(array $r, array $users): array
    {
        $report = self::decode((string) ($r['report'] ?? ''));
        $uid = (int) ($r['user_id'] ?? 0);
        $by = (int) ($r['reviewed_by'] ?? 0);
        return [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'description' => (string) ($report['description'] ?? ''),
            'expected' => (string) ($report['expected'] ?? ''),
            'page' => (string) ($report['page'] ?? ''),
            'version' => (string) ($report['version'] ?? ''),
            'variant' => (string) ($report['variant'] ?? ''),
            'user_agent' => (string) ($report['userAgent'] ?? ''),
            'role' => (string) ($report['role'] ?? ''),
            'last_error' => isset($report['lastError']) ? (string) $report['lastError'] : null,
            // Wer gemeldet hat (null ohne Anmeldung); der aktuelle Name, sonst der beim Melden
            'user_id' => $uid > 0 ? $uid : null,
            'name' => $uid > 0 ? ($users[$uid]->name ?? (isset($report['name']) ? (string) $report['name'] : null)) : null,
            'share_name' => (int) $r['share_name'] === 1,
            'screenshot' => (string) $r['screenshot_type'] !== '' && (string) $r['status'] === self::OPEN,
            'created' => (int) $r['created_at'],
            'reason' => (string) $r['status'] === self::CLOSED ? (string) $r['reason'] : null,
            'reviewed_by' => $by > 0 ? ($users[$by]->name ?? null) : null,
            'reviewed_at' => $r['reviewed_at'] !== null ? (int) $r['reviewed_at'] : null,
        ];
    }

    private function notifyReviewers(?User $user, int $id): void
    {
        try {
            $this->app->push()->notifyAudience(
                PushService::TYPE_REPORTS_REVIEW,
                PushService::message('Neue Fehlermeldung', $user !== null ? sprintf('%s hat einen Fehler gemeldet.', $user->name) : 'Jemand hat ohne Anmeldung einen Fehler gemeldet.', 'verwaltung/meldungen', 'meldungen'),
                // Wer selbst meldet, braucht keinen Hinweis auf die eigene Meldung.
                static fn (int $uid): bool => $uid !== ($user?->id ?? 0),
            );
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zu Fehlermeldung ' . $id . ' nicht eingereiht: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $row */
    private function notifyReporter(array $row, string $body): void
    {
        $uid = (int) $row['user_id'];
        if ($uid <= 0) {
            return;
        }
        try {
            $this->app->push()->notifyUser($uid, PushService::TYPE_REPORTS, PushService::message('Deine Fehlermeldung', $body, 'hilfe/melden', 'meldung-' . $row['id']));
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zu Fehlermeldung ' . $row['id'] . ' nicht eingereiht: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }

    /** Zeitfalle und Honeypot (gemeinsam mit der Selbstregistrierung). */
    private function trap(): FormTrap
    {
        return new FormTrap($this->app->statusReporter()->secret(), 'bug-report-form', self::MIN_SECONDS, self::MAX_FORM_AGE);
    }

    /**
     * Bildschirmfoto (optional): Base64 (auch als data:-Adresse), PNG, JPEG oder WebP, echtes Bild mit sinnvollen Maßen.
     *
     * @return array{0: string|null, 1: string} Base64 und Typ, oder null und ''
     */
    private function validScreenshot(mixed $in): array
    {
        if ($in === null || $in === '') {
            return [null, ''];
        }
        $data = is_array($in) ? ($in['data'] ?? null) : $in;
        $data = is_string($data) ? $data : '';
        if (str_starts_with($data, 'data:') && ($comma = strpos($data, ',')) !== false) {
            $data = substr($data, $comma + 1);
        }
        // Grobe Grenze vor dem Dekodieren (Base64 ist ein Drittel größer).
        if (strlen($data) > intdiv(self::MAX_SCREENSHOT_BYTES * 4, 3) + 8) {
            throw ApiError::invalid('Das Bildschirmfoto darf höchstens 2 MB groß sein.');
        }
        $bytes = $data === '' ? false : base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw ApiError::invalid('Das Bildschirmfoto konnte nicht gelesen werden.');
        }
        if (strlen($bytes) > self::MAX_SCREENSHOT_BYTES) {
            throw ApiError::invalid('Das Bildschirmfoto darf höchstens 2 MB groß sein.');
        }
        $type = null;
        foreach (self::TYPES as $mime => $signature) {
            if (str_starts_with($bytes, $signature) && ($mime !== 'image/webp' || substr($bytes, 8, 4) === 'WEBP')) {
                $type = $mime;
            }
        }
        $info = $type !== null ? @getimagesizefromstring($bytes) : false;
        if ($type === null || $info === false || ($info['mime'] ?? '') !== $type) {
            throw ApiError::invalid('Als Bildschirmfoto gehen nur PNG-, JPEG- oder WebP-Bilder.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_EDGE || $info[1] > self::MAX_EDGE || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw ApiError::invalid('Das Bildschirmfoto hat ungewöhnliche Maße. Bitte ein normales Bildschirmfoto wählen.');
        }
        // Neu kodiert statt der Eingabe: sicher gültiges Base64 ohne Zeilenumbrüche.
        return [base64_encode($bytes), $type];
    }

    private function roleLabel(?User $user): string
    {
        return $user === null ? 'ohne Anmeldung' : $this->app->roles()->label($user->role);
    }

    /** Text ohne Steuerzeichen (Zeilenumbrüche nur mehrzeilig), Zeilenenden vereinheitlicht, außen ohne Leerraum. */
    private static function text(mixed $value, int $max, bool $multiline): string
    {
        if (!is_string($value)) {
            return '';
        }
        $s = mb_scrub(str_replace(["\r\n", "\r"], "\n", $value), 'UTF-8');
        $s = (string) preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $s);
        $s = trim($s);
        return $max > 0 ? mb_substr($s, 0, $max) : $s;
    }

    private function url(): string
    {
        return StatusReporter::safeUrl($this->app->packageConfig['reportUrl'] ?? '');
    }
}
