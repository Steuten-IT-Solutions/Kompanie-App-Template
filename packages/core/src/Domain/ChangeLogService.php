<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\ChangeLog\Item;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\ChangeLogRepository;

/**
 * Änderungsprotokoll: wer wann welchen Eintrag angelegt, geändert oder gelöscht hat, mit den alten und neuen Werten. Erfasst
 * wird auf dem Server an den Routen, die ChangeLog\Subjects kennen (Http\Api lädt den Stand vor und nach der Anfrage);
 * nicht in der Vorschau und nur, was erfolgreich war.
 *
 * - Standard an (ENABLED_SETTING), abschaltbar; aus löscht alle Einträge sofort.
 * - Frist einstellbar (KEEP_SETTING, KEEP_MIN bis KEEP_MAX Tage, Standard KEEP_DEFAULT); die tägliche Aufgabe löscht ältere.
 * - Sehen: view_changes. Werte persönlicher Angaben (E-Mail, Telefon, Adresse, Geburtsdatum) nur mit view_personal.
 * - Geheimes (Passwörter, Zugangsdaten, Schlüssel, Dateien) kommt nie als Wert ins Protokoll, nur „geändert“.
 * - Abstimmungen in Umfragen und Push-Abos werden nicht protokolliert.
 * - Konto gelöscht: Einträge über die Person verlieren ihre Werte (forgetSubject), was sie selbst geändert hat, bleibt mit
 *   ihrem Namen bis zur Frist. Ehemalige nach der Aufbewahrungsfrist: nur die Werte persönlicher Angaben fallen weg.
 * - Nicht in Sicherungen (keine Kerntabelle von Storage\Backup).
 */
final class ChangeLogService
{
    public const TASK = 'change_log_prune';
    public const INTERVAL = 86400;
    /** An (Standard) oder aus, gespeichert als 'on'/'off' (siehe ActivityService::personal) */
    public const ENABLED_SETTING = 'change_log';
    /** Frist in Tagen */
    public const KEEP_SETTING = 'change_log_days';
    public const KEEP_DEFAULT = 365;
    public const KEEP_MIN = 30;
    public const KEEP_MAX = 730;
    public const ACTIONS = ['create', 'update', 'delete', 'event'];
    /** Längere Werte werden gekürzt gespeichert (Zeichen). */
    public const VALUE_MAX = 300;
    /** Höchstens so viele Felder je Eintrag */
    public const FIELDS_MAX = 60;
    /** Längster Zeitraum einer Abfrage in Tagen */
    public const RANGE_MAX = 731;
    /** Höchstens so viele Einträge je Abruf */
    public const ENTRIES_MAX = 2000;
    /** Bezeichnung von Einträgen ohne Bereich (Verwaltung, Konto, Einstellungen) */
    public const APP_AREA_LABEL = 'Verwaltung';
    /** Urheber ohne Konto (actor_id 0): Aufgaben, die von selbst laufen (Updates, Sicherungen, Fristen) */
    public const SYSTEM = 'System (automatisch)';
    /** Urheber ohne Konto: Befehle auf dem Server (bin/kompanie.php) */
    public const CLI = 'Kommandozeile auf dem Server';
    /** Urheber ohne Konto: install.php */
    public const INSTALLER = 'Installationsprogramm';

    private readonly ChangeLogRepository $repo;

    public function __construct(private readonly App $app)
    {
        $this->repo = new ChangeLogRepository($app->db);
    }

    public function enabled(): bool
    {
        return $this->app->settings->get(self::ENABLED_SETTING, 'on') !== 'off';
    }

    public function keepDays(): int
    {
        $days = Input::integer($this->app->settings->get(self::KEEP_SETTING, self::KEEP_DEFAULT));
        return $days === null ? self::KEEP_DEFAULT : max(self::KEEP_MIN, min(self::KEEP_MAX, $days));
    }

    /** @return array{enabled: bool, keep_days: int, keep_min: int, keep_max: int} */
    public function settings(): array
    {
        return ['enabled' => $this->enabled(), 'keep_days' => $this->keepDays(), 'keep_min' => self::KEEP_MIN, 'keep_max' => self::KEEP_MAX];
    }

    /**
     * Ein- oder ausschalten (aus löscht alle Einträge) und die Frist setzen (eine kürzere löscht ältere Einträge sofort).
     * Was fehlt, bleibt.
     *
     * @param array<string, mixed> $in
     * @return array{enabled: bool, keep_days: int, keep_min: int, keep_max: int}
     */
    public function updateSettings(array $in): array
    {
        $enabled = $in['enabled'] ?? null;
        if ($enabled !== null && !is_bool($enabled)) {
            throw ApiError::invalid('Bitte angeben, ob Änderungen protokolliert werden sollen.');
        }
        $days = null;
        if (array_key_exists('keep_days', $in)) {
            $days = Input::integer($in['keep_days']);
            if ($days === null || $days < self::KEEP_MIN || $days > self::KEEP_MAX) {
                throw ApiError::invalid(sprintf('Bitte eine Frist von %d bis %d Tagen angeben.', self::KEEP_MIN, self::KEEP_MAX));
            }
        }
        if ($days !== null) {
            $this->app->settings->set(self::KEEP_SETTING, $days);
        }
        if ($enabled !== null) {
            $this->app->settings->set(self::ENABLED_SETTING, $enabled ? 'on' : 'off');
            if (!$enabled) {
                $this->repo->clear();
            }
        }
        if ($days !== null && $this->enabled()) {
            $this->prune();
        }
        return $this->settings();
    }

    /**
     * Eine Änderung festhalten. Ohne Unterschied (Aktion update) entsteht kein Eintrag. Scheitert das Schreiben (z. B.
     * Tabelle fehlt nach einem abgebrochenen Update), bleibt die Änderung selbst davon unberührt.
     *
     * @param User|string $actor Konto oder, ohne Konto, die Bezeichnung des Urhebers (SYSTEM, CLI, INSTALLER)
     * @param 'create'|'update'|'delete'|'event' $action event: etwas ohne vergleichbaren Stand (Update eingespielt), die Bezeichnung ist der Satz
     */
    public function record(User|string $actor, string $area, string $action, ?Item $before, ?Item $after, bool $event = false): void
    {
        $this->recordMany($actor, $area, [[$action, $before, $after, $event]]);
    }

    /**
     * Etwas ohne vergleichbaren Stand festhalten (z. B. „Update auf Version 1.2.3 automatisch eingespielt“).
     *
     * @param User|string $actor Konto oder Bezeichnung des Urhebers (SYSTEM, CLI, INSTALLER)
     */
    public function event(User|string $actor, string $label, string $area = 'app', string $type = 'Einstellungen', ?int $subject = null): void
    {
        $this->recordMany($actor, $area, [['event', null, new Item($type, '', $label, [], [], [], $subject), true]]);
    }

    /**
     * Mehrere Änderungen einer Anfrage festhalten, mit festen Kosten (ein Abruf der Konten, wenige INSERTs), auch bei
     * vielen Einträgen (Punkte für alle).
     *
     * Mehrere Einträge bekommen eine gemeinsame Kennung (batch): Die Ansicht zeigt sie als eine Zeile.
     *
     * @param User|string $actor Konto oder, ohne Konto, die Bezeichnung des Urhebers (SYSTEM, CLI, INSTALLER)
     * @param list<array{0: string, 1: ?Item, 2: ?Item, 3: bool}> $changes je Aktion, Stand davor, danach, ohne vergleichbaren Stand
     */
    public function recordMany(User|string $actor, string $area, array $changes): void
    {
        if (($actor instanceof User && $actor->preview) || $changes === []) {
            return;
        }
        $actorId = $actor instanceof User ? $actor->id : 0;
        $actorName = $actor instanceof User ? $actor->name : $actor;
        try {
            if (!$this->enabled()) {
                return;
            }
            $now = $this->app->time()->timestamp();
            $area = preg_match('/^[a-z_]{1,20}$/', $area) === 1 ? $area : 'app';
            $rows = [];
            foreach ($changes as [$action, $before, $after, $event]) {
                if (!in_array($action, self::ACTIONS, true) || ($before === null && $after === null)) {
                    continue;
                }
                $item = $after ?? $before;
                $diff = self::diff($action === 'create' ? null : $before, $action === 'delete' ? null : $after);
                if ($diff === [] && $action === 'update' && !$event) {
                    continue;
                }
                $rows[] = [
                    'at' => $now,
                    'actor_id' => $actorId,
                    'actor_name' => $actorName,
                    'area' => $area,
                    'type' => $item->type,
                    'item' => $item->id,
                    'label' => $item->label,
                    'action' => $action,
                    'subject_user_id' => $item->subject,
                    'personal' => false,
                    'changes' => $diff,
                ];
            }
            if ($rows === []) {
                return;
            }
            $batch = count($rows) > 1 ? bin2hex(random_bytes(8)) : '';
            // Konto gibt es nicht mehr (gerade gelöscht): keine Werte über die Person behalten.
            $subjects = array_values(array_unique(array_filter(array_column($rows, 'subject_user_id'), static fn (?int $id): bool => $id !== null)));
            $existing = $subjects === [] ? [] : $this->app->users->findMany($subjects);
            foreach ($rows as $i => $row) {
                if ($row['subject_user_id'] !== null && !isset($existing[$row['subject_user_id']])) {
                    $rows[$i]['changes'] = self::strip($row['changes'], false);
                }
                $rows[$i]['personal'] = self::hasPersonalValues($rows[$i]['changes']);
                $rows[$i]['batch'] = $batch;
            }
            if (count($rows) === 1) {
                $this->repo->insert($rows[0]);
            } else {
                $this->repo->insertMany($rows);
            }
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Änderung nicht protokolliert: ' . $e->getMessage());
        }
    }

    /**
     * Felder, die sich unterscheiden. Ohne Stand davor: alle gesetzten Felder als neu, ohne Stand danach: alle als alt.
     *
     * @return list<array{f: string, o?: string|null, n?: string|null, p?: int, s?: int}>
     */
    public static function diff(?Item $before, ?Item $after): array
    {
        $old = $before?->fields ?? [];
        $new = $after?->fields ?? [];
        $personal = array_flip([...($before?->personal ?? []), ...($after?->personal ?? [])]);
        $secret = array_flip([...($before?->secret ?? []), ...($after?->secret ?? [])]);
        $out = [];
        foreach (array_keys($old + $new) as $field) {
            $field = (string) $field;
            $o = self::value($old[$field] ?? null);
            $n = self::value($new[$field] ?? null);
            if ($o === $n) {
                continue;
            }
            $c = ['f' => $field];
            if (isset($secret[$field])) {
                $c['s'] = 1;
            } else {
                if ($before !== null) {
                    $c['o'] = $o;
                }
                if ($after !== null) {
                    $c['n'] = $n;
                }
                if (isset($personal[$field])) {
                    $c['p'] = 1;
                }
            }
            $out[] = $c;
            if (count($out) >= self::FIELDS_MAX) {
                break;
            }
        }
        return $out;
    }

    /** Wert für Vergleich und Anzeige: Text, gekürzt; leer und null gleich. */
    public static function value(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'ja' : 'nein';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            if ($v === []) {
                return null;
            }
            $scalars = array_filter($v, static fn (mixed $x): bool => is_scalar($x) || $x === null);
            $v = array_is_list($v) && count($scalars) === count($v)
                ? implode(', ', array_map(static fn (mixed $x): string => is_bool($x) ? ($x ? 'ja' : 'nein') : (string) $x, $v))
                : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        return mb_strlen($v) > self::VALUE_MAX ? mb_substr($v, 0, self::VALUE_MAX) . ' …' : $v;
    }

    /**
     * Einträge im Zeitraum, neueste zuerst, höchstens ENTRIES_MAX (truncated sagt, ob es mehr gab). Werte persönlicher
     * Angaben ohne view_personal ausgeblendet (hidden).
     *
     * @return array<string, mixed>
     */
    public function entries(User $viewer, mixed $from, mixed $to, mixed $actorId = null, mixed $subjectId = null, mixed $area = null): array
    {
        [$fromDay, $toDay] = $this->range($from, $to);
        $tz = $this->app->time()->timezone();
        $start = (new \DateTimeImmutable($fromDay, $tz))->getTimestamp();
        $end = (new \DateTimeImmutable($toDay, $tz))->modify('+1 day')->getTimestamp();
        $areaKey = null;
        if ($area !== null && $area !== '') {
            if (!is_string($area) || preg_match('/^[a-z_]{1,20}$/', $area) !== 1) {
                throw ApiError::invalid('Unbekannter Bereich.');
            }
            $areaKey = $area;
        }
        $rows = $this->repo->entries($start, $end, self::optionalId($actorId), self::optionalId($subjectId), $areaKey, self::ENTRIES_MAX + 1);
        $truncated = count($rows) > self::ENTRIES_MAX;
        $rows = array_slice($rows, 0, self::ENTRIES_MAX);
        $personal = $viewer->can(Capability::ViewPersonal);
        $entries = array_map(fn (array $r): array => $this->present($r, $personal), $rows);
        $areas = array_map(fn (array $a): array => ['area' => $a['area'], 'label' => $this->areaLabel($a['area'])], $this->repo->areas());
        return ['from' => $fromDay, 'to' => $toDay, 'entries' => $entries, 'truncated' => $truncated, 'areas' => $areas] + $this->settings();
    }

    /** Tägliche Aufgabe: Einträge nach der Frist löschen. */
    public function prune(): void
    {
        $this->repo->prune($this->app->time()->timestamp() - $this->keepDays() * 86400);
    }

    /**
     * Konto gelöscht ($onlyPersonal false): Einträge über die Person behalten nur Art, Bezeichnung und Aktion. Ehemalige nach
     * der Aufbewahrungsfrist ($onlyPersonal true): nur die Werte persönlicher Angaben fallen weg.
     */
    public function forgetSubject(int $userId, bool $onlyPersonal = false): void
    {
        foreach ($this->repo->of('subject_user_id', $userId) as $r) {
            if (!$onlyPersonal || $r['personal']) {
                $changes = self::strip($r['changes'], $onlyPersonal);
                $this->repo->replaceChanges($r['id'], $changes, self::hasPersonalValues($changes));
            }
        }
    }

    /**
     * Für die Auskunft (Domain\PersonalDataExport): Einträge über die Person mit Werten, Einträge von ihr ohne Werte (darin
     * stehen Angaben anderer).
     *
     * @return array{about: list<array<string, mixed>>, by: list<array<string, mixed>>}
     */
    public function export(int $userId): array
    {
        $about = array_map(fn (array $r): array => $this->present($r, true), $this->repo->of('subject_user_id', $userId));
        $by = array_map(fn (array $r): array => array_diff_key($this->present($r, false), ['changes' => 1]), $this->repo->of('actor_id', $userId));
        return ['about' => $about, 'by' => $by];
    }

    public function areaLabel(string $area): string
    {
        if ($area === 'app') {
            return self::APP_AREA_LABEL;
        }
        $def = Features::DEFINITIONS[$area] ?? null;
        return $def === null ? $area : $this->app->config->sectionLabel($area, $def['label']);
    }

    /**
     * @param array{id: int, at: int, actor_id: int, actor_name: string, area: string, type: string, item: string, label: string, action: string, subject_user_id: int|null, personal: bool, changes: list<array<string, mixed>>, batch: string} $r
     * @return array<string, mixed>
     */
    private function present(array $r, bool $personal): array
    {
        $changes = [];
        foreach ($r['changes'] as $c) {
            $out = ['field' => (string) ($c['f'] ?? '')];
            if (!empty($c['s'])) {
                $out['secret'] = true;
            } elseif (!empty($c['p']) && !$personal) {
                $out['hidden'] = true;
            } else {
                if (array_key_exists('o', $c)) {
                    $out['old'] = $c['o'];
                }
                if (array_key_exists('n', $c)) {
                    $out['new'] = $c['n'];
                }
            }
            if (!empty($c['p'])) {
                $out['personal'] = true;
            }
            $changes[] = $out;
        }
        return [
            'id' => $r['id'],
            'at' => $r['at'],
            'actor_id' => $r['actor_id'],
            'actor_name' => $r['actor_name'],
            'area' => $r['area'],
            'area_label' => $this->areaLabel($r['area']),
            'type' => $r['type'],
            'item' => $r['item'],
            'label' => $r['label'],
            'action' => $r['action'],
            'subject_user_id' => $r['subject_user_id'],
            'batch' => $r['batch'],
            'changes' => $changes,
        ];
    }

    /**
     * Werte entfernen: alle oder nur die persönlicher Angaben. Das Feld bleibt (man sieht, dass es geändert wurde).
     *
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    private static function strip(array $changes, bool $onlyPersonal): array
    {
        return array_map(static fn (array $c): array => !$onlyPersonal || !empty($c['p']) ? array_diff_key($c, ['o' => 1, 'n' => 1]) : $c, $changes);
    }

    /** @param list<array<string, mixed>> $changes */
    private static function hasPersonalValues(array $changes): bool
    {
        foreach ($changes as $c) {
            if (!empty($c['p']) && (array_key_exists('o', $c) || array_key_exists('n', $c))) {
                return true;
            }
        }
        return false;
    }

    private static function optionalId(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $id = Input::integer($v);
        if ($id === null || $id <= 0) {
            throw ApiError::invalid('Ungültiges Konto.');
        }
        return $id;
    }

    /**
     * Zeitraum JJJJ-MM-TT bis JJJJ-MM-TT; ohne Angabe die letzten 30 Tage bis heute.
     *
     * @return array{0: string, 1: string}
     */
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
