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
    private const BACKUP_REMIND_DAYS = 90;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{items: array<int, array<string, string>>, support: string} */
    public function report(): array
    {
        $items = [$this->updates(), $this->gallery(), $this->calendar(), $this->backup(), $this->server()];
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
                . ($s['autoAllowed'] ? "Die Installation wechselt beim nächsten Abgleich automatisch auf Version {$s['fallback']}; das geht auch sofort unter Einstellungen → Updates." : "Bitte unter Einstellungen → Updates auf Version {$s['fallback']} wechseln (automatische Updates sind hier gesperrt)."));
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
            return $this->item('updates', 'Updates', 'warn', "Eine neue Hauptversion ({$s['latest']}) ist da. Sie wird nicht automatisch eingespielt; bitte unter Einstellungen → Updates bestätigen.");
        }
        if ($s['available'] && $s['skipped']) {
            return $this->item('updates', 'Updates', 'ok', "Version {$s['current']} läuft (zurückgerollt). Version {$s['latest']} wird nicht automatisch eingespielt; von Hand geht es unter Einstellungen → Updates.");
        }
        if ($s['available'] && !$s['autoAllowed']) {
            return $this->item('updates', 'Updates', 'warn', "Version {$s['latest']} ist da. Automatische Updates sind hier gesperrt (z. B. in WordPress); bitte unter Einstellungen → Updates aktualisieren.");
        }
        if ($s['available']) {
            return $this->item('updates', 'Updates', 'warn', $s['auto']
                ? "Version {$s['latest']} wird beim nächsten Abgleich automatisch eingespielt."
                : "Version {$s['latest']} ist da. Automatische Updates sind aus; bitte unter Einstellungen → Updates aktualisieren.");
        }
        return $this->item('updates', 'Updates', 'ok', "Version {$s['current']} ist aktuell. Zuletzt geprüft: " . $this->date($s['checked_at']) . '.');
    }

    private function gallery(): array
    {
        $g = $this->app->gallery();
        if (!$this->app->features()->enabled('gallery')) {
            return $this->item('gallery', 'Galerie', 'off', 'Die Galerie ist ausgeschaltet.');
        }
        if (!$g->isConfigured()) {
            return $this->item('gallery', 'Galerie', 'off', 'Noch nicht eingerichtet (Einstellungen → Galerie).');
        }
        $st = $g->status();
        $last = isset($st['last_sync']) ? (int) $st['last_sync'] : null;
        if (($st['error'] ?? '') !== '') {
            return $this->item('gallery', 'Galerie', 'error', 'Der letzte Abgleich ist fehlgeschlagen: ' . $st['error'] . ' Ist der Ordner noch für das Dienstkonto freigegeben?');
        }
        if ($last === null || $last < $this->app->time()->timestamp() - 3 * 86400) {
            return $this->item('gallery', 'Galerie', 'warn', 'Der letzte Abgleich ist länger her (' . $this->date($last) . '). Werden die Seiten der App selten aufgerufen, hilft ein Cronjob beim Hoster.');
        }
        $failed = $g->countFailed();
        $pending = $g->countPending();
        if ($failed > 0) {
            return $this->item('gallery', 'Galerie', 'warn', "$failed Bilder ließen sich nicht laden (z. B. beschädigte Dateien). Abgleich zuletzt: " . $this->date($last) . '.');
        }
        return $this->item('gallery', 'Galerie', 'ok', ($pending > 0 ? "$pending Bilder werden noch aufbereitet. " : '') . 'Abgleich zuletzt: ' . $this->date($last) . '.');
    }

    private function calendar(): array
    {
        if (!$this->app->features()->enabled('calendar')) {
            return $this->item('calendar', 'Kalender', 'off', 'Der Kalender ist ausgeschaltet.');
        }
        return $this->app->calendar()->isConfigured()
            ? $this->item('calendar', 'Kalender', 'ok', 'Eingerichtet. Ob er erreichbar ist, zeigt „Kalender testen“ in den Einstellungen.')
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
        if ($last === null || $last < $this->app->time()->timestamp() - self::BACKUP_REMIND_DAYS * 86400) {
            return $this->item('backup', 'Datensicherung', 'warn', 'Letzte heruntergeladene Sicherung: ' . $this->date($last) . '. Bitte unter Einstellungen → Datensicherung eine Sicherung herunterladen und aufbewahren.');
        }
        return $this->item('backup', 'Datensicherung', 'ok', 'Letzte heruntergeladene Sicherung: ' . $this->date($last) . '.');
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
