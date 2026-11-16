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
 * - Angaben: Vor- und Nachname (Pflicht), Adresse (Domain\Address), Telefon, E-Mail-Adresse, Geburtsdatum (JJJJ-MM-TT)
 *   und optional ein Mitglied als Betreuer.
 * - Liste sehen, anlegen, Namen und Betreuer ändern, löschen: wer „Passive verwalten“ (manage_passive) hat. Adresse,
 *   Telefon, E-Mail und Geburtsdatum sieht davon nur, wer view_personal hat, und ändert nur, wer manage_personal hat
 *   (wie bei Mitgliedern).
 * - Ausnahme (bewusst so entschieden): Wer Betreuer ist, sieht unter Meins → Meine Passiven alle Angaben seiner eigenen
 *   Passiven, auch ohne view_personal, aber nur zum Lesen (mine()).
 *
 * @phpstan-import-type PassiveRow from PassiveMemberRepository
 */
final class PassiveMemberService
{
    public const FEATURE = 'passive';
    public const NAME_MAX = 100;
    public const EMAIL_MAX = 100;
    public const BIRTH_YEAR_MIN = 1900;
    /** Felder mit persönlichen Angaben (anzeigen mit view_personal, ändern mit manage_personal) */
    public const PERSONAL = ['address', 'phone', 'email', 'birth_date'];

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
     * @return array{passives: list<array<string, mixed>>, caretakers: list<array{id: int, name: string}>, personal: bool, manage_personal: bool}
     */
    public function list(User $viewer): array
    {
        $rows = $this->repository()->all();
        $personal = $viewer->can(Capability::ViewPersonal);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'caretaker_id'));
        $out = array_map(fn (array $r): array => $this->format($r, $personal, $names), $rows);
        usort($out, self::compare(...));
        return [
            'passives' => $out,
            'caretakers' => $this->caretakers(),
            'personal' => $personal,
            'manage_personal' => $viewer->can(Capability::ManagePersonal),
        ];
    }

    /** @return array<string, mixed> */
    public function get(User $viewer, int $id): array
    {
        $row = $this->find($id);
        return $this->format($row, $viewer->can(Capability::ViewPersonal), new UserNames($this->app->users));
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
        $out = array_map(fn (array $r): array => $this->format($r, true, $names), $this->repository()->ofCaretaker($user->id));
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

    /** @return PassiveRow */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Diesen Passiven gibt es nicht (mehr).');
    }

    /**
     * @param PassiveRow $r
     * @return array<string, mixed>
     */
    private function format(array $r, bool $personal, UserNames $names): array
    {
        $out = [
            'id' => $r['id'],
            'first_name' => $r['first_name'],
            'last_name' => $r['last_name'],
            'name' => PersonName::join($r['first_name'], $r['last_name']),
            'caretaker' => $r['caretaker_id'] !== null ? ['id' => $r['caretaker_id'], 'name' => $names->name($r['caretaker_id'])] : null,
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
