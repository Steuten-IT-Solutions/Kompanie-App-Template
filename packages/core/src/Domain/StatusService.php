<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;

/**
 * Statusseite für Admins: eine Ampel je Bereich in Alltagssprache, mit dem, was zu tun ist.
 * level: ok (grün), warn (gelb), error (rot), off (nicht eingerichtet).
 */
final class StatusService
{
    public const BACKUP_SETTING = 'backup_last';
    /** Ohne Sicherung außerhalb des Servers (heruntergeladen oder in Google Drive) so viele Tage: gelb. */
    public const BACKUP_REMIND_DAYS = 30;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{items: array<int, array<string, string>>, support: string} */
    public function report(): array
    {
        $items = [$this->updates(), $this->gallery(), $this->calendar(), $this->backup(), $this->server(), $this->push()];
        foreach ($this->app->healthChecks?->checks() ?? [] as $check) {
            $items[] = $check;
        }
        return ['items' => $items, 'support' => (string) ($this->app->packageConfig['support'] ?? '')];
    }

    /** @return array<string, string> */
    private function item(string $key, string $label, string $level, string $text): array
    {
        return ['key' => $key, 'label' => $label, 'level' => $level, 'text' => $text];
    }

    private function date(?int $ts): string
    {
        return $ts ? $this->app->time()->format($ts, 'd.m.Y H:i') : 'noch nie';
    }

    private function updates(): array
    {
        $u = $this->app->updates();
        if (!$u->configured()) {
            return $this->item('updates', 'Updates', 'off', 'Für diese Installation sind keine automatischen Updates eingerichtet.');
        }
        $s = $u->status();
        $last = is_array($s['last']) ? $s['last'] : null;
        if ($s['withdrawn'] && $s['fallback'] === null) {
            return $this->item('updates', 'Updates', 'error', "Version {$s['current']} wurde zurückgezogen, aber es gibt noch keine geprüfte Version, auf die die Installation wechseln kann. Bitte bei den Betreuern der App melden.");
        }
        if ($s['withdrawn']) {
            return $this->item('updates', 'Updates', 'error', "Version {$s['current']} wurde zurückgezogen. "
                . ($s['autoAllowed'] ? "Die Installation wechselt beim nächsten Abgleich automatisch auf Version {$s['fallback']}; das geht auch sofort unter Einstellungen → Zustand und Updates." : "Bitte unter Einstellungen → Zustand und Updates auf Version {$s['fallback']} wechseln (automatische Updates sind hier gesperrt)."));
        }
        if ($s['held'] !== null) {
            return $this->item('updates', 'Updates', 'warn', "Das automatische Update auf Version {$s['held']['version']} wurde zurückgestellt. {$s['held']['error']} Bitte unter Einstellungen → Zustand und Updates entscheiden: trotzdem aktualisieren oder abbrechen.");
        }
        if ($last && !$last['ok'] && ($last['at'] ?? 0) >= ($s['checked_at'] ?? 0) - 86400 * 2) {
            return $this->item('updates', 'Updates', 'error', 'Das letzte Update hat nicht geklappt: ' . $last['error'] . ' Die bisherige Version läuft weiter.');
        }
        if ($s['problems']) {
            return $this->item('updates', 'Updates', 'error', "Version {$s['latest']} kann hier nicht eingespielt werden: " . implode(' ', $s['problems']));
        }
        if ($s['error'] !== '') {
            return $this->item('updates', 'Updates', 'warn', 'Die Update-Liste war zuletzt nicht erreichbar: ' . $s['error']);
        }
        if ($s['major']) {
            return $this->item('updates', 'Updates', 'warn', "Eine neue Hauptversion ({$s['latest']}) ist da. Sie wird nicht automatisch eingespielt; bitte unter Einstellungen → Zustand und Updates bestätigen.");
        }
        if ($s['available'] && $s['skipped']) {
            return $this->item('updates', 'Updates', 'ok', "Version {$s['current']} läuft (zurückgerollt). Version {$s['latest']} wird nicht automatisch eingespielt; von Hand geht es unter Einstellungen → Zustand und Updates.");
        }
        if ($s['available'] && !$s['autoAllowed']) {
            return $this->item('updates', 'Updates', 'warn', "Version {$s['latest']} ist da. Automatische Updates sind hier gesperrt (z. B. in WordPress); bitte unter Einstellungen → Zustand und Updates aktualisieren.");
        }
        if ($s['available']) {
            return $this->item('updates', 'Updates', 'warn', $s['auto']
                ? "Version {$s['latest']} wird beim nächsten Abgleich automatisch eingespielt."
                : "Version {$s['latest']} ist da. Automatische Updates sind aus; bitte unter Einstellungen → Zustand und Updates aktualisieren.");
        }
        return $this->item('updates', 'Updates', 'ok', "Version {$s['current']} ist aktuell. Zuletzt geprüft: " . $this->date($s['checked_at']) . '.');
    }

    private function gallery(): array
    {
        $g = $this->app->gallery();
        $label = $this->app->config->sectionLabel('gallery', 'Galerie');
        if (!$this->app->features()->enabled('gallery')) {
            return $this->item('gallery', $label, 'off', "Der Bereich „{$label}“ ist ausgeschaltet.");
        }
        if (!$g->isConfigured()) {
            return $this->item('gallery', $label, 'off', "Noch nicht eingerichtet (Einstellungen → {$label}).");
        }
        $st = $g->status();
        $last = isset($st['last_sync']) ? (int) $st['last_sync'] : null;
        if (($st['error'] ?? '') !== '') {
            return $this->item('gallery', $label, 'error', 'Der letzte Abgleich ist fehlgeschlagen: ' . $st['error'] . ' Ist der Ordner für das verbundene Google-Konto bzw. das Dienstkonto noch sichtbar (Einstellungen → Google-Zugang)?');
        }
        if ($last === null || $last < $this->app->time()->timestamp() - 3 * 86400) {
            return $this->item('gallery', $label, 'warn', 'Der letzte Abgleich ist länger her (' . $this->date($last) . '). Werden die Seiten der App selten aufgerufen, hilft ein Cronjob beim Hoster.');
        }
        $failed = $g->countFailed();
        $pending = $g->countPending();
        if ($failed > 0) {
            return $this->item('gallery', $label, 'warn', "$failed Bilder ließen sich nicht laden (z. B. beschädigte Dateien). Abgleich zuletzt: " . $this->date($last) . '.');
        }
        return $this->item('gallery', $label, 'ok', ($pending > 0 ? "$pending Bilder werden noch aufbereitet. " : '') . 'Abgleich zuletzt: ' . $this->date($last) . '.');
    }

    private function calendar(): array
    {
        if (!$this->app->features()->enabled('calendar')) {
            return $this->item('calendar', 'Kalender', 'off', 'Der Kalender ist ausgeschaltet.');
        }
        $cal = $this->app->calendar();
        $via = [
            'google' => 'über die Google-API mit eigenem API-Schlüssel',
            'google_connection' => 'über die Kalender-IDs und den Google-Zugang',
            'ics' => 'über die iCal-Adresse',
        ][$cal->source()] ?? '';
        if ($cal->connectionNeedsCalendarAccess()) {
            // Kalender-IDs eingetragen, aber die Verbindung zu Google wurde ohne Kalender-Berechtigung hergestellt.
            $reconnect = 'Die Verbindung mit Google darf den Kalender mit den eingetragenen Kalender-IDs nicht lesen (beim Verbinden nicht erlaubt). Unter Einstellungen → Google-Zugang die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den Kalender setzen.';
            return $cal->isConfigured()
                ? $this->item('calendar', 'Kalender', 'ok', 'Eingerichtet ' . $via . '. ' . $reconnect)
                : $this->item('calendar', 'Kalender', 'warn', $reconnect . ' Bis dahin kannst du eine iCal-Adresse eintragen (Einstellungen → Kalender).');
        }
        return $cal->isConfigured()
            ? $this->item('calendar', 'Kalender', 'ok', 'Eingerichtet ' . $via . '. Ob er erreichbar ist, zeigt „Kalender testen“ in den Einstellungen.')
            : $this->item('calendar', 'Kalender', 'off', 'Noch nicht eingerichtet (Einstellungen → Kalender).');
    }

    private function backup(): array
    {
        $last = $this->app->settings->get(self::BACKUP_SETTING);
        // WordPress liefert Zahlen aus den Options als Zeichenkette zurück.
        $last = is_numeric($last) && (int) $last > 0 ? (int) $last : null;
        if ($this->app->backup === null) {
            return $this->item('backup', 'Datensicherung', 'off', 'In dieser Installation nicht verfügbar.');
        }
        $drive = $this->app->backupUpload()->status();
        $text = 'Letzte heruntergeladene Sicherung: ' . $this->date($last) . '.';
        $hint = '';
        if ($drive['enabled']) {
            $text .= ' Letzte Sicherung in Google Drive: ' . $this->date($drive['last_ok']) . '.';
            if (!$drive['access']) {
                return $this->item('backup', 'Datensicherung', 'warn', $text . ' Die Verbindung zu Google erlaubt das Hochladen nicht mehr; bitte unter Einstellungen → Google-Zugang neu verbinden.');
            }
            if ($drive['error'] !== null) {
                return $this->item('backup', 'Datensicherung', 'warn', $text . ' Das letzte Hochladen ist fehlgeschlagen: ' . $drive['error']);
            }
            if ($drive['encrypted'] && !$this->app->backupEncryption()->verified()) {
                // Kein eigener Abbruch: Die Erinnerung an eine zu alte Sicherung (unten) bleibt sichtbar. Bis dahin bleiben
                // in Drive unverschlüsselte Sicherungen liegen (BackupUploadService::prune).
                $hint = ' Das Passwort für Sicherungen ist noch nicht geprüft: Ist es vertippt, lassen sich die verschlüsselten Sicherungen nicht wiederherstellen. Bitte unter Einstellungen → Datensicherung „Passwort prüfen“ wählen; bis dahin löscht die App in Google Drive keine älteren unverschlüsselten Sicherungen.';
            }
        }
        $newest = max($last ?? 0, $drive['enabled'] ? (int) $drive['last_ok'] : 0);
        if ($newest < $this->app->time()->timestamp() - self::BACKUP_REMIND_DAYS * 86400) {
            return $this->item('backup', 'Datensicherung', 'warn', $text . ' Bitte unter Einstellungen → Datensicherung eine Sicherung herunterladen und aufbewahren oder Sicherungen in Google Drive einschalten.' . $hint);
        }
        return $this->item('backup', 'Datensicherung', $hint !== '' ? 'warn' : 'ok', $text . $hint);
    }

    private function push(): array
    {
        $missing = $this->app->push()->requirements();
        if ($missing !== []) {
            return $this->item('push', 'Push-Benachrichtigungen', 'warn', 'Auf diesem Server nicht möglich, es fehlt: ' . implode(', ', $missing) . '. Bitte beim Hoster nachfragen.');
        }
        $c = $this->app->push()->repository()->counts();
        return $this->item('push', 'Push-Benachrichtigungen', 'ok', sprintf('%d Geräte angemeldet (%d Konten, %d Gäste).', $c['devices'], $c['accounts'], $c['guests']));
    }

    private function server(): array
    {
        $free = @disk_free_space($this->app->files->path('.', false));
        if (is_float($free) && $free < 200 * 1024 * 1024) {
            return $this->item('server', 'Server', 'warn', sprintf('Auf dem Webspace sind nur noch %d MB frei. Für Updates und Galeriebilder bitte Platz schaffen oder das Paket beim Hoster erweitern.', (int) ($free / 1048576)));
        }
        return $this->item('server', 'Server', 'ok', 'PHP ' . PHP_VERSION . ', Datenbank ' . ($this->app->db->driver() === 'sqlite' ? 'SQLite' : 'MySQL/MariaDB') . '.');
    }
}
