<?php

declare(strict_types=1);

namespace KompanieApp\Contract;

/**
 * Das gemeinsame API-Szenario: Rechte, Validierung, Summen, Rollen, Passwörter,
 * Termine. Läuft gegen jeden Adapter über einen ApiDriver.
 * Legt eigene Testbenutzer an und löscht sie am Ende wieder.
 */
final class ApiScenario
{
    /** @var int[] */
    private array $users = [];

    public function __construct(private readonly ApiDriver $d, private readonly Checks $c)
    {
    }

    public function run(): void
    {
        try {
            $this->scores();
            $this->createUsers();
            $this->password();
            $this->roles();
            $this->events();
            $this->config();
        } finally {
            $this->d->actAs(null);
            foreach (array_reverse($this->users) as $id) {
                $this->d->deleteUser($id);
            }
        }
    }

    private function user(string $login, string $level): int
    {
        $id = $this->d->createUser($login, $level);
        $this->users[] = $id;
        return $id;
    }

    /** @return array{0: int, 1: mixed} */
    private function req(string $method, string $path, array $body = [], array $query = []): array
    {
        [$s, $data] = $this->d->request($method, $path, $body, $query);
        return [$s, $data];
    }

    private function scores(): void
    {
        $c = $this->c;
        $this->spiess = $this->user('kptest_spiess', 'manager');
        $this->m1 = $this->user('kptest_mitglied1', 'member');
        $this->m2 = $this->user('kptest_mitglied2', 'member');
        [$m1, $m2] = [$this->m1, $this->m2];

        $c->section('Nicht angemeldet');
        $this->d->actAs(null);
        $c->ok($this->req('GET', '/me')[0] === 401, 'GET /me ohne Anmeldung → 401');
        $c->ok($this->req('GET', '/me/points')[0] === 401, 'GET /me/points ohne Anmeldung → 401');

        $c->section('Mitglied darf nichts verwalten');
        $this->d->actAs($m1);
        $c->ok($this->req('GET', '/members')[0] === 403, 'GET /members als Mitglied → 403');
        $c->ok($this->req('GET', "/members/$m2")[0] === 403, 'GET /members/{id} als Mitglied → 403');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$m1], 'points' => 100, 'reason' => 'Selbstbedienung'])[0] === 403, 'POST /points als Mitglied → 403');
        $c->ok($this->req('POST', '/penalties', ['user_ids' => [$m2], 'amount_cents' => 500, 'reason' => 'x'])[0] === 403, 'POST /penalties als Mitglied → 403');
        $c->ok($this->req('POST', '/catalog', ['title' => 'x', 'points' => 1])[0] === 403, 'POST /catalog als Mitglied → 403');
        [$s, $d] = $this->req('GET', '/me');
        $c->ok($s === 200 && $d['level'] === 'member', 'GET /me als Mitglied → level member');

        $c->section('Verwalter vergibt Punkte und Strafen');
        $this->d->actAs($this->spiess);
        [$s, $d] = $this->req('GET', '/me');
        $c->ok($s === 200 && $d['level'] === 'manager', 'GET /me als Verwalter → level manager');

        [$s, $cat] = $this->req('POST', '/catalog', ['title' => 'Teilnahme Sommerfest', 'points' => 5]);
        $c->ok($s === 201 && $cat['points'] === 5, 'Katalogeintrag anlegen');
        $this->catalogId = $cat['id'] ?? 0;
        [$s, $d] = $this->req('PATCH', '/catalog/' . $this->catalogId, ['active' => false]);
        $c->ok($s === 200 && $d['active'] === false, 'Katalogeintrag deaktivieren');
        [, $list] = $this->req('GET', '/catalog');
        [, $all] = $this->req('GET', '/catalog', [], ['all' => '1']);
        $c->ok(!in_array($this->catalogId, array_column($list, 'id'), true) && in_array($this->catalogId, array_column($all, 'id'), true), 'Inaktive Einträge nur mit all=1');
        $this->req('PATCH', '/catalog/' . $this->catalogId, ['active' => true]);

        [$s, $d] = $this->req('POST', '/points', ['user_ids' => [$m1, $m2], 'catalog_id' => $this->catalogId, 'date' => '2026-06-27']);
        $c->ok($s === 201 && count($d) === 2, 'Punkte aus Katalog an zwei Mitglieder');
        $c->ok(($d[0]['points'] ?? 0) === 5 && ($d[0]['reason'] ?? '') === 'Teilnahme Sommerfest' && ($d[0]['date'] ?? '') === '2026-06-27', 'Punkte, Grund und Datum aus Katalog übernommen');
        $c->ok(($d[0]['created_by_name'] ?? '') !== '', 'Angelegt-von-Name gesetzt');

        [$s, $d] = $this->req('POST', '/points', ['user_ids' => [$m1], 'points' => 3, 'reason' => 'Aufbau Maifest']);
        $c->ok($s === 201, 'Freie Punkte an Mitglied 1');
        $freePoint = $d[0]['id'] ?? 0;

        $c->ok($this->req('POST', '/points', ['user_ids' => [$m1], 'points' => 0, 'reason' => 'x'])[0] === 400, '0 Punkte → 400');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$m1], 'points' => 1.5, 'reason' => 'x'])[0] === 400, 'Kommazahl bei Punkten → 400');
        $c->ok($this->req('POST', '/points', ['user_ids' => [999999], 'points' => 1, 'reason' => 'x'])[0] === 400, 'Unbekanntes Mitglied → 400');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$m1], 'points' => 1, 'reason' => 'x', 'date' => '2026-02-30'])[0] === 400, 'Ungültiges Datum → 400');
        $c->ok($this->req('POST', '/points', ['user_ids' => [], 'points' => 1, 'reason' => 'x'])[0] === 400, 'Kein Mitglied → 400');

        [$s, $d] = $this->req('POST', '/penalties', ['user_ids' => [$m1], 'amount_cents' => 250, 'reason' => 'Zu spät']);
        $c->ok($s === 201 && ($d[0]['amount_cents'] ?? 0) === 250 && ($d[0]['status'] ?? '') === 'open', 'Strafe 2,50 € offen angelegt');
        $pen1 = $d[0]['id'] ?? 0;
        [, $d] = $this->req('POST', '/penalties', ['user_ids' => [$m1], 'amount_cents' => 1000, 'reason' => 'Mütze vergessen']);
        $pen2 = $d[0]['id'] ?? 0;
        [, $d] = $this->req('POST', '/penalties', ['user_ids' => [$m2], 'amount_cents' => 700, 'reason' => 'Handy klingelt']);
        $penM2 = $d[0]['id'] ?? 0;

        $c->ok($this->req('POST', '/penalties', ['user_ids' => [$m1], 'amount_cents' => 0, 'reason' => 'x'])[0] === 400, 'Strafe 0 € → 400');
        $c->ok($this->req('POST', '/penalties', ['user_ids' => [$m1], 'amount_cents' => 100001, 'reason' => 'x'])[0] === 400, 'Strafe > 1.000 € → 400');
        $c->ok($this->req('POST', '/penalties', ['user_ids' => [$m1], 'amount_cents' => 500, 'reason' => ''])[0] === 400, 'Strafe ohne Grund → 400');

        [$s, $d] = $this->req('PATCH', "/penalties/$pen2", ['status' => 'paid']);
        $c->ok($s === 200 && $d['status'] === 'paid' && $d['paid_at'], 'Strafe als bezahlt markiert');
        $c->ok($this->req('PATCH', "/penalties/$pen2", ['status' => 'egal'])[0] === 400, 'Ungültiger Status → 400');

        $c->ok($this->req('DELETE', "/points/$freePoint")[0] === 200, 'Punkte-Eintrag storniert');
        $c->ok($this->req('DELETE', "/points/$freePoint")[0] === 404, 'Doppelte Stornierung → 404');

        [$s, $members] = $this->req('GET', '/members');
        $by = array_column((array) $members, null, 'id');
        $c->ok($s === 200 && ($by[$m1]['points'] ?? -1) === 5 && ($by[$m1]['open_cents'] ?? -1) === 250, 'Übersicht Mitglied 1: 5 Punkte, 2,50 € offen');
        $c->ok(($by[$m2]['points'] ?? -1) === 5 && ($by[$m2]['open_cents'] ?? -1) === 700, 'Übersicht Mitglied 2: 5 Punkte, 7,00 € offen');

        [$s, $d] = $this->req('GET', "/members/$m1");
        $c->ok($s === 200 && count($d['point_list']) === 1 && count($d['penalties']) === 2, 'Detail Mitglied 1 (stornierte Punkte ausgeblendet)');
        $c->ok($this->req('GET', '/members/999999')[0] === 404, 'Unbekanntes Mitglied → 404');

        $c->section('Mitglied sieht nur eigene Daten');
        $this->d->actAs($m1);
        [, $d] = $this->req('GET', '/me');
        $c->ok($d['points'] === 5 && $d['open_cents'] === 250, 'GET /me: Summen Mitglied 1');
        [$s, $d] = $this->req('GET', '/me/points', [], ['user_id' => $m2]);
        $foreign = array_filter((array) $d, static fn (array $r): bool => $r['user_id'] !== $m1);
        $c->ok($s === 200 && count($d) === 1 && $foreign === [], 'GET /me/points?user_id=fremd liefert nur eigene Einträge');
        [, $d] = $this->req('GET', '/me/penalties', [], ['user_id' => $m2]);
        $ids = array_column((array) $d, 'id');
        $c->ok(count($d) === 2 && !in_array($penM2, $ids, true) && in_array($pen1, $ids, true), 'GET /me/penalties zeigt nur eigene Strafen');
        $c->ok($this->req('PATCH', "/penalties/$pen1", ['status' => 'paid'])[0] === 403, 'Mitglied kann eigene Strafe nicht auf bezahlt setzen');
        $c->ok($this->req('DELETE', "/penalties/$pen1")[0] === 403, 'Mitglied kann eigene Strafe nicht stornieren');
    }

    private int $spiess = 0;
    private int $m1 = 0;
    private int $m2 = 0;
    private int $catalogId = 0;

    private function createUsers(): void
    {
        $c = $this->c;
        $c->section('Mitglieder anlegen (nur Admin)');
        $admin = $this->user('kptest_admin', 'admin');

        $this->d->actAs($this->m1);
        $c->ok($this->req('POST', '/users', ['name' => 'Hans Probe'])[0] === 403, 'Mitglied darf keine Benutzer anlegen → 403');
        $this->d->actAs($this->spiess);
        $c->ok($this->req('POST', '/users', ['name' => 'Hans Probe'])[0] === 403, 'Verwalter darf keine Benutzer anlegen → 403');

        $this->d->actAs($admin);
        [$s, $u1] = $this->req('POST', '/users', ['name' => 'Kptest Müller']);
        if (isset($u1['id'])) {
            $this->users[] = $u1['id'];
        }
        $c->ok($s === 201 && $u1['username'] === 'kptest.mueller', 'Admin legt Mitglied an, Benutzername aus Name (Umlaut → ue)');
        $c->ok(strlen($u1['password'] ?? '') === 12 && !preg_match('/[0O1lI]/', $u1['password']), 'Startpasswort: 12 Zeichen, keine verwechselbaren Zeichen');
        $c->ok($this->d->checkPassword($u1['id'] ?? 0, $u1['password'] ?? ''), 'Anmeldung mit Startpasswort funktioniert');

        [$s, $u2] = $this->req('POST', '/users', ['name' => 'Kptest Müller']);
        if (isset($u2['id'])) {
            $this->users[] = $u2['id'];
        }
        $c->ok($s === 201 && $u2['username'] === 'kptest.mueller2', 'Gleicher Name → eindeutiger Benutzername mit Zähler');

        $c->ok($this->req('POST', '/users', ['name' => 'X', 'username' => 'kptest.mueller'])[0] === 400, 'Vergebener Benutzername → 400');
        $c->ok($this->req('POST', '/users', ['name' => 'X', 'username' => 'Mit Leerzeichen!'])[0] === 400, 'Ungültiger Benutzername → 400');
        $c->ok($this->req('POST', '/users', ['name' => 'X', 'email' => 'keine-mail'])[0] === 400, 'Ungültige E-Mail → 400');
        $c->ok($this->req('POST', '/users', ['name' => '   '])[0] === 400, 'Leerer Name → 400');

        [, $members] = $this->req('GET', '/members');
        $row = array_column((array) $members, null, 'id')[$u1['id'] ?? 0] ?? null;
        $c->ok($row !== null && $row['level'] === 'member' && $row['name'] === 'Kptest Müller', 'Neues Mitglied mit Rolle Mitglied in der Liste');

        $c->section('Startpasswort zurücksetzen');
        $this->d->actAs($this->spiess);
        $c->ok($this->req('POST', '/members/' . $u1['id'] . '/password')[0] === 403, 'Verwalter darf keine Passwörter zurücksetzen → 403');
        $this->d->actAs($admin);
        [$s, $d] = $this->req('POST', '/members/' . $u1['id'] . '/password');
        $c->ok($s === 200 && strlen($d['password']) === 12 && $this->d->checkPassword($u1['id'], $d['password']) && !$this->d->checkPassword($u1['id'], $u1['password']), 'Admin erzeugt neues Startpasswort, altes gilt nicht mehr');
        $c->ok($this->req('POST', "/members/$admin/password")[0] === 400, 'Eigenes Passwort nicht über Zurücksetzen');
    }

    private function password(): void
    {
        $c = $this->c;
        $c->section('Eigenes Passwort ändern');
        $this->d->setPassword($this->m2, 'altes-passwort');
        $this->d->actAs(null);
        $body = ['current_password' => 'altes-passwort', 'new_password' => 'neues-passwort'];
        $c->ok($this->req('POST', '/me/password', $body)[0] === 401, 'Ohne Anmeldung → 401');
        $this->d->actAs($this->m2);
        $c->ok($this->req('POST', '/me/password', ['current_password' => 'falsch', 'new_password' => 'neues-passwort'])[0] === 400, 'Falsches aktuelles Passwort → 400');
        $c->ok($this->req('POST', '/me/password', ['current_password' => 'altes-passwort', 'new_password' => 'kurz'])[0] === 400, 'Neues Passwort zu kurz → 400');
        $c->ok($this->req('POST', '/me/password', ['current_password' => 'altes-passwort', 'new_password' => 'altes-passwort'])[0] === 400, 'Neues = altes Passwort → 400');
        $c->ok($this->req('POST', '/me/password', $body + ['user_id' => $this->m1])[0] === 200, 'Passwort geändert');
        $c->ok($this->d->checkPassword($this->m2, 'neues-passwort'), 'Neues Passwort gilt für das eigene Konto');
        $c->ok(!$this->d->checkPassword($this->m1, 'neues-passwort'), 'Fremdes Konto (user_id-Parameter) bleibt unverändert');
    }

    private function roles(): void
    {
        $c = $this->c;
        $c->section('Rollen: Gast, Mitglied, Verwalter, Admin');
        $gast = $this->user('kptest_gast', 'guest');
        $admin = $this->user('kptest_admin2', 'admin');
        $m2 = $this->m2;

        // [Methode, Pfad, Inhalt, erwarteter Status je Rolle: anonym, Gast, Mitglied, Verwalter, Admin]
        $matrix = [
            ['GET', '/config', [], [200, 200, 200, 200, 200]],
            ['GET', '/events', [], [200, 200, 200, 200, 200]],
            ['GET', '/news', [], [200, 200, 200, 200, 200]],
            ['GET', '/gallery', [], [200, 200, 200, 200, 200]],
            ['GET', '/me', [], [401, 200, 200, 200, 200]],
            ['GET', '/me/points', [], [401, 403, 200, 200, 200]],
            ['GET', '/me/penalties', [], [401, 403, 200, 200, 200]],
            ['GET', '/catalog', [], [401, 200, 200, 200, 200]],
            ['GET', '/members', [], [401, 403, 403, 200, 200]],
            ['POST', '/events', ['title' => ''], [401, 403, 403, 403, 400]],
            ['PATCH', "/members/$m2/role", ['level' => 'egal'], [401, 403, 403, 403, 400]],
            ['POST', '/users', ['name' => ''], [401, 403, 403, 403, 400]],
            ['POST', '/points', ['user_ids' => []], [401, 403, 403, 400, 400]],
        ];
        $who = [null, $gast, $this->m1, $this->spiess, $admin];
        foreach ($matrix as [$method, $path, $body, $expected]) {
            $got = [];
            foreach ($who as $uid) {
                $this->d->actAs($uid);
                $got[] = $this->req($method, $path, $body)[0];
            }
            $c->ok($got === $expected, sprintf('%s %s → %s', $method, $path, implode('/', $got)));
        }

        $this->d->actAs($gast);
        [, $d] = $this->req('GET', '/me');
        $c->ok($d['level'] === 'guest', 'GET /me als Gast-Konto → level guest');

        $this->d->actAs($this->spiess);
        [, $members] = $this->req('GET', '/members');
        $c->ok(!in_array($gast, array_column($members, 'id'), true), 'Mitgliederliste enthält keine Gäste');
        [, $members] = $this->req('GET', '/members', [], ['all' => '1']);
        $c->ok(!in_array($gast, array_column($members, 'id'), true), 'Verwalter bekommt auch mit all=1 keine Gäste');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$gast], 'points' => 1, 'reason' => 'x'])[0] === 400, 'Gast kann keine Punkte bekommen → 400');

        $this->d->actAs($admin);
        [, $members] = $this->req('GET', '/members', [], ['all' => '1']);
        $levels = array_column($members, 'level', 'id');
        $c->ok(($levels[$gast] ?? '') === 'guest', 'Admin sieht mit all=1 auch Gäste');

        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'member']);
        $c->ok($s === 200 && $d['level'] === 'member', 'Admin macht Gast zum Mitglied');
        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'manager']);
        $c->ok($s === 200 && $d['level'] === 'manager', 'Admin macht Mitglied zum Verwalter');
        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'guest']);
        $c->ok($s === 200 && $d['level'] === 'guest', 'Zurück zum Gast');
        $c->ok($this->req('PATCH', "/members/$admin/role", ['level' => 'guest'])[0] === 400, 'Eigene Rolle nicht änderbar → 400');

        $other = $this->user('kptest_admin3', 'admin');
        if ($this->d->canAssignAdmin()) {
            [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'admin']);
            $c->ok($s === 200 && $d['level'] === 'admin', 'Standalone: Admin ernennt weiteren Admin');
            [$s] = $this->req('PATCH', "/members/$gast/role", ['level' => 'member']);
            $c->ok($s === 200, 'Standalone: Admin stuft anderen Admin herab');
        } else {
            [$s] = $this->req('PATCH', "/members/$other/role", ['level' => 'guest']);
            [, $d] = $this->req('GET', "/members/$other");
            $c->ok($s === 400 && $d['level'] === 'admin', 'WordPress: Admins sind in der App nicht herabstufbar');
            $c->ok($this->req('PATCH', "/members/$gast/role", ['level' => 'admin'])[0] === 400, 'WordPress: keine Admins in der App ernennen');
        }
    }

    private function events(): void
    {
        $c = $this->c;
        $c->section('Öffentliche Termine');
        $admin = $this->user('kptest_admin4', 'admin');
        $this->d->actAs($admin);
        $tz = new \DateTimeZone('Europe/Berlin');
        $day = static fn (int $n): string => (new \DateTimeImmutable("+$n days", $tz))->format('Y-m-d');

        [$s, $ev1] = $this->req('POST', '/events', ['title' => 'Kptest Sommerfest', 'start_date' => $day(1), 'end_date' => $day(7), 'location' => 'Festplatz', 'url' => 'https://example.org/fest']);
        $c->ok($s === 201 && $ev1['all_day'] === true && $ev1['end'] === $day(8), 'Mehrtägigen Termin anlegen (Ende exklusiv)');
        [$s, $ev2] = $this->req('POST', '/events', ['title' => 'Kptest Vereinsabend', 'start_date' => $day(1), 'start_time' => '19:00', 'end_time' => '23:00']);
        $c->ok($s === 201 && $ev2['all_day'] === false && $ev2['start_time'] === '19:00', 'Termin mit Uhrzeit anlegen');
        [, $old] = $this->req('POST', '/events', ['title' => 'Kptest Alt', 'start_date' => '2020-01-01']);
        $c->ok($this->req('POST', '/events', ['title' => 'X', 'start_date' => '2026-02-30'])[0] === 400, 'Ungültiges Datum → 400');
        $c->ok($this->req('POST', '/events', ['title' => 'X', 'start_date' => $day(7), 'end_date' => $day(1)])[0] === 400, 'Ende vor Start → 400');
        $c->ok($this->req('POST', '/events', ['title' => 'X', 'start_date' => $day(1), 'start_time' => '25:00'])[0] === 400, 'Ungültige Uhrzeit → 400');
        $c->ok($this->req('POST', '/events', ['title' => 'X', 'start_date' => $day(1), 'url' => 'javascript:alert(1)'])[0] === 400, 'javascript:-Link → 400');
        $c->ok($this->req('POST', '/events', ['title' => '<b>X</b>', 'start_date' => $day(1), 'end_time' => '10:00'])[0] === 400, 'Endzeit ohne Startzeit → 400');
        [$s, $d] = $this->req('PATCH', '/events/' . $ev2['id'], ['location' => 'Vereinslokal']);
        $c->ok($s === 200 && $d['location'] === 'Vereinslokal' && $d['start_time'] === '19:00', 'Termin teilweise ändern');
        $c->ok($this->req('PATCH', '/events/999999', ['title' => 'x'])[0] === 404, 'Unbekannter Termin → 404');

        $this->d->actAs(null);
        [, $list] = $this->req('GET', '/events');
        $titles = array_column((array) $list, 'title');
        $c->ok(in_array('Kptest Sommerfest', $titles, true) && !in_array('Kptest Alt', $titles, true), 'Gast sieht kommende, aber keine vergangenen Termine');

        $this->d->actAs($admin);
        foreach ([$ev1['id'], $ev2['id'], $old['id'] ?? 0] as $id) {
            $c->ok($this->req('DELETE', "/events/$id")[0] === 200, "Termin $id löschen");
        }
        $c->ok($this->req('DELETE', '/events/' . $ev1['id'])[0] === 404, 'Gelöschter Termin → 404');
    }

    private function config(): void
    {
        $c = $this->c;
        $c->section('Laufzeit-Konfiguration');
        $this->d->actAs(null);
        [$s, $d] = $this->req('GET', '/config');
        $c->ok($s === 200 && $d['user'] === null && $d['csrf']['token'] === '' && in_array($d['auth']['mode'], ['form', 'redirect'], true), '/config für Gäste ohne Benutzer und Token');
        $c->ok(is_bool($d['features']['points'] ?? null) && is_string($d['version'] ?? null), '/config mit Bereichen und Version');
        $this->d->actAs($this->m1);
        [, $d] = $this->req('GET', '/config');
        $c->ok(($d['user']['id'] ?? 0) === $this->m1 && in_array('member', $d['user']['capabilities'], true) && $d['csrf']['token'] !== '', '/config mit Benutzer, Rechten und Token');
        $c->ok(str_starts_with((string) ($d['csrf']['refreshUrl'] ?? ''), 'http'), '/config nennt die Adresse zum Erneuern des Tokens');
        $c->ok($this->req('GET', '/auth/csrf')[0] === 200, 'Neues CSRF-Token für Angemeldete');
        $this->d->actAs(null);
        $c->ok($this->req('GET', '/auth/csrf')[0] === 401, 'Kein CSRF-Token ohne Anmeldung');
        $c->ok($this->req('GET', '/gibt-es-nicht')[0] === 404, 'Unbekannte Adresse → 404');
    }
}
