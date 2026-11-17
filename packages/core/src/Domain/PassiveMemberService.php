<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\PassiveMemberRepository;

/**
 * Passive (Bereich passive): eine interne Liste passiver Mitglieder unter Verwaltung → Passive (Entscheidungen vom
 * 16.11.2026).
 *
 * - Passive sind eigene Einträge, keine Konten: Sie bekommen nie einen Zugang zur App (auch nicht über „Passwort
 *   vergessen“ in WordPress), stehen in keiner Mitgliederliste, haben keine Punkte, Strafen oder Anwesenheit, und ihr
 *   Geburtstag erscheint nicht bei den Geburtstagen der Mitglieder.
 * - Angaben: Vor- und Nachname (Pflicht), Adresse (Domain\Address), Telefon, E-Mail-Adresse, Geburtsdatum (JJJJ-MM-TT),
 *   Eintritt (freier Text, z. B. „2023“ oder „vor 2014“), Bemerkung und optional ein Mitglied als Betreuer. Eintritt und
 *   Bemerkung sind keine persönlichen Angaben: Sie pflegt, wer Passive verwaltet (Annahme vom 17.11.2026).
 * - Import aus einer Tabelle: Domain\PassiveImportService.
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
    public const JOINED_MAX = 40;
    public const NOTE_MAX = 500;
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
     * @return array{passives: list<array<string, mixed>>, caretakers: list<array{id: int, name: string}>, personal: bool, manage_personal: bool, fee_cents: int, methods: list<string>, year: int}
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
     * @param array<string, mixed> $in first_name, last_name, caretaker_id, address, phone, email, birth_date, joined, note
     * @return array<string, mixed>
     */
    public function create(User $actor, array $in): array
    {
        $values = $this->prepare($actor, $in);
        $id = $this->repository()->insert($values, $actor->id, $this->app->time()->nowUtc());
        return $this->get($actor, $id);
    }

    /**
     * Angaben eines neuen Passiven prüfen (wie create(), ohne zu speichern); Spalte => Wert für das Repository.
     *
     * @param array<string, mixed> $in
     * @return array<string, string|int|null>
     */
    public function prepare(User $actor, array $in): array
    {
        return $this->names($in, null) + $this->caretakerValue($in, true) + self::extraValues($in) + $this->personalValues($actor, $in);
    }

    /**
     * Zahlung prüfen (wie addPayment(), ohne zu speichern).
     *
     * @param array<string, mixed> $in year, amount_cents, paid_on, method
     * @return array{year: int, amount_cents: int, paid_on: string, method: string}
     */
    public function checkPayment(array $in): array
    {
        return [
            'year' => $this->paymentYear($in['year'] ?? null),
            'amount_cents' => self::cents($in['amount_cents'] ?? null),
            'paid_on' => $this->paidOn($in['paid_on'] ?? null),
            'method' => $this->method($in['method'] ?? null, null),
        ];
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
        $values = $this->names($in, $row) + $this->caretakerValue($in, false) + self::extraValues($in) + $this->personalValues($actor, $in);
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
     * @return array{fee_cents: int, methods: list<string>}
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
        ];
    }

    /**
     * Was fehlt, bleibt.
     *
     * @param array<string, mixed> $in fee_cents (0 oder leer = keiner), methods (Liste von Bezeichnungen, mindestens eine)
     * @return array{fee_cents: int, methods: list<string>}
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
        // Erst alles prüfen, dann speichern
        if ($methods !== null) {
            $this->app->settings->set(self::METHODS_SETTING, $methods);
        }
        if ($cents !== null) {
            $this->app->settings->set(self::FEE_SETTING, $cents);
        }
        return $this->settings();
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
            'joined' => $r['joined'],
            'note' => $r['note'],
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
     * Eintritt und Bemerkung (nur die mitgeschickten); leer heißt keine Angabe.
     *
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private static function extraValues(array $in): array
    {
        $out = [];
        if (array_key_exists('joined', $in)) {
            $out['joined'] = self::joined($in['joined']);
        }
        if (array_key_exists('note', $in)) {
            $out['note'] = self::note($in['note']);
        }
        return $out;
    }

    /** Eintritt: freier Text (Jahr, Datum oder z. B. „vor 2014“), höchstens JOINED_MAX Zeichen. */
    public static function joined(mixed $raw): string
    {
        $text = Text::line(is_string($raw) || is_int($raw) ? (string) $raw : '');
        if (mb_strlen($text) > self::JOINED_MAX) {
            throw ApiError::invalid(sprintf('Der Eintritt darf höchstens %d Zeichen lang sein.', self::JOINED_MAX));
        }
        return $text;
    }

    /** Bemerkung: auch mehrzeilig, höchstens NOTE_MAX Zeichen. */
    public static function note(mixed $raw): string
    {
        $text = Text::multiline(is_string($raw) ? $raw : '');
        if (mb_strlen($text) > self::NOTE_MAX) {
            throw ApiError::invalid(sprintf('Die Bemerkung darf höchstens %d Zeichen lang sein.', self::NOTE_MAX));
        }
        return $text;
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
