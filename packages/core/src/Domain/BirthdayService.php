<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\BirthdayRepository;

/**
 * Geburtstage (Issue #1).
 *
 * - Jedes Mitglied pflegt seinen eigenen Geburtstag (Tag und Monat, Jahr freiwillig) mit zwei Schaltern: „Anderen
 *   Mitgliedern zeigen“ (visible, Standard an) und „Alter anzeigen“ (show_age, Standard aus). Dazu wählt es, wem der
 *   Geburtstag auf seiner Seite in der Mitgliederliste erscheint (profile: none, members, public; Standard members,
 *   unabhängig von visible): mit show_age und Jahr das ganze Datum und das Alter, sonst nur Tag und Monat.
 * - Wer persönliche Angaben pflegt (manage_personal), trägt das Datum anderer ein oder entfernt es (beim Anlegen auch,
 *   wer Mitglieder anlegen darf); die beiden Schalter bleiben dabei, wie die Person sie gesetzt hat.
 * - Sehen und erscheinen: nur Konten mit Mitgliederbereich (Recht member). Gäste und Anwärter sehen keine Geburtstage
 *   und erscheinen auch nicht. Ohne visible weder in der Liste noch in der Benachrichtigung. Das Alter nur mit show_age
 *   und Jahr; das Geburtsjahr gibt /birthdays nie aus.
 * - „Heute“ in der Zeitzone der Kompanie. Der 29.02. gilt in Jahren ohne Schalttag am 28.02. Alter = das Alter, das
 *   die Person an diesem Geburtstag erreicht.
 * - Benachrichtigung am Tag selbst (Aufgabe birthday_notifications, stündlich, aber erst ab der eingestellten Stunde
 *   Ortszeit, Standard 7 Uhr): an alle Mitglieder außer dem Geburtstagskind; je Geburtstag und Tag höchstens einmal
 *   (last_notified, atomar beansprucht). Admins können sie ausschalten (birthday_notify) und die Stunde wählen
 *   (birthday_notify_hour).
 * - Anstehende Geburtstage bei den Terminen: heute und die nächsten N Tage; N stellen Admins ein (birthday_upcoming_days,
 *   0–30, Standard 7). Die App bekommt N mit /config.
 *
 * @phpstan-import-type Birthday from BirthdayRepository
 */
final class BirthdayService
{
    public const TASK = 'birthday_notifications';
    /** Benachrichtigung am Tag selbst an oder aus; Einstellung der Admins (Standard an). */
    public const NOTIFY_SETTING = 'birthday_notify';
    /** Ab dieser Stunde (0–23, Zeitzone der Kompanie) wird benachrichtigt; Einstellung der Admins. */
    public const NOTIFY_HOUR_SETTING = 'birthday_notify_hour';
    public const NOTIFY_HOUR_DEFAULT = 7;
    public const MIN_YEAR = 1900;
    /** Anstehend: heute und die nächsten N Tage (App, „Anstehende Geburtstage“); Einstellung der Admins. */
    public const UPCOMING_DAYS_SETTING = 'birthday_upcoming_days';
    public const UPCOMING_DAYS = 7;
    public const UPCOMING_DAYS_MAX = 30;

    private ?BirthdayRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): BirthdayRepository
    {
        return $this->repo ??= new BirthdayRepository($this->app->db);
    }

    /** Wer mit seinem Geburtstag erscheint: alle Mitglieder der Kompanie. */
    public static function isMember(?User $user): bool
    {
        return $user !== null && $user->isMember();
    }

    /** Wer Geburtstage sieht und Benachrichtigungen dazu bekommt: alle mit view_birthdays. */
    public static function canSee(?User $user): bool
    {
        return $user !== null && $user->can(Capability::ViewBirthdays);
    }

    /* ---------------------------------------------------------------- */
    /* Eingaben                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Tag, Monat und optional Jahr prüfen: gültiges Datum (29.02. nur im Schaltjahr oder ohne Jahr), Jahr ab 1900,
     * nicht in der Zukunft (heute in der Zeitzone der Kompanie).
     *
     * @return array{month: int, day: int, year: int|null}
     */
    public function validate(mixed $month, mixed $day, mixed $year): array
    {
        $m = Input::integer($month);
        $d = Input::integer($day);
        if ($m === null || $d === null || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
            throw ApiError::invalid('Bitte Tag und Monat des Geburtstags angeben.');
        }
        $y = null;
        if ($year !== null && $year !== '') {
            $y = Input::integer($year);
            if ($y === null) {
                throw ApiError::invalid('Ungültiges Geburtsjahr.');
            }
        }
        // Ohne Jahr: der 29.02. ist erlaubt (Schaltjahr 2000 als Maßstab).
        if (!checkdate($m, $d, $y ?? 2000)) {
            throw ApiError::invalid($y !== null && $m === 2 && $d === 29
                ? sprintf('Den 29.02.%d gibt es nicht (kein Schaltjahr).', $y)
                : 'Dieses Datum gibt es nicht.');
        }
        if ($y !== null) {
            if ($y < self::MIN_YEAR) {
                throw ApiError::invalid(sprintf('Das Geburtsjahr muss ab %d liegen.', self::MIN_YEAR));
            }
            $today = $this->app->time()->today();
            if (sprintf('%04d-%02d-%02d', $y, $m, $d) > $today->format('Y-m-d')) {
                throw ApiError::invalid('Der Geburtstag darf nicht in der Zukunft liegen.');
            }
        }
        return ['month' => $m, 'day' => $d, 'year' => $y];
    }

    /**
     * Geburtstag beim Anlegen von Konten (POST /users, /users/bulk): „JJJJ-MM-TT“ oder ohne Jahr „MM-TT“; leer = keiner.
     *
     * @return array{month: int, day: int, year: int|null}|null
     */
    public function parse(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(?:(\d{4})-)?(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            throw ApiError::invalid('Geburtstag bitte als JJJJ-MM-TT oder ohne Jahr als MM-TT angeben.');
        }
        return $this->validate($m[2], $m[3], $m[1] === '' ? null : $m[1]);
    }

    /* ---------------------------------------------------------------- */
    /* Eigener Geburtstag und Pflege                                    */
    /* ---------------------------------------------------------------- */

    /** @return array{month: int, day: int, year: int|null, visible: bool, show_age: bool, profile: string}|null */
    public function of(int $userId): ?array
    {
        $b = $this->repository()->find($userId);
        return $b === null ? null : self::full($b);
    }

    /**
     * Eigener Geburtstag mit Jahr und Schaltern (PUT /me/birthday); fehlende Schalter bleiben (neu: sichtbar, ohne Alter,
     * auf der eigenen Seite in der Mitgliederliste nur für Mitglieder).
     *
     * @param array<string, mixed> $in month, day, year, visible, show_age, profile
     * @return array{month: int, day: int, year: int|null, visible: bool, show_age: bool, profile: string}|null
     */
    public function saveOwn(User $user, array $in): ?array
    {
        $date = $this->validate($in['month'] ?? null, $in['day'] ?? null, $in['year'] ?? null);
        $flag = static function (string $key) use ($in): ?bool {
            if (!array_key_exists($key, $in) || $in[$key] === null) {
                return null;
            }
            if (!is_bool($in[$key])) {
                throw ApiError::invalid('Ungültiger Wert für ' . $key . ' (an oder aus erwartet).');
            }
            return $in[$key];
        };
        $profile = isset($in['profile']) ? ProfileVisibility::parse($in['profile']) : null;
        $this->repository()->save($user->id, $date['month'], $date['day'], $date['year'], $flag('visible'), $flag('show_age'), $user->id, $this->app->time()->nowUtc(), $profile);
        return $this->of($user->id);
    }

    /**
     * Datum eines anderen Mitglieds (PUT /members/{id}/birthday, manage_personal): nur Tag, Monat und Jahr; visible,
     * show_age und profile bleiben, wie die Person sie gesetzt hat (mitgeschickte Schalter werden nicht übernommen).
     *
     * @param array<string, mixed> $in month, day, year
     * @return array{month: int, day: int, year: int|null, visible: bool, show_age: bool, profile: string}|null
     */
    public function saveFor(User $actor, int $userId, array $in): ?array
    {
        $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $date = $this->validate($in['month'] ?? null, $in['day'] ?? null, $in['year'] ?? null);
        $this->store($actor, $userId, $date);
        return $this->of($userId);
    }

    /** @param array{month: int, day: int, year: int|null} $date geprüft (validate bzw. parse) */
    public function store(User $actor, int $userId, array $date): void
    {
        $this->repository()->save($userId, $date['month'], $date['day'], $date['year'], null, null, $actor->id, $this->app->time()->nowUtc());
    }

    public function remove(int $userId): void
    {
        $this->repository()->delete($userId);
    }

    public function removeFor(int $userId): void
    {
        $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $this->remove($userId);
    }

    /* ---------------------------------------------------------------- */
    /* Liste                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Sichtbare Geburtstage der Mitglieder, ab heute sortiert (GET /birthdays). Nie das Geburtsjahr; das Alter nur mit
     * show_age und Jahr. Mit $days nur die in den nächsten $days Tagen (0 = nur heute).
     *
     * @return list<array{user_id: int, name: string, month: int, day: int, date: string, days_until: int, today: bool, age: int|null}>
     */
    public function list(?int $days = null): array
    {
        $today = $this->app->time()->today();
        $members = [];
        foreach ($this->app->users->list(false) as $u) {
            if (self::isMember($u)) {
                $members[$u->id] = $u;
            }
        }
        $out = [];
        foreach ($this->repository()->visible() as $b) {
            $user = $members[$b['user_id']] ?? null;
            if ($user === null) {
                continue;
            }
            $next = self::nextOccurrence($b['month'], $b['day'], $today);
            $until = (int) $today->diff($next)->days;
            if ($days !== null && $until > $days) {
                continue;
            }
            $out[] = [
                'user_id' => $b['user_id'],
                'name' => $user->name,
                'month' => $b['month'],
                'day' => $b['day'],
                // Tag des nächsten Geburtstags (29.02. ohne Schaltjahr: 28.02.)
                'date' => $next->format('Y-m-d'),
                'days_until' => $until,
                'today' => $until === 0,
                'age' => self::publicAge($b, (int) $next->format('Y')),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['days_until'], mb_strtolower($a['name'])] <=> [$b['days_until'], mb_strtolower($b['name'])]);
        return $out;
    }

    /**
     * Geburtstage für die Seiten in der Mitgliederliste (GET /directory), soweit die Person sie $viewer zeigt (eine
     * Abfrage): mit „Alter anzeigen“ und Jahr das ganze Datum und das heutige Alter, sonst nur Tag und Monat.
     *
     * @return array<int, array{month: int, day: int, year: int|null, age: int|null}> Benutzer-ID => Geburtstag
     */
    public function shownTo(?User $viewer): array
    {
        $today = $this->app->time()->today();
        $out = [];
        foreach ($this->repository()->withProfile(ProfileVisibility::shownTo($viewer)) as $b) {
            $full = $b['show_age'] && $b['year'] !== null;
            $out[$b['user_id']] = [
                'month' => $b['month'],
                'day' => $b['day'],
                'year' => $full ? $b['year'] : null,
                'age' => $full ? self::ageOn($b['month'], $b['day'], (int) $b['year'], $today) : null,
            ];
        }
        return $out;
    }

    /** Alter an einem Tag: der 29.02. zählt in Jahren ohne Schalttag ab dem 28.02. */
    public static function ageOn(int $month, int $day, int $year, \DateTimeImmutable $today): int
    {
        $thisYear = (int) $today->format('Y');
        $birthday = self::inYear($month, $day, $thisYear, $today->getTimezone());
        return $thisYear - $year - ($birthday->format('Y-m-d') > $today->format('Y-m-d') ? 1 : 0);
    }

    /** Geburtstag in einem Jahr: der 29.02. in Jahren ohne Schalttag am 28.02. */
    public static function inYear(int $month, int $day, int $year, \DateTimeZone $tz): \DateTimeImmutable
    {
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }
        return (new \DateTimeImmutable('now', $tz))->setDate($year, $month, $day)->setTime(0, 0);
    }

    /** Nächster Geburtstag ab heute (heute eingeschlossen). */
    public static function nextOccurrence(int $month, int $day, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $year = (int) $today->format('Y');
        $date = self::inYear($month, $day, $year, $today->getTimezone());
        return $date->format('Y-m-d') < $today->format('Y-m-d') ? self::inYear($month, $day, $year + 1, $today->getTimezone()) : $date;
    }

    /**
     * Alter für andere: nur mit Jahr und „Alter anzeigen“.
     *
     * @param Birthday $b
     */
    private static function publicAge(array $b, int $inYear): ?int
    {
        return $b['show_age'] && $b['year'] !== null ? $inYear - $b['year'] : null;
    }

    /**
     * @param Birthday $b
     * @return array{month: int, day: int, year: int|null, visible: bool, show_age: bool, profile: string}
     */
    private static function full(array $b): array
    {
        return ['month' => $b['month'], 'day' => $b['day'], 'year' => $b['year'], 'visible' => $b['visible'], 'show_age' => $b['show_age'], 'profile' => $b['profile']];
    }

    /* ---------------------------------------------------------------- */
    /* Benachrichtigung                                                 */
    /* ---------------------------------------------------------------- */

    /**
     * Aufgabe birthday_notifications (stündlich): ist die Benachrichtigung an, ab der eingestellten Stunde für jeden sichtbaren Geburtstag eines Mitglieds
     * von heute einmal benachrichtigen. last_notified wird vor dem Versand beansprucht (nur ein Lauf gewinnt), damit
     * wiederholte oder gleichzeitige Läufe (Pseudo-Cron) nicht doppelt senden. Ein im Laufe des Tages eingetragener
     * Geburtstag von heute kommt beim nächsten Lauf noch dran.
     *
     * @return int Zahl der eingereihten Benachrichtigungen
     */
    public function notifyToday(): int
    {
        if (!$this->notifyEnabled()) {
            return 0;
        }
        $time = $this->app->time();
        $now = (new \DateTimeImmutable('@' . $time->timestamp()))->setTimezone($time->timezone());
        if ((int) $now->format('G') < $this->notifyHour()) {
            return 0;
        }
        $today = $time->today();
        $date = $today->format('Y-m-d');
        $year = (int) $today->format('Y');
        $count = 0;
        foreach ($this->repository()->visible() as $b) {
            if (self::inYear($b['month'], $b['day'], $year, $today->getTimezone())->format('Y-m-d') !== $date) {
                continue;
            }
            $user = $this->app->users->find($b['user_id']);
            if ($user === null || !$user->isMember() || $b['last_notified'] === $date || !$this->repository()->claim($b['user_id'], $date)) {
                continue;
            }
            $count += $this->app->pushTriggers()->birthday($b['user_id'], $user->name, self::publicAge($b, $year));
        }
        return $count;
    }

    /** @return array{enabled: bool, hour: int} Einstellungen der Benachrichtigung (GET /admin/push) */
    public function notifySettings(): array
    {
        return ['enabled' => $this->notifyEnabled(), 'hour' => $this->notifyHour()];
    }

    public function notifyEnabled(): bool
    {
        return (bool) $this->app->settings->get(self::NOTIFY_SETTING, true);
    }

    public function notifyHour(): int
    {
        $h = $this->app->settings->get(self::NOTIFY_HOUR_SETTING, self::NOTIFY_HOUR_DEFAULT);
        return is_numeric($h) && (int) $h >= 0 && (int) $h <= 23 ? (int) $h : self::NOTIFY_HOUR_DEFAULT;
    }

    /**
     * Einstellungen ändern (PATCH /admin/push: birthday_notify, birthday_hour); erst beide prüfen, dann speichern.
     * Ein heute schon benachrichtigter Geburtstag bleibt beansprucht: Eine spätere Stunde schickt ihn nicht noch einmal.
     */
    public function setNotify(mixed $enabled, mixed $hour): void
    {
        if ($enabled !== null && !is_bool($enabled)) {
            throw ApiError::invalid('Bitte an oder aus angeben.');
        }
        if ($hour !== null && (!is_numeric($hour) || (int) $hour < 0 || (int) $hour > 23 || (string) (int) $hour !== (string) $hour)) {
            throw ApiError::invalid('Bitte eine volle Stunde von 0 bis 23 angeben.');
        }
        if ($enabled !== null) {
            // Als 1/0: WordPress speichert false nicht, solange es die Option noch nicht gibt (update_option vergleicht
            // mit dem Standardwert false), dann bliebe es beim Standard „an“.
            $this->app->settings->set(self::NOTIFY_SETTING, $enabled ? 1 : 0);
        }
        if ($hour !== null) {
            $this->app->settings->set(self::NOTIFY_HOUR_SETTING, (int) $hour);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Anstehende Geburtstage                                           */
    /* ---------------------------------------------------------------- */

    /** Wie viele Tage im Voraus die App einen Geburtstag als anstehend zeigt (0 = nur heute). */
    public function upcomingDays(): int
    {
        $d = $this->app->settings->get(self::UPCOMING_DAYS_SETTING, self::UPCOMING_DAYS);
        return is_numeric($d) && (int) $d >= 0 && (int) $d <= self::UPCOMING_DAYS_MAX ? (int) $d : self::UPCOMING_DAYS;
    }

    /** Vorlauf ändern (PATCH /admin/birthdays: upcoming_days); ganze Tage von 0 bis 30. */
    public function setUpcomingDays(mixed $days): void
    {
        if (!is_numeric($days) || (string) (int) $days !== (string) $days || (int) $days < 0 || (int) $days > self::UPCOMING_DAYS_MAX) {
            throw ApiError::invalid(sprintf('Bitte eine ganze Zahl von 0 bis %d Tagen angeben.', self::UPCOMING_DAYS_MAX));
        }
        $this->app->settings->set(self::UPCOMING_DAYS_SETTING, (int) $days);
    }

    /** @return array{upcoming_days: int} Einstellungen der Geburtstage (GET /admin/birthdays) */
    public function settings(): array
    {
        return ['upcoming_days' => $this->upcomingDays()];
    }
}
