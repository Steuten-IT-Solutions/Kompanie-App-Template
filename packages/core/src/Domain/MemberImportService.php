<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Mitglieder aus einer Tabelle übernehmen (Ersterfassung nach der Installation, auch später): CSV oder, in der App aus
 * einer Excel-Datei gelesen, Tabulator-getrennt. Die Kopfzeile ist Pflicht und bestimmt die Spalten (Reihenfolge egal,
 * Groß- und Kleinschreibung egal, siehe COLUMNS).
 *
 * - status: Mitglied (auch leer), eine andere Rolle, die sich beim Anlegen wählen lässt (nach ihrer Bezeichnung, z. B.
 *   Anwärter), oder ehemalig. Ehemalige werden wie beim Import der Ehemaligen angelegt (FormerMemberService::createImported:
 *   Konto ohne Anmeldung); die anderen bekommen ein Startpasswort, das nur in der Antwort von apply steht.
 * - Handynummer und Adresse (Straße, Hausnummer, Zusatz, PLZ, Ort, Land; Land als Name oder ISO-Code, leer =
 *   Deutschland) werden wie beim Anlegen geprüft (MemberService::prepare).
 * - Geburtstag, Eintritt, Punkte, Dienstgrad und Titel nur mit dem Bereich (und für Punkte, Dienstgrad und Titel mit dem
 *   Recht, sie zu verwalten); sonst wird die Spalte mit Hinweis übergangen. Punkte, Dienstgrad und Titel nur für Rollen mit
 *   Mitgliederbereich.
 * - Wer genau so heißt wie ein vorhandenes Konto, wird übersprungen (existing); doppelte Namen in der Tabelle sind ein
 *   Fehler. Ein zweiter Import derselben Tabelle legt also niemanden doppelt an.
 * - Ohne apply nur die Vorschau (new: was angelegt würde); mit apply erst, wenn keine Zeile einen Fehler hat (new: was
 *   angelegt wurde). Steht eine neue Person in Amtszeiten
 *   nur als freier Name, wird der Eintrag mit ihrem Konto verknüpft (OfficeService::linkNames).
 */
final class MemberImportService
{
    public const MAX_BYTES = 200000;
    public const MAX_ROWS = 500;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FORMER = 'former';

    /** Spalte => Schreibweisen in der Kopfzeile (klein, ohne Leerzeichen, Binde- und Unterstriche) */
    public const COLUMNS = [
        'first_name' => ['vorname', 'firstname'],
        'last_name' => ['nachname', 'name', 'familienname', 'lastname'],
        'status' => ['status', 'rolle', 'mitgliedschaft'],
        'username' => ['benutzername', 'benutzer', 'login', 'username'],
        'email' => ['email', 'mail', 'emailadresse'],
        'phone' => ['handynummer', 'handy', 'mobil', 'mobilnummer', 'mobiltelefon'],
        'street' => ['straße', 'strasse', 'street'],
        'house_number' => ['hausnummer', 'hausnr', 'nr'],
        'extra' => ['adresszusatz', 'zusatz'],
        'postal_code' => ['plz', 'postleitzahl'],
        'city' => ['ort', 'wohnort', 'stadt'],
        'country' => ['land'],
        'birthday' => ['geburtstag', 'geburtsdatum', 'geboren'],
        'joined' => ['eintritt', 'eintrittsdatum', 'eintrittsjahr', 'mitgliedseit'],
        'points' => ['punkte', 'anfangspunkte'],
        'rank' => ['dienstgrad', 'rang'],
        'titles' => ['titel'],
        'left' => ['ausgeschieden', 'austritt', 'austrittsdatum', 'ausgetreten'],
        'reason' => ['grund'],
    ];

    /** Feste Schreibweisen des Status (klein); dazu die Bezeichnungen der Rollen, die sich beim Anlegen wählen lassen */
    private const STATUS = ['' => Roles::MEMBER, 'mitglied' => Roles::MEMBER, 'aktiv' => Roles::MEMBER, 'ehemalig' => self::STATUS_FORMER, 'ehemalige' => self::STATUS_FORMER, 'ehemaliger' => self::STATUS_FORMER];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{columns: array{used: list<string>, ignored: list<array{column: string, reason: string}>}, rows: list<array<string, mixed>>, new: array{active: int, former: int}, existing: int, offices_linked: int, applied: bool, created: list<array<string, mixed>>, error?: string}
     */
    public function import(User $actor, mixed $csv, bool $apply): array
    {
        $lines = CsvTable::lines($csv, self::MAX_BYTES);
        $header = null;
        $separator = ';';
        $map = [];
        $ignored = [];
        foreach ($lines as $i => $line) {
            if (!CsvTable::blank($line)) {
                $separator = CsvTable::separator($line);
                [$map, $ignored] = $this->header(CsvTable::cells($line, $separator), $actor);
                $header = $i;
                break;
            }
        }
        if ($header === null) {
            throw ApiError::invalid('Die Tabelle enthält keine Zeilen.');
        }
        if (!isset($map['first_name'])) {
            throw ApiError::invalid('Die erste Zeile muss die Spaltennamen enthalten, mindestens „Vorname“ (z. B. Vorname;Nachname;Status).');
        }

        $accounts = [];
        foreach ($this->app->users->list(true) as $u) {
            $accounts[mb_strtolower($u->name)][] = $u;
        }
        $context = $this->context();
        $taken = [];
        $seen = [];
        $rows = [];
        $planned = [];
        $existing = 0;
        $new = [self::STATUS_ACTIVE => 0, self::STATUS_FORMER => 0];
        foreach ($lines as $i => $line) {
            if ($i <= $header || CsvTable::blank($line)) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ApiError::invalid(sprintf('Höchstens %d Personen auf einmal.', self::MAX_ROWS));
            }
            $cells = CsvTable::cells($line, $separator);
            $get = static fn (string $key): string => isset($map[$key]) ? ($cells[$map[$key]] ?? '') : '';
            $row = ['line' => $i + 1, 'first_name' => Text::line($get('first_name'), PersonName::MAX), 'last_name' => Text::line($get('last_name'), PersonName::MAX)];
            $row['name'] = PersonName::join($row['first_name'], $row['last_name']);
            try {
                $key = mb_strtolower($row['name']);
                if ($key !== '' && isset($seen[$key])) {
                    throw ApiError::invalid(sprintf('Der Name steht schon in Zeile %d. Gleichnamige bitte unterscheidbar machen (z. B. „I“ und „II“).', $seen[$key]));
                }
                $seen[$key] = $row['line'];
                // Vorhandene Konten bleiben, wie sie sind: nichts weiter prüfen (z. B. ihre E-Mail beim zweiten Import).
                $match = $accounts[$key] ?? [];
                if ($match !== []) {
                    $row['existing'] = ['id' => $match[0]->id, 'name' => $match[0]->name, 'former' => $this->app->formerMembers()->previousRole($match[0]) !== null];
                    $existing++;
                } else {
                    $plan = $this->row($actor, $get, $context, $taken);
                    $row += $plan['out'];
                    $planned[] = $plan;
                }
            } catch (ApiError $e) {
                $row['error'] = $e->getMessage();
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw ApiError::invalid('Die Tabelle enthält außer den Spaltennamen keine Zeilen.');
        }
        foreach ($rows as $row) {
            if (!isset($row['existing']) && !isset($row['error'])) {
                $new[$row['status']]++;
            }
        }
        $errors = array_values(array_filter($rows, static fn (array $r): bool => isset($r['error'])));
        if ($apply && $errors !== []) {
            throw ApiError::invalid(sprintf('Zeile %d: %s', $errors[0]['line'], $errors[0]['error']) . (count($errors) > 1 ? sprintf(' (und %d weitere Fehler)', count($errors) - 1) : ''));
        }
        $names = [];
        foreach ($planned as $plan) {
            $names[mb_strtolower($plan['prepared']['name'])] = null;
        }
        $result = [
            'columns' => ['used' => array_keys($map), 'ignored' => $ignored],
            'rows' => $rows,
            'new' => $new,
            'existing' => $existing,
            'offices_linked' => $this->app->offices()->linkNames($names, false),
            'applied' => false,
            'created' => [],
        ];
        if (!$apply) {
            return $result;
        }
        // Konten lassen sich bei WordPress nicht in einer Transaktion anlegen. Bricht es ab, stehen die schon angelegten
        // (mit ihren Startpasswörtern) im Ergebnis; ein zweiter Lauf findet sie als vorhanden und legt nur den Rest an.
        $done = 0;
        $result['new'] = [self::STATUS_ACTIVE => 0, self::STATUS_FORMER => 0];
        foreach ($planned as $plan) {
            try {
                $names[mb_strtolower($plan['prepared']['name'])] = $this->create($actor, $plan, $result['created']);
            } catch (\Throwable $e) {
                // Auch bei anderen Fehlern (z. B. Datenbank) nicht werfen: Sonst wären die Startpasswörter der schon angelegten verloren.
                if (!$e instanceof ApiError) {
                    error_log('Kompanie-App: Import abgebrochen: ' . $e->getMessage());
                }
                $why = $e instanceof ApiError ? $e->getMessage() : 'Konnte nicht angelegt werden.';
                $result['error'] = sprintf('Nach %d von %d Personen abgebrochen bei „%s“: %s Ein neuer Import legt nur die übrigen an.', $done, count($planned), $plan['prepared']['name'], $why);
                break;
            }
            $result['new'][$plan['status']]++;
            $done++;
        }
        $result['offices_linked'] = $this->app->offices()->linkNames(array_filter($names), true);
        $result['applied'] = true;
        return $result;
    }

    /**
     * Kopfzeile: Spalte => Index. Unbekannte Spalten und Spalten zu ausgeschalteten Bereichen bzw. ohne das Recht werden
     * mit Grund übergangen.
     *
     * @param list<string> $cells
     * @return array{0: array<string, int>, 1: list<array{column: string, reason: string}>}
     */
    private function header(array $cells, User $actor): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $key => $names) {
            foreach ($names as $n) {
                $aliases[$n] = $key;
            }
        }
        $features = $this->app->features();
        $config = $this->app->config;
        $off = static fn (string $feature, string $label): string => sprintf('Bereich %s ist ausgeschaltet', $config->sectionLabel($feature, $label));
        $unavailable = [
            'birthday' => !$features->enabled('birthdays') ? $off('birthdays', 'Geburtstage') : null,
            'points' => !$features->enabled('points') ? $off('points', 'Punkte') : (!$actor->can(Capability::ManagePoints) ? 'du darfst keine Punkte vergeben' : null),
            'rank' => !$features->enabled('ranks') ? $off('ranks', 'Dienstgrade') : (!$actor->can(Capability::ManageRanks) ? 'du darfst keine Dienstgrade vergeben' : null),
            'titles' => !$features->enabled('titles') ? $off('titles', 'Titel') : (!$actor->can(Capability::ManageTitles) ? 'du darfst keine Titel vergeben' : null),
        ];
        $map = [];
        $ignored = [];
        foreach ($cells as $index => $cell) {
            if ($cell === '') {
                continue;
            }
            $key = $aliases[(string) preg_replace('/[\s_.\-]+/u', '', mb_strtolower($cell))] ?? null;
            if ($key === null) {
                $ignored[] = ['column' => $cell, 'reason' => 'unbekannte Spalte'];
            } elseif (isset($map[$key])) {
                $ignored[] = ['column' => $cell, 'reason' => 'doppelt'];
            } elseif (($unavailable[$key] ?? null) !== null) {
                $ignored[] = ['column' => $cell, 'reason' => (string) $unavailable[$key]];
            } else {
                $map[$key] = $index;
            }
        }
        return [$map, $ignored];
    }

    /**
     * Dienstgrade und Titel nach Bezeichnung (klein), einmal je Import.
     *
     * @return array{ranks: array<string, int>, titles: array<string, array{id: int, title: string}>, linked: array<int, mixed>, levels: array<string, string>}
     */
    private function context(): array
    {
        $ranks = [];
        if ($this->app->features()->enabled('ranks')) {
            foreach ($this->app->ranks()->repository()->all(true) as $r) {
                $ranks[mb_strtolower($r['title'])] = $r['id'];
            }
        }
        $titles = [];
        $linked = [];
        if ($this->app->features()->enabled('titles')) {
            foreach ($this->app->titles()->repository()->all(true) as $t) {
                $titles[mb_strtolower($t['title'])] = ['id' => $t['id'], 'title' => $t['title']];
            }
            $linked = $this->app->offices()->linkedTitles();
        }
        // Rollen für neue Konten nach Bezeichnung (klein); feste Schreibweisen gehen vor.
        $levels = [];
        foreach ($this->app->roles()->all() as $key => $r) {
            if ($key === Roles::MEMBER || $r['new_accounts']) {
                $levels[mb_strtolower($r['label'])] = $key;
            }
        }
        return ['ranks' => $ranks, 'titles' => $titles, 'linked' => $linked, 'levels' => self::STATUS + $levels];
    }

    /**
     * Eine Zeile prüfen (ohne doppelte Namen und vorhandene Konten, die prüft import()).
     *
     * @param \Closure(string): string $get Zelle der Spalte
     * @param array{ranks: array<string, int>, titles: array<string, array{id: int, title: string}>, linked: array<int, mixed>, levels: array<string, string>} $context
     * @param array<string, true> $taken
     * @return array{status: string, prepared: array<string, mixed>, out: array<string, mixed>, initial: array<string, mixed>|null, title_ids: list<int>, birthday: array{month: int, day: int, year: int|null}|null, joined: string, former: array{reason: string, joined_on: string, founder: bool, left_on: string}|null}
     */
    private function row(User $actor, \Closure $get, array $context, array &$taken): array
    {
        if ($get('first_name') === '') {
            throw ApiError::invalid('Der Vorname fehlt.');
        }
        $level = $context['levels'][mb_strtolower($get('status'))] ?? null;
        if ($level === null) {
            $labels = array_map(fn (string $key): string => $this->app->roles()->label($key), array_values(array_unique(array_diff($context['levels'], [self::STATUS_FORMER]))));
            throw ApiError::invalid(sprintf('Status „%s“ unbekannt: bitte %s oder ehemalig (leer = %s).', $get('status'), implode(', ', $labels), $this->app->roles()->label(Roles::MEMBER)));
        }
        $status = $level === self::STATUS_FORMER ? self::STATUS_FORMER : self::STATUS_ACTIVE;
        $level = $status === self::STATUS_FORMER ? Roles::MEMBER : $level;
        $today = $this->app->time()->today();
        $out = ['status' => $status, 'level' => $status === self::STATUS_FORMER ? null : $level];

        $former = null;
        $joined = '';
        if ($status === self::STATUS_FORMER) {
            $former = FormerMemberService::importData($get('joined'), $get('left'), $get('reason'), $today);
            $out += $former;
        } else {
            if ($get('left') !== '' || $get('reason') !== '') {
                throw ApiError::invalid('Ausgeschieden und Grund gibt es nur bei Ehemaligen (Status „ehemalig“).');
            }
            if (FormerMemberService::isFounder($get('joined'))) {
                throw ApiError::invalid('„Gründer“ als Eintritt gibt es nur bei Ehemaligen; sonst bitte das Datum oder Jahr angeben.');
            }
            $joined = FormerMemberService::date($get('joined'), 'Eintritt', $today);
            $out['joined_on'] = $joined;
        }

        $birthday = self::birthday($get('birthday'));
        if ($birthday !== '' && preg_match('/^(?:\d{4}-)?\d{2}-\d{2}$/', $birthday) !== 1) {
            throw ApiError::invalid('Geburtstag bitte als TT.MM.JJJJ oder ohne Jahr als TT.MM. angeben.');
        }
        $birthday = $birthday === '' ? null : $this->app->birthdays()->parse($birthday);
        $out['birthday'] = $birthday === null ? '' : ($birthday['year'] !== null ? sprintf('%04d-', $birthday['year']) : '') . sprintf('%02d-%02d', $birthday['month'], $birthday['day']);

        $initial = null;
        $titleIds = [];
        $points = $get('points');
        $rank = $get('rank');
        $titles = $get('titles');
        $memberArea = $status === self::STATUS_ACTIVE && in_array(Capability::Member->value, $this->app->roles()->find($level)['capabilities'] ?? [], true);
        if (!$memberArea && ($points !== '' || $rank !== '' || $titles !== '')) {
            throw ApiError::invalid('Punkte, Dienstgrad und Titel gibt es beim Import nur für Rollen, die zur Kompanie gehören, nicht für Ehemalige.');
        }
        if ($points !== '' || $rank !== '') {
            $rankId = null;
            if ($rank !== '') {
                $rankId = $context['ranks'][mb_strtolower($rank)] ?? throw ApiError::invalid(sprintf('Dienstgrad „%s“ gibt es nicht (bitte zuerst unter Verwaltung anlegen).', $rank));
            }
            $initial = $this->app->ranks()->prepareInitial($actor, ['initial_points' => $points, 'rank_id' => $rankId]);
            $out['points'] = $initial['points'];
            $out['rank'] = $initial['rank']['title'] ?? '';
        }
        if ($titles !== '') {
            $out['titles'] = [];
            foreach (array_filter(array_map('trim', explode(',', $titles)), static fn (string $t): bool => $t !== '') as $title) {
                $t = $context['titles'][mb_strtolower($title)] ?? throw ApiError::invalid(sprintf('Titel „%s“ gibt es nicht (bitte zuerst unter Verwaltung anlegen).', $title));
                if (isset($context['linked'][$t['id']])) {
                    throw ApiError::invalid(sprintf('Titel „%s“ folgt einem Amt und wird dort vergeben.', $t['title']));
                }
                $titleIds[$t['id']] = $t['id'];
                $out['titles'][] = $t['title'];
            }
        }

        // Benutzername und E-Mail zuletzt: prepare() merkt sie sich als vergeben.
        $prepared = $this->app->members()->prepare([
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'username' => $get('username'),
            'email' => $get('email'),
            'phone' => $get('phone'),
            'address' => $this->address($get),
            'level' => $level,
        ], $taken, $actor);
        $out['username'] = $status === self::STATUS_FORMER ? '' : $prepared['login'];
        $out['email'] = $prepared['email'];
        $out['phone'] = $prepared['phone'];
        $out['address'] = $prepared['address'];

        return ['status' => $status, 'prepared' => $prepared, 'out' => $out, 'initial' => $initial, 'title_ids' => array_values($titleIds), 'birthday' => $birthday, 'joined' => $joined, 'former' => $former];
    }

    /**
     * Konto anlegen und die übrigen Angaben eintragen. Scheitert eine Angabe nach dem Anlegen, steht ein Hinweis bei der
     * Person (wie beim Anlegen mehrerer).
     *
     * @param array<string, mixed> $plan aus row()
     * @param list<array<string, mixed>> $created Zugangsdaten der neuen aktiven Konten
     */
    private function create(User $actor, array $plan, array &$created): int
    {
        if ($plan['status'] === self::STATUS_FORMER) {
            $id = $this->app->formerMembers()->createImported($actor, $plan['prepared'], $plan['former']);
            $this->extras($actor, $id, $plan);
            return $id;
        }
        $account = $this->app->members()->insert($plan['prepared']);
        $id = (int) $account['id'];
        $warnings = $this->extras($actor, $id, $plan);
        if ($warnings !== []) {
            $account['warning'] = 'Angelegt, aber es fehlen noch: ' . implode(', ', $warnings) . '. Bitte in den Mitgliedsdetails nachtragen.';
        }
        $created[] = $account;
        return $id;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<string> was nicht übernommen werden konnte
     */
    private function extras(User $actor, int $id, array $plan): array
    {
        $missing = [];
        $steps = [
            'Anfangspunkte bzw. Dienstgrad' => $plan['initial'] !== null ? fn () => $this->app->ranks()->applyInitial($actor, $id, $plan['initial']) : null,
            'Titel' => $plan['title_ids'] !== [] ? fn () => $this->app->titles()->assign($id, $plan['title_ids']) : null,
            'Geburtstag' => $plan['birthday'] !== null ? fn () => $this->app->birthdays()->store($actor, $id, $plan['birthday']) : null,
            'Eintritt' => $plan['joined'] !== '' ? fn () => $this->app->joinYears()->saveFor($actor, $id, ['date' => $plan['joined']]) : null,
        ];
        foreach ($steps as $what => $step) {
            if ($step === null) {
                continue;
            }
            try {
                $step();
            } catch (\Throwable $e) {
                error_log('Kompanie-App: Import, ' . $what . ' nicht übernommen: ' . $e->getMessage());
                $missing[] = $what;
            }
        }
        return $missing;
    }

    /**
     * Adresse aus den Spalten für Address::parse; das Land als ISO-Code (DE) oder Name (Deutschland), sonst als anderes
     * Land mit seinem Namen.
     *
     * @param \Closure(string): string $get
     * @return array<string, string>
     */
    private function address(\Closure $get): array
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
     * Geburtstag aus der Tabelle in die Form von BirthdayService::parse: TT.MM.JJJJ → JJJJ-MM-TT, TT.MM. → MM-TT;
     * JJJJ-MM-TT bleibt. Anderes geht unverändert weiter (row() meldet den Fehler).
     */
    private static function birthday(string $raw): string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})?$/', $raw, $m) === 1) {
            return (isset($m[3]) && $m[3] !== '' ? $m[3] . '-' : '') . sprintf('%02d-%02d', (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m) === 1) {
            return sprintf('%s-%02d-%02d', $m[1], (int) $m[2], (int) $m[3]);
        }
        return $raw;
    }
}
