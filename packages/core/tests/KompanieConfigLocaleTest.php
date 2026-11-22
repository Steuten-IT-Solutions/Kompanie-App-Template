<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\I18n\I18n;
use PHPUnit\Framework\TestCase;

/** Bezeichnungen aus kompanie.json in der Sprache der Anfrage (Plan „Mehrsprachigkeit“): Vorgaben übersetzt, eigene bleiben. */
final class KompanieConfigLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    private static function config(array $extra = []): KompanieConfig
    {
        return new KompanieConfig(['name' => 'Musterkompanie', 'labels' => ['roles' => ['manager' => 'Spieß'], 'sections' => ['penalties' => 'Kasse']]] + $extra);
    }

    public function testGermanStaysAsItIs(): void
    {
        $c = self::config();
        self::assertSame('Termine', $c->sectionLabel('events'));
        self::assertSame('Kasse', $c->sectionLabel('penalties'));
        self::assertSame('Punktekatalog', $c->sectionLabel('catalog'));
        self::assertSame('Mitglied', $c->roleLabel('member'));
        self::assertSame('Dienstgrad', $c->rankLabel());
    }

    public function testEnglishTranslatesOnlyThePresets(): void
    {
        I18n::setLocale('en');
        $c = self::config();
        self::assertSame('Events', $c->sectionLabel('events'));
        self::assertSame('Titles', $c->sectionLabel('titles'));
        self::assertSame('Kasse', $c->sectionLabel('penalties'));
        self::assertSame('Points catalogue', $c->sectionLabel('catalog'));
        self::assertSame('Member', $c->roleLabel('member'));
        self::assertSame('Spieß', $c->roleLabel('manager'));
        self::assertSame('Rank', $c->rankLabel());
        // Eigene Namen der Installation (Einstellungen → Navigation) bleiben in jeder Sprache
        $c->overrideSections(static fn (): array => ['events' => 'Feste']);
        self::assertSame('Feste', $c->sectionLabel('events'));
        // Gespeicherte Rollen mit eigenem Namen bleiben, feste Rollen unter ihrem Vorgabenamen werden übersetzt
        self::assertSame('Vorstand', $c->localized('roles', 'admin', 'Vorstand'));
        self::assertSame('Admin', $c->localized('roles', 'admin', 'Admin'));
        self::assertSame('Guest', $c->localized('roles', 'guest', 'Gast'));
    }

    public function testTranslationsFromKompanieJsonComeFirst(): void
    {
        I18n::setLocale('en');
        $c = self::config(['translations' => ['en' => ['labels' => ['roles' => ['manager' => 'Sergeant'], 'sections' => ['penalties' => 'Kitty'], 'rank' => 'Grade']]]]);
        self::assertSame('Sergeant', $c->roleLabel('manager'));
        self::assertSame('Kitty', $c->sectionLabel('penalties'));
        self::assertSame('Grade', $c->rankLabel());
        self::assertSame('Events', $c->sectionLabel('events'));
    }
}
