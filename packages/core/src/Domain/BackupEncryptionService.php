<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\BackupCrypto;

/**
 * Passwort für Sicherungen (Storage\BackupCrypto): einrichten, prüfen, ändern, entfernen; Sicherungen verschlüsseln.
 *
 * Gespeichert wird nur der öffentliche Schlüssel (Einstellung BackupCrypto::SETTING, gehört zur Installation und kommt
 * nicht in Sicherungen). Das Passwort selbst kennt die App nicht: Wer es vergisst, kann verschlüsselte Sicherungen nicht
 * mehr wiederherstellen. Ändern betrifft nur neue Sicherungen; ältere brauchen weiter das alte Passwort.
 * Mit Passwort gehen auch die Sicherungen in Google Drive verschlüsselt dorthin, ohne unverschlüsselt (BackupUploadService).
 */
final class BackupEncryptionService
{
    /**
     * Fehlversuche „Passwort prüfen“ bzw. Wiederherstellen in 15 Minuten (je für die ganze Installation), danach 15
     * Minuten gesperrt. Nur Fehlversuche zählen, und jeweils getrennt: Prüfen sperrt kein Wiederherstellen im Notfall.
     */
    public const ATTEMPTS = 10;
    /** Eigene Zähler für „Prüfen“ und „Wiederherstellen“: Fehlversuche beim Prüfen sperren kein Wiederherstellen. */
    private const VERIFY = 'verify';
    private const RESTORE = 'restore';

    /** Erste Bytes einer gzip-Datei */
    public const GZIP_MAGIC = "\x1f\x8b";
    /** Stufe beim Packen: 6 ist der übliche Mittelweg aus Größe und Rechenzeit. */
    private const GZIP_LEVEL = 6;

    public function __construct(private readonly App $app)
    {
    }

    /** Ist ein Passwort eingerichtet, auch wenn sodium gerade fehlt (dann ist es nicht configured)? */
    public function stored(): bool
    {
        return $this->key() !== null;
    }

    public function configured(): bool
    {
        // Ohne sodium (Erweiterung später abgeschaltet) lässt sich nichts verschlüsseln: nicht als eingerichtet zählen,
        // sonst entstünden leere Downloads und Drive scheiterte bei jedem Lauf.
        return BackupCrypto::available() && $this->key() !== null;
    }

    /**
     * Wurde das Passwort seit dem Einrichten einmal richtig geprüft („Passwort prüfen“)? Erst dann löscht Google Drive
     * die alten unverschlüsselten Sicherungen: Ein vertipptes Passwort ließe sonst keine lesbare Sicherung übrig.
     */
    public function verified(): bool
    {
        return ($this->key()['verified'] ?? false) === true;
    }

    /** Fingerabdruck des eingerichteten Schlüssels (BackupCrypto::fingerprint), sonst null. */
    public function fingerprint(): ?string
    {
        $key = $this->key();
        return $key !== null ? BackupCrypto::fingerprint($key['public_key']) : null;
    }

    /** @return array{available: bool, configured: bool, stored: bool, verified: bool, fingerprint: ?string, set_at: ?int, min_length: int} */
    public function status(): array
    {
        $key = $this->key();
        return [
            'available' => BackupCrypto::available(),
            'configured' => $this->configured(), // wie überall: nur mit sodium (sonst steht available: false dabei)
            'stored' => $this->stored(), // eingerichtet, auch wenn sodium fehlt (dann lässt es sich nur entfernen)
            'verified' => $this->verified(),
            'fingerprint' => $this->fingerprint(),
            'set_at' => $key !== null && is_int($key['set_at'] ?? null) ? $key['set_at'] : null,
            'min_length' => BackupCrypto::MIN_LENGTH,
        ];
    }

    /** @return array<string, mixed> status() */
    public function set(mixed $passphrase, mixed $confirm): array
    {
        if (!BackupCrypto::available()) {
            throw ApiError::invalid('Auf diesem Server fehlt PHP die Erweiterung sodium; bitte beim Hoster nachfragen.');
        }
        $passphrase = is_string($passphrase) ? $passphrase : '';
        if (mb_strlen($passphrase) < BackupCrypto::MIN_LENGTH) {
            throw ApiError::invalid('Das Passwort für Sicherungen braucht mindestens ' . BackupCrypto::MIN_LENGTH . ' Zeichen.');
        }
        if (!is_string($confirm) || !hash_equals($passphrase, $confirm)) {
            throw ApiError::invalid('Die beiden Passwörter sind nicht gleich.');
        }
        try {
            $key = BackupCrypto::newKey($passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $this->app->settings->set(BackupCrypto::SETTING, $key + ['set_at' => $this->app->time()->timestamp(), 'verified' => false]);
        return $this->status();
    }

    /** Passwort entfernen: neue Sicherungen sind dann unverschlüsselt, auch in Drive. @return array<string, mixed> */
    public function remove(): array
    {
        $this->app->settings->delete(BackupCrypto::SETTING);
        return $this->status();
    }

    /** Passt das Passwort zum eingerichteten? (begrenzt: ATTEMPTS Fehlversuche in 15 Minuten) */
    public function verify(mixed $passphrase): bool
    {
        $key = $this->key() ?? throw ApiError::invalid('Es ist noch kein Passwort für Sicherungen eingerichtet.');
        $this->throttle(self::VERIFY);
        try {
            $ok = is_string($passphrase) && BackupCrypto::matches($key, $passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        if (!$ok) {
            $this->failed(self::VERIFY);
        } else {
            $this->markVerified($key);
        }
        return $ok;
    }

    /**
     * Mit $passphrase wurde gerade eine Sicherung entschlüsselt (Web oder Kommandozeile): Ist es das eingerichtete
     * Passwort, ist das so gut wie „Passwort prüfen“. Nur eine Zugabe: Fehler hier lassen das Wiederherstellen nicht scheitern.
     */
    public function restoredWith(string $passphrase): void
    {
        $key = $this->key();
        try {
            if ($key !== null && !$this->verified() && BackupCrypto::matches($key, $passphrase)) {
                $this->markVerified($key);
            }
        } catch (\RuntimeException) {
        }
    }

    /**
     * @param array{public_key: string, verified?: bool} $key
     */
    private function markVerified(array $key): void
    {
        if (($key['verified'] ?? false) === true) {
            return;
        }
        // Nur den geprüften Schlüssel markieren: Hat jemand inzwischen ein neues Passwort gesetzt, bleibt es ungeprüft.
        $now = $this->app->settings->fresh(BackupCrypto::SETTING);
        if (is_array($now) && ($now['public_key'] ?? null) === $key['public_key']) {
            $this->app->settings->set(BackupCrypto::SETTING, ['verified' => true] + $now);
        }
    }

    /**
     * Hochgeladene Sicherung (POST /admin/backup, backup_base64); ist sie verschlüsselt, mit dem Passwort entschlüsseln.
     * Der Klartext entsteht nur im Arbeitsspeicher (einmal), damit er nicht im /tmp des Servers landet.
     *
     * @throws ApiError 400 passphrase_required, wenn das Passwort fehlt; 400 bei falschem Passwort oder beschädigter
     *                  Datei; 429 nach zu vielen Fehlversuchen
     */
    public function readUpload(string $base64, mixed $passphrase): string
    {
        // Zeilenumbrüche (z. B. von `base64` auf der Kommandozeile) zuerst entfernen: Sonst verdeckten sie den Anfang, und
        // die Stücke unten passten nicht.
        if (strpbrk($base64, " \r\n\t") !== false) {
            $base64 = (string) preg_replace('/\s+/', '', $base64);
        }
        // Am Anfang erkennen, was es ist: Klartext nie in eine Datei (php://temp schreibt ab 2 MB ins /tmp des Servers).
        $head = (string) base64_decode(substr($base64, 0, 4 * (int) ceil(strlen(BackupCrypto::MAGIC) / 3)), false);
        if (!BackupCrypto::isEncrypted($head)) {
            $plain = base64_decode($base64, true);
            if ($plain === false) {
                throw ApiError::invalid('Die Datei ließ sich nicht lesen.');
            }
            return self::unpack($plain); // unverschlüsselte Sicherung (auch gepackt, .json.gz)
        }
        if (!is_string($passphrase) || $passphrase === '') {
            throw new ApiError(400, 'passphrase_required', 'Die Sicherung ist verschlüsselt. Bitte das Passwort für Sicherungen eingeben.');
        }
        $this->throttle(self::RESTORE);
        // Der verschlüsselte Inhalt darf in eine temporäre Datei, der Klartext entsteht nur im Arbeitsspeicher.
        $in = fopen('php://temp', 'w+b');
        if ($in === false) {
            throw ApiError::invalid('Die Datei ließ sich nicht lesen.');
        }
        try {
            // In Stücken von 1 MiB (durch 4 teilbar): streng geprüft, damit
            // eine abgeschnittene oder kaputte Übertragung nicht als „beschädigte Sicherung“ erscheint.
            for ($pos = 0, $len = strlen($base64); $pos < $len; $pos += 1048576) {
                $chunk = base64_decode(substr($base64, $pos, 1048576), true);
                if ($chunk === false) {
                    throw ApiError::invalid('Die Datei ließ sich nicht lesen (Übertragung unvollständig?).');
                }
                if (fwrite($in, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Die Sicherung ließ sich auf dem Server nicht zwischenspeichern (Speicherplatz?).');
                }
            }
            rewind($in);
            $plain = BackupCrypto::decrypt($in, $passphrase);
            $this->restoredWith($passphrase);
            return self::unpack($plain); // in Google Drive gepackt, dann verschlüsselt
        } catch (\RuntimeException $e) {
            // Nur ein falsches Passwort zählt; eine beschädigte Datei oder zu wenig Speicher sperrt niemanden aus.
            if ($e->getCode() === BackupCrypto::WRONG_PASSPHRASE) {
                $this->failed(self::RESTORE);
            }
            throw $e instanceof ApiError ? $e : ApiError::invalid($e->getMessage());
        } finally {
            fclose($in);
        }
    }

    /**
     * Ganze Sicherung verschlüsselt in eine temporäre Datei: beim Schreiben verschlüsselt (BackupEncryptor), der
     * Klartext liegt nie als Datei vor. Der Aufrufer löscht die Datei.
     *
     * @return string Pfad der verschlüsselten Datei
     */
    public function exportToTempFile(BackupProvider $backup, bool $gzip = false): string
    {
        return $this->writeBackupFile(self::packed(static fn ($out) => $backup->exportTo($out), $gzip));
    }

    /**
     * Ganze Sicherung unverschlüsselt in eine temporäre Datei (Google Drive ohne Passwort für Sicherungen), mit denselben
     * Vorkehrungen wie verschlüsselt: nur für den Besitzer lesbar, gelöscht am Ende der Anfrage, falls PHP abbricht. Der
     * Aufrufer löscht sie (discard).
     */
    public function exportPlainToTempFile(BackupProvider $backup, bool $gzip = false): string
    {
        return $this->writeBackupFile(self::packed(static fn ($out) => $backup->exportTo($out), $gzip), null, false);
    }

    /**
     * Ganze Sicherung verschlüsselt in die neue Datei $path (Kommandozeile; nur für den Besitzer lesbar). Gibt es sie
     * schon, oder scheitert etwas, gibt es eine Ausnahme; eine halbe Datei bleibt nicht liegen.
     */
    public function exportToFile(BackupProvider $backup, string $path): void
    {
        $this->writeBackupFile(static fn ($out) => $backup->exportTo($out), $path);
    }

    /**
     * Ganze Sicherung verschlüsselt nach $out (z. B. an den Browser): beim Schreiben verschlüsselt, ohne Zwischendatei.
     *
     * @param resource $out
     * @return int geschriebene Bytes
     */
    public function exportEncryptedTo(BackupProvider $backup, $out): int
    {
        return $this->encryptInto($out, static fn ($o) => $backup->exportTo($o));
    }

    /**
     * @param resource $out
     * @param \Closure(resource): void $write
     */
    private function encryptInto($out, \Closure $write): int
    {
        // configured(): auch ohne sodium eine verständliche Meldung statt eines Fehlers über fehlende Funktionen
        $key = ($this->configured() ? $this->key() : null) ?? throw new \RuntimeException('Es ist kein Passwort für Sicherungen eingerichtet (oder PHP fehlt die Erweiterung sodium).');
        return BackupCrypto::encryptTo($out, $key, $this->meta(), $write);
    }

    /**
     * Datei (z. B. die Sicherung vor einem Update auf dem Server) verschlüsselt in eine temporäre Datei schreiben (zum
     * Hochladen); der Aufrufer löscht sie.
     *
     * @return string Pfad der verschlüsselten Datei
     */
    public function encryptFile(string $plainPath, bool $gzip = false): string
    {
        return $this->copyFile($plainPath, true, $gzip);
    }

    /**
     * Datei (z. B. die Sicherung vor einem Update) gepackt (gzip) in eine temporäre Datei schreiben, unverschlüsselt; der
     * Aufrufer löscht sie.
     */
    public function gzipFile(string $plainPath): string
    {
        return $this->copyFile($plainPath, false, true);
    }

    /** Lässt sich hier packen? Braucht die PHP-Erweiterung zlib (Filter zlib.deflate und gzdecode zum Entpacken). */
    public static function gzipAvailable(): bool
    {
        return function_exists('gzdecode') && in_array('zlib.*', stream_get_filters(), true);
    }

    /**
     * Gepackte Sicherung (gzip, erkennbar an den ersten beiden Bytes) entpacken, sonst unverändert. JSON beginnt nie mit
     * diesen Bytes.
     *
     * @throws ApiError wenn sie sich nicht entpacken lässt
     */
    public static function unpack(string $data): string
    {
        if (!str_starts_with($data, self::GZIP_MAGIC)) {
            return $data;
        }
        if (!function_exists('gzdecode')) {
            throw ApiError::invalid('Die Sicherung ist gepackt (gzip), aber PHP fehlt die Erweiterung zlib. Bitte die Datei vorher entpacken (z. B. mit 7-Zip) und die .json einspielen.');
        }
        $plain = @gzdecode($data);
        if ($plain === false) {
            throw ApiError::invalid('Die gepackte Sicherung ist beschädigt (gzip lässt sich nicht entpacken).');
        }
        return $plain;
    }

    private function copyFile(string $plainPath, bool $encrypt, bool $gzip): string
    {
        $in = @fopen($plainPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (Datei nicht lesbar).');
        }
        try {
            return $this->writeBackupFile(self::packed(static fn ($out) => BackupCrypto::copy($in, $out), $gzip), null, $encrypt);
        } finally {
            fclose($in);
        }
    }

    /**
     * Mit $gzip: was $write schreibt, vorher packen (gzip). Der Filter kommt vor alle anderen (prepend), also vor das
     * Verschlüsseln (BackupEncryptor): gepackt wird der Klartext, verschlüsselte Daten ließen sich nicht packen. Das
     * Entfernen des Filters schreibt den Rest samt Prüfsumme, bevor das Verschlüsseln abschließt.
     *
     * @param \Closure(resource): void $write
     * @return \Closure(resource): void
     */
    private static function packed(\Closure $write, bool $gzip): \Closure
    {
        if (!$gzip) {
            return $write;
        }
        return static function ($out) use ($write): void {
            $filter = @stream_filter_prepend($out, 'zlib.deflate', STREAM_FILTER_WRITE, ['level' => self::GZIP_LEVEL, 'window' => 31]);
            if ($filter === false) {
                throw new \RuntimeException('Die Sicherung ließ sich nicht packen (PHP-Erweiterung zlib).');
            }
            try {
                $write($out);
            } finally {
                $removed = stream_filter_remove($filter);
            }
            if (!$removed) {
                throw new \RuntimeException('Die gepackte Sicherung ließ sich nicht vollständig schreiben.');
            }
        };
    }

    /**
     * Datei (ohne $file: temporär), in die $write schreibt (mit $encrypt verschlüsselt, sonst unverschlüsselt); geprüft, ob alles angekommen ist
     * (Speicherplatz). Bricht PHP ab (Zeitlimit, Abbruch eines Downloads), löscht das Ende der Anfrage die Datei.
     *
     * @param \Closure(resource): void $write
     */
    private function writeBackupFile(\Closure $write, ?string $file = null, bool $encrypt = true): string
    {
        $temp = $file === null;
        $file ??= $this->app->files->tempFile();
        // Eine vorgegebene Datei nur neu anlegen (x), nie eine fremde überschreiben oder löschen.
        $out = @fopen($file, $temp ? 'wb' : 'xb');
        if ($out === false) {
            $temp && @unlink($file); // tempFile() hat sie schon angelegt
            throw new \RuntimeException($temp ? 'Die Sicherung ließ sich nicht erstellen (temporäre Datei).' : "$file lässt sich nicht anlegen (Ordner vorhanden, Schreibrechte, gibt es sie schon?).");
        }
        self::cleanupAtShutdown($file);
        @chmod($file, 0600);
        try {
            try {
                if ($encrypt) {
                    $bytes = $this->encryptInto($out, $write);
                } else {
                    // Unverschlüsselt (Google Drive ohne Passwort): Die Länge kennt nur der Schreiber; fflush meldet volle Platte.
                    $write($out);
                    if (!fflush($out)) {
                        throw new \RuntimeException('Die Sicherung ließ sich nicht vollständig schreiben (Speicherplatz?).');
                    }
                    $bytes = null;
                }
            } finally {
                fclose($out);
            }
            clearstatcache(true, $file);
            if ($bytes !== null && filesize($file) !== $bytes) {
                throw new \RuntimeException('Die verschlüsselte Sicherung ließ sich nicht vollständig schreiben (Speicherplatz?).');
            }
            if (!$temp) {
                unset(self::$temp[$file]); // fertig: bleibt (eine temporäre löscht der Aufrufer, sonst das Ende der Anfrage)
            }
            return $file;
        } catch (\Throwable $e) {
            self::discard($file);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Die Sicherung ließ sich nicht erstellen: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Temporäre Datei (exportToTempFile, encryptFile) löschen, wenn der Aufrufer fertig ist; auch aus der Liste fürs Ende. */
    public static function discard(string $file): void
    {
        @unlink($file);
        unset(self::$temp[$file]);
    }

    /** @var array<string, true> temporäre Dateien, die das Ende der Anfrage löscht (falls noch da) */
    private static array $temp = [];

    /** Bricht PHP ab (Zeitlimit, Abbruch eines Downloads), löscht das Ende der Anfrage die Datei; eine Funktion für alle. */
    private static function cleanupAtShutdown(string $file): void
    {
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$temp) as $f) {
                    is_file($f) && @unlink($f);
                }
            });
        }
        self::$temp[$file] = true;
    }

    /** @return array<string, scalar> unverschlüsselte Angaben im Kopf */
    private function meta(): array
    {
        return ['created' => gmdate('c', $this->app->time()->timestamp()), 'kompanie' => $this->app->config->name()];
    }

    /** @return array{public_key: string, salt: string, ops: int, mem: int, set_at?: int}|null */
    private function key(): ?array
    {
        $k = $this->app->settings->get(BackupCrypto::SETTING);
        if (!is_array($k) || !is_string($k['public_key'] ?? null) || !is_string($k['salt'] ?? null) || !is_numeric($k['ops'] ?? null) || !is_numeric($k['mem'] ?? null)) {
            return null;
        }
        // WordPress kann Zahlen als Zeichenkette zurückgeben.
        return ['public_key' => $k['public_key'], 'salt' => $k['salt'], 'ops' => (int) $k['ops'], 'mem' => (int) $k['mem']]
            + (is_numeric($k['set_at'] ?? null) ? ['set_at' => (int) $k['set_at']] : [])
            // Fehlt der Schalter, gilt das Passwort als ungeprüft: Drive löscht dann lieber nichts.
            + ['verified' => in_array($k['verified'] ?? false, [true, '1', 1], true)];
    }

    private function throttle(string $kind): void
    {
        if ($this->app->cache->get('backup_passphrase_blocked_' . $kind) !== null) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
    }

    /** Fehlversuch zählen; nach ATTEMPTS in 15 Minuten 15 Minuten sperren. */
    private function failed(string $kind): void
    {
        if ($this->app->cache->increment('backup_passphrase_' . $kind, 900) >= self::ATTEMPTS) {
            $this->app->cache->set('backup_passphrase_blocked_' . $kind, 1, 900);
        }
    }
}
