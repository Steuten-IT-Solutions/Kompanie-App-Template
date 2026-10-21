<?php

declare(strict_types=1);

namespace KompanieApp\Core\Telemetry;

/**
 * Ursache zu einem Fehlercode (Statusmeldungen, Feld details), z. B. „ics_http_404“ oder „timeout“.
 *
 * Eine Ursache entsteht nur aus Angaben, die der Code selbst festlegt (Name einer Aufgabe, Klasse einer
 * Ausnahme, Route, SQLSTATE, Schlüssel einer Ampel) oder aus festen Mustern in einer Fehlermeldung
 * (HTTP-Status, Zeitüberschreitung). Aus dem Text einer Meldung wird nie etwas übernommen außer diesen
 * Mustern: So kommen keine Namen, Pfade oder Adressen in die Statusmeldungen.
 */
final class Detail
{
    /** Erlaubte Form (so in docs/STATUSMELDUNGEN.md beschrieben, für die Prüfung beim Empfänger) */
    public const PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /**
     * Teile zu einem Schlüssel verbinden: klein, nur a–z, 0–9 und _, beginnt mit einem Buchstaben (sonst
     * „n_“ davor), höchstens 64 Zeichen; '' wenn nichts übrig bleibt.
     */
    public static function of(string ...$parts): string
    {
        $token = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(implode('_', $parts))), '_');
        if ($token !== '' && !ctype_alpha($token[0])) {
            $token = 'n_' . $token;
        }
        return rtrim(substr($token, 0, 64), '_');
    }

    /**
     * Art eines Fehlers aus einer Meldung, nur über feste Muster: „http_404“, „timeout“, „dns“,
     * „connect“, „tls“ oder '' (nicht erkannt).
     */
    public static function fromMessage(string $message): string
    {
        if (preg_match('/\bHTTP\s*(\d{3})\b/i', $message, $m)) {
            return 'http_' . $m[1];
        }
        return match (true) {
            (bool) preg_match('/cURL error 28\b|timed out|time-?out|Zeitüberschreitung/i', $message) => 'timeout',
            (bool) preg_match('/cURL error 6\b|resolve host|getaddrinfo|Name or service not known/i', $message) => 'dns',
            (bool) preg_match('/cURL error (35|51|58|60|77)\b|\bSSL\b|certificate|Zertifikat/i', $message) => 'tls',
            (bool) preg_match('/cURL error 7\b|Connection refused|Failed to connect|Keine Verbindung/i', $message) => 'connect',
            default => '',
        };
    }

    /** Kurzname einer Klasse ohne Namensraum, z. B. „TypeError“. */
    public static function className(object $o): string
    {
        $name = $o::class;
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /** Text einer Ausnahme fürs Fehlerprotokoll: Klasse, Meldung, Datei (ohne Verzeichnis) und Zeile. */
    public static function describe(\Throwable $e): string
    {
        return self::className($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}
