<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Push\PushService;

/**
 * Selbstregistrierung (Issue #314): Personen beantragen in der App selbst einen Zugang, wer Mitglieder anlegen darf
 * (create_users), gibt ihn frei oder lehnt ab. Admins schalten das unter Einstellungen → Zugang beantragen ein.
 *
 * - Bis zur Freigabe gibt es kein Konto, nur einen Antrag (Tabelle registration_requests, nicht in Sicherungen). Die Person
 *   legt im Antrag Benutzername und Passwort fest; gespeichert wird nur der Hash, die Freigabe legt damit das Konto an
 *   (UserDirectory::createWithPasswordHash).
 * - Mit eingerichtetem Mailversand (MailService::configured) bestätigt die Person zuerst ihre E-Mail-Adresse (Link,
 *   CONFIRM_HOURS gültig) und erfährt Freigabe oder Ablehnung per Mail. Ohne Mailversand wartet der Antrag gleich auf
 *   Freigabe; die Person sieht den Stand beim Anmelden (eigenständige Version) bzw. unter „Stand meiner Anfrage“ (POST
 *   /registration/status), und eine Ablehnung wird dafür REJECTED_DAYS aufbewahrt (ohne Name, E-Mail und Nachricht).
 * - Gegen Programme ohne Captcha: Honeypot und Zeitfalle (FormTrap), Begrenzung je Anfragendem und Tag, höchstens MAX_OPEN
 *   offene Anträge. Durch kommt ohnehin nur, wer freigegeben wird (Entscheidung: kein Zugangscode).
 * - Mit Mailversand verrät das Formular nicht, ob es zu einer E-Mail-Adresse schon ein Konto gibt: Die Antwort ist immer
 *   „Bitte bestätige deine E-Mail-Adresse“, die Mail sagt dann, dass es schon ein Konto gibt. Ohne Mailversand ginge das
 *   nicht (die Person erführe nie etwas); dort sagt es das Formular.
 * - Mitglieder ohne Zugang (MemberService::NO_ACCESS, z. B. für Chroniken angelegt): Ihre E-Mail-Adresse und ihr
 *   Benutzername gelten im Formular als frei, damit sich die Person selbst registrieren kann. Beim Freigeben schlägt die
 *   App passende Mitglieder ohne Zugang vor (MemberService::noAccessMatches); mit user_id verbindet approve() den Antrag mit
 *   diesem Mitglied statt ein zweites Konto anzulegen (MemberService::linkRegistration).
 * - Fristen (Aufgabe registrations, stündlich): unbestätigte Anträge nach CONFIRM_HOURS, unbearbeitete nach PENDING_DAYS,
 *   Ablehnungen nach REJECTED_DAYS gelöscht.
 */
final class RegistrationService
{
    public const TASK = 'registrations';
    public const INTERVAL = 3600;

    public const ENABLED_SETTING = 'registration_enabled';
    public const ROLE_SETTING = 'registration_role';

    public const UNCONFIRMED = 'unconfirmed';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';

    public const MIN_SECONDS = 3;
    public const MAX_FORM_AGE = 6 * 3600;
    /** Je Anfragendem (IP-Hash) und Stunde bzw. je Installation und Tag */
    public const CLIENT_RATE = 5;
    public const DAILY_RATE = 30;
    /** Höchstens so viele offene Anträge (unbestätigt oder wartend) gleichzeitig */
    public const MAX_OPEN = 50;
    /** Abfragen „Stand meiner Anfrage“ je Anfragendem in 15 Minuten */
    public const STATUS_RATE = 10;
    public const CONFIRM_HOURS = 24;
    public const PENDING_DAYS = 30;
    public const REJECTED_DAYS = 14;
    public const MESSAGE_MAX = 500;
    public const REASON_MAX = 500;
    /** Mails „nicht bearbeitet“ je Lauf der Aufgabe (Webspace bricht PHP oft nach 30 Sekunden ab) */
    private const EXPIRED_MAILS_PER_RUN = 20;

    public function __construct(private readonly App $app)
    {
    }

    /** Ob Personen einen Zugang beantragen können (Einstellung der Admins). */
    public function enabled(): bool
    {
        return (bool) $this->app->settings->get(self::ENABLED_SETTING, false);
    }

    /** Mit Mailversand: Bestätigung der Adresse und Ergebnis per Mail. */
    public function withMail(): bool
    {
        return $this->app->mail()->configured();
    }

    /** Rolle, die bei der Freigabe vorgeschlagen ist: Mitglied oder eine Rolle für neue Konten (sonst Mitglied). */
    public function defaultRole(): string
    {
        $key = (string) $this->app->settings->get(self::ROLE_SETTING, Roles::MEMBER);
        return self::newAccountRole($this->app->roles()->find($key)) ? $key : Roles::MEMBER;
    }

    /** @param array<string, mixed>|null $role */
    private static function newAccountRole(?array $role): bool
    {
        return $role !== null && ($role['key'] === Roles::MEMBER || ($role['new_accounts'] ?? false));
    }

    private function trap(): FormTrap
    {
        return new FormTrap($this->app->statusReporter()->secret(), 'registration-form', self::MIN_SECONDS, self::MAX_FORM_AGE);
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled()) {
            throw new ApiError(404, 'registration_unavailable', 'In dieser App kann man zurzeit keinen Zugang beantragen.');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Antrag                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Zum Öffnen des Formulars: Zeitmarke und was das Formular zeigt.
     *
     * @return array{token: string, minSeconds: int, email_confirmation: bool, privacy_url: string|null}
     */
    public function form(): array
    {
        $this->requireEnabled();
        $privacy = $this->app->settingsService()->legal()['privacy_url'];
        return [
            'token' => $this->trap()->token($this->app->time()->timestamp()),
            'minSeconds' => self::MIN_SECONDS,
            'email_confirmation' => $this->withMail(),
            'privacy_url' => $privacy !== '' ? $privacy : null,
        ];
    }

    /**
     * Antrag annehmen. Mit Mailversand: Status unconfirmed und Mail mit dem Link zum Bestätigen; ohne: gleich pending.
     *
     * @param array<string, mixed> $in first_name, last_name, email, username, password, message, consent, token, website
     * @return array{status: string}
     */
    public function submit(?User $user, array $in, string $client): array
    {
        $this->requireEnabled();
        if ($user !== null) {
            throw ApiError::invalid('Du bist schon angemeldet.');
        }
        $mail = $this->withMail();
        $answer = ['status' => $mail ? 'confirm_email' : self::PENDING];
        if (FormTrap::honeypot($in)) {
            return $answer;
        }
        $now = $this->app->time()->timestamp();
        $this->trap()->check($in['token'] ?? null, $now);
        $first = Text::line($in['first_name'] ?? null, PersonName::MAX);
        $last = Text::line($in['last_name'] ?? null, PersonName::MAX);
        if ($first === '' || $last === '') {
            throw ApiError::invalid('Bitte Vor- und Nachnamen angeben.');
        }
        if (mb_strlen(PersonName::join($first, $last)) > PersonName::MAX) {
            throw ApiError::invalid(sprintf('Vor- und Nachname sind zusammen zu lang (höchstens %d Zeichen).', PersonName::MAX));
        }
        $email = trim(is_string($in['email'] ?? null) ? $in['email'] : '');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > MemberService::EMAIL_MAX) {
            throw ApiError::invalid('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        $login = trim(is_string($in['username'] ?? null) ? $in['username'] : '');
        if (!Passwords::validLogin($login)) {
            throw ApiError::invalid('Der Benutzername darf nur Buchstaben (a–z), Ziffern sowie . _ - @ enthalten.');
        }
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        self::checkNewPassword($password);
        $message = Text::multiline($in['message'] ?? null);
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw ApiError::invalid(sprintf('Die Nachricht darf höchstens %d Zeichen lang sein.', self::MESSAGE_MAX));
        }
        if (($in['consent'] ?? null) !== true) {
            throw ApiError::invalid('Bitte bestätige, dass du die Datenschutzerklärung gelesen hast.');
        }

        $this->limit($client);
        $nowUtc = gmdate('Y-m-d H:i:s', $now);
        $free = $this->noAccessContacts();
        if (($this->app->users->loginExists($login) && !isset($free['logins'][mb_strtolower($login)])) || $this->openWithLogin($login, $nowUtc)) {
            throw ApiError::invalid('Dieser Benutzername ist schon vergeben. Bitte einen anderen wählen.');
        }
        $name = PersonName::join($first, $last);
        $emailTaken = $this->app->users->emailExists($email) && !isset($free['emails'][mb_strtolower($email)]);
        $pendingSame = $this->db()->scalar(
            'SELECT COUNT(*) FROM {registration_requests} WHERE status = ? AND LOWER(email) = LOWER(?) AND expires_at >= ?',
            [self::PENDING, $email, $nowUtc],
        ) > 0;
        if ($mail && ($emailTaken || $pendingSame)) {
            // Nichts verraten: Die Antwort ist dieselbe, die Mail sagt, was los ist.
            $this->sendOrFail($email, $name, $this->subject('Zugang zur App'), $emailTaken
                ? ["Hallo {$first},", sprintf('zu dieser E-Mail-Adresse gibt es in der App „%s“ schon ein Konto. Bitte melde dich damit an:', $this->appName()), $this->app->env->appUrl(), 'Wenn du dich nicht anmelden kannst, wende dich bitte an die Admins der App. Hast du keinen Zugang beantragt, kannst du diese Mail einfach ignorieren.']
                : ["Hallo {$first},", sprintf('für diese E-Mail-Adresse liegt schon eine Anfrage auf einen Zugang zur App „%s“ vor. Sie wartet auf Freigabe; du bekommst eine Mail, sobald sie bearbeitet ist.', $this->appName())]);
            return $answer;
        }
        if (!$mail && $emailTaken) {
            throw ApiError::invalid('Zu dieser E-Mail-Adresse gibt es schon ein Konto. Bitte melde dich damit an oder wende dich an die Admins der App.');
        }
        if (!$mail && $this->openWithEmail($email, $nowUtc)) {
            throw ApiError::invalid('Für diese E-Mail-Adresse gibt es schon eine Anfrage. Sie wartet auf Freigabe.');
        }

        $token = bin2hex(random_bytes(32));
        $id = $this->db()->insert('registration_requests', [
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'message' => $message !== '' ? $message : null,
            'status' => $mail ? self::UNCONFIRMED : self::PENDING,
            'token_hash' => $mail ? hash('sha256', $token) : null,
            'reason' => null,
            'created_at' => $nowUtc,
            // Nur mit Mailversand bestätigt die Person ihre Adresse (confirm); ohne bleibt es leer.
            'confirmed_at' => null,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + ($mail ? self::CONFIRM_HOURS * 3600 : self::PENDING_DAYS * 86400)),
        ]);
        if (!$mail) {
            $this->notifyApprovers($name);
            return $answer;
        }
        try {
            $this->sendOrFail($email, $name, $this->subject('E-Mail-Adresse bestätigen'), [
                "Hallo {$first},",
                sprintf('bitte bestätige deine E-Mail-Adresse, damit deine Anfrage auf einen Zugang zur App „%s“ bearbeitet werden kann:', $this->appName()),
                $this->confirmUrl($token),
                sprintf('Der Link gilt %d Stunden. Hast du keinen Zugang beantragt, kannst du diese Mail einfach ignorieren.', self::CONFIRM_HOURS),
            ]);
        } catch (ApiError $e) {
            $this->db()->execute('DELETE FROM {registration_requests} WHERE id = ?', [$id]);
            throw $e;
        }
        return $answer;
    }

    /** Neues Passwort: gleiche Regeln wie beim Passwortwechsel (MemberService::changeOwnPassword). */
    private static function checkNewPassword(string $password): void
    {
        if (mb_strlen($password) < Passwords::MIN_LENGTH) {
            throw ApiError::invalid(sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', Passwords::MIN_LENGTH));
        }
        if ($password !== trim($password)) {
            throw ApiError::invalid('Das Passwort darf nicht mit Leerzeichen beginnen oder enden.');
        }
        if (strlen($password) > 72) {
            // bcrypt (PASSWORD_DEFAULT) wertet nur die ersten 72 Bytes aus.
            throw ApiError::invalid('Das Passwort ist zu lang (höchstens 72 Zeichen ohne Umlaute).');
        }
    }

    /**
     * Link aus der Mail: E-Mail-Adresse bestätigt, der Antrag wartet jetzt auf Freigabe. Andere Anträge mit derselben
     * Adresse fallen weg (die Person hat es neu versucht).
     *
     * @return array{status: string}
     */
    public function confirm(mixed $token): array
    {
        $now = $this->app->time()->timestamp();
        $nowUtc = gmdate('Y-m-d H:i:s', $now);
        $row = is_string($token) && preg_match('/^[0-9a-f]{64}$/D', $token)
            ? $this->db()->selectOne('SELECT * FROM {registration_requests} WHERE token_hash = ? AND expires_at >= ?', [hash('sha256', $token), $nowUtc])
            : null;
        if ($row === null) {
            throw new ApiError(400, 'link_invalid', 'Der Link ist ungültig oder abgelaufen. Bitte stelle die Anfrage neu.');
        }
        if ($row['status'] !== self::UNCONFIRMED) {
            return ['status' => self::PENDING];
        }
        $this->db()->update('registration_requests', [
            'status' => self::PENDING,
            'token_hash' => null,
            'confirmed_at' => $nowUtc,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::PENDING_DAYS * 86400),
        ], ['id' => (int) $row['id']]);
        $this->db()->execute('DELETE FROM {registration_requests} WHERE id <> ? AND LOWER(email) = LOWER(?)', [(int) $row['id'], (string) $row['email']]);
        $this->notifyApprovers(PersonName::join((string) $row['first_name'], (string) $row['last_name']));
        return ['status' => self::PENDING];
    }

    /**
     * „Stand meiner Anfrage“: nur mit Benutzername (oder E-Mail-Adresse) und dem Passwort aus dem Antrag, damit niemand
     * fremde Anträge ausspähen kann.
     *
     * @return array{status: string, reason?: string}
     */
    public function status(mixed $login, mixed $password, string $client): array
    {
        if ($this->app->cache->increment('registration_status_' . hash('sha256', PushService::clientKey($client)), 900) > self::STATUS_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
        $login = trim(is_string($login) ? $login : '');
        $password = is_string($password) ? $password : '';
        if ($login === '' || $password === '') {
            throw ApiError::invalid('Bitte Benutzername und Passwort eingeben.');
        }
        return $this->match($login, $password) ?? ['status' => 'none'];
    }

    /**
     * Nach einer gescheiterten Anmeldung (eigenständige Version): Fehler, der sagt, dass der Antrag noch wartet bzw.
     * abgelehnt wurde; null, wenn es keinen passenden Antrag gibt. Die Anmeldung zählt die Versuche schon selbst.
     */
    public function loginHint(string $login, string $password): ?ApiError
    {
        if (trim($login) === '' || $password === '') {
            return null;
        }
        $found = $this->match(trim($login), $password);
        return match ($found['status'] ?? null) {
            self::PENDING => new ApiError(403, 'registration_pending', 'Deine Anfrage auf einen Zugang wartet noch auf Freigabe. Danach kannst du dich mit diesen Daten anmelden.'),
            self::UNCONFIRMED => new ApiError(403, 'registration_unconfirmed', 'Bitte bestätige zuerst deine E-Mail-Adresse mit dem Link aus der Mail.'),
            self::REJECTED => new ApiError(403, 'registration_rejected', 'Deine Anfrage auf einen Zugang wurde abgelehnt.' . (($found['reason'] ?? '') !== '' ? ' Begründung: ' . $found['reason'] : '')),
            default => null,
        };
    }

    /** @return array{status: string, reason?: string}|null */
    private function match(string $login, string $password): ?array
    {
        $rows = $this->db()->select(
            'SELECT status, password_hash, reason FROM {registration_requests} WHERE (LOWER(login) = LOWER(?) OR (email <> \'\' AND LOWER(email) = LOWER(?))) AND expires_at >= ? ORDER BY id DESC LIMIT 5',
            [$login, $login, $this->app->time()->nowUtc()],
        );
        foreach ($rows as $row) {
            if (password_verify($password, (string) $row['password_hash'])) {
                return ['status' => (string) $row['status']] + ($row['status'] === self::REJECTED ? ['reason' => (string) ($row['reason'] ?? '')] : []);
            }
        }
        if ($rows === []) {
            // Gleiche Antwortzeit, ob es einen Antrag gibt oder nicht.
            password_verify($password, '$2y$10$' . str_repeat('a', 53));
        }
        return null;
    }

    /**
     * Benutzernamen und E-Mail-Adressen der Mitglieder ohne Zugang (klein), die im Formular als frei gelten.
     *
     * @return array{logins: array<string, true>, emails: array<string, true>}
     */
    private function noAccessContacts(): array
    {
        $out = ['logins' => [], 'emails' => []];
        foreach (array_keys($this->app->members()->noAccessMembers()) as $id) {
            $p = $this->app->users->profile($id);
            if ($p === null) {
                continue;
            }
            $out['logins'][mb_strtolower($p['login'])] = true;
            if ($p['email'] !== '') {
                $out['emails'][mb_strtolower($p['email'])] = true;
            }
        }
        return $out;
    }

    private function openWithLogin(string $login, string $nowUtc): bool
    {
        return (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM {registration_requests} WHERE status IN (?, ?) AND LOWER(login) = LOWER(?) AND expires_at >= ?',
            [self::UNCONFIRMED, self::PENDING, $login, $nowUtc],
        ) > 0;
    }

    private function openWithEmail(string $email, string $nowUtc): bool
    {
        return (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM {registration_requests} WHERE status IN (?, ?) AND LOWER(email) = LOWER(?) AND expires_at >= ?',
            [self::UNCONFIRMED, self::PENDING, $email, $nowUtc],
        ) > 0;
    }

    /** Begrenzung je Anfragendem, je Installation und Zahl der offenen Anträge. */
    private function limit(string $client): void
    {
        $cache = $this->app->cache;
        if ($cache->increment('registration_c_' . hash('sha256', PushService::clientKey($client)), 3600) > self::CLIENT_RATE
            || $cache->increment('registration_day', 86400) > self::DAILY_RATE) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Anfragen in kurzer Zeit. Bitte versuche es später erneut.');
        }
        $open = (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM {registration_requests} WHERE status IN (?, ?) AND expires_at >= ?',
            [self::UNCONFIRMED, self::PENDING, $this->app->time()->nowUtc()],
        );
        if ($open >= self::MAX_OPEN) {
            throw new ApiError(503, 'registrations_full', 'Zurzeit können keine weiteren Anfragen angenommen werden. Bitte versuche es später erneut.');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Freigeben und Ablehnen                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Anträge, die auf Freigabe warten (älteste zuerst), mit der vorgeschlagenen Rolle, je Antrag passenden Mitgliedern
     * ohne Zugang (matches) und allen Mitgliedern ohne Zugang zum Auswählen (no_access_members).
     *
     * @return array{requests: list<array<string, mixed>>, default_role: string, email_confirmation: bool, no_access_members: list<array{id: int, name: string}>}
     */
    public function pending(): array
    {
        $rows = $this->db()->select(
            'SELECT * FROM {registration_requests} WHERE status = ? AND expires_at >= ? ORDER BY id',
            [self::PENDING, $this->app->time()->nowUtc()],
        );
        $members = $this->app->members();
        $free = $this->noAccessContacts();
        return [
            'requests' => array_map(fn (array $r): array => [
                'id' => (int) $r['id'],
                'first_name' => (string) $r['first_name'],
                'last_name' => (string) $r['last_name'],
                'name' => PersonName::join((string) $r['first_name'], (string) $r['last_name']),
                'email' => (string) $r['email'],
                'username' => (string) $r['login'],
                // Benutzername bzw. Adresse eines Mitglieds ohne Zugang gilt als frei: Die Anfrage lässt sich mit ihm verbinden.
                'username_taken' => $this->app->users->loginExists((string) $r['login']) && !isset($free['logins'][mb_strtolower((string) $r['login'])]),
                'email_taken' => $this->app->users->emailExists((string) $r['email']) && !isset($free['emails'][mb_strtolower((string) $r['email'])]),
                'message' => (string) ($r['message'] ?? ''),
                'created_at' => Time::utcToIso((string) $r['created_at']),
                // Mit Mailversand bestätigt; ohne war die Adresse nie geprüft.
                'email_confirmed' => $r['confirmed_at'] !== null,
                'expires_at' => Time::utcToIso((string) $r['expires_at']),
                'matches' => $members->noAccessMatches((string) $r['first_name'], (string) $r['last_name'], (string) $r['email']),
            ], $rows),
            'default_role' => $this->defaultRole(),
            'email_confirmation' => $this->withMail(),
            'no_access_members' => array_values(array_map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name], $members->noAccessMembers())),
        ];
    }

    public function countPending(): int
    {
        return (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM {registration_requests} WHERE status = ? AND expires_at >= ?',
            [self::PENDING, $this->app->time()->nowUtc()],
        );
    }

    /** @return array<string, mixed> */
    private function pendingRow(int $id): array
    {
        $row = $this->db()->selectOne('SELECT * FROM {registration_requests} WHERE id = ? AND status = ? AND expires_at >= ?', [$id, self::PENDING, $this->app->time()->nowUtc()]);
        return $row ?? throw ApiError::notFound('Diese Anfrage gibt es nicht (mehr). Vielleicht hat sie schon jemand bearbeitet.');
    }

    /**
     * Freigeben: Konto mit der gewählten Rolle (level, sonst die vorgeschlagene) und dem Passwort aus dem Antrag anlegen.
     * Gleiche Prüfungen wie beim Anlegen (MemberService::prepare: Rolle, die $actor vergeben darf, Benutzername und
     * E-Mail-Adresse frei); ein anderer Benutzername (username) geht, wenn der gewünschte inzwischen vergeben ist.
     * Mit user_id: statt ein Konto anzulegen den Antrag mit diesem Mitglied ohne Zugang verbinden (die Rolle bleibt).
     *
     * @param array<string, mixed> $in level, username, user_id
     * @return array{member: array<string, mixed>, mailed: bool, linked: bool}
     */
    public function approve(User $actor, int $id, array $in): array
    {
        $row = $this->pendingRow($id);
        $username = is_string($in['username'] ?? null) && trim($in['username']) !== '' ? trim($in['username']) : (string) $row['login'];
        $linkTo = Input::integer($in['user_id'] ?? null);
        if ($linkTo !== null && $linkTo > 0) {
            return $this->approveLinked($actor, $row, $linkTo, $username);
        }
        $taken = [];
        $prepared = $this->app->members()->prepare([
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'username' => $username,
            'email' => (string) $row['email'],
            'level' => is_string($in['level'] ?? null) && $in['level'] !== '' ? $in['level'] : $this->defaultRole(),
        ], $taken, $actor);
        // Erst den Antrag beanspruchen (gleichzeitige Freigabe durch zwei Personen), dann das Konto anlegen.
        if ($this->db()->execute('DELETE FROM {registration_requests} WHERE id = ? AND status = ?', [$id, self::PENDING]) !== 1) {
            throw ApiError::notFound('Diese Anfrage hat gerade schon jemand bearbeitet.');
        }
        try {
            $member = $this->app->members()->insertWithPasswordHash($prepared, (string) $row['password_hash']);
        } catch (\Throwable $e) {
            // Antrag wiederherstellen, damit er nicht verloren geht.
            unset($row['id']);
            $this->db()->insert('registration_requests', $row);
            throw $e;
        }
        return ['member' => $member, 'mailed' => $this->mailApproved($prepared['email'], $prepared['name'], $prepared['first_name'], $prepared['login']), 'linked' => false];
    }

    /**
     * Freigeben und mit einem Mitglied ohne Zugang verbinden (MemberService::linkRegistration). Wie beim Anlegen erst den
     * Antrag beanspruchen; scheitert das Verbinden, ist er wieder da.
     *
     * @param array<string, mixed> $row
     * @return array{member: array<string, mixed>, mailed: bool, linked: bool}
     */
    private function approveLinked(User $actor, array $row, int $userId, string $username): array
    {
        $members = $this->app->members();
        if (!$members->hasNoAccess($userId)) {
            throw ApiError::invalid('Dieses Mitglied hat schon einen Zugang. Verbinden lässt sich nur ein Mitglied ohne Zugang.');
        }
        if ($this->db()->execute('DELETE FROM {registration_requests} WHERE id = ? AND status = ?', [(int) $row['id'], self::PENDING]) !== 1) {
            throw ApiError::notFound('Diese Anfrage hat gerade schon jemand bearbeitet.');
        }
        try {
            $member = $members->linkRegistration($actor, $userId, $username, (string) $row['email'], (string) $row['password_hash']);
        } catch (\Throwable $e) {
            unset($row['id']);
            $this->db()->insert('registration_requests', $row);
            throw $e;
        }
        return ['member' => $member, 'mailed' => $this->mailApproved($member['email'], $member['name'], $member['first_name'], $member['username']), 'linked' => true];
    }

    /** Mail „Dein Zugang ist freigegeben“ (nur mit Mailversand); true, wenn sie raus ist. */
    private function mailApproved(string $email, string $name, string $first, string $login): bool
    {
        if (!$this->withMail() || $email === '') {
            return false;
        }
        return $this->send($email, $name, $this->subject('Dein Zugang ist freigegeben'), [
            'Hallo ' . $first . ',',
            sprintf('dein Zugang zur App „%s“ ist freigegeben. Du kannst dich jetzt mit dem Passwort aus deiner Anfrage anmelden.', $this->appName()),
            'Benutzername: ' . $login . ' (oder deine E-Mail-Adresse)',
            'Zur App: ' . $this->app->env->appUrl(),
            'So holst du die App auf dein Handy: ' . $this->app->env->appUrl() . '#/installieren',
        ]);
    }

    /**
     * Ablehnen, optional mit Begründung. Mit Mailversand bekommt die Person eine Mail und der Antrag ist gleich gelöscht;
     * ohne bleibt nur, was die Person zum Nachsehen braucht (Benutzername, Passwort-Hash, Begründung), REJECTED_DAYS lang.
     *
     * @return array{rejected: bool, mailed: bool}
     */
    public function reject(int $id, mixed $reason): array
    {
        $row = $this->pendingRow($id);
        $reason = Text::multiline($reason);
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ApiError::invalid(sprintf('Die Begründung darf höchstens %d Zeichen lang sein.', self::REASON_MAX));
        }
        $mailed = false;
        if ($this->withMail() && (string) $row['email'] !== '') {
            $mailed = $this->send((string) $row['email'], PersonName::join((string) $row['first_name'], (string) $row['last_name']), $this->subject('Deine Anfrage'), array_values(array_filter([
                'Hallo ' . $row['first_name'] . ',',
                sprintf('deine Anfrage auf einen Zugang zur App „%s“ wurde leider abgelehnt.', $this->appName()),
                $reason !== '' ? 'Begründung: ' . $reason : null,
                'Bei Fragen wende dich bitte an die Admins der App.',
            ])));
        }
        if ($mailed) {
            $this->db()->execute('DELETE FROM {registration_requests} WHERE id = ?', [$id]);
        } else {
            $this->db()->update('registration_requests', [
                'status' => self::REJECTED,
                'first_name' => '',
                'last_name' => '',
                'email' => '',
                'message' => null,
                'token_hash' => null,
                'reason' => $reason,
                'expires_at' => gmdate('Y-m-d H:i:s', $this->app->time()->timestamp() + self::REJECTED_DAYS * 86400),
            ], ['id' => $id]);
        }
        return ['rejected' => true, 'mailed' => $mailed];
    }

    private function notifyApprovers(string $name): void
    {
        try {
            $this->app->push()->notifyAudience(
                PushService::TYPE_REGISTRATIONS,
                PushService::message('Zugang beantragt', sprintf('%s möchte einen Zugang zur App.', $name), 'verwaltung/anfragen', 'anfragen'),
                static fn (int $uid): bool => true,
            );
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Benachrichtigung zu einer Anfrage auf einen Zugang nicht eingereiht: ' . $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Einstellungen und Fristen                                        */
    /* ---------------------------------------------------------------- */

    /** @return array{enabled: bool, default_role: string, email_confirmation: bool, pending: int, link: string} */
    public function settings(): array
    {
        return [
            'enabled' => $this->enabled(),
            'default_role' => $this->defaultRole(),
            'email_confirmation' => $this->withMail(),
            'pending' => $this->countPending(),
            'link' => $this->app->env->appUrl() . '#/registrieren',
        ];
    }

    /**
     * Übernimmt nur die mitgeschickten Felder: enabled, default_role. Erst alles prüfen.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> wie settings()
     */
    public function updateSettings(array $in): array
    {
        $set = [];
        if (array_key_exists('enabled', $in)) {
            if (!is_bool($in['enabled'])) {
                throw ApiError::invalid('Bitte an oder aus angeben.');
            }
            $set[self::ENABLED_SETTING] = $in['enabled'];
        }
        if (array_key_exists('default_role', $in)) {
            $role = is_string($in['default_role']) ? $this->app->roles()->find($in['default_role']) : null;
            if (!self::newAccountRole($role) || $role['key'] === Roles::ADMIN) {
                throw ApiError::invalid('Diese Rolle lässt sich für neue Konten nicht wählen.');
            }
            $set[self::ROLE_SETTING] = $role['key'];
        }
        $wasEnabled = $this->enabled();
        foreach ($set as $key => $value) {
            $this->app->settings->set($key, $value);
        }
        if (!$wasEnabled && $this->enabled()) {
            // Beim ersten Einschalten: Benachrichtigungen über neue Anfragen auf den Geräten der Freigebenden einschalten.
            try {
                $this->app->push()->offerTypeToExisting(PushService::TYPE_REGISTRATIONS);
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Benachrichtigungen „Zugang beantragt“ nicht eingeschaltet: ' . $e->getMessage());
            }
        }
        return $this->settings();
    }

    /** Stündliche Aufgabe: abgelaufene Anträge löschen; wer lange nichts gehört hat, bekommt (mit Mailversand) Bescheid. */
    public function runTask(): void
    {
        $nowUtc = $this->app->time()->nowUtc();
        if ($this->withMail()) {
            $expired = $this->db()->select(
                'SELECT id, first_name, last_name, email FROM {registration_requests} WHERE status = ? AND expires_at < ? ORDER BY id LIMIT ' . self::EXPIRED_MAILS_PER_RUN,
                [self::PENDING, $nowUtc],
            );
            foreach ($expired as $row) {
                if ((string) $row['email'] !== '') {
                    $this->send((string) $row['email'], PersonName::join((string) $row['first_name'], (string) $row['last_name']), $this->subject('Deine Anfrage'), [
                        'Hallo ' . $row['first_name'] . ',',
                        sprintf('deine Anfrage auf einen Zugang zur App „%s“ wurde in %d Tagen nicht bearbeitet und ist deshalb gelöscht.', $this->appName(), self::PENDING_DAYS),
                        'Du kannst gerne eine neue Anfrage stellen oder dich direkt an die Admins der App wenden.',
                    ]);
                }
                $this->db()->execute('DELETE FROM {registration_requests} WHERE id = ?', [(int) $row['id']]);
            }
            if (count($expired) === self::EXPIRED_MAILS_PER_RUN) {
                // Rest beim nächsten Lauf (mit Mail), nicht stillschweigend löschen.
                $this->db()->execute('DELETE FROM {registration_requests} WHERE status <> ? AND expires_at < ?', [self::PENDING, $nowUtc]);
                return;
            }
        }
        $this->db()->execute('DELETE FROM {registration_requests} WHERE expires_at < ?', [$nowUtc]);
    }

    /* ---------------------------------------------------------------- */
    /* Mails                                                            */
    /* ---------------------------------------------------------------- */

    private function appName(): string
    {
        return $this->app->config->name();
    }

    private function subject(string $what): string
    {
        return $what . ': ' . $this->appName();
    }

    private function confirmUrl(string $token): string
    {
        return $this->app->env->appUrl() . '#/registrieren/bestaetigen/' . $token;
    }

    /**
     * Eine Mail aus Absätzen (Text); Adressen werden im HTML zu Links.
     *
     * @param list<string> $paragraphs
     */
    private function send(string $to, string $name, string $subject, array $paragraphs): bool
    {
        try {
            $html = implode('', array_map(static function (string $p): string {
                $escaped = nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'));
                return '<p>' . (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $escaped) . '</p>';
            }, $paragraphs));
            $mailer = $this->app->mailer();
            $result = $mailer->send(new Mail($to, $name, $subject, $html, implode("\n\n", $paragraphs)));
            $mailer->close();
            if (!$result->ok) {
                error_log('Kompanie-App: Mail zur Anfrage auf einen Zugang nicht gesendet: ' . $result->error);
            }
            return $result->ok;
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Mail zur Anfrage auf einen Zugang nicht gesendet: ' . $e->getMessage());
            return false;
        }
    }

    /** @param list<string> $paragraphs */
    private function sendOrFail(string $to, string $name, string $subject, array $paragraphs): void
    {
        if (!$this->send($to, $name, $subject, $paragraphs)) {
            throw new ApiError(503, 'mail_failed', 'Die Mail an deine Adresse konnte gerade nicht gesendet werden. Bitte versuche es später erneut.');
        }
    }

    private function db(): \KompanieApp\Core\Storage\Sql\Connection
    {
        return $this->app->db;
    }
}
