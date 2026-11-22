<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\I18n\I18n;

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
        $items = array_values(array_filter([$this->updates(), $this->gallery(), $this->calendar(), $this->backup(), $this->legal(), $this->server(), $this->push(), $this->app->website()->statusItem()]));
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
        return $ts ? $this->app->time()->format($ts, 'd.m.Y H:i') : I18n::t('noch nie');
    }

    private function updates(): array
    {
        $u = $this->app->updates();
        if (!$u->configured()) {
            return $this->item('updates', I18n::t('Updates'), 'off', I18n::t('Für diese Installation sind keine automatischen Updates eingerichtet.'));
        }
        $s = $u->status();
        $last = is_array($s['last']) ? $s['last'] : null;
        if ($s['withdrawn'] && $s['fallback'] === null) {
            return $this->item('updates', I18n::t('Updates'), 'error', I18n::t('Version {version} wurde zurückgezogen, aber es gibt noch keine geprüfte Version, auf die die Installation wechseln kann. Bitte bei den Betreuern der App melden.', ['version' => (string) $s['current']]));
        }
        if ($s['withdrawn']) {
            return $this->item('updates', I18n::t('Updates'), 'error', I18n::t('Version {version} wurde zurückgezogen.', ['version' => (string) $s['current']]) . ' '
                . ($s['autoAllowed']
                    ? I18n::t('Die Installation wechselt beim nächsten Abgleich automatisch auf Version {version}; das geht auch sofort unter Einstellungen → Zustand und Updates.', ['version' => (string) $s['fallback']])
                    : I18n::t('Bitte unter Einstellungen → Zustand und Updates auf Version {version} wechseln (automatische Updates sind hier gesperrt).', ['version' => (string) $s['fallback']])));
        }
        if ($s['held'] !== null) {
            return $this->item('updates', I18n::t('Updates'), 'warn', I18n::t('Das automatische Update auf Version {version} wurde zurückgestellt. {error} Bitte unter Einstellungen → Zustand und Updates entscheiden: trotzdem aktualisieren oder abbrechen.', ['version' => (string) $s['held']['version'], 'error' => (string) $s['held']['error']]));
        }
        if ($last && !$last['ok'] && ($last['at'] ?? 0) >= ($s['checked_at'] ?? 0) - 86400 * 2) {
            return $this->item('updates', I18n::t('Updates'), 'error', I18n::t('Das letzte Update hat nicht geklappt: {error} Die bisherige Version läuft weiter.', ['error' => (string) $last['error']]));
        }
        if ($s['problems']) {
            return $this->item('updates', I18n::t('Updates'), 'error', I18n::t('Version {version} kann hier nicht eingespielt werden: {problems}', ['version' => (string) $s['latest'], 'problems' => implode(' ', $s['problems'])]));
        }
        if ($s['error'] !== '') {
            return $this->item('updates', I18n::t('Updates'), 'warn', I18n::t('Die Update-Liste war zuletzt nicht erreichbar: {error}', ['error' => (string) $s['error']]));
        }
        if ($s['major']) {
            return $this->item('updates', I18n::t('Updates'), 'warn', I18n::t('Eine neue Hauptversion ({version}) ist da. Sie wird nicht automatisch eingespielt; bitte unter Einstellungen → Zustand und Updates bestätigen.', ['version' => (string) $s['latest']]));
        }
        if ($s['available'] && $s['skipped']) {
            return $this->item('updates', I18n::t('Updates'), 'ok', I18n::t('Version {current} läuft (zurückgerollt). Version {latest} wird nicht automatisch eingespielt; von Hand geht es unter Einstellungen → Zustand und Updates.', ['current' => (string) $s['current'], 'latest' => (string) $s['latest']]));
        }
        if ($s['available'] && !$s['autoAllowed']) {
            return $this->item('updates', I18n::t('Updates'), 'warn', I18n::t('Version {version} ist da. Automatische Updates sind hier gesperrt (z. B. in WordPress); bitte unter Einstellungen → Zustand und Updates aktualisieren.', ['version' => (string) $s['latest']]));
        }
        if ($s['available']) {
            return $this->item('updates', I18n::t('Updates'), 'warn', $s['auto']
                ? I18n::t('Version {version} wird beim nächsten Abgleich automatisch eingespielt.', ['version' => (string) $s['latest']])
                : I18n::t('Version {version} ist da. Automatische Updates sind aus; bitte unter Einstellungen → Zustand und Updates aktualisieren.', ['version' => (string) $s['latest']]));
        }
        return $this->item('updates', I18n::t('Updates'), 'ok', I18n::t('Version {version} ist aktuell. Zuletzt geprüft: {date}.', ['version' => (string) $s['current'], 'date' => $this->date($s['checked_at'])]));
    }

    private function gallery(): array
    {
        $g = $this->app->gallery();
        $label = $this->app->config->sectionLabel('gallery', I18n::t('Galerie'));
        if (!$this->app->features()->enabled('gallery')) {
            return $this->item('gallery', $label, 'off', I18n::t('Der Bereich „{label}“ ist ausgeschaltet.', ['label' => $label]));
        }
        if (!$g->isConfigured()) {
            return $this->item('gallery', $label, 'off', I18n::t('Noch nicht eingerichtet (Einstellungen → {label}).', ['label' => $label]));
        }
        // Belegter Platz der hochgeladenen Bilder (Issue #285): keine Grenze in der App, ein voller Webspace soll auffallen
        $bytes = $g->mediaBytes();
        $space = $bytes > 0 ? ' ' . I18n::t('Hochgeladene Bilder belegen {size}.', ['size' => ServerLimits::format($bytes)]) : '';
        if (!$g->driveConfigured()) {
            $failed = $g->countFailed();
            $pending = $g->countPending();
            return $this->item('gallery', $label, $failed > 0 ? 'warn' : 'ok', I18n::t('Quelle: eigener Server.')
                . ($pending > 0 ? ' ' . I18n::tn('{count} Bild wird noch aufbereitet.', '{count} Bilder werden noch aufbereitet.', $pending) : '')
                . ($failed > 0 ? ' ' . I18n::tn('{count} Bild ließ sich nicht aufbereiten.', '{count} Bilder ließen sich nicht aufbereiten.', $failed) : '') . $space);
        }
        $st = $g->status();
        $last = isset($st['last_sync']) ? (int) $st['last_sync'] : null;
        if (($st['error'] ?? '') !== '') {
            return $this->item('gallery', $label, 'error', I18n::t('Der letzte Abgleich ist fehlgeschlagen: {error} Ist der Ordner für das verbundene Google-Konto bzw. das Dienstkonto noch sichtbar (Einstellungen → Google-Zugang)?', ['error' => (string) $st['error']]));
        }
        if ($last === null || $last < $this->app->time()->timestamp() - 3 * 86400) {
            return $this->item('gallery', $label, 'warn', I18n::t('Der letzte Abgleich ist länger her ({date}). Werden die Seiten der App selten aufgerufen, hilft ein Cronjob beim Hoster.', ['date' => $this->date($last)]));
        }
        $failed = $g->countFailed();
        $pending = $g->countPending();
        if ($failed > 0) {
            return $this->item('gallery', $label, 'warn', I18n::tn('{count} Bild ließ sich nicht laden (z. B. beschädigte Datei). Abgleich zuletzt: {date}.', '{count} Bilder ließen sich nicht laden (z. B. beschädigte Dateien). Abgleich zuletzt: {date}.', $failed, ['date' => $this->date($last)]));
        }
        return $this->item('gallery', $label, 'ok', ($pending > 0 ? I18n::tn('{count} Bild wird noch aufbereitet.', '{count} Bilder werden noch aufbereitet.', $pending) . ' ' : '') . I18n::t('Abgleich zuletzt: {date}.', ['date' => $this->date($last)]) . $space);
    }

    private function calendar(): array
    {
        if (!$this->app->features()->enabled('calendar')) {
            return $this->item('calendar', I18n::t('Kalender'), 'off', I18n::t('Der Kalender ist ausgeschaltet.'));
        }
        $cal = $this->app->calendar();
        if ($cal->source() === CalendarService::SOURCE_APP) {
            return $this->item('calendar', I18n::t('Kalender'), 'ok', I18n::t('Die Termine werden in der App gepflegt.'));
        }
        $via = [
            'google' => I18n::t('Eingerichtet über die Google-API mit eigenem API-Schlüssel.'),
            'google_connection' => I18n::t('Eingerichtet über die Kalender-IDs und den Google-Zugang.'),
            'ics' => I18n::t('Eingerichtet über die iCal-Adresse.'),
        ][$cal->source()] ?? '';
        if ($cal->connectionNeedsCalendarAccess()) {
            // Kalender-IDs eingetragen, aber die Verbindung zu Google wurde ohne Kalender-Berechtigung hergestellt.
            $reconnect = I18n::t('Die Verbindung mit Google darf den Kalender mit den eingetragenen Kalender-IDs nicht lesen (beim Verbinden nicht erlaubt). Unter Einstellungen → Google-Zugang die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den Kalender setzen.');
            return $cal->isConfigured()
                ? $this->item('calendar', I18n::t('Kalender'), 'ok', $via . ' ' . $reconnect)
                : $this->item('calendar', I18n::t('Kalender'), 'warn', $reconnect . ' ' . I18n::t('Bis dahin kannst du eine iCal-Adresse eintragen (Einstellungen → Kalender).'));
        }
        return $cal->isConfigured()
            ? $this->item('calendar', I18n::t('Kalender'), 'ok', $via . ' ' . I18n::t('Ob er erreichbar ist, zeigt „Kalender testen“ in den Einstellungen.'))
            : $this->item('calendar', I18n::t('Kalender'), 'off', I18n::t('Noch nicht eingerichtet (Einstellungen → Kalender).'));
    }

    private function backup(): array
    {
        $last = $this->app->settings->get(self::BACKUP_SETTING);
        // WordPress liefert Zahlen aus den Options als Zeichenkette zurück.
        $last = is_numeric($last) && (int) $last > 0 ? (int) $last : null;
        if ($this->app->backup === null) {
            return $this->item('backup', I18n::t('Datensicherung'), 'off', I18n::t('In dieser Installation nicht verfügbar.'));
        }
        $drive = $this->app->backupUpload()->status();
        $text = I18n::t('Letzte heruntergeladene Sicherung: {date}.', ['date' => $this->date($last)]);
        $hint = '';
        if ($drive['enabled']) {
            $text .= ' ' . I18n::t('Letzte Sicherung in Google Drive: {date}.', ['date' => $this->date($drive['last_ok'])]);
            if (!$drive['access']) {
                return $this->item('backup', I18n::t('Datensicherung'), 'warn', $text . ' ' . I18n::t('Die Verbindung zu Google erlaubt das Hochladen nicht mehr; bitte unter Einstellungen → Google-Zugang neu verbinden.'));
            }
            if ($drive['error'] !== null) {
                return $this->item('backup', I18n::t('Datensicherung'), 'warn', $text . ' ' . I18n::t('Das letzte Hochladen ist fehlgeschlagen: {error}', ['error' => $drive['error']]));
            }
            if ($drive['encrypted'] && !$this->app->backupEncryption()->verified()) {
                // Kein eigener Abbruch: Die Erinnerung an eine zu alte Sicherung (unten) bleibt sichtbar. Bis dahin bleiben
                // in Drive unverschlüsselte Sicherungen liegen (BackupUploadService::prune).
                $hint = ' ' . I18n::t('Das Passwort für Sicherungen ist noch nicht geprüft: Ist es vertippt, lassen sich die verschlüsselten Sicherungen nicht wiederherstellen. Bitte unter Einstellungen → Datensicherung „Passwort prüfen“ wählen; bis dahin löscht die App in Google Drive keine älteren unverschlüsselten Sicherungen.');
            }
        }
        // Sicherungen auf dem eigenen Server: nur Hinweis und Fehler. Sie zählen nicht als aufbewahrte Sicherung, weil sie
        // mit dem Server verloren gehen (die Erinnerung unten bleibt).
        $server = $this->app->serverBackup()->status();
        if ($server['enabled']) {
            $text .= ' ' . I18n::t('Letzte Sicherung auf dem Server: {date}.', ['date' => $this->date($server['last_ok'])]);
            if ($server['error'] !== null) {
                return $this->item('backup', I18n::t('Datensicherung'), 'warn', $text . ' ' . I18n::t('Die letzte Sicherung auf dem Server ist fehlgeschlagen: {error}', ['error' => $server['error']]));
            }
        }
        $newest = max($last ?? 0, $drive['enabled'] ? (int) $drive['last_ok'] : 0);
        if ($newest < $this->app->time()->timestamp() - self::BACKUP_REMIND_DAYS * 86400) {
            return $this->item('backup', I18n::t('Datensicherung'), 'warn', $text . ' ' . I18n::t('Bitte unter Einstellungen → Datensicherung eine Sicherung herunterladen und aufbewahren oder Sicherungen in Google Drive einschalten.') . $hint);
        }
        return $this->item('backup', I18n::t('Datensicherung'), $hint !== '' ? 'warn' : 'ok', $text . $hint);
    }

    /** Impressum und Datenschutzerklärung: gelb, solange ein Link fehlt (Einstellungen → Impressum und Datenschutz). */
    private function legal(): array
    {
        $legal = $this->app->settingsService()->legal();
        $imprint = $legal['imprint_url'] === '';
        $privacy = $legal['privacy_url'] === '';
        $label = I18n::t('Impressum und Datenschutz');
        if ($imprint || $privacy) {
            $missing = match (true) {
                $imprint && $privacy => I18n::t('Es fehlt der Link auf das Impressum und die Datenschutzerklärung.'),
                $imprint => I18n::t('Es fehlt der Link auf das Impressum.'),
                default => I18n::t('Es fehlt der Link auf die Datenschutzerklärung.'),
            };
            return $this->item('legal', $label, 'warn', $missing . ' ' . I18n::t('Bitte unter Einstellungen → Impressum und Datenschutz eintragen; die App zeigt die Links dann allen, auch ohne Anmeldung.'));
        }
        return $this->item('legal', $label, 'ok', I18n::t('Die Links auf Impressum und Datenschutzerklärung sind eingetragen und für alle sichtbar.'));
    }

    private function push(): array
    {
        $missing = $this->app->push()->requirements();
        if ($missing !== []) {
            return $this->item('push', I18n::t('Push-Benachrichtigungen'), 'warn', I18n::t('Auf diesem Server nicht möglich, es fehlt: {missing}. Bitte beim Hoster nachfragen.', ['missing' => implode(', ', $missing)]));
        }
        $c = $this->app->push()->repository()->counts();
        return $this->item('push', I18n::t('Push-Benachrichtigungen'), 'ok', I18n::t('{devices} angemeldet ({accounts}, {guests}).', [
            'devices' => I18n::tn('{count} Gerät', '{count} Geräte', $c['devices']),
            'accounts' => I18n::tn('{count} Konto', '{count} Konten', $c['accounts']),
            'guests' => I18n::tn('{count} Gast', '{count} Gäste', $c['guests']),
        ]));
    }

    private function server(): array
    {
        $free = @disk_free_space($this->app->files->path('.', false));
        if (is_float($free) && $free < 200 * 1024 * 1024) {
            return $this->item('server', I18n::t('Server'), 'warn', I18n::t('Auf dem Webspace sind nur noch {mb} MB frei. Für Updates und Galeriebilder bitte Platz schaffen oder das Paket beim Hoster erweitern.', ['mb' => (int) ($free / 1048576)]));
        }
        return $this->item('server', I18n::t('Server'), 'ok', I18n::t('PHP {php}, Datenbank {db}.', ['php' => PHP_VERSION, 'db' => $this->app->db->driver() === 'sqlite' ? 'SQLite' : 'MySQL/MariaDB']));
    }
}
