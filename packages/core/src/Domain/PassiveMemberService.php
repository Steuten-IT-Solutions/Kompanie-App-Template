<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Storage\PassiveMemberRepository;

/**
 * Passive (Bereich passive): eine interne Liste passiver Mitglieder unter Verwaltung → Passive (Entscheidungen vom
 * 16.11.2026).
 *
 * - Passive sind eigene Einträge, keine Konten: Sie bekommen nie einen Zugang zur App (auch nicht über „Passwort
 *   vergessen“ in WordPress), stehen in keiner Mitgliederliste, haben keine Punkte, Strafen oder Anwesenheit, und ihr
 *   Geburtstag erscheint nicht bei den Geburtstagen der Mitglieder.
 * - Angaben: Vor- und Nachname (Pflicht), Adresse (Domain\Address), Telefon, E-Mail-Adresse, Geburtsdatum (JJJJ-MM-TT)
 *   und optional ein Mitglied als Betreuer.
 * - Liste sehen: wer „Passive sehen“ (view_passive) hat; anlegen, Namen und Betreuer ändern, löschen: wer „Passive verwalten“ (manage_passive) hat. Adresse,
 *   Telefon, E-Mail und Geburtsdatum sieht davon nur, wer view_personal hat, und ändert nur, wer manage_personal hat
 *   (wie bei Mitgliedern).
 * - Ausnahme (bewusst so entschieden): Wer Betreuer ist, sieht unter Meins → Meine Passiven alle Angaben seiner eigenen
 *   Passiven, auch ohne view_personal, aber nur zum Lesen (mine()).
 * - Beitrag (Entscheidungen vom 16.11.2026): je Passivem und Jahr höchstens eine Zahlung mit Betrag, Datum und Zahlungsart. Eintragen,
 *   ändern und löschen darf, wer Passive verwaltet (manage_passive). Ein Jahresbeitrag lässt sich als Vorschlag
 *   einstellen, die App setzt ihn beim Eintragen ins Feld; die Zahlungsarten zur Auswahl richtet man dort auch ein
 *   (settings()). Gespeichert wird die Bezeichnung der Zahlungsart, frühere Zahlungen behalten sie also, auch wenn sie
 *   später aus der Auswahl entfernt wird. Der Betreuer sieht die Zahlungen seiner Passiven unter
 *   Meins, nur zum Lesen.
 * - Geburtstage (Entscheidungen vom 17.11.2026): Erinnert wird nur an die eingestellten Alter (Liste, anfangs leer =
 *   aus), und zwar die Verwalter: wer manage_passive und view_personal hat, nicht der Betreuer. Eine Push-Nachricht
 *   N Tage vorher (0–30, Vorgabe 7, 0 = am Tag selbst), je Passivem und Geburtstag einmal (birthday_reminded, atomar
 *   beansprucht), ab der Stunde der Geburtstage (BirthdayService::notifyHour, Vorgabe 7 Uhr). Ein Geburtstag, der
 *   erst innerhalb der N Tage eingetragen wird, kommt beim nächsten Lauf noch dran. Dazu zeigt Verwaltung → Passive
 *   dieselben anstehenden Geburtstage als Hinweis (upcomingBirthdays()). Der 29.02. gilt in Jahren ohne Schalttag am
 *   28.02.
 *
 * @phpstan-import-type PassiveRow from PassiveMemberRepository
 * @phpstan-import-type PaymentRow from PassiveMemberRepository
 */
final class PassiveMemberService
{
    public const FEATURE = 'passive';
    public const NAME_MAX = 100;
    public const EMAIL_MAX = 100;
    public const BIRTH_YEAR_MIN = 1900;
    /** Felder mit persönlichen Angaben (anzeigen mit view_personal, ändern mit manage_personal) */
    public const PERSONAL = ['address', 'phone', 'email', 'birth_date'];
    /** Einstellung: Jahresbeitrag als Vorschlag in Cent (0 = keiner) */
    public const FEE_SETTING = 'passive_fee';
    /** Höchster Betrag einer Zahlung und des Vorschlags in Cent (1.000 Euro) */
    public const MAX_CENTS = 100000;
    public const YEAR_MIN = 1900;
    /** Einstellung: Zahlungsarten zur Auswahl (Liste von Bezeichnungen) */
    public const METHODS_SETTING = 'passive_payment_methods';
    /** Zahlungsarten, solange keine eingerichtet sind (Wunsch vom 16.11.2026) */
    public const DEFAULT_METHODS = ['Bar', 'SEPA', 'PayPal'];
    public const METHOD_MAX = 40;
    public const METHODS_MAX = 20;
    /** Aufgabe: stündlich an anstehende Geburtstage der eingestellten Alter erinnern (remindBirthdays()). */
    public const TASK = 'passive_birthdays';
    /** Alter, an die erinnert wird (Liste von Zahlen, leer = keine Erinnerung) */
    public const BIRTHDAY_AGES_SETTING = 'passive_birthday_ages';
    /** So viele Tage vor dem Geburtstag kommt die Erinnerung (0 = am Tag selbst). */
    public const BIRTHDAY_DAYS_SETTING = 'passive_birthday_days';
    public const BIRTHDAY_DAYS_DEFAULT = 7;
    public const BIRTHDAY_DAYS_MAX = 30;
    public const AGE_MAX = 120;
    public const AGES_MAX = 30;

    private ?PassiveMemberRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): PassiveMemberRepository
    {
        return $this->repo ??= new PassiveMemberRepository($this->app->db);
    }

    /**
     * Alle Passiven für die Verwaltung, nach Nachname, dazu die Mitglieder, die Betreuer sein können.
     *
     * @return array{passives: list<array<string, mixed>>, caretakers: list<array{id: int, name: string}>, personal: bool, manage_personal: bool, fee_cents: int, methods: list<string>, birthday_ages: list<int>, birthday_days: int, year: int, birthdays: list<array{id: int, name: string, date: string, age: int, days: int}>}
     */
    public function list(User $viewer): array
    {
        $rows = $this->repository()->all();
        $personal = $viewer->can(Capability::ViewPersonal);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'caretaker_id'));
        $payments = $this->repository()->payments();
        $out = array_map(fn (array $r): array => $this->format($r, $personal, $names, $payments[$r['id']] ?? []), $rows);
        usort($out, self::compare(...));
        return [
            'passives' => $out,
            'caretakers' => $this->caretakers(),
            'personal' => $personal,
            'manage_personal' => $viewer->can(Capability::ManagePersonal),
            ...$this->settings(),
            'year' => $this->year(),
            // Anstehende Geburtstage der eingestellten Alter nur, wer Geburtsdaten sehen darf
            'birthdays' => $personal ? $this->upcomingBirthdays($rows) : [],
        ];
    }

    /** @return array<string, mixed> */
    public function get(User $viewer, int $id): array
    {
        $row = $this->find($id);
        return $this->format($row, $viewer->can(Capability::ViewPersonal), new UserNames($this->app->users), $this->repository()->payments([$id])[$id] ?? []);
    }

    /**
     * Neuer Passiver. Persönliche Angaben nur mit manage_personal.
     *
     * @param array<string, mixed> $in first_name, last_name, caretaker_id, address, phone, email, birth_date
     * @return array<string, mixed>
     */
    public function create(User $actor, array $in): array
    {
        $values = $this->names($in, null) + $this->caretakerValue($in, true) + $this->personalValues($actor, $in);
        $id = $this->repository()->insert($values, $actor->id, $this->app->time()->nowUtc());
        return $this->get($actor, $id);
    }

    /**
     * Angaben ändern; was fehlt, bleibt. Persönliche Angaben nur mit manage_personal.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $in): array
    {
        $row = $this->find($id);
        $values = $this->names($in, $row) + $this->caretakerValue($in, false) + $this->personalValues($actor, $in);
        $this->repository()->update($id, $values, $actor->id, $this->app->time()->nowUtc());
        return $this->get($actor, $id);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->repository()->delete($id);
    }

    /**
     * Meins → Meine Passiven: die Passiven, die diese Person betreut, mit allen Angaben (auch ohne view_personal; nur
     * lesen).
     *
     * @return list<array<string, mixed>>
     */
    public function mine(User $user): array
    {
        $names = (new UserNames($this->app->users))->prime([$user->id]);
        $rows = $this->repository()->ofCaretaker($user->id);
        $payments = $this->repository()->payments(array_column($rows, 'id'));
        $out = array_map(fn (array $r): array => $this->format($r, true, $names, $payments[$r['id']] ?? []), $rows);
        usort($out, self::compare(...));
        return $out;
    }

    /** Wie viele Passive die Person betreut (für den Punkt „Meine Passiven“); 0 ohne den Bereich. */
    public function countMine(?User $user): int
    {
        if ($user === null || $user->id <= 0 || !$this->app->features()->enabled(self::FEATURE)) {
            return 0;
        }
        return $this->repository()->countOfCaretaker($user->id);
    }

    /**
     * Mitglieder, die Betreuer sein können (Recht member), nach Namen.
     *
     * @return list<array{id: int, name: string}>
     */
    public function caretakers(): array
    {
        $out = [];
        foreach ($this->app->users->list(false) as $u) {
            if ($u->isMember()) {
                $out[] = ['id' => $u->id, 'name' => $u->name];
            }
        }
        return $out;
    }

    /**
     * Zahlung des Beitrags für ein Jahr eintragen (je Jahr nur eine).
     *
     * @param array<string, mixed> $in year, amount_cents, paid_on, method
     * @return array{id: int, year: int, amount_cents: int, paid_on: string, method: string}
     */
    public function addPayment(User $actor, int $passiveId, array $in): array
    {
        $this->find($passiveId);
        $year = $this->paymentYear($in['year'] ?? null);
        $cents = self::cents($in['amount_cents'] ?? null);
        $paidOn = $this->paidOn($in['paid_on'] ?? null);
        $method = $this->method($in['method'] ?? null, null);
        if ($this->repository()->hasPayment($passiveId, $year)) {
            throw ApiError::invalid(sprintf('Für %d ist schon eine Zahlung eingetragen.', $year));
        }
        $id = $this->repository()->insertPayment($passiveId, $year, $cents, $paidOn, $method, $actor->id, $this->app->time()->nowUtc());
        return self::payment($this->findPayment($id));
    }

    /**
     * Zahlung ändern; was fehlt, bleibt.
     *
     * @param array<string, mixed> $in year, amount_cents, paid_on, method
     * @return array{id: int, year: int, amount_cents: int, paid_on: string, method: string}
     */
    public function updatePayment(User $actor, int $id, array $in): array
    {
        $row = $this->findPayment($id);
        $values = [];
        if (array_key_exists('year', $in)) {
            $values['year'] = $this->paymentYear($in['year']);
            if ($this->repository()->hasPayment($row['passive_id'], $values['year'], $id)) {
                throw ApiError::invalid(sprintf('Für %d ist schon eine Zahlung eingetragen.', $values['year']));
            }
        }
        if (array_key_exists('amount_cents', $in)) {
            $values['amount_cents'] = self::cents($in['amount_cents']);
        }
        if (array_key_exists('paid_on', $in)) {
            $values['paid_on'] = $this->paidOn($in['paid_on']);
        }
        if (array_key_exists('method', $in)) {
            $values['method'] = $this->method($in['method'], $row['method']);
        }
        $this->repository()->updatePayment($id, $values, $actor->id, $this->app->time()->nowUtc());
        return self::payment($this->findPayment($id));
    }

    public function deletePayment(int $id): void
    {
        $this->findPayment($id);
        $this->repository()->deletePayment($id);
    }

    /**
     * Einstellungen der Beiträge: Jahresbeitrag als Vorschlag beim Eintragen (0 = keiner) und die Zahlungsarten zur
     * Auswahl.
     *
     * @return array{fee_cents: int, methods: list<string>, birthday_ages: list<int>, birthday_days: int}
     */
    public function settings(): array
    {
        // WordPress kann Zahlen als Zeichenkette zurückgeben.
        $v = $this->app->settings->get(self::FEE_SETTING, 0);
        $m = $this->app->settings->get(self::METHODS_SETTING);
        $methods = is_array($m) ? array_values(array_filter(array_map(static fn (mixed $x): string => is_string($x) ? $x : '', $m), static fn (string $x): bool => $x !== '')) : [];
        return [
            'fee_cents' => is_numeric($v) ? max(0, min(self::MAX_CENTS, (int) $v)) : 0,
            'methods' => is_array($m) ? $methods : self::DEFAULT_METHODS,
            'birthday_ages' => $this->birthdayAges(),
            'birthday_days' => $this->birthdayDays(),
        ];
    }

    /** @return list<int> Alter, an die erinnert wird, aufsteigend; leer = keine Erinnerung */
    public function birthdayAges(): array
    {
        $raw = $this->app->settings->get(self::BIRTHDAY_AGES_SETTING);
        if (!is_array($raw)) {
            return [];
        }
        $ages = [];
        foreach ($raw as $v) {
            if (is_numeric($v) && (int) $v >= 1 && (int) $v <= self::AGE_MAX) {
                $ages[(int) $v] = true;
            }
        }
        $out = array_keys($ages);
        sort($out);
        return $out;
    }

    public function birthdayDays(): int
    {
        // WordPress kann Zahlen als Zeichenkette zurückgeben.
        $v = $this->app->settings->get(self::BIRTHDAY_DAYS_SETTING, self::BIRTHDAY_DAYS_DEFAULT);
        return is_numeric($v) && (int) $v >= 0 && (int) $v <= self::BIRTHDAY_DAYS_MAX ? (int) $v : self::BIRTHDAY_DAYS_DEFAULT;
    }

    /**
     * Was fehlt, bleibt.
     *
     * @param array<string, mixed> $in fee_cents (0 oder leer = keiner), methods (Liste von Bezeichnungen, mindestens eine),
     *                                  birthday_ages (Liste von Altern 1–120, leer = keine Erinnerung), birthday_days (0–30)
     * @return array{fee_cents: int, methods: list<string>, birthday_ages: list<int>, birthday_days: int}
     */
    public function setSettings(array $in): array
    {
        $methods = null;
        if (array_key_exists('methods', $in)) {
            if (!is_array($in['methods'])) {
                throw ApiError::invalid('Bitte die Zahlungsarten als Liste angeben.');
            }
            $methods = [];
            foreach ($in['methods'] as $raw) {
                $name = Text::line(is_string($raw) ? $raw : '');
                if ($name === '') {
                    continue;
                }
                if (mb_strlen($name) > self::METHOD_MAX) {
                    throw ApiError::invalid(sprintf('Eine Zahlungsart darf höchstens %d Zeichen lang sein.', self::METHOD_MAX));
                }
                if (in_array(mb_strtolower($name), array_map('mb_strtolower', $methods), true)) {
                    throw ApiError::invalid(sprintf('Die Zahlungsart „%s“ steht doppelt in der Liste.', $name));
                }
                $methods[] = $name;
            }
            if ($methods === []) {
                throw ApiError::invalid('Bitte mindestens eine Zahlungsart angeben.');
            }
            if (count($methods) > self::METHODS_MAX) {
                throw ApiError::invalid(sprintf('Es gehen höchstens %d Zahlungsarten.', self::METHODS_MAX));
            }
        }
        $cents = null;
        if (array_key_exists('fee_cents', $in)) {
            $raw = $in['fee_cents'];
            $cents = $raw === null || $raw === '' || Input::integer($raw) === 0 ? 0 : self::cents($raw);
        }
        $ages = array_key_exists('birthday_ages', $in) ? self::ages($in['birthday_ages']) : null;
        $days = null;
        if (array_key_exists('birthday_days', $in)) {
            $days = Input::integer($in['birthday_days']);
            if ($days === null || $days < 0 || $days > self::BIRTHDAY_DAYS_MAX) {
                throw ApiError::invalid(sprintf('Bitte 0 bis %d Tage vorher angeben.', self::BIRTHDAY_DAYS_MAX));
            }
        }
        // Erst alles prüfen, dann speichern
        if ($ages !== null) {
            $before = $this->birthdayAges();
            $this->app->settings->set(self::BIRTHDAY_AGES_SETTING, $ages);
            // Beim ersten Einrichten bekommen bestehende Abos der Verwalter die neue Art einmalig dazu.
            if ($before === [] && $ages !== []) {
                try {
                    $this->app->push()->offerTypeToExisting(PushService::TYPE_PASSIVE_BIRTHDAYS);
                } catch (\Throwable $e) {
                    error_log('Kompanie-App: Benachrichtigungen „' . PushService::TYPE_PASSIVE_BIRTHDAYS . '“ nicht eingeschaltet: ' . $e->getMessage());
                }
            }
        }
        if ($days !== null) {
            $this->app->settings->set(self::BIRTHDAY_DAYS_SETTING, $days);
        }
        if ($methods !== null) {
            $this->app->settings->set(self::METHODS_SETTING, $methods);
        }
        if ($cents !== null) {
            $this->app->settings->set(self::FEE_SETTING, $cents);
        }
        return $this->settings();
    }

    /**
     * Liste der Alter prüfen: ganze Zahlen 1–120, doppelte zählen einmal, aufsteigend.
     *
     * @return list<int>
     */
    private static function ages(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw ApiError::invalid('Bitte die Alter als Liste angeben.');
        }
        $ages = [];
        foreach ($raw as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $age = Input::integer($v);
            if ($age === null || $age < 1 || $age > self::AGE_MAX) {
                throw ApiError::invalid(sprintf('Bitte nur Alter von 1 bis %d angeben.', self::AGE_MAX));
            }
            $ages[$age] = true;
        }
        if (count($ages) > self::AGES_MAX) {
            throw ApiError::invalid(sprintf('Es gehen höchstens %d Alter.', self::AGES_MAX));
        }
        $out = array_keys($ages);
        sort($out);
        return $out;
    }

    /**
     * Anstehende Geburtstage der eingestellten Alter: heute bis in N Tagen (birthdayDays()), nach Datum, dann Name.
     *
     * @param list<PassiveRow>|null $rows null = alle Passiven
     * @return list<array{id: int, name: string, date: string, age: int, days: int}> date = Tag des Geburtstags, age = das
     *         Alter, das die Person dann erreicht, days = Tage bis dahin (0 = heute)
     */
    public function upcomingBirthdays(?array $rows = null): array
    {
        $ages = $this->birthdayAges();
        if ($ages === []) {
            return [];
        }
        $limit = $this->birthdayDays();
        $today = $this->app->time()->today();
        $out = [];
        foreach ($rows ?? $this->repository()->all() as $r) {
            if ($r['birth_date'] === null) {
                continue;
            }
            [$y, $m, $d] = array_map('intval', explode('-', $r['birth_date']));
            $next = BirthdayService::nextOccurrence($m, $d, $today);
            // Ganze Kalendertage (in UTC gerechnet, damit die Zeitumstellung nichts verschiebt)
            $utc = new \DateTimeZone('UTC');
            $days = (int) (new \DateTimeImmutable($today->format('Y-m-d'), $utc))->diff(new \DateTimeImmutable($next->format('Y-m-d'), $utc))->days;
            $age = (int) $next->format('Y') - $y;
            if ($days > $limit || !in_array($age, $ages, true)) {
                continue;
            }
            $out[] = ['id' => $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $next->format('Y-m-d'), 'age' => $age, 'days' => $days, 'last_name' => $r['last_name']];
        }
        usort($out, static fn (array $a, array $b): int => [$a['date'], mb_strtolower($a['last_name']), mb_strtolower($a['name'])] <=> [$b['date'], mb_strtolower($b['last_name']), mb_strtolower($b['name'])]);
        return array_map(static fn (array $b): array => ['id' => $b['id'], 'name' => $b['name'], 'date' => $b['date'], 'age' => $b['age'], 'days' => $b['days']], $out);
    }

    /** Wer an Geburtstage der Passiven erinnert wird: Verwalter, die Geburtsdaten sehen dürfen. */
    public static function remindsBirthdays(?User $user): bool
    {
        return $user !== null && $user->can(Capability::ManagePassive) && $user->can(Capability::ViewPersonal);
    }

    /**
     * Aufgabe passive_birthdays (stündlich): ab der Stunde der Geburtstage für jeden anstehenden Geburtstag der
     * eingestellten Alter einmal die Verwalter erinnern. Beansprucht wird vor dem Versand (nur ein Lauf gewinnt).
     *
     * @return int Zahl der eingereihten Benachrichtigungen
     */
    public function remindBirthdays(): int
    {
        if (!$this->app->features()->enabled(self::FEATURE) || $this->birthdayAges() === []) {
            return 0;
        }
        $time = $this->app->time();
        $now = (new \DateTimeImmutable('@' . $time->timestamp()))->setTimezone($time->timezone());
        if ((int) $now->format('G') < $this->app->birthdays()->notifyHour()) {
            return 0;
        }
        $count = 0;
        foreach ($this->upcomingBirthdays() as $b) {
            if ($this->repository()->claimBirthday($b['id'], $b['date'])) {
                $count += $this->app->pushTriggers()->passiveBirthday($b['id'], $b['name'], $b['date'], $b['age'], $b['days']);
            }
        }
        return $count;
    }

    /** Laufendes Jahr (Zeitzone der App) */
    private function year(): int
    {
        return (int) $this->app->time()->today()->format('Y');
    }

    /** Jahr einer Zahlung: ab 1900, höchstens nächstes Jahr (Vorauszahlung). */
    private function paymentYear(mixed $raw): int
    {
        $year = Input::integer($raw);
        $max = $this->year() + 1;
        if ($year === null || $year < self::YEAR_MIN || $year > $max) {
            throw ApiError::invalid(sprintf('Bitte ein Jahr zwischen %d und %d angeben.', self::YEAR_MIN, $max));
        }
        return $year;
    }

    /** Betrag in Cent: mehr als 0, höchstens MAX_CENTS. */
    private static function cents(mixed $raw): int
    {
        $cents = Input::integer($raw);
        if ($cents === null || $cents <= 0 || $cents > self::MAX_CENTS) {
            throw ApiError::invalid(sprintf('Bitte einen Betrag über 0 und höchstens %s Euro angeben.', number_format(self::MAX_CENTS / 100, 0, ',', '.')));
        }
        return $cents;
    }

    /**
     * Zahlungsart (Pflicht): eine der eingerichteten. Beim Ändern bleibt die bisherige erlaubt, auch wenn sie nicht mehr
     * zur Auswahl steht.
     */
    private function method(mixed $raw, ?string $current): string
    {
        $name = Text::line(is_string($raw) ? $raw : '');
        $methods = $this->settings()['methods'];
        if ($name !== '' && $name === $current) {
            return $name;
        }
        if ($name === '' || !in_array($name, $methods, true)) {
            throw ApiError::invalid('Bitte eine der Zahlungsarten wählen: ' . implode(', ', $methods) . '.');
        }
        return $name;
    }

    /** Datum der Zahlung JJJJ-MM-TT, ab 1900, nicht in der Zukunft. */
    private function paidOn(mixed $raw): string
    {
        if (!Input::isDate($raw)) {
            throw ApiError::invalid('Bitte das Datum der Zahlung angeben (JJJJ-MM-TT).');
        }
        /** @var string $raw */
        if ((int) substr($raw, 0, 4) < self::YEAR_MIN) {
            throw ApiError::invalid(sprintf('Das Datum der Zahlung muss ab %d liegen.', self::YEAR_MIN));
        }
        if ($raw > $this->app->time()->today()->format('Y-m-d')) {
            throw ApiError::invalid('Das Datum der Zahlung darf nicht in der Zukunft liegen.');
        }
        return $raw;
    }

    /** @return PaymentRow */
    private function findPayment(int $id): array
    {
        return $this->repository()->findPayment($id) ?? throw ApiError::notFound('Diese Zahlung gibt es nicht (mehr).');
    }

    /**
     * @param PaymentRow $p
     * @return array{id: int, year: int, amount_cents: int, paid_on: string, method: string}
     */
    private static function payment(array $p): array
    {
        return ['id' => $p['id'], 'year' => $p['year'], 'amount_cents' => $p['amount_cents'], 'paid_on' => $p['paid_on'], 'method' => $p['method']];
    }

    /** @return PassiveRow */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Diesen Passiven gibt es nicht (mehr).');
    }

    /**
     * @param PassiveRow $r
     * @param list<PaymentRow> $payments
     * @return array<string, mixed>
     */
    private function format(array $r, bool $personal, UserNames $names, array $payments): array
    {
        $out = [
            'id' => $r['id'],
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'name' => PersonName::join($r['first_name'], $r['last_name']),
            'caretaker' => $r['caretaker_id'] !== null ? ['id' => $r['caretaker_id'], 'name' => $names->name($r['caretaker_id'])] : null,
            // Beiträge, neuestes Jahr zuerst
            'payments' => array_map(self::payment(...), $payments),
        ];
        if ($personal) {
            $out += ['address' => $r['address'], 'phone' => $r['phone'], 'email' => $r['email'], 'birth_date' => $r['birth_date']];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        return strcasecmp((string) $a['last_name'], (string) $b['last_name']) ?: strcasecmp((string) $a['first_name'], (string) $b['first_name']) ?: $a['id'] <=> $b['id'];
    }

    /**
     * Vor- und Nachname prüfen; beim Ändern nur, was mitkommt.
     *
     * @param array<string, mixed> $in
     * @param PassiveRow|null $current
     * @return array<string, string>
     */
    private function names(array $in, ?array $current): array
    {
        $first = array_key_exists('first_name', $in) || $current === null ? Text::line($in['first_name'] ?? null) : $current['first_name'];
        $last = array_key_exists('last_name', $in) || $current === null ? Text::line($in['last_name'] ?? null) : $current['last_name'];
        if ($first === '') {
            throw ApiError::invalid('Bitte einen Vornamen angeben.');
        }
        if ($last === '') {
            throw ApiError::invalid('Bitte einen Nachnamen angeben.');
        }
        if (mb_strlen($first) > self::NAME_MAX || mb_strlen($last) > self::NAME_MAX) {
            throw ApiError::invalid(sprintf('Vor- und Nachname dürfen je höchstens %d Zeichen lang sein.', self::NAME_MAX));
        }
        return ['first_name' => $first, 'last_name' => $last];
    }

    /**
     * Betreuer: ein Mitglied (Recht member) oder null für keinen.
     *
     * @param array<string, mixed> $in
     * @return array<string, int|null>
     */
    private function caretakerValue(array $in, bool $create): array
    {
        if (!array_key_exists('caretaker_id', $in)) {
            return $create ? ['caretaker_id' => null] : [];
        }
        $raw = $in['caretaker_id'];
        if ($raw === null || $raw === '' || $raw === 0) {
            return ['caretaker_id' => null];
        }
        $id = Input::integer($raw);
        $user = $id !== null && $id > 0 ? $this->app->users->find($id) : null;
        if ($user === null || !$user->isMember()) {
            throw ApiError::invalid('Betreuer kann nur ein Mitglied sein.');
        }
        return ['caretaker_id' => $user->id];
    }

    /**
     * Adresse, Telefon, E-Mail und Geburtsdatum aus der Anfrage (nur die mitgeschickten); dafür braucht es
     * manage_personal. Der Server prüft das, nicht nur die App.
     *
     * @param array<string, mixed> $in
     * @return array<string, string|null>
     */
    private function personalValues(User $actor, array $in): array
    {
        $given = array_values(array_filter(self::PERSONAL, static fn (string $k): bool => array_key_exists($k, $in)));
        if ($given === []) {
            return [];
        }
        if (!$actor->can(Capability::ManagePersonal)) {
            throw ApiError::forbidden('Adresse, Telefon, E-Mail und Geburtsdatum pflegt nur, wer persönliche Angaben ändern darf.');
        }
        $out = [];
        if (in_array('address', $given, true)) {
            $out += PassiveMemberRepository::addressColumns(Address::parse($in['address']));
        }
        if (in_array('phone', $given, true)) {
            $out['phone'] = self::phone($in['phone']);
        }
        if (in_array('email', $given, true)) {
            $out['email'] = self::email($in['email']);
        }
        if (in_array('birth_date', $given, true)) {
            $out['birth_date'] = $this->birthDate($in['birth_date']);
        }
        return $out;
    }

    /** Telefonnummer: wie die Handynummer der Mitglieder (Festnetz geht genauso); leer heißt keine. */
    public static function phone(mixed $raw): string
    {
        $phone = Text::line(is_string($raw) ? $raw : '');
        if ($phone === '') {
            return '';
        }
        $digits = strlen((string) preg_replace('/\D/', '', $phone));
        if (mb_strlen($phone) > MemberService::PHONE_MAX || preg_match('#^\+?[0-9 ()/-]+$#', $phone) !== 1 || $digits < 5 || $digits > 20) {
            throw ApiError::invalid('Die Telefonnummer ist ungültig. Erlaubt sind Ziffern, Leerzeichen und + ( ) / -, z. B. +49 2151 123456.');
        }
        return $phone;
    }

    public static function email(mixed $raw): string
    {
        $email = Text::line(is_string($raw) ? $raw : '');
        if ($email === '') {
            return '';
        }
        if (strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::invalid('Die E-Mail-Adresse ist ungültig.');
        }
        return $email;
    }

    /** Geburtsdatum JJJJ-MM-TT, ab 1900, nicht in der Zukunft; leer heißt keins. */
    private function birthDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!Input::isDate($raw)) {
            throw ApiError::invalid('Bitte das Geburtsdatum als Datum angeben (JJJJ-MM-TT).');
        }
        /** @var string $raw */
        if ((int) substr($raw, 0, 4) < self::BIRTH_YEAR_MIN) {
            throw ApiError::invalid(sprintf('Das Geburtsjahr muss ab %d liegen.', self::BIRTH_YEAR_MIN));
        }
        if ($raw > $this->app->time()->today()->format('Y-m-d')) {
            throw ApiError::invalid('Das Geburtsdatum darf nicht in der Zukunft liegen.');
        }
        return $raw;
    }
}
