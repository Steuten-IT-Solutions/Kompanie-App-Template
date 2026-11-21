<?php

declare(strict_types=1);

namespace KompanieApp\Core\I18n;

/**
 * Übersetzungen der Texte, die der Server zeigt oder verschickt (Meldungen, Mails, Push).
 *
 * Deutsch ist die Ausgangssprache: Der deutsche Text ist der Schlüssel und braucht keinen Katalog. Jede weitere Sprache
 * hat einen Katalog neben dieser Datei (<sprache>.json), gemeinsam mit der App (packages/frontend/src/i18n.ts). Fehlt
 * ein Eintrag, erscheint der deutsche Text. scripts/check-i18n.php sammelt alle Aufrufe; deshalb sind die Texte immer
 * einfache Zeichenketten.
 *
 * Die Sprache gilt je Anfrage ({@see self::setLocale()}) oder beim Versand je Empfänger ({@see self::in()}).
 */
final class I18n
{
    public const LOCALES = ['de', 'en'];
    public const SOURCE_LOCALE = 'de';
    /** Header, mit dem die App ihre Sprache mitschickt */
    public const HEADER = 'X-Kompanie-Lang';

    private static string $locale = self::SOURCE_LOCALE;

    /** @var array<string, array<string, string|array{one: string, other: string}>> */
    private static array $catalogs = [];

    public static function isLocale(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::LOCALES, true);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** Setzt die Sprache; Unbekanntes wird Deutsch. */
    public static function setLocale(string $locale): void
    {
        self::$locale = self::isLocale($locale) ? $locale : self::SOURCE_LOCALE;
    }

    /**
     * Führt $fn in einer anderen Sprache aus (z. B. eine Mail in der Sprache des Empfängers), danach gilt wieder die
     * vorige.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function in(string $locale, callable $fn): mixed
    {
        $previous = self::$locale;
        self::setLocale($locale);
        try {
            return $fn();
        } finally {
            self::$locale = $previous;
        }
    }

    /**
     * Text in der aktuellen Sprache; $source ist der deutsche Text, {name} wird durch $params['name'] ersetzt.
     *
     * @param array<string, string|int|float> $params
     */
    public static function t(string $source, array $params = []): string
    {
        $entry = self::catalog(self::$locale)[$source] ?? null;
        return self::fill(is_string($entry) && $entry !== '' ? $entry : $source, $params);
    }

    /**
     * Text mit Anzahl, {count} ist die Zahl. Schlüssel ist die deutsche Mehrzahl.
     *
     * @param array<string, string|int|float> $params
     */
    public static function tn(string $one, string $other, int $count, array $params = []): string
    {
        // Deutsch und Englisch nehmen die Einzahl nur für genau 1.
        $form = $count === 1 ? 'one' : 'other';
        $params += ['count' => $count];
        $entry = self::catalog(self::$locale)[$other] ?? null;
        if (is_array($entry) && ($entry[$form] ?? '') !== '') {
            return self::fill($entry[$form], $params);
        }
        return self::fill($form === 'one' ? $one : $other, $params);
    }

    /** @param array<string, string|int|float> $params */
    public static function fill(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0],
            $text,
        );
    }

    /** @return array<string, string|array{one: string, other: string}> */
    private static function catalog(string $locale): array
    {
        if ($locale === self::SOURCE_LOCALE) {
            return [];
        }
        if (!isset(self::$catalogs[$locale])) {
            $json = @file_get_contents(__DIR__ . '/' . $locale . '.json');
            $data = is_string($json) ? json_decode($json, true) : null;
            /** @var array<string, string|array{one: string, other: string}> $catalog */
            $catalog = is_array($data) ? $data : [];
            self::$catalogs[$locale] = $catalog;
        }
        return self::$catalogs[$locale];
    }
}
