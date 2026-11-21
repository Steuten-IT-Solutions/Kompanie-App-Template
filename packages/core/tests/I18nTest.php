<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\I18n\I18n;
use PHPUnit\Framework\TestCase;

/** Übersetzungen im Kern (Plan „Mehrsprachigkeit“): Deutsch als Schlüssel, Rückfall, Platzhalter, Mehrzahl, Sprache. */
final class I18nTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    public function testGermanIsTheDefaultAndTheKey(): void
    {
        self::assertSame('de', I18n::locale());
        self::assertSame('Bitte melde dich an.', I18n::t('Bitte melde dich an.'));
        self::assertSame('Hallo, Anna', I18n::t('Hallo, {name}', ['name' => 'Anna']));
        self::assertSame('{a} und 2', I18n::fill('{a} und {b}', ['b' => 2]));
    }

    public function testMissingEntriesFallBackToGerman(): void
    {
        I18n::setLocale('en');
        self::assertSame('en', I18n::locale());
        self::assertSame('Gibt es nicht im Katalog', I18n::t('Gibt es nicht im Katalog'));
        self::assertSame('2 Gibt es nicht im Katalog', I18n::tn('{count} Gibt es nicht', '{count} Gibt es nicht im Katalog', 2));
    }

    public function testPluralUsesTheSingularForOneOnly(): void
    {
        self::assertSame('1 Bild', I18n::tn('{count} Bild', '{count} Bilder', 1));
        self::assertSame('0 Bilder', I18n::tn('{count} Bild', '{count} Bilder', 0));
        self::assertSame('3 Bilder von Anna', I18n::tn('{count} Bild von {name}', '{count} Bilder von {name}', 3, ['name' => 'Anna']));
    }

    public function testUnknownLocaleFallsBackToGermanAndInRestores(): void
    {
        I18n::setLocale('fr');
        self::assertSame('de', I18n::locale());
        self::assertFalse(I18n::isLocale('fr'));
        self::assertTrue(I18n::isLocale('en'));
        $seen = I18n::in('en', static fn (): string => I18n::locale());
        self::assertSame('en', $seen);
        self::assertSame('de', I18n::locale());
    }

    public function testCatalogsAreValidJsonObjects(): void
    {
        foreach (I18n::LOCALES as $locale) {
            if ($locale === I18n::SOURCE_LOCALE) {
                continue;
            }
            $data = json_decode((string) file_get_contents(dirname(__DIR__) . "/src/I18n/$locale.json"), true);
            self::assertIsArray($data, "$locale.json");
        }
    }
}
