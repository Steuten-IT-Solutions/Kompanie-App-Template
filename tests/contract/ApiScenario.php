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
            $this->features();
            $this->scores();
            $this->createUsers();
            $this->password();
            $this->roles();
            $this->customRoles();
            $this->candidates();
            $this->ranks();
            $this->titles();
            $this->attendance();
            $this->documents();
            $this->events();
            $this->push();
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

    /** Bereiche (Issue #7): nur Admins schalten; danach sind für die übrigen Abschnitte alle an. */
    private function features(): void
    {
        $c = $this->c;
        $c->section('Bereiche ein- und ausschalten');
        $admin = $this->user('kptest_bereiche', 'admin');
        $member = $this->user('kptest_bereiche_m', 'member');
        $this->d->actAs(null);
        $c->ok($this->req('GET', '/admin/features')[0] === 401, 'GET /admin/features ohne Anmeldung → 401');
        $this->d->actAs($member);
        $c->ok($this->req('GET', '/admin/features')[0] === 403, 'GET /admin/features als Mitglied → 403');
        $c->ok($this->req('PUT', '/admin/features', ['points' => true])[0] === 403, 'PUT /admin/features als Mitglied → 403');

        $this->d->actAs($admin);
        $c->ok($this->req('PUT', '/admin/features', ['inventar' => true])[0] === 400, 'Unbekannter Bereich → 400');
        $c->ok($this->req('PUT', '/admin/features', ['points' => 'ja'])[0] === 400, 'Kein Wahrheitswert → 400');
        [$s, $d] = $this->req('PUT', '/admin/features', ['events' => false]);
        $c->ok($s === 200 && array_column((array) $d, 'enabled', 'key')['events'] === false, 'Termine ausschalten');
        $this->d->actAs(null);
        [$s, $d] = $this->req('GET', '/events');
        $c->ok($s === 403 && ($d['code'] ?? '') === 'feature_disabled', 'Ausgeschalteter Bereich → 403 feature_disabled');

        $this->d->actAs($admin);
        $all = ['events' => true, 'calendar' => true, 'news' => true, 'gallery' => true, 'points' => true, 'penalties' => true, 'ranks' => true, 'titles' => true, 'attendance' => true, 'documents' => true];
        [$s, $d] = $this->req('PUT', '/admin/features', $all);
        $c->ok($s === 200 && !in_array(false, array_column((array) $d, 'enabled', 'key'), true), 'Alle Bereiche einschalten');

        // Standardrollen der Bereiche (Issue #28): Termine, Punkte und Strafen bringen je eine mit.
        $roles = array_column((array) $this->req('GET', '/admin/roles')[1], 'capabilities', 'key');
        $c->ok(($roles['events'] ?? null) === ['member', 'manage_events'] && ($roles['points'] ?? null) === ['member', 'manage_points'] && ($roles['penalties'] ?? null) === ['member', 'manage_penalties'], 'Standardrollen der eingeschalteten Bereiche');
        // Für die folgenden Abschnitte: eine Leitung wie früher (Punkte, Strafen, Termine).
        // Bei wiederholten Läufen auf derselben Installation gibt es sie schon (Namen sind eindeutig).
        $this->leitung = (string) (array_column((array) $this->req('GET', '/admin/roles')[1], 'key', 'label')['Kptest Leitung'] ?? '');
        if ($this->leitung === '') {
            [, $d] = $this->req('POST', '/admin/roles', ['label' => 'Kptest Leitung', 'capabilities' => ['member', 'manage_points', 'manage_penalties', 'manage_events']]);
            $this->leitung = (string) (array_column((array) $d, 'key', 'label')['Kptest Leitung'] ?? '');
        }
        $c->ok($this->leitung !== '', 'Rolle „Kptest Leitung“ vorhanden');
        $this->d->actAs(null);
        $c->ok($this->req('GET', '/events')[0] === 200, 'Eingeschalteter Bereich wieder erreichbar');
    }

    private function scores(): void
    {
        $c = $this->c;
        $this->spiess = $this->user('kptest_spiess', $this->leitung);
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

        $c->section('Leitung vergibt Punkte und Strafen');
        $this->d->actAs($this->spiess);
        [$s, $d] = $this->req('GET', '/me');
        $c->ok($s === 200 && $d['level'] === $this->leitung, 'GET /me als Leitung → ihre Rolle');

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
    /** Schlüssel der Rolle „Kptest Leitung“ (in features() angelegt) */
    private string $leitung = '';
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
        $c->ok($this->req('POST', '/users', ['name' => 'Hans Probe'])[0] === 403, 'Leitung darf keine Benutzer anlegen → 403');

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
        $c->ok($this->req('POST', '/members/' . $u1['id'] . '/password')[0] === 403, 'Leitung darf keine Passwörter zurücksetzen → 403');
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
        $c->section('Rollen: Gast, Mitglied, Verwalter, Leitung, Admin');
        $gast = $this->user('kptest_gast', 'guest');
        $verwalter = $this->user('kptest_verwalter', 'events');
        $admin = $this->user('kptest_admin2', 'admin');
        $m2 = $this->m2;

        // [Methode, Pfad, Inhalt, erwarteter Status je Rolle: anonym, Gast, Mitglied, Termine verwalten, Leitung (eigene Rolle), Admin]
        $matrix = [
            ['GET', '/config', [], [200, 200, 200, 200, 200, 200]],
            ['GET', '/events', [], [200, 200, 200, 200, 200, 200]],
            ['GET', '/news', [], [200, 200, 200, 200, 200, 200]],
            ['GET', '/gallery', [], [200, 200, 200, 200, 200, 200]],
            ['GET', '/me', [], [401, 200, 200, 200, 200, 200]],
            ['GET', '/me/points', [], [401, 403, 200, 200, 200, 200]],
            ['GET', '/me/penalties', [], [401, 403, 200, 200, 200, 200]],
            ['GET', '/catalog', [], [401, 200, 200, 200, 200, 200]],
            ['GET', '/members', [], [401, 403, 403, 403, 200, 200]],
            ['POST', '/events', ['title' => ''], [401, 403, 403, 400, 400, 400]],
            ['PATCH', "/members/$m2/role", ['level' => 'egal'], [401, 403, 403, 403, 403, 400]],
            ['POST', '/users', ['name' => ''], [401, 403, 403, 403, 403, 400]],
            ['POST', '/points', ['user_ids' => []], [401, 403, 403, 403, 400, 400]],
            ['POST', '/penalties', ['user_ids' => []], [401, 403, 403, 403, 400, 400]],
            ['POST', '/catalog', ['label' => ''], [401, 403, 403, 403, 400, 400]],
            ['GET', '/admin/settings', [], [401, 403, 403, 403, 403, 200]],
        ];
        $who = [null, $gast, $this->m1, $verwalter, $this->spiess, $admin];
        foreach ($matrix as [$method, $path, $body, $expected]) {
            $got = [];
            foreach ($who as $uid) {
                $this->d->actAs($uid);
                $got[] = $this->req($method, $path, $body)[0];
            }
            $c->ok($got === $expected, sprintf('%s %s → %s', $method, $path, implode('/', $got)));
        }

        // Punkte und Strafen sind getrennte Rechte; die Mitgliederliste zeigt nur die eigenen Daten.
        $pw = $this->user('kptest_punktewart', 'points');
        $kw = $this->user('kptest_kassenwart', 'penalties');
        $this->d->actAs($pw);
        $c->ok($this->req('POST', '/points', ['user_ids' => []])[0] === 400 && $this->req('POST', '/penalties', ['user_ids' => []])[0] === 403, '„Punkte verwalten“: Punkte ja, Strafen nein');
        $row = array_column((array) $this->req('GET', '/members')[1], null, 'id')[$this->m1] ?? [];
        $c->ok(array_key_exists('points', $row) && $row['points'] !== null && $row['open_cents'] === null, '„Punkte verwalten“ sieht keine offenen Beträge');
        $this->d->actAs($kw);
        $c->ok($this->req('POST', '/penalties', ['user_ids' => []])[0] === 400 && $this->req('POST', '/points', ['user_ids' => []])[0] === 403 && $this->req('POST', '/catalog', ['title' => ''])[0] === 403, '„Strafen verwalten“: Strafen ja, Punkte und Katalog nein');
        $detail = $this->req('GET', '/members/' . $this->m1)[1];
        $c->ok(array_key_exists('points', (array) $detail) && $detail['points'] === null && ($detail['point_list'] ?? [1]) === [] && is_array($detail['penalties'] ?? null), '„Strafen verwalten“ sieht keine Punkte');

        $this->d->actAs($gast);
        [, $d] = $this->req('GET', '/me');
        $c->ok($d['level'] === 'guest', 'GET /me als Gast-Konto → level guest');

        $this->d->actAs($verwalter);
        [, $d] = $this->req('GET', '/config');
        $caps = (array) ($d['user']['capabilities'] ?? []);
        $c->ok(($d['user']['level'] ?? '') === 'events' && in_array('manage_events', $caps, true) && !in_array('manage_points', $caps, true) && !in_array('manage_penalties', $caps, true), 'GET /config mit „Termine verwalten“ → Termine, ohne Punkte/Strafen');
        [$s, $ev] = $this->req('POST', '/events', ['title' => 'Kptest Verwalter-Termin', 'start_date' => '2099-05-01']);
        $c->ok($s === 201, 'Verwalter legt öffentlichen Termin an');
        if (isset($ev['id'])) {
            $c->ok($this->req('DELETE', '/events/' . $ev['id'])[0] === 200, 'Verwalter löscht öffentlichen Termin');
        }
        $this->d->actAs($this->spiess);
        [, $d] = $this->req('GET', '/config');
        $c->ok(in_array('manage_events', (array) ($d['user']['capabilities'] ?? []), true), 'Leitung darf öffentliche Termine pflegen');

        $this->d->actAs($this->spiess);
        [, $members] = $this->req('GET', '/members');
        $c->ok(!in_array($gast, array_column($members, 'id'), true), 'Mitgliederliste enthält keine Gäste');
        [, $members] = $this->req('GET', '/members', [], ['all' => '1']);
        $c->ok(!in_array($gast, array_column($members, 'id'), true), 'Leitung bekommt auch mit all=1 keine Gäste');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$gast], 'points' => 1, 'reason' => 'x'])[0] === 400, 'Gast kann keine Punkte bekommen → 400');

        $this->d->actAs($admin);
        [, $members] = $this->req('GET', '/members', [], ['all' => '1']);
        $levels = array_column($members, 'level', 'id');
        $c->ok(($levels[$gast] ?? '') === 'guest', 'Admin sieht mit all=1 auch Gäste');

        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'member']);
        $c->ok($s === 200 && $d['level'] === 'member', 'Admin macht Gast zum Mitglied');
        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => 'events']);
        $c->ok($s === 200 && $d['level'] === 'events', 'Admin gibt die Standardrolle „Termine verwalten“');
        [$s, $d] = $this->req('PATCH', "/members/$gast/role", ['level' => $this->leitung]);
        $c->ok($s === 200 && $d['level'] === $this->leitung, 'Admin gibt eine eigene Rolle');
        $c->ok($this->req('PATCH', "/members/$gast/role", ['level' => 'manager'])[0] === 400, 'Frühere Vorlage „manager“ gibt es in neuen Installationen nicht → 400');
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

    /** Rollen frei anlegen (Issue #28): Rechte wirken sofort, Punktefaktor beim Buchen, Löschen mit Ersatz. */
    private function customRoles(): void
    {
        $c = $this->c;
        $c->section('Eigene Rollen und Punktefaktor');
        $admin = $this->user('kptest_rollen_admin', 'admin');
        $m = $this->user('kptest_rollen_m', 'member');
        $this->d->actAs($m);
        $c->ok($this->req('GET', '/admin/roles')[0] === 403, 'GET /admin/roles als Mitglied → 403');

        $this->d->actAs($admin);
        [$s, $d] = $this->req('POST', '/admin/roles', ['label' => 'Kptest Jugend', 'capabilities' => ['member'], 'points_factor' => 0.5]);
        $role = array_column((array) $d, null, 'label')['Kptest Jugend'] ?? [];
        $c->ok($s === 201 && ($role['points_factor'] ?? null) == 0.5, 'Rolle „Jugend“ mit Faktor 0,5 angelegt');
        $key = (string) ($role['key'] ?? '');
        [$s, $d] = $this->req('PATCH', "/members/$m/role", ['level' => $key]);
        $c->ok($s === 200 && ($d['level'] ?? '') === $key, 'Rolle zugewiesen');
        [, $d] = $this->req('POST', '/points', ['user_ids' => [$m], 'points' => 5, 'reason' => 'Kptest Faktor']);
        $c->ok(($d[0]['points'] ?? null) == 2.5 && ($d[0]['factor'] ?? null) == 0.5, 'Punkte mit Faktor gebucht: 5 × 0,5 = 2,5');
        $members = array_column((array) $this->req('GET', '/members')[1], 'points', 'id');
        $c->ok(($members[$m] ?? null) == 2.5, 'Summe mit Faktor (beide Datenbanken)');

        [$s] = $this->req('PATCH', '/admin/roles/' . $key, ['capabilities' => []]);
        $this->d->actAs($m);
        $c->ok($s === 200 && $this->req('GET', '/me/points')[0] === 403, 'Rechte entzogen → wirkt sofort');

        $this->d->actAs($admin);
        $c->ok($this->req('DELETE', '/admin/roles/' . $key)[0] === 400, 'Löschen ohne Ersatz, obwohl zugeordnet → 400');
        [$s] = $this->req('DELETE', '/admin/roles/' . $key, [], ['replacement' => 'member']);
        $this->d->actAs($m);
        $c->ok($s === 200 && $this->req('GET', '/me')[1]['level'] === 'member', 'Gelöscht, Person ist wieder Mitglied');

        // Wer Konten anlegen darf, ohne Admin zu sein: Passwörter nur von Mitgliedern mit eigenen Rechten.
        $this->d->actAs($admin);
        [, $d] = $this->req('POST', '/admin/roles', ['label' => 'Kptest Kontenpflege', 'capabilities' => ['member', 'create_users']]);
        $pflege = (string) (array_column((array) $d, 'key', 'label')['Kptest Kontenpflege'] ?? '');
        $holder = $this->user('kptest_rollen_pflege', $pflege);
        $guest = $this->user('kptest_rollen_gast', 'guest');
        $boss = $this->user('kptest_rollen_leitung', $this->leitung);
        $this->d->actAs($holder);
        $c->ok($this->req('POST', "/members/$m/password")[0] === 200, 'Kontenpflege setzt Passwort eines Mitglieds zurück');
        $c->ok($this->req('POST', "/members/$guest/password")[0] === 403, 'Kontenpflege: Passwort eines Gasts → 403');
        $c->ok($this->req('POST', "/members/$boss/password")[0] === 403, 'Kontenpflege: Passwort einer Person mit mehr Rechten → 403');
        $c->ok($this->req('POST', "/members/$admin/password")[0] === 403, 'Kontenpflege: Passwort eines Admins → 403');
        $this->d->actAs($admin);
        $this->req('DELETE', '/admin/roles/' . $pflege, [], ['replacement' => 'member']);
    }

    /** Anwärter (Issue #23): interner Kalender ja, Mitgliederbereich nein; wer Konten anlegt, nimmt sie auf. */
    private function candidates(): void
    {
        $c = $this->c;
        $c->section('Anwärter');
        $admin = $this->user('kptest_anw_admin', 'admin');
        $cand = $this->user('kptest_anwaerter', 'candidate');
        $guest = $this->user('kptest_anw_gast', 'guest');

        // Kalender wie ein Mitglied (ohne eingerichteten Kalender ggf. 503, aber nie „keine Rechte“), sonst nichts.
        $this->d->actAs($this->m1);
        $memberCalendar = $this->req('GET', '/calendar')[0];
        $this->d->actAs($guest);
        $c->ok($this->req('GET', '/calendar')[0] === 403, 'Gast: kein interner Kalender');
        $this->d->actAs($cand);
        $c->ok($this->req('GET', '/calendar')[0] === $memberCalendar && $memberCalendar !== 403, 'Anwärter sieht den internen Kalender');
        foreach (['/me/points', '/me/penalties', '/members', "/members/{$this->m1}"] as $path) {
            $c->ok($this->req('GET', $path)[0] === 403, "Anwärter: GET $path → 403");
        }
        $types = array_column((array) ($this->req('POST', '/me/push/status')[1]['types'] ?? []), 'key');
        $c->ok(in_array('events', $types, true) && !array_intersect(['points', 'penalties'], $types), 'Anwärter: Benachrichtigungen zu Terminen, nicht zu Punkten oder Strafen');
        [, $cfg] = $this->req('GET', '/config');
        $caps = (array) ($cfg['user']['capabilities'] ?? []);
        $c->ok(in_array('calendar', $caps, true) && !in_array('member', $caps, true), '/config: Recht calendar, nicht member');
        $roles = array_column((array) ($cfg['roles'] ?? []), 'label', 'key');
        $c->ok(isset($roles['candidate']), 'Rolle „Anwärter“ in /config');

        // Wer Konten anlegt, legt Anwärter an und nimmt sie auf – nur von Anwärter zu Mitglied.
        $this->d->actAs($admin);
        [, $d] = $this->req('POST', '/admin/roles', ['label' => 'Kptest Aufnahme', 'capabilities' => ['member', 'create_users']]);
        $aufnahme = (string) (array_column((array) $d, 'key', 'label')['Kptest Aufnahme'] ?? '');
        $c->ok($this->req('POST', '/admin/roles', ['label' => 'Kptest Nur Kalender', 'capabilities' => ['calendar']])[0] === 201, 'Eigene Rolle nur mit dem Kalender');
        $c->ok($this->req('POST', '/admin/roles', ['label' => 'Kptest Ungültig', 'capabilities' => ['calendar', 'manage_points']])[0] === 400, 'Verwalten ohne Mitgliederbereich → 400');
        $holder = $this->user('kptest_anw_aufnahme', $aufnahme);
        $this->d->actAs($holder);
        [$s, $u] = $this->req('POST', '/users', ['name' => 'Kptest Anwärterin', 'level' => 'candidate']);
        if (isset($u['id'])) {
            $this->users[] = $u['id'];
        }
        $c->ok($s === 201 && ($u['level'] ?? '') === 'candidate', 'Konto als Anwärter anlegen');
        $c->ok($this->req('POST', '/users', ['name' => 'X', 'level' => 'admin'])[0] === 400, 'Neues Konto als Admin → 400');
        $c->ok($this->req('POST', '/members/' . ($u['id'] ?? 0) . '/password')[0] === 200, 'Passwort eines Anwärters zurücksetzen');
        $list = array_column((array) $this->req('GET', '/members')[1], 'level', 'id');
        $withCand = array_column((array) $this->req('GET', '/members', [], ['candidates' => '1'])[1], 'level', 'id');
        $c->ok(!isset($list[$u['id'] ?? 0]) && ($withCand[$u['id'] ?? 0] ?? '') === 'candidate' && !isset($withCand[$guest]), 'Mitgliederliste: Anwärter nur auf Wunsch, Gäste nie');
        $c->ok($this->req('POST', "/members/{$this->m2}/promote")[0] === 400, 'Mitglied „aufnehmen“ → 400');
        $c->ok($this->req('POST', "/members/$guest/promote")[0] === 400, 'Gast aufnehmen → 400');
        [$s, $d] = $this->req('POST', '/members/' . ($u['id'] ?? 0) . '/promote');
        $c->ok($s === 200 && ($d['level'] ?? '') === 'member', 'Anwärter zum Mitglied gemacht');
        $this->d->actAs($this->spiess);
        $c->ok($this->req('POST', "/members/$cand/promote")[0] === 403, 'Ohne „Mitglieder anlegen“ keine Aufnahme → 403');

        $this->d->actAs($admin);
        $this->req('DELETE', '/admin/roles/' . $aufnahme, [], ['replacement' => 'member']);
        $nur = (string) (array_column((array) $this->req('GET', '/admin/roles')[1], 'key', 'label')['Kptest Nur Kalender'] ?? '');
        if ($nur !== '') {
            $this->req('DELETE', '/admin/roles/' . $nur, [], ['replacement' => 'member']);
        }
    }

    /** Dienstgrade (Issue #9) mit Punkten: Schwellen gelten für alle, Übersicht, eigener Stand. */
    private function ranks(): void
    {
        $c = $this->c;
        $c->section('Dienstgrade');
        $admin = $this->user('kptest_rang_admin', 'admin');
        $m = $this->user('kptest_rang_m', 'member');
        $guest = $this->user('kptest_rang_gast', 'guest');
        $this->d->actAs($admin);
        // Wiederholte Läufe auf derselben Installation: frühere Testeinträge deaktivieren (vergebene lassen sich nicht löschen).
        foreach ((array) $this->req('GET', '/ranks', [], ['all' => '1'])[1] as $r) {
            if (str_starts_with((string) ($r['title'] ?? ''), 'Kptest') && ($r['active'] ?? false)) {
                $this->req('PATCH', '/ranks/' . $r['id'], ['active' => false]);
            }
        }
        $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
        [$s, $a] = $this->req('POST', '/ranks', ['title' => "Kptest Gefreiter $sfx", 'min_points' => 10]);
        [, $b] = $this->req('POST', '/ranks', ['title' => "Kptest Unteroffizier $sfx", 'min_points' => 30]);
        $c->ok($s === 201 && ($a['min_points'] ?? null) === 10, 'Dienstgrad mit Schwelle anlegen');
        $c->ok($this->req('POST', '/ranks', ['title' => "Kptest Falsch $sfx", 'min_points' => 20])[0] === 400, 'Schwelle unter dem vorigen Dienstgrad → 400');

        $this->d->actAs($guest);
        $c->ok($this->req('GET', '/ranks')[0] === 403, 'Gast: GET /ranks → 403');
        $this->d->actAs($m);
        $c->ok($this->req('GET', '/ranks')[0] === 200, 'Mitglied: GET /ranks');
        $c->ok($this->req('PUT', "/members/$m/rank", ['rank_id' => $a['id'] ?? 0])[0] === 403, 'Mitglied befördert nicht → 403');
        $this->d->actAs($this->spiess);
        $c->ok($this->req('GET', '/promotions')[0] === 403, 'Ohne „Dienstgrade verwalten“: keine Übersicht → 403');

        $this->d->actAs($admin);
        $this->req('POST', '/points', ['user_ids' => [$m], 'points' => 5, 'reason' => 'Kptest Rang']);
        $c->ok($this->req('PUT', "/members/$m/rank", ['rank_id' => $a['id'] ?? 0])[0] === 400, 'Befördern unter der Schwelle → 400, auch als Admin');
        $this->req('POST', '/points', ['user_ids' => [$m], 'points' => 10, 'reason' => 'Kptest Rang']);
        [$s, $d] = $this->req('PUT', "/members/$m/rank", ['rank_id' => $a['id'] ?? 0, 'reason' => 'Kptest']);
        $c->ok($s === 200 && ($d['rank']['id'] ?? 0) === ($a['id'] ?? -1) && ($d['points_to_next'] ?? null) === 15, 'Befördert; noch 15 Punkte bis zum nächsten');
        $c->ok($this->req('PUT', "/members/$m/rank", ['rank_id' => null])[0] === 400, 'Degradieren über der Schwelle → 400');
        $rows = array_column((array) $this->req('GET', '/promotions')[1], null, 'user_id');
        $c->ok(isset($rows[$m]) && !isset($rows[$guest]) && $rows[$m]['points_to_next'] === 15, 'Beförderungsübersicht ohne Gäste');
        $this->d->actAs($m);
        [, $me] = $this->req('GET', '/me/rank');
        $c->ok(($me['rank']['id'] ?? 0) === ($a['id'] ?? -1) && ($me['next_rank']['id'] ?? 0) === ($b['id'] ?? -1) && count((array) ($me['history'] ?? [])) === 1, 'Eigener Dienstgrad, nächster und Verlauf');

        $this->d->actAs($admin);
        foreach ([$a, $b] as $r) {
            $this->req('PATCH', '/ranks/' . ($r['id'] ?? 0), ['active' => false]);
        }
    }

    /** Titel (Issue #22): mehrere je Mitglied, Löschschutz, Rechte, Anzeige. */
    private function titles(): void
    {
        $c = $this->c;
        $c->section('Titel');
        $admin = $this->user('kptest_titel_admin', 'admin');
        $m = $this->user('kptest_titel_m', 'member');
        $guest = $this->user('kptest_titel_gast', 'guest');
        $this->d->actAs($admin);
        $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
        [$s, $a] = $this->req('POST', '/titles', ['title' => "Kptest Ehrenmitglied $sfx"]);
        [, $b] = $this->req('POST', '/titles', ['title' => "Kptest Vorsitz $sfx"]);
        $c->ok($s === 201 && ($a['assigned'] ?? null) === 0, 'Titel anlegen');
        $c->ok($this->req('POST', '/titles', ['title' => "kptest ehrenmitglied $sfx"])[0] === 400, 'Doppelter Titel → 400');

        $this->d->actAs($m);
        $c->ok($this->req('GET', '/titles')[0] === 403, 'Mitglied: GET /titles → 403');
        $c->ok($this->req('PUT', "/members/$m/titles", ['title_ids' => [$a['id'] ?? 0]])[0] === 403, 'Mitglied vergibt keine Titel → 403');
        $this->d->actAs($this->spiess);
        $c->ok($this->req('GET', '/titles')[0] === 403, 'Ohne „Titel verwalten“ → 403');

        $this->d->actAs($admin);
        [$s, $d] = $this->req('PUT', "/members/$m/titles", ['title_ids' => [$b['id'] ?? 0, $a['id'] ?? 0]]);
        $c->ok($s === 200 && array_column((array) ($d['titles'] ?? []), 'id') === [$a['id'] ?? -1, $b['id'] ?? -1], 'Mehrere Titel, in der Reihenfolge der Liste');
        $c->ok($this->req('PUT', "/members/$guest/titles", ['title_ids' => [$a['id'] ?? 0]])[0] === 400, 'Gast bekommt keinen Titel → 400');
        $c->ok($this->req('DELETE', '/titles/' . ($a['id'] ?? 0))[0] === 400, 'Zugewiesenen Titel löschen → 400');
        $c->ok($this->req('PATCH', '/titles/' . ($a['id'] ?? 0), ['title' => "Kptest Ehrenkamerad $sfx"])[0] === 200, 'Zugewiesenen Titel umbenennen');
        $list = array_column((array) $this->req('GET', '/members')[1], null, 'id');
        $c->ok(array_column((array) ($list[$m]['titles'] ?? []), 'title') === ["Kptest Ehrenkamerad $sfx", "Kptest Vorsitz $sfx"], 'Titel in der Mitgliederliste');
        $c->ok(count((array) ($this->req('GET', "/members/$m")[1]['titles'] ?? [])) === 2, 'Titel in den Mitgliedsdetails');
        $this->d->actAs($m);
        $c->ok(count((array) ($this->req('GET', '/me')[1]['titles'] ?? [])) === 2, 'Eigene Titel in GET /me');

        $this->d->actAs($admin);
        $this->req('PUT', "/members/$m/titles", ['title_ids' => []]);
        $ok = true;
        foreach ([$a, $b] as $t) {
            $ok = $this->req('DELETE', '/titles/' . ($t['id'] ?? 0))[0] === 200 && $ok;
        }
        $c->ok($ok, 'Nicht mehr zugewiesene Titel löschen');
    }

    /** Anwesenheit (Issue #21): freie Liste, Status, Vorschlag für Punkte einmal je Person, eigene Anwesenheit. */
    private function attendance(): void
    {
        $c = $this->c;
        $c->section('Anwesenheit');
        $admin = $this->user('kptest_anw_admin', 'admin');
        $m = $this->user('kptest_anw_m', 'member');
        $cand = $this->user('kptest_anw_anw', 'candidate');
        $guest = $this->user('kptest_anw_gast', 'guest');
        $this->d->actAs($m);
        $c->ok($this->req('GET', '/attendance')[0] === 403, 'Mitglied: GET /attendance → 403');
        $this->d->actAs($admin);
        [$s, $l] = $this->req('PUT', '/attendance', ['source' => 'free', 'title' => 'Kptest Übung', 'date' => '2026-10-19', 'entries' => [$m => 'present', $cand => 'absent']]);
        $people = array_column((array) ($l['people'] ?? []), null, 'id');
        $c->ok($s === 201 && ($l['counts'] ?? null) === ['present' => 1, 'excused' => 0, 'absent' => 1], 'Freie Liste mit Status anlegen');
        $c->ok(isset($people[$m], $people[$cand]) && !isset($people[$guest]) && $people[$cand]['member'] === false, 'Alle mit einer Rolle, ohne Gäste; Anwärter ohne Punkte');
        $id = (int) ($l['id'] ?? 0);
        $c->ok($this->req('PUT', '/attendance', ['id' => $id, 'entries' => [$guest => 'present']])[0] === 400, 'Gast eintragen → 400');
        [$s, $rows] = $this->req('POST', '/points', ['user_ids' => [$m], 'points' => 3, 'reason' => 'Kptest Übung', 'attendance_id' => $id]);
        $c->ok($s === 201 && ($rows[0]['attendance_id'] ?? null) === $id, 'Punkte aus dem Vorschlag tragen die Liste');
        $c->ok($this->req('POST', '/points', ['user_ids' => [$m], 'points' => 3, 'reason' => 'Kptest Übung', 'attendance_id' => $id])[0] === 400, 'Zweites Mal für dieselbe Liste → 400');
        $c->ok((array_column((array) ($this->req('GET', "/attendance/$id")[1]['people'] ?? []), 'booked', 'id')[$m] ?? false) === true, 'Liste zeigt, wer schon Punkte hat');
        $this->d->actAs($m);
        [, $mine] = $this->req('GET', '/me/attendance');
        $c->ok(in_array($id, array_column((array) ($mine['entries'] ?? []), 'list_id'), true), 'Eigene Anwesenheit');
        $this->d->actAs($admin);
        $c->ok($this->req('DELETE', "/attendance/$id")[0] === 200, 'Liste löschen');
    }

    /** Dokumente (Issue #2): Hochladen (Base64), Prüfung der Signatur, Sichtbarkeit, Kategorien, Rechte. */
    private function documents(): void
    {
        $c = $this->c;
        $c->section('Dokumente');
        $admin = $this->user('kptest_dok_admin', 'admin');
        $m = $this->user('kptest_dok_m', 'member');
        $pdf = base64_encode("%PDF-1.4\n%%EOF\n");
        $this->d->actAs($m);
        $c->ok($this->req('POST', '/documents', ['title' => 'Kptest', 'date' => '2026-10-01', 'file' => ['name' => 'a.pdf', 'data' => $pdf]])[0] === 403, 'Mitglied lädt nicht hoch → 403');
        $this->d->actAs($admin);
        $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
        [, $cat] = $this->req('POST', '/document-categories', ['title' => "Kptest Protokolle $sfx"]);
        [$s, $a] = $this->req('POST', '/documents', ['title' => "Kptest Satzung $sfx", 'date' => '2026-10-01', 'category_id' => $cat['id'] ?? null, 'file' => ['name' => 'satzung.pdf', 'data' => $pdf]]);
        $c->ok($s === 201 && ($a['kind'] ?? '') === 'pdf' && str_contains((string) ($a['url'] ?? ''), 'dokument'), 'PDF hochladen');
        [, $b] = $this->req('POST', '/documents', ['title' => "Kptest Kasse $sfx", 'date' => '2026-10-01', 'visibility' => 'managers', 'file' => ['name' => 'kasse.pdf', 'data' => $pdf]]);
        $c->ok($this->req('POST', '/documents', ['title' => 'Kptest', 'date' => '2026-10-01', 'file' => ['name' => 'a.png', 'data' => $pdf]])[0] === 400, 'Falsche Signatur → 400');
        $this->d->actAs($m);
        $titles = array_column((array) ($this->req('GET', '/documents')[1]['documents'] ?? []), 'title');
        $c->ok(in_array("Kptest Satzung $sfx", $titles, true) && !in_array("Kptest Kasse $sfx", $titles, true), 'Mitglied sieht nur Dokumente für alle');
        $this->d->actAs($admin);
        $this->req('DELETE', '/document-categories/' . ($cat['id'] ?? 0));
        $list = array_column((array) ($this->req('GET', '/documents')[1]['documents'] ?? []), null, 'id');
        $c->ok(array_key_exists((int) ($a['id'] ?? 0), $list) && $list[(int) ($a['id'] ?? 0)]['category_id'] === null, 'Kategorie gelöscht → Dokument ohne Kategorie');
        $ok = true;
        foreach ([$a, $b] as $doc) {
            $ok = $this->req('DELETE', '/documents/' . ($doc['id'] ?? 0))[0] === 200 && $ok;
        }
        $c->ok($ok, 'Dokumente löschen');
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

    /** Ein Gerät mit echtem P-256-Schlüssel (der Server prüft ihn), aber ohne Versand. */
    private static function device(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = $key === false ? [] : (array) (openssl_pkey_get_details($key)['ec'] ?? []);
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $raw = "\x04" . str_pad((string) ($ec['x'] ?? ''), 32, "\0", STR_PAD_LEFT) . str_pad((string) ($ec['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/kptest-' . bin2hex(random_bytes(8)), 'keys' => ['p256dh' => $b64($raw), 'auth' => $b64(random_bytes(16))]];
    }

    /** Web Push (Issue #19): Abos für Konten und Gäste, ohne Nachrichten zu senden. */
    private function push(): void
    {
        $c = $this->c;
        $c->section('Benachrichtigungen');
        $this->d->actAs(null);
        [$s, $d] = $this->req('GET', '/push/key');
        $c->ok($s === 200 && is_bool($d['supported'] ?? null), 'GET /push/key öffentlich');
        if (!($d['supported'] ?? false)) {
            $c->ok(true, 'Server ohne Push-Unterstützung: übrige Prüfungen übersprungen');
            return;
        }
        $c->ok(strlen((string) $d['publicKey']) === 87, 'Öffentlicher Schlüssel (65 Bytes, base64url)');
        $guest = self::device();
        $c->ok($this->req('POST', '/me/push', $guest)[0] === 401, 'POST /me/push ohne Anmeldung → 401');
        $c->ok($this->req('POST', '/push', ['endpoint' => 'https://intern.example.test/x'] + $guest)[0] === 400, 'Push-Adresse eines fremden Servers → 400');
        $c->ok($this->req('POST', '/push', $guest + ['types' => ['points']])[0] === 400, 'Gast ohne erlaubte Arten → 400');
        [$s, $d] = $this->req('POST', '/push', $guest + ['types' => ['events', 'points']]);
        $c->ok($s === 200 && $d['types'] === ['events'], 'Gast abonniert nur öffentliche Arten');
        [$s, $d] = $this->req('POST', '/push/status', ['endpoint' => $guest['endpoint'], 'auth' => $guest['keys']['auth']]);
        $c->ok($s === 200 && ($d['subscription']['types'] ?? null) === ['events'] && !in_array('points', array_column((array) $d['types'], 'key'), true), 'Gast sieht sein Abo und nur öffentliche Arten');
        [, $d] = $this->req('POST', '/push/status', ['endpoint' => $guest['endpoint'], 'auth' => self::device()['keys']['auth']]);
        $c->ok(is_array($d) && array_key_exists('subscription', $d) && $d['subscription'] === null, 'Ohne das Geheimnis des Geräts kein Abo sichtbar');
        $c->ok($this->req('PUT', '/push', ['endpoint' => $guest['endpoint'], 'auth' => self::device()['keys']['auth'], 'types' => ['news']])[0] === 404, 'Fremdes Gast-Abo ändern → 404');
        $c->ok($this->req('POST', '/push', ['endpoint' => $guest['endpoint'], 'keys' => self::device()['keys']])[0] === 403, 'Gerät mit anderem Geheimnis übernehmen → 403');

        $this->d->actAs($this->m1);
        [$s, $d] = $this->req('POST', '/me/push', $guest + ['types' => ['points', 'news']]);
        $c->ok($s === 200 && $d['types'] === ['points', 'news'] && $d['details'] === false, 'Mitglied übernimmt das Gerät mit Punkten');
        [$s, $d] = $this->req('PUT', '/me/push', ['endpoint' => $guest['endpoint'], 'details' => true]);
        $c->ok($s === 200 && $d['details'] === true && $d['types'] === ['points', 'news'], 'Details einschalten, Arten bleiben');
        $this->d->actAs($this->m2);
        $c->ok($this->req('PUT', '/me/push', ['endpoint' => $guest['endpoint'], 'types' => ['news']])[0] === 404, 'Abo eines anderen Mitglieds ändern → 404');
        [, $d] = $this->req('DELETE', '/me/push', ['endpoint' => $guest['endpoint']]);
        $c->ok(($d['deleted'] ?? null) === false, 'Abo eines anderen Mitglieds löschen → nichts gelöscht');
        $this->d->actAs(null);
        [, $d] = $this->req('DELETE', '/push', ['endpoint' => $guest['endpoint'], 'auth' => $guest['keys']['auth']]);
        $c->ok(($d['deleted'] ?? null) === false, 'Gast löscht kein Konto-Abo');
        $this->d->actAs($this->m1);
        [, $d] = $this->req('DELETE', '/me/push', ['endpoint' => $guest['endpoint']]);
        $c->ok(($d['deleted'] ?? null) === true, 'Mitglied meldet das Gerät ab');
        // Sitzung beendet, ohne die App abzumelden: Beim nächsten Start gibt das Gerät das Konto-Abo frei.
        $this->req('POST', '/me/push', $guest);
        $this->d->actAs(null);
        $this->req('POST', '/push/status', ['endpoint' => $guest['endpoint'], 'auth' => $guest['keys']['auth']]);
        $this->d->actAs($this->m1);
        [, $d] = $this->req('POST', '/me/push/status', ['endpoint' => $guest['endpoint']]);
        $c->ok(is_array($d) && array_key_exists('subscription', $d) && $d['subscription'] === null, 'Abo eines abgemeldeten Kontos fällt beim nächsten Start weg');

        $c->ok($this->req('GET', '/admin/push')[0] === 403, 'GET /admin/push als Mitglied → 403');
        $admin = $this->user('kptest_admin_push', 'admin');
        $this->d->actAs($admin);
        [$s, $d] = $this->req('GET', '/admin/push');
        $c->ok($s === 200 && is_int($d['devices'] ?? null) && is_int($d['reminderHour'] ?? null), 'GET /admin/push als Admin');
        $hour = (int) ($d['reminderHour'] ?? 18);
        $c->ok($this->req('PATCH', '/admin/push', ['reminder_hour' => 24])[0] === 400, 'Ungültige Stunde → 400');
        [$s, $d] = $this->req('PATCH', '/admin/push', ['reminder_hour' => 7]);
        $c->ok($s === 200 && $d['reminderHour'] === 7, 'Uhrzeit der Erinnerung ändern');
        $this->req('PATCH', '/admin/push', ['reminder_hour' => $hour]);
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
