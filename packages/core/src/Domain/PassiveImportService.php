<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Passive aus einer Tabelle übernehmen (Wunsch vom 17.11.2026, z. B. eine bisher in Excel geführte Liste): CSV oder, in
 * der App aus einer Excel-Datei gelesen, Tabulator-getrennt. Wer Passive verwaltet (manage_passive), darf importieren.
 *
 * - Kopfzeile: die erste der ersten Zeilen, in der „Vorname“ oder „Nachname“ steht (darüber dürfen z. B. ein Stand oder
 *   ein Titel stehen); sonst die erste Zeile. Die Spalten erkennt der Import an ihrem Namen (COLUMNS); die App zeigt die
 *   Zuordnung, und man kann sie ändern (columns: Feld => Nummer der Spalte ab 0, null = keine).
 * - Adresse, Telefon, E-Mail und Geburtsdatum nur mit manage_personal; sonst werden diese Spalten mit Hinweis übergangen.
 *   Eine ungültige Angabe (z. B. eine Telefonnummer mit Buchstaben) bleibt leer, mit Hinweis an der Zeile; der Passive
 *   wird trotzdem angelegt.
 * - Betreuer: ein Mitglied, als „Vorname Nachname“, „Nachname, Vorname“ oder „V. Nachname“. Passt keins oder mehr als
 *   eins, bleibt der Betreuer leer, und die Zeile bekommt einen Hinweis (kein Fehler).
 * - Beitrag für ein Jahr (year, Vorgabe das laufende): entweder die Spalten Betrag und Zahlungsart oder Spalten, die wie
 *   eine eingerichtete Zahlungsart heißen (auch abgekürzt, z. B. „Überw.“ für „Überweisung“) und den Betrag enthalten.
 *   Das Datum steht in der Spalte „Bezahlt am“ (TT.MM.JJJJ, TT.MM. für das Jahr des Beitrags oder JJJJ-MM-TT, Text
 *   danach zählt nicht); fehlt es, gilt paid_on (Vorgabe heute). Steht eine Zahlungsart ohne Betrag da, gilt der
 *   Jahresbeitrag (wenn eingestellt).
 * - Zellen ohne Buchstaben und Ziffern (z. B. „-“) gelten als leer.
 * - Wer genau so heißt wie ein vorhandener Passiver, wird übersprungen (existing); doppelte Namen in der Tabelle sind ein
 *   Fehler. Ein zweiter Import derselben Tabelle legt also niemanden doppelt an.
 * - Ohne apply nur die Vorschau. Mit apply nur, wenn keine Zeile einen Fehler hat, oder mit skip_errors ohne die Zeilen
 *   mit Fehler (z. B. Summenzeilen unter der Liste). Alle in einer Transaktion.
 *
 * @phpstan-type Payment array{year: int, amount_cents: int, paid_on: string, method: string}
 */
final class PassiveImportService
{
    public const MAX_BYTES = 500000;
    public const MAX_ROWS = 1000;
    /** In so vielen Zeilen am Anfang wird die Kopfzeile gesucht */
    private const HEADER_SEARCH = 20;

    /** Feld => Schreibweisen in der Kopfzeile (klein, ohne Leerzeichen, Punkte, Binde-, Schräg- und Unterstriche) */
    public const COLUMNS = [
        'first_name' => ['vorname', 'firstname'],
        'last_name' => ['nachname', 'name', 'familienname', 'lastname'],
        'street' => ['straße', 'strasse', 'str', 'street', 'anschrift'],
        'house_number' => ['hausnummer', 'hausnr'],
        'extra' => ['adresszusatz', 'zusatz'],
        'postal_code' => ['plz', 'postleitzahl'],
        'city' => ['ort', 'wohnort', 'stadt'],
        'country' => ['land'],
        'phone' => ['telefon', 'telefonnr', 'telefonnummer', 'tel', 'handy', 'handynummer', 'mobil', 'mobilnummer', 'festnetz'],
        'email' => ['email', 'mail', 'emailadresse'],
        'birth_date' => ['geburtsdatum', 'gebdatum', 'geburtstag', 'geboren'],
        'joined' => ['eintritt', 'eintrittsjahr', 'eintrittsdatum', 'mitgliedseit', 'passivseit', 'seit'],
        'note' => ['bemerkung', 'bemerkungen', 'notiz', 'notizen', 'anmerkung'],
        'caretaker' => ['betreuer', 'betreuerin', 'betreutvon'],
        'amount' => ['betrag', 'beitrag'],
        'method' => ['zahlungsart', 'zahlweise'],
        'paid_on' => ['bezahltam', 'zahlungsdatum', 'datumderzahlung', 'zahldatum'],
    ];

    /** Felder mit persönlichen Angaben (nur mit manage_personal) */
    private const PERSONAL = ['street', 'house_number', 'extra', 'postal_code', 'city', 'country', 'phone', 'email', 'birth_date'];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param mixed $columns Feld => Nummer der Spalte (ab 0) oder null; ohne: an den Spaltennamen erkannt
     * @return array<string, mixed>
     */
    public function import(User $actor, mixed $csv, bool $apply, mixed $columns = null, mixed $year = null, mixed $paidOn = null, bool $skipErrors = false): array
    {
        $lines = CsvTable::lines($csv, self::MAX_BYTES);
        [$headerIndex, $separator, $headers] = self::findHeader($lines);
        $passives = $this->app->passives();
        $settings = $passives->settings();
        $personal = $actor->can(Capability::ManagePersonal);

        $map = is_array($columns) ? self::manualMap($columns, count($headers)) : self::autoMap($headers);
        $ignored = [];
        if (!$personal) {
            foreach (self::PERSONAL as $field) {
                if (isset($map[$field])) {
                    $ignored[] = ['column' => $headers[$map[$field]], 'reason' => 'nur mit dem Recht, persönliche Angaben zu ändern'];
                    unset($map[$field]);
                }
            }
        }
        // Spalten, die wie eine Zahlungsart heißen und den Betrag enthalten (nur ohne eigene Spalte für den Betrag)
        $methodColumns = [];
        if (!isset($map['amount'])) {
            $used = array_flip($map);
            foreach ($headers as $i => $h) {
                $method = isset($used[$i]) ? null : self::matchMethod($h, $settings['methods']);
                if ($method !== null) {
                    $methodColumns[$i] = $method;
                }
            }
        }
        if (!isset($map['first_name']) && !isset($map['last_name'])) {
            throw ApiError::invalid('In der Tabelle fehlt eine Spalte „Vorname“ oder „Nachname“. Bitte die Spalten zuordnen.');
        }

        $current = (int) $this->app->time()->today()->format('Y');
        $paymentYear = $year === null || $year === '' ? $current : Input::integer($year);
        if ($paymentYear === null || $paymentYear < PassiveMemberService::YEAR_MIN || $paymentYear > $current + 1) {
            throw ApiError::invalid(sprintf('Bitte für den Beitrag ein Jahr zwischen %d und %d angeben.', PassiveMemberService::YEAR_MIN, $current + 1));
        }
        $fallbackDate = $paidOn === null || $paidOn === '' ? $this->app->time()->today()->format('Y-m-d') : $paidOn;
        if (!Input::isDate($fallbackDate)) {
            throw ApiError::invalid('Bitte das Datum für Zahlungen ohne Datum angeben (JJJJ-MM-TT).');
        }
        /** @var string $fallbackDate */

        $existing = [];
        foreach ($passives->repository()->all() as $r) {
            $existing[self::key(PersonName::join($r['first_name'], $r['last_name']))] = $r['id'];
        }
        $caretakers = $this->caretakerIndex();
        $seen = [];
        $rows = [];
        $planned = [];
        $showPersonal = $actor->can(Capability::ViewPersonal);
        foreach ($lines as $i => $line) {
            if ($i <= $headerIndex || CsvTable::blank($line)) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ApiError::invalid(sprintf('Höchstens %d Passive auf einmal.', self::MAX_ROWS));
            }
            $cells = CsvTable::cells($line, $separator);
            $get = static fn (string $field): string => isset($map[$field]) ? self::cell($cells[$map[$field]] ?? '') : '';
            $row = ['line' => $i + 1, 'first_name' => Text::line($get('first_name'), PersonName::MAX), 'last_name' => Text::line($get('last_name'), PersonName::MAX)];
            $row['name'] = PersonName::join($row['first_name'], $row['last_name']);
            $warnings = [];
            try {
                $key = self::key($row['name']);
                foreach (['first_name' => 'Vorname', 'last_name' => 'Nachname'] as $field => $label) {
                    if ($row[$field] !== '' && preg_match('/\p{L}/u', $row[$field]) !== 1) {
                        throw ApiError::invalid(sprintf('Der %s „%s“ enthält keine Buchstaben (z. B. eine Summenzeile).', $label, $row[$field]));
                    }
                }
                if ($key !== '' && isset($seen[$key])) {
                    throw ApiError::invalid(sprintf('Der Name steht schon in Zeile %d. Gleichnamige bitte unterscheidbar machen.', $seen[$key]));
                }
                if ($key !== '') {
                    $seen[$key] = $row['line'];
                }
                if ($key !== '' && isset($existing[$key])) {
                    $row['existing'] = true;
                    $rows[] = $row;
                    continue;
                }
                $in = ['first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'joined' => $get('joined'), 'note' => $get('note')];
                $caretaker = $this->caretaker($get('caretaker'), $caretakers, $warnings);
                $in['caretaker_id'] = $caretaker['id'] ?? null;
                if ($personal) {
                    $in += $this->personal($actor, $get, $warnings);
                }
                $values = $passives->prepare($actor, $in);
                $payment = $this->payment($cells, $get, $methodColumns, $settings, $paymentYear, $fallbackDate);
                $row['caretaker'] = $caretaker;
                $row['joined'] = $values['joined'] ?? '';
                $row['note'] = $values['note'] ?? '';
                if ($showPersonal && $personal) {
                    $row['phone'] = $values['phone'] ?? '';
                    $row['email'] = $values['email'] ?? '';
                    $row['birth_date'] = $values['birth_date'] ?? null;
                    $row['address'] = isset($in['address']) ? Address::parse($in['address']) : null;
                }
                $row['payment'] = $payment;
                $planned[] = ['values' => $values, 'payment' => $payment];
            } catch (ApiError $e) {
                $row['error'] = $e->getMessage();
            }
            if ($warnings !== []) {
                $row['warnings'] = $warnings;
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw ApiError::invalid('Die Tabelle enthält außer den Spaltennamen keine Zeilen.');
        }
        $errors = array_values(array_filter($rows, static fn (array $r): bool => isset($r['error'])));
        if ($apply && $errors !== [] && !$skipErrors) {
            throw ApiError::invalid(sprintf('Zeile %d: %s', $errors[0]['line'], $errors[0]['error']) . (count($errors) > 1 ? sprintf(' (und %d weitere Fehler)', count($errors) - 1) : ''));
        }
        if ($apply && $planned !== []) {
            $passives->repository()->insertMany($planned, $actor->id, $this->app->time()->nowUtc());
        }
        $fieldsUsed = [];
        foreach ($map as $field => $index) {
            $fieldsUsed[$field] = $index;
        }
        return [
            'headers' => $headers,
            'header_line' => $headerIndex + 1,
            'columns' => (object) $fieldsUsed,
            'method_columns' => array_map(static fn (int $i, string $m): array => ['column' => $i, 'method' => $m], array_keys($methodColumns), array_values($methodColumns)),
            'ignored' => $ignored,
            'personal' => $personal,
            'year' => $paymentYear,
            'paid_on' => $fallbackDate,
            'methods' => $settings['methods'],
            'rows' => $rows,
            'new' => count($planned),
            'payments' => count(array_filter($planned, static fn (array $p): bool => $p['payment'] !== null)),
            'existing' => count(array_filter($rows, static fn (array $r): bool => !empty($r['existing']))),
            'errors' => count($errors),
            'applied' => $apply,
        ];
    }

    /**
     * Kopfzeile suchen: die erste der ersten Zeilen mit „Vorname“ oder „Nachname“, sonst die erste nicht leere.
     *
     * @param list<string> $lines
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private static function findHeader(array $lines): array
    {
        $first = null;
        $checked = 0;
        foreach ($lines as $i => $line) {
            if (CsvTable::blank($line)) {
                continue;
            }
            $separator = CsvTable::separator($line);
            $cells = CsvTable::cells($line, $separator);
            $first ??= [$i, $separator, $cells];
            $map = self::autoMap($cells);
            if (isset($map['first_name']) || isset($map['last_name'])) {
                return [$i, $separator, $cells];
            }
            if (++$checked >= self::HEADER_SEARCH) {
                break;
            }
        }
        if ($first === null) {
            throw ApiError::invalid('Die Tabelle enthält keine Zeilen.');
        }
        return $first;
    }

    /** Spaltenname zum Vergleich: klein, ohne Leerzeichen, Punkte, Binde-, Schräg- und Unterstriche. */
    private static function norm(string $header): string
    {
        return (string) preg_replace('/[\s._\/-]+/u', '', mb_strtolower(trim($header)));
    }

    /**
     * Spalten an ihren Namen erkennen; die erste passende gewinnt.
     *
     * @param list<string> $headers
     * @return array<string, int>
     */
    private static function autoMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $i => $h) {
            $n = self::norm($h);
            foreach (self::COLUMNS as $field => $names) {
                if (!isset($map[$field]) && in_array($n, $names, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    /**
     * Zuordnung aus der App: Feld => Spalte (ab 0) oder null. Unbekannte Felder sind ein Fehler, ebenso eine Spalte für zwei Felder.
     *
     * @param array<mixed> $columns
     * @return array<string, int>
     */
    private static function manualMap(array $columns, int $count): array
    {
        $map = [];
        foreach ($columns as $field => $raw) {
            if (!is_string($field) || !isset(self::COLUMNS[$field])) {
                throw ApiError::invalid('Unbekanntes Feld in der Zuordnung der Spalten.');
            }
            if ($raw === null || $raw === '' || $raw === -1) {
                continue;
            }
            $i = Input::integer($raw);
            if ($i === null || $i < 0 || $i >= $count) {
                throw ApiError::invalid('Die Zuordnung nennt eine Spalte, die es nicht gibt.');
            }
            if (in_array($i, $map, true)) {
                throw ApiError::invalid('Eine Spalte kann nur einem Feld zugeordnet werden.');
            }
            $map[$field] = $i;
        }
        return $map;
    }

    /**
     * Zahlungsart zu einem Spaltennamen: gleich (ohne Groß- und Kleinschreibung) oder eine Abkürzung mit mindestens drei
     * Buchstaben (z. B. „Überw.“ für „Überweisung“).
     *
     * @param list<string> $methods
     */
    private static function matchMethod(string $text, array $methods): ?string
    {
        $n = self::norm($text);
        if ($n === '') {
            return null;
        }
        foreach ($methods as $m) {
            if (self::norm($m) === $n) {
                return $m;
            }
        }
        $abbrev = rtrim(mb_strtolower(trim($text)), '.');
        if (mb_strlen($abbrev) < 3) {
            return null;
        }
        $hits = array_values(array_filter($methods, static fn (string $m): bool => str_starts_with(mb_strtolower($m), $abbrev)));
        return count($hits) === 1 ? $hits[0] : null;
    }

    /** Zelle ohne Buchstaben und Ziffern (z. B. „-“ oder „- - -“) gilt als leer. */
    private static function cell(string $raw): string
    {
        $v = trim($raw);
        return preg_match('/[\p{L}\p{N}]/u', $v) === 1 ? $v : '';
    }

    private static function key(string $name): string
    {
        return mb_strtolower(Text::line($name));
    }

    /**
     * Datum am Anfang einer Zelle: TT.MM.JJJJ, TT.MM. (dann $year), JJJJ-MM-TT; Text danach zählt nicht. null, wenn keins.
     */
    private static function date(string $raw, ?int $year): ?string
    {
        $v = trim($raw);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)/', $v, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})?(?!\d)/', $v, $m) === 1) {
            [$d, $mo] = [(int) $m[1], (int) $m[2]];
            $y = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $year;
            if ($y === null) {
                return null;
            }
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Adresse aus den Spalten für Address::parse; das Land als ISO-Code (DE) oder Name (Deutschland), sonst als anderes
     * Land mit seinem Namen. Eine Hausnummer in der Spalte Straße trennt Address::parse ab.
     *
     * @param \Closure(string): string $get
     * @return array<string, string>
     */
    private static function address(\Closure $get): array
    {
        $land = Text::line($get('country'));
        $country = Address::DEFAULT_COUNTRY;
        if ($land !== '') {
            $country = isset(Address::COUNTRIES[strtoupper($land)]) ? strtoupper($land) : Address::OTHER;
            foreach (Address::COUNTRIES as $code => [$name]) {
                if (mb_strtolower($name) === mb_strtolower($land)) {
                    $country = $code;
                }
            }
        }
        $a = ['street' => $get('street'), 'house_number' => $get('house_number'), 'extra' => $get('extra'), 'postal_code' => $get('postal_code'), 'city' => $get('city'), 'country' => $country];
        return $a + ['country_name' => $country === Address::OTHER ? $land : ''];
    }

    /**
     * Adresse, Telefon, E-Mail und Geburtsdatum einer Zeile. Was nicht stimmt (z. B. eine Adresse im Ausland ohne
     * Spalte Land), bleibt leer, und die Zeile bekommt einen Hinweis: Der Passive wird trotzdem angelegt.
     *
     * @param \Closure(string): string $get
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function personal(User $actor, \Closure $get, array &$warnings): array
    {
        $birth = self::date($get('birth_date'), null);
        $fields = [
            'address' => ['Adresse', self::address($get)],
            'phone' => ['Telefon', $get('phone')],
            'email' => ['E-Mail', $get('email')],
            'birth_date' => ['Geburtsdatum', $birth],
        ];
        $out = [];
        foreach ($fields as $key => [$label, $value]) {
            if ($key === 'birth_date' && $birth === null && $get('birth_date') !== '') {
                $warnings[] = sprintf('Geburtsdatum „%s“ ist kein Datum (TT.MM.JJJJ) und wird nicht übernommen.', $get('birth_date'));
                continue;
            }
            try {
                $this->app->passives()->prepare($actor, ['first_name' => '-', 'last_name' => '-', $key => $value]);
                $out[$key] = $value;
            } catch (ApiError $e) {
                $warnings[] = sprintf('%s nicht übernommen: %s', $label, $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Mitglieder, die Betreuer sein können, mit Vor- und Nachnamen (klein).
     *
     * @return list<array{id: int, name: string, first: string, last: string}>
     */
    private function caretakerIndex(): array
    {
        $out = [];
        foreach ($this->app->users->list(false) as $u) {
            if (!$u->isMember()) {
                continue;
            }
            [$first, $last] = $u->firstName !== '' || $u->lastName !== '' ? [$u->firstName, $u->lastName] : PersonName::split($u->name);
            $out[] = ['id' => $u->id, 'name' => $u->name, 'first' => self::key($first), 'last' => self::key($last)];
        }
        return $out;
    }

    /**
     * Betreuer zu einem Eintrag der Tabelle; passt keins oder mehr als eins, null mit Hinweis.
     *
     * @param list<array{id: int, name: string, first: string, last: string}> $index
     * @param list<string> $warnings
     * @return array{id: int, name: string}|null
     */
    private function caretaker(string $raw, array $index, array &$warnings): ?array
    {
        $text = Text::line($raw);
        if ($text === '') {
            return null;
        }
        $t = self::key($text);
        $hits = array_values(array_filter($index, static fn (array $c): bool => self::key($c['name']) === $t || ($c['first'] !== '' && $c['last'] !== '' && ($t === $c['first'] . ' ' . $c['last'] || $t === $c['last'] . ', ' . $c['first']))));
        if ($hits === [] && preg_match('/^(\p{L})\.?\s+(.+)$/u', $t, $m) === 1) {
            $hits = array_values(array_filter($index, static fn (array $c): bool => $c['last'] === $m[2] && mb_substr($c['first'], 0, 1) === $m[1]));
        }
        if (count($hits) === 1) {
            return ['id' => $hits[0]['id'], 'name' => $hits[0]['name']];
        }
        $warnings[] = $hits === []
            ? sprintf('Betreuer „%s“ ist kein Mitglied; der Passive wird ohne Betreuer angelegt.', $text)
            : sprintf('Betreuer „%s“ passt zu mehreren Mitgliedern (%s); der Passive wird ohne Betreuer angelegt.', $text, implode(', ', array_column($hits, 'name')));
        return null;
    }

    /** Betrag in Cent aus „25“, „25,00“, „25.50“ oder „25 €“; null, wenn keine Zahl. */
    private static function cents(string $raw): ?int
    {
        $v = trim(str_replace(['€', 'EUR', 'Euro', ' '], '', $raw));
        if (preg_match('/^(\d{1,6})(?:[,.](\d{1,2}))?$/', $v, $m) !== 1) {
            return null;
        }
        return (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);
    }

    /**
     * Zahlung einer Zeile, geprüft wie beim Eintragen; null ohne Zahlung.
     *
     * @param list<string> $cells
     * @param \Closure(string): string $get
     * @param array<int, string> $methodColumns
     * @param array{fee_cents: int, methods: list<string>} $settings
     * @return Payment|null
     */
    private function payment(array $cells, \Closure $get, array $methodColumns, array $settings, int $year, string $fallbackDate): ?array
    {
        $method = '';
        $amount = '';
        if ($methodColumns !== []) {
            $found = [];
            foreach ($methodColumns as $i => $m) {
                $v = self::cell($cells[$i] ?? '');
                if ($v !== '') {
                    $found[$m] = $v;
                }
            }
            if (count($found) > 1) {
                throw ApiError::invalid('Beträge bei mehreren Zahlungsarten (' . implode(', ', array_keys($found)) . '); bitte nur eine.');
            }
            if ($found !== []) {
                $method = (string) array_key_first($found);
                $amount = $found[$method];
            }
        }
        if ($method === '' && $get('method') !== '') {
            $method = self::matchMethod($get('method'), $settings['methods'])
                ?? throw ApiError::invalid(sprintf('Die Zahlungsart „%s“ ist nicht eingerichtet. Eingerichtet: %s.', $get('method'), implode(', ', $settings['methods'])));
            $amount = $get('amount');
        } elseif ($method === '' && $get('amount') !== '') {
            throw ApiError::invalid('Zum Betrag fehlt die Zahlungsart.');
        }
        if ($method === '') {
            return null;
        }
        $cents = self::cents($amount);
        if ($cents === null && $settings['fee_cents'] > 0) {
            $cents = $settings['fee_cents'];
        }
        if ($cents === null) {
            throw ApiError::invalid(sprintf('„%s“ ist kein Betrag (z. B. 25 oder 25,00).', $amount));
        }
        $paidOn = $fallbackDate;
        if ($get('paid_on') !== '') {
            $paidOn = self::date($get('paid_on'), $year) ?? $fallbackDate;
        }
        return $this->app->passives()->checkPayment(['year' => $year, 'amount_cents' => $cents, 'paid_on' => $paidOn, 'method' => $method]);
    }
}
