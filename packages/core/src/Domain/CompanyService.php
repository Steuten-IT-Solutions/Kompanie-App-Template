<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\CompanyRepository;

/**
 * Kompanieliste (Issue #280, Bereich companies): die Kompanien einer Bruderschaft oder eines Vereins mit Logo,
 * Gründungsjahr, Beschreibung und Links, für alle mit view_companies (anfangs auch Gast, also ohne Anmeldung). Pflegen
 * und Personen zuordnen darf, wer „Kompanien verwalten“ (manage_companies) hat.
 *
 * - Aufgelöste Kompanien (active false) bleiben als „ehemalig“ in der Liste.
 * - Eine Gruppe der Ämter lässt sich verknüpfen (office_group_id): Die App zeigt dann deren laufende Amtszeiten.
 * - Personen sind optional zugeordnet, auch mehreren Kompanien (eindeutig ist das Paar). Wer sie sehen darf, regelt die
 *   Mitgliederliste: nur mit Bereich directory und view_directory, nur Personen, die dort stehen.
 * - Feste Zahl von Abfragen für die Liste, unabhängig von der Zahl der Kompanien und Personen.
 *
 * @phpstan-import-type Company from CompanyRepository
 */
final class CompanyService
{
    public const FEATURE = 'companies';
    public const NAME_MAX = 100;
    public const SHORT_MAX = 30;
    /** Höchstlänge der Beschreibung (bereinigtes HTML). */
    public const DESCRIPTION_MAX = 20000;

    private ?CompanyRepository $repo = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): CompanyRepository
    {
        return $this->repo ??= new CompanyRepository($this->app->db);
    }

    /**
     * Alle Kompanien in ihrer Reihenfolge, für $viewer (null: ohne Anmeldung). Mitglieder (members, member_count) nur,
     * soweit der Betrachter die Mitgliederliste sieht; wer Kompanien verwaltet, bekommt dazu die IDs aller Zugeordneten.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?User $viewer): array
    {
        $who = $viewer ?? $this->app->anonymous();
        $manager = $viewer !== null && $viewer->can(Capability::ManageCompanies);
        $directory = $this->app->features()->enabled('directory') && $who->can(Capability::ViewDirectory);
        $assignments = $this->repository()->assignments();
        $byCompany = [];
        foreach ($assignments as $userId => $ids) {
            foreach ($ids as $id) {
                $byCompany[$id][] = $userId;
            }
        }
        $people = [];
        if ($directory && $assignments !== []) {
            // In der Reihenfolge der Mitgliederliste
            foreach ($this->app->members()->directory() as $i => $m) {
                $people[(int) $m['id']] = ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'pos' => $i];
            }
        }
        $groups = [];
        foreach ($this->app->offices()->repository()->groups() as $g) {
            $groups[$g['id']] = $g['title'];
        }
        $offices = $this->app->features()->enabled('offices');
        return array_map(function (array $c) use ($byCompany, $people, $directory, $manager, $groups, $offices): array {
            $ids = $byCompany[$c['id']] ?? [];
            $out = $this->format($c, $groups, $offices);
            if ($directory) {
                $members = [];
                foreach ($ids as $userId) {
                    if (isset($people[$userId])) {
                        $members[] = $people[$userId];
                    }
                }
                usort($members, static fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);
                $out['members'] = array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name']], $members);
                $out['member_count'] = count($members);
            }
            if ($manager) {
                $out['member_ids'] = $ids;
            }
            return $out;
        }, $this->repository()->all());
    }

    /**
     * @param array<string, mixed> $in name (Pflicht), short_name, founded_year, description, website_url, app_url, office_group_id, active
     * @return array<string, mixed>
     */
    public function create(array $in, User $actor): array
    {
        if (!array_key_exists('name', $in)) {
            throw ApiError::invalid('Bitte den Namen der Kompanie angeben.');
        }
        $data = $this->validate($in);
        $description = $data['description'] ?? '';
        $repo = $this->repository();
        $now = $this->app->time()->nowUtc();
        $id = $this->app->db->transaction(function () use ($repo, $data, $description, $actor, $now): int {
            $id = $repo->insert(['description' => ''] + $data + ['sort' => $repo->maxSort() + 10, 'active' => 1], $now);
            // Bilder der Beschreibung gehören danach der Kompanie (dafür braucht es ihre ID).
            if ($description !== '') {
                $repo->update($id, ['description' => $this->app->richImages()->claim($actor, $description, RichImageService::OWNER_COMPANY, $id)], $now);
            }
            return $id;
        });
        return $this->one($id, $actor);
    }

    /**
     * @param array<string, mixed> $in wie create(), jeweils optional
     * @return array<string, mixed>
     */
    public function update(int $id, array $in, User $actor): array
    {
        $this->find($id);
        $data = $this->validate($in);
        if (isset($data['description'])) {
            // Bilder, die nicht mehr in der Beschreibung stehen, werden gelöscht (es gibt keinen Verlauf).
            $data['description'] = $this->app->richImages()->claim($actor, $data['description'], RichImageService::OWNER_COMPANY, $id, true);
        }
        $this->repository()->update($id, $data, $this->app->time()->nowUtc());
        return $this->one($id, $actor);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->app->db->transaction(function () use ($id): void {
            $this->repository()->delete($id);
            $this->app->richImages()->deleteOwner(RichImageService::OWNER_COMPANY, $id);
        });
    }

    /**
     * Neue Reihenfolge: alle IDs in der gewünschten Anzeigereihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    public function reorder(mixed $ids, User $actor): array
    {
        $repo = $this->repository();
        $companies = [];
        foreach ($repo->all() as $c) {
            $companies[$c['id']] = $c;
        }
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        $known = array_keys($companies);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Einträge angeben.');
        }
        $now = $this->app->time()->nowUtc();
        foreach ($order as $i => $id) {
            $sort = ($i + 1) * 10;
            if ($companies[(int) $id]['sort'] !== $sort) {
                $repo->update((int) $id, ['sort' => $sort], $now);
            }
        }
        return $this->list($actor);
    }

    /**
     * @param mixed $file {name, data (Base64, auch als data:-Adresse)}: PNG, JPG, WebP oder SVG
     * @return array<string, mixed>
     */
    public function uploadLogo(int $id, mixed $file, User $actor): array
    {
        $this->find($id);
        $logo = LogoImage::fromUpload($this->app, $file);
        $this->repository()->saveLogo($id, $logo['type'], $logo['logo'], $logo['version'], $this->app->time()->nowUtc());
        return $this->one($id, $actor);
    }

    /** @return array<string, mixed> */
    public function removeLogo(int $id, User $actor): array
    {
        $this->find($id);
        $this->repository()->saveLogo($id, '', '', '', $this->app->time()->nowUtc());
        return $this->one($id, $actor);
    }

    /**
     * Kompanien je Person für die Mitgliederliste und die Verwaltung (zwei Abfragen), in der Reihenfolge der Liste.
     *
     * @return array<int, list<array{id: int, name: string, short_name: string, active: bool}>>
     */
    public function byUser(): array
    {
        $all = $this->repository()->all();
        $out = [];
        foreach ($this->repository()->assignments() as $userId => $ids) {
            $out[$userId] = self::refs($all, $ids);
        }
        return $out;
    }

    /** @return list<array{id: int, name: string, short_name: string, active: bool}> */
    public function of(int $userId): array
    {
        return $this->repository()->refsOf($userId);
    }

    /**
     * Kompanien einer Person ersetzen (Verwaltung → Mitglied). Neu zuordnen lassen sich nur Mitglieder; wer keines mehr
     * ist, behält seine Zuordnungen, bis sie entfernt werden.
     *
     * @return list<array{id: int, name: string, short_name: string, active: bool}>
     */
    public function assignToUser(int $userId, mixed $companyIds): array
    {
        $user = $this->app->users->find($userId) ?? throw ApiError::notFound('Mitglied nicht gefunden.');
        $known = array_column($this->repository()->all(), 'id');
        $ids = self::ids($companyIds, 'Bitte die Kompanien als Liste angeben.');
        foreach ($ids as $id) {
            if (!in_array($id, $known, true)) {
                throw ApiError::invalid('Unbekannte Kompanie.');
            }
        }
        $current = $this->repository()->companyIdsOf($userId);
        if (!$user->isMember() && array_diff($ids, $current) !== []) {
            throw ApiError::invalid('Kompanien zuordnen lassen sich nur Mitglieder.');
        }
        $this->repository()->replaceOfUser($userId, $ids, $this->app->time()->nowUtc());
        return $this->of($userId);
    }

    /**
     * Personen einer Kompanie ersetzen (gesammelt auf der Seite der Kompanie). Wie assignToUser: neu nur Mitglieder.
     *
     * @return array<string, mixed> die Kompanie
     */
    public function assignMembers(int $id, mixed $userIds, User $actor): array
    {
        $this->find($id);
        $ids = self::ids($userIds, 'Bitte die Personen als Liste angeben.');
        $current = $this->repository()->memberIdsOf($id);
        $users = $this->app->users->findMany($ids);
        foreach ($ids as $userId) {
            $user = $users[$userId] ?? null;
            if ($user === null) {
                throw ApiError::invalid('Unbekannte Person.');
            }
            if (!$user->isMember() && !in_array($userId, $current, true)) {
                throw ApiError::invalid(sprintf('%s ist kein Mitglied und lässt sich nicht zuordnen.', $user->name));
            }
        }
        $this->repository()->replaceOfCompany($id, $ids, $this->app->time()->nowUtc());
        return $this->one($id, $actor);
    }

    /**
     * Für die Auslieferung über companyLogoUrl(): wer die Kompanien sehen darf (ohne Anmeldung mit den Rechten von Gast).
     *
     * @return array{status: int, headers: array<string, string>, body?: string}
     */
    public function logoForDelivery(?User $viewer, int $id, mixed $version): array
    {
        if (!$this->app->features()->enabled(self::FEATURE)) {
            return LogoImage::deny(404);
        }
        if ($this->app->maintenance()->blocks($viewer)) {
            return LogoImage::deny(503);
        }
        if (!($viewer ?? $this->app->anonymous())->can(Capability::ViewCompanies)) {
            return LogoImage::deny($viewer === null ? 401 : 403);
        }
        $company = $this->repository()->find($id);
        $body = $company === null ? null : $this->repository()->logo($id);
        if ($company === null || $body === null) {
            return LogoImage::deny(404);
        }
        $public = $this->app->anonymous()->can(Capability::ViewCompanies);
        return ['status' => 200, 'headers' => LogoImage::headers($this->app, $company['logo_type'], $company['logo_version'], $public, $version), 'body' => $body];
    }

    /** @return Company */
    private function find(int $id): array
    {
        return $this->repository()->find($id) ?? throw ApiError::notFound('Kompanie nicht gefunden.');
    }

    /** @return array<string, mixed> eine Kompanie wie in list() */
    private function one(int $id, User $actor): array
    {
        foreach ($this->list($actor) as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        throw ApiError::notFound('Kompanie nicht gefunden.');
    }

    /**
     * @param Company $c
     * @param array<int, string> $groups Gruppen der Ämter: ID => Bezeichnung
     * @return array<string, mixed>
     */
    private function format(array $c, array $groups, bool $offices): array
    {
        $logo = in_array($c['logo_type'], ['png', 'svg'], true) && $c['logo_version'] !== '';
        $group = $c['office_group_id'] !== null && isset($groups[$c['office_group_id']]) ? $c['office_group_id'] : null;
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'short_name' => $c['short_name'],
            'founded_year' => $c['founded_year'],
            'description' => $c['description'] === '' ? '' : $this->app->richImages()->render($c['description']),
            'website_url' => $c['website_url'],
            'app_url' => $c['app_url'],
            'active' => $c['active'],
            'logo_url' => $logo ? $this->app->env->companyLogoUrl($c['id'], $c['logo_version']) : null,
            'logo_type' => $logo ? $c['logo_type'] : null,
            // Gruppe der Ämter: die App zeigt deren laufende Amtszeiten; ohne Bereich Ämter nur für die Pflege
            'office_group' => $group !== null ? ['id' => $group, 'title' => $groups[$group], 'shown' => $offices] : null,
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed> geprüfte Felder
     */
    private function validate(array $in): array
    {
        $data = [];
        if (array_key_exists('name', $in)) {
            $data['name'] = self::line($in['name'], self::NAME_MAX, 'Name', true);
        }
        if (array_key_exists('short_name', $in)) {
            $data['short_name'] = self::line($in['short_name'], self::SHORT_MAX, 'Kurzname', false);
        }
        if (array_key_exists('founded_year', $in)) {
            $data['founded_year'] = $this->year($in['founded_year']);
        }
        if (array_key_exists('description', $in)) {
            if ($in['description'] !== null && !is_string($in['description'])) {
                throw ApiError::invalid('Ungültige Beschreibung.');
            }
            // Bilder übernimmt create()/update() (claim); öffentlich ausgeliefert mit view_companies (Issue #281).
            $html = RichText::sanitize((string) $in['description']);
            $html = RichText::isEmpty($html) ? '' : $html;
            if (strlen($html) > self::DESCRIPTION_MAX) {
                throw ApiError::invalid('Die Beschreibung ist zu lang.');
            }
            $data['description'] = $html;
        }
        $message = 'Bitte einen Link angeben, der mit https:// beginnt, oder das Feld leer lassen.';
        if (array_key_exists('website_url', $in)) {
            $data['website_url'] = Input::httpsUrl($in['website_url'], $message);
        }
        if (array_key_exists('app_url', $in)) {
            $data['app_url'] = Input::httpsUrl($in['app_url'], $message);
        }
        if (array_key_exists('office_group_id', $in)) {
            $group = $in['office_group_id'] === null || $in['office_group_id'] === '' ? null : Input::integer($in['office_group_id']);
            if ($in['office_group_id'] !== null && $in['office_group_id'] !== '' && ($group === null || $this->app->offices()->repository()->findGroup($group) === null)) {
                throw ApiError::invalid('Unbekannte Gruppe der Ämter.');
            }
            $data['office_group_id'] = $group;
        }
        if (array_key_exists('active', $in)) {
            if (!is_bool($in['active'])) {
                throw ApiError::invalid('Ungültiger Wert für „aktiv“.');
            }
            $data['active'] = $in['active'];
        }
        return $data;
    }

    private function year(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $year = Input::integer($raw);
        $max = (int) $this->app->time()->format($this->app->time()->timestamp(), 'Y');
        if ($year === null || $year < OfficeService::YEAR_MIN || $year > $max) {
            throw ApiError::invalid(sprintf('Bitte ein Gründungsjahr zwischen %d und %d angeben oder das Feld leer lassen.', OfficeService::YEAR_MIN, $max));
        }
        return $year;
    }

    private static function line(mixed $raw, int $max, string $label, bool $required): string
    {
        $text = Text::line($raw ?? '');
        if ($required && $text === '') {
            throw ApiError::invalid('Bitte den Namen der Kompanie angeben.');
        }
        if (mb_strlen($text) > $max) {
            throw ApiError::invalid(sprintf('Der %s darf höchstens %d Zeichen lang sein.', $label, $max));
        }
        return $text;
    }

    /** @return list<int> jede ID einmal, in der angegebenen Reihenfolge */
    private static function ids(mixed $raw, string $message): array
    {
        if (!is_array($raw)) {
            throw ApiError::invalid($message);
        }
        $out = [];
        foreach ($raw as $v) {
            $id = Input::integer($v);
            if ($id === null || $id <= 0) {
                throw ApiError::invalid($message);
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }

    /**
     * @param list<Company> $all nach Reihenfolge
     * @param list<int> $ids
     * @return list<array{id: int, name: string, short_name: string, active: bool}>
     */
    private static function refs(array $all, array $ids): array
    {
        $out = [];
        foreach ($all as $c) {
            if (in_array($c['id'], $ids, true)) {
                $out[] = ['id' => $c['id'], 'name' => $c['name'], 'short_name' => $c['short_name'], 'active' => $c['active']];
            }
        }
        return $out;
    }
}
