<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\ActivityRepository;

/**
 * Nutzungsauswertung (Issue #175): wer wann welchen Bereich der App nutzt. Erfasst wird auf dem Server an den API-Routen
 * (Http\Api), nur für angemeldete Konten mit einer Rolle in der App und nicht in der Vorschau. Gespeichert werden nur
 * Bereich (Schlüssel aus Features, dazu 'app' für Allgemeines und 'login'), Aktion (ACTIONS) und Zeit: keine Inhalte,
 * keine IP-Adressen, kein User-Agent. Was offline aus dem Zwischenspeicher kommt, erreicht den Server nicht und wird
 * nicht gezählt.
 *
 * Zwei Stufen:
 * - **Je Person** (Einstellung PERSONAL_SETTING, Standard an, abschaltbar): Einträge mit Konto (90 Tage, KEEP_DAYS) und
 *   „zuletzt aktiv“ je Konto, für die Auswertung der Admins (Recht view_activity). Ausschalten löscht beides sofort.
 * - **Ohne Personenbezug** (immer): Tageszahlen je Bereich (Personen, Aktionen) und für die Zahl aktiver Nutzer „zuletzt
 *   gesehen“ je Pseudonym und Bereich (HMAC der Benutzer-ID mit einem Geheimnis der Installation, 31 Tage). Das ist
 *   Pseudonymisierung, keine Anonymisierung (wie bei anonymen Umfragen); an das Cockpit gehen nur Summen (usage()).
 *
 * Ansichten (Aktion view) zählen höchstens einmal je Person, Bereich und 15 Minuten (VIEW_GAP). Nichts davon kommt in
 * Sicherungen; beim Löschen eines Kontos verschwinden seine Einträge und sein Pseudonym, die Tageszahlen bleiben.
 */
final class ActivityService
{
    public const TASK = 'activity_prune';
    public const INTERVAL = 86400;
    /** Erfassung je Person an (Standard) oder aus */
    public const PERSONAL_SETTING = 'activity_personal';
    /** Geheimnis für das Pseudonym; gehört zur Installation (Storage\Backup::LOCAL_SETTINGS) */
    public const SECRET_SETTING = 'activity_secret';
    /** Einträge je Person werden nach so vielen Tagen gelöscht. */
    public const KEEP_DAYS = 90;
    /** „Zuletzt gesehen“ je Pseudonym: so lange, wie die Zahl aktiver Nutzer der letzten 30 Tage braucht. */
    public const SEEN_DAYS = 31;
    /** Ansichten höchstens einmal je Person und Bereich in so vielen Sekunden. */
    public const VIEW_GAP = 900;
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'vote', 'login'];
    /** Bereiche außer denen aus Features: Allgemeines (Verwaltung, Konto, Einstellungen) und die Anmeldung. */
    public const EXTRA_AREAS = ['app' => 'Allgemein', 'login' => 'Anmeldung'];
    /** Zeile für „irgendein Bereich“ in activity_seen und activity_daily (aktive Personen, alle Aktionen eines Tages) */
    public const ALL = '_all';
    /** Längster Zeitraum einer Auswertung in Tagen */
    public const RANGE_MAX = 366;
    /** Höchstens so viele Einträge je Abruf (Verlauf, CSV) */
    public const ENTRIES_MAX = 5000;

    private readonly ActivityRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ActivityRepository($app->db);
    }

    /**
     * Gespeichert als 'on' bzw. 'off', nicht als bool: WordPress legt mit update_option(…, false) eine noch nicht
     * vorhandene Option nicht an, dann gälte wieder der Standard.
     */
    public function personal(): bool
    {
        return $this->app->settings->get(self::PERSONAL_SETTING, 'on') !== 'off';
    }

    /** @return list<string> */
    public static function areas(): array
    {
        return [...Features::keys(), ...array_keys(self::EXTRA_AREAS)];
    }

    /**
     * Eine Nutzung erfassen. Scheitert das (z. B. Tabelle fehlt nach einem abgebrochenen Update), bleibt die Anfrage
     * davon unberührt.
     */
    public function record(?User $user, string $area, string $action): void
    {
        // Gäste (und Konten ohne Rechte) zählen nicht: Ihre Rolle gilt auch ohne Anmeldung.
        if ($user === null || $user->preview || $user->role === Roles::GUEST || $user->capabilities === [] || !in_array($area, self::areas(), true) || !in_array($action, self::ACTIONS, true)) {
            return;
        }
        try {
            $this->write($user->id, $area, $action);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Nutzung nicht erfasst: ' . $e->getMessage());
        }
    }

    private function write(int $userId, string $area, string $action): void
    {
        $now = $this->app->time()->timestamp();
        $token = $this->pseudonym($userId);
        $areas = [$area, self::ALL];
        $seen = $this->repo->seen($token, $areas);
        if ($action === 'view' && isset($seen[$area]) && $now - $seen[$area] < self::VIEW_GAP) {
            return;
        }
        $dayStart = $this->app->time()->today()->getTimestamp();
        $counts = [];
        foreach ($areas as $a) {
            $counts[$a] = [($seen[$a] ?? 0) < $dayStart ? 1 : 0, 1];
        }
        $this->repo->touch($token, $areas, $now);
        $this->repo->count($this->app->time()->format($now, 'Y-m-d'), $counts);
        if ($this->personal()) {
            $this->repo->log($userId, $area, $action, $now);
        }
    }

    /**
     * Zahlen ohne Personenbezug für die Statusmeldungen (Format 3, docs/STATUSMELDUNGEN.md): Konten mit einer Rolle in der
     * App, aktive Konten in den letzten 1, 7 und 30 Tagen (je 24 Stunden ab jetzt zurück) und Personen je Bereich in den
     * letzten 30 Tagen (auch 'app' und 'login'). Immer d1 ≤ d7 ≤ d30 ≤ users (wie das Cockpit prüft); Bereiche nur mit Schlüsseln nach ^[a-z]{1,20}$.
     *
     * @return array{users: int, active: array{d1: int, d7: int, d30: int}, areas: object}
     */
    public function usage(): array
    {
        $now = $this->app->time()->timestamp();
        $users = count($this->app->users->list(false));
        $d30 = min($users, $this->repo->activeSince(self::ALL, $now - 30 * 86400));
        $d7 = min($d30, $this->repo->activeSince(self::ALL, $now - 7 * 86400));
        $d1 = min($d7, $this->repo->activeSince(self::ALL, $now - 86400));
        $areas = array_filter(
            $this->repo->areasSince($now - 30 * 86400, self::ALL),
            static fn (string $k): bool => preg_match('/^[a-z]{1,20}$/', $k) === 1,
            ARRAY_FILTER_USE_KEY,
        );
        return ['users' => $users, 'active' => ['d1' => $d1, 'd7' => $d7, 'd30' => $d30], 'areas' => (object) $areas];
    }

    /**
     * Übersicht für Admins: Zahlen wie usage(), Tageszahlen im Zeitraum (aktive Personen und Aktionen je Tag), Nutzung je
     * Bereich und, mit Erfassung je Person, alle Konten mit „zuletzt aktiv“ (null = nie, seit die Erfassung läuft) und der
     * Zahl ihrer Einträge im Zeitraum (actions).
     *
     * @return array<string, mixed>
     */
    public function overview(mixed $from, mixed $to): array
    {
        [$fromDay, $toDay] = $this->range($from, $to);
        $usage = $this->usage();
        $days = [];
        $areas = [];
        foreach ($this->repo->daily($fromDay, $toDay) as $r) {
            if ($r['area'] === self::ALL) {
                $days[] = ['day' => $r['day'], 'users' => $r['users'], 'actions' => $r['actions']];
                continue;
            }
            $areas[$r['area']] ??= ['area' => $r['area'], 'label' => $this->label($r['area']), 'actions' => 0, 'people' => 0];
            $areas[$r['area']]['actions'] += $r['actions'];
        }
        foreach ((array) $usage['areas'] as $key => $people) {
            $areas[$key] ??= ['area' => $key, 'label' => $this->label($key), 'actions' => 0, 'people' => 0];
            $areas[$key]['people'] = $people;
        }
        usort($areas, static fn (array $a, array $b): int => [$b['actions'], $b['people'], $a['label']] <=> [$a['actions'], $a['people'], $b['label']]);
        $members = null;
        if ($this->personal()) {
            $last = $this->repo->lastActive();
            [$start, $end] = $this->bounds($fromDay, $toDay);
            $counts = $this->repo->countsByUser($start, $end);
            $members = array_map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'last_active' => $last[$u->id] ?? null, 'actions' => $counts[$u->id] ?? 0], $this->app->users->list(false));
        }
        return [
            'personal' => $this->personal(),
            'keep_days' => self::KEEP_DAYS,
            'from' => $fromDay,
            'to' => $toDay,
            'users' => $usage['users'],
            'active' => $usage['active'],
            'days' => $days,
            'areas' => array_values($areas),
            'members' => $members,
        ];
    }

    /**
     * Einträge je Person im Zeitraum (Verlauf einer Person oder alle, z. B. für die CSV-Datei), neueste zuerst, höchstens
     * ENTRIES_MAX; truncated sagt, ob es mehr gab.
     *
     * @return array{from: string, to: string, entries: list<array{user_id: int, name: string, area: string, label: string, action: string, at: int}>, truncated: bool}
     */
    public function entries(mixed $from, mixed $to, mixed $userId): array
    {
        [$fromDay, $toDay] = $this->range($from, $to);
        $id = null;
        if ($userId !== null && $userId !== '') {
            $id = Input::integer($userId);
            if ($id === null || $id <= 0) {
                throw ApiError::invalid('Ungültiges Konto.');
            }
        }
        [$start, $end] = $this->bounds($fromDay, $toDay);
        $rows = $this->repo->entries($start, $end, $id, self::ENTRIES_MAX + 1);
        $truncated = count($rows) > self::ENTRIES_MAX;
        $rows = array_slice($rows, 0, self::ENTRIES_MAX);
        $names = (new UserNames($this->app->users))->prime(array_column($rows, 'user_id'));
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['user_id' => $r['user_id'], 'name' => (string) $names->name($r['user_id'])] + $r + ['label' => $this->label($r['area'])];
        }
        return ['from' => $fromDay, 'to' => $toDay, 'entries' => $out, 'truncated' => $truncated];
    }

    /** Erfassung je Person ein- oder ausschalten; aus löscht alle Einträge je Person sofort. */
    public function setPersonal(mixed $on): bool
    {
        if (!is_bool($on)) {
            throw ApiError::invalid('Bitte angeben, ob die Nutzung je Person erfasst werden soll.');
        }
        $this->app->settings->set(self::PERSONAL_SETTING, $on ? 'on' : 'off');
        if (!$on) {
            $this->repo->clearPersonal();
        }
        return $on;
    }

    /** Tägliche Aufgabe: Einträge je Person nach 90 Tagen, Pseudonyme nach 31 Tagen löschen. */
    public function prune(): void
    {
        $now = $this->app->time()->timestamp();
        $this->repo->prune($now - self::KEEP_DAYS * 86400, $now - self::SEEN_DAYS * 86400);
    }

    /** Ein Konto wurde gelöscht: sein Pseudonym entfernen (die Einträge je Person löscht Storage\UserData). */
    public function forget(int $userId): void
    {
        $secret = $this->app->settings->get(self::SECRET_SETTING);
        if (is_string($secret) && $secret !== '') {
            $this->repo->forget(self::token($secret, $userId));
        }
    }

    public static function token(string $secret, int $userId): string
    {
        return substr(hash_hmac('sha256', 'activity:' . $userId, $secret), 0, 32);
    }

    private function pseudonym(int $userId): string
    {
        $secret = $this->app->settings->get(self::SECRET_SETTING);
        if (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
            $secret = bin2hex(random_bytes(32));
            $this->app->settings->set(self::SECRET_SETTING, $secret);
        }
        return self::token($secret, $userId);
    }

    private function label(string $area): string
    {
        if (isset(self::EXTRA_AREAS[$area])) {
            return self::EXTRA_AREAS[$area];
        }
        $def = Features::DEFINITIONS[$area] ?? null;
        return $def === null ? $area : $this->app->config->sectionLabel($area, $def['label']);
    }

    /**
     * Zeitraum JJJJ-MM-TT bis JJJJ-MM-TT; ohne Angabe die letzten 30 Tage bis heute.
     *
     * @return array{0: string, 1: string}
     */
    /** @return array{int, int} Beginn von $fromDay bis Beginn des Tags nach $toDay (Zeitzone der Installation) */
    private function bounds(string $fromDay, string $toDay): array
    {
        $tz = $this->app->time()->timezone();
        return [(new \DateTimeImmutable($fromDay, $tz))->getTimestamp(), (new \DateTimeImmutable($toDay, $tz))->modify('+1 day')->getTimestamp()];
    }

    private function range(mixed $from, mixed $to): array
    {
        $today = $this->app->time()->today();
        $toDay = Input::date($to, $today);
        $fromDay = ($from === null || $from === '') ? (new \DateTimeImmutable($toDay))->modify('-29 days')->format('Y-m-d') : Input::date($from, $today);
        if ($fromDay > $toDay) {
            throw ApiError::invalid('Der Beginn liegt nach dem Ende.');
        }
        if ((new \DateTimeImmutable($fromDay))->diff(new \DateTimeImmutable($toDay))->days >= self::RANGE_MAX) {
            throw ApiError::invalid(sprintf('Bitte höchstens %d Tage auf einmal auswählen.', self::RANGE_MAX));
        }
        return [$fromDay, $toDay];
    }
}
