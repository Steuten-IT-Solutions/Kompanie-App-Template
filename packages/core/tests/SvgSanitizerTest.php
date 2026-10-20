<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Domain\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SVG-Abzeichen (Issue #17): Sammlung von Angriffen; nichts Ausführbares und kein Verweis nach außen darf bleiben. */
final class SvgSanitizerTest extends TestCase
{
    private const OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';
    private const SHAPE = '<rect id="r" width="10" height="10" fill="#c00"/>';

    /** @return array<string, array{string, list<string>}> SVG, Bestandteile, die danach fehlen müssen */
    public static function attacks(): array
    {
        return [
            'script' => [self::OPEN . self::SHAPE . '<script>alert(1)</script></svg>', ['<script', 'alert']],
            'script im Namensraum mit Präfix' => [self::OPEN . self::SHAPE . '<svg:script xmlns:svg="http://www.w3.org/2000/svg">alert(1)</svg:script></svg>', ['script', 'alert']],
            'onload' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">' . self::SHAPE . '</svg>', ['onload', 'alert']],
            'onclick auf Form' => [self::OPEN . '<rect width="1" height="1" onclick="alert(1)" OnMouseOver="alert(2)"/></svg>', ['onclick', 'alert', 'mouseover']],
            'javascript-Link' => [self::OPEN . self::SHAPE . '<a xlink:href="javascript:alert(1)"><rect width="5" height="5"/></a></svg>', ['javascript', '<a']],
            'href mit javascript an use' => [self::OPEN . self::SHAPE . '<use href="javascript:alert(1)"/></svg>', ['javascript']],
            'externes xlink:href' => [self::OPEN . self::SHAPE . '<use xlink:href="https://evil.example/x.svg#a"/></svg>', ['evil.example']],
            'use mit externem href' => [self::OPEN . self::SHAPE . '<use href="//evil.example/sprite.svg#icon"/></svg>', ['evil.example']],
            'image mit externer Adresse' => [self::OPEN . self::SHAPE . '<image href="https://evil.example/track.png" width="1" height="1"/></svg>', ['<image', 'evil.example']],
            'image mit data:-Adresse' => [self::OPEN . self::SHAPE . '<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" width="1" height="1"/></svg>', ['<image', 'data:']],
            'foreignObject' => [self::OPEN . self::SHAPE . '<foreignObject width="10" height="10"><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://evil.example"/></foreignObject></svg>', ['foreignObject', 'iframe', 'evil.example']],
            'style mit @import' => [self::OPEN . '<style>@import url(https://evil.example/a.css); rect{fill:red}</style>' . self::SHAPE . '</svg>', ['@import', 'evil.example']],
            'style mit url() nach außen' => [self::OPEN . '<style>rect{fill:url(https://evil.example/p.svg#a)}</style>' . self::SHAPE . '</svg>', ['evil.example']],
            'style-Attribut mit url() nach außen' => [self::OPEN . '<rect width="1" height="1" style="fill:url(\'https://evil.example/a\')"/>' . self::SHAPE . '</svg>', ['evil.example']],
            'style mit CSS-Escape' => [self::OPEN . '<rect width="1" height="1" style="fill:u\72l(https://evil.example)"/>' . self::SHAPE . '</svg>', ['evil.example']],
            'filter-Attribut nach außen' => [self::OPEN . '<rect width="1" height="1" filter="url(https://evil.example/f.svg#x)"/>' . self::SHAPE . '</svg>', ['evil.example']],
            'animate setzt href auf javascript' => [self::OPEN . self::SHAPE . '<a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>', ['animate', 'javascript']],
            'set setzt href' => [self::OPEN . self::SHAPE . '<set attributeName="xlink:href" to="javascript:alert(1)"/></svg>', ['<set', 'javascript']],
            'Fremder Namensraum (XHTML)' => [self::OPEN . self::SHAPE . '<html:script xmlns:html="http://www.w3.org/1999/xhtml">alert(1)</html:script></svg>', ['script', 'alert']],
            'Verarbeitungsanweisung und Kommentar' => [self::OPEN . '<?xml-stylesheet href="https://evil.example/a.css"?><!-- geheim -->' . self::SHAPE . '</svg>', ['evil.example', 'geheim']],
            'xml:base' => ['<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://evil.example/">' . self::SHAPE . '</svg>', ['evil.example']],
        ];
    }

    /** @param list<string> $gone */
    #[DataProvider('attacks')]
    public function testAttackIsRemoved(string $svg, array $gone): void
    {
        $clean = SvgSanitizer::sanitize($svg);
        foreach ($gone as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean);
        }
        $this->assertStringNotContainsStringIgnoringCase('javascript', $clean);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean);
        $this->assertStringContainsString('<rect', $clean, 'Die Zeichnung bleibt');
        // Das Ergebnis ist wieder gültiges XML.
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($clean, LIBXML_NONET));
    }

    /** @return array<string, array{string}> */
    public static function rejected(): array
    {
        return [
            'XXE-Entität' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'],
            'Billion Laughs' => ['<!DOCTYPE svg [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>'],
            'externe DTD' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg">' . self::SHAPE . '</svg>'],
            'undeklarierte Entität' => ['<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>'],
            'kein XML' => ['das ist kein Bild'],
            'leer' => [''],
            'HTML statt SVG' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><svg/></body></html>'],
            'svg ohne Namensraum' => ['<svg>' . '<rect width="1" height="1"/></svg>'],
            'nur Skript' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'nur foreignObject' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>'],
            'nur use nach außen' => ['<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/a.svg#x"/></svg>'],
        ];
    }

    #[DataProvider('rejected')]
    public function testUnusableDocumentsAreRejected(string $svg): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SvgSanitizer::sanitize($svg);
    }

    public function testHarmlessContentStays(): void
    {
        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" width="64" height="32">'
            . '<title>Gefreiter</title><metadata>x</metadata><inkscape:grid/>'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#fc0"/></linearGradient><path id="p" d="M0 0h10"/></defs>'
            . '<style>.a{fill:url(#g)}</style>'
            . '<g transform="translate(1 1)" inkscape:label="Ebene"><rect class="a" width="10" height="10" style="stroke:url(\'#g\');stroke-width:2"/>'
            . '<use xlink:href="#p"/><use href="#p"/><text x="1" y="9" font-family="sans-serif">OG</text></g></svg>';
        $clean = SvgSanitizer::sanitize($svg);
        foreach (['<title>Gefreiter</title>', 'linearGradient', 'fill:url(#g)', "stroke:url('#g')", 'xlink:href="#p"', 'href="#p"', '>OG</text>', 'transform="translate(1 1)"'] as $kept) {
            $this->assertStringContainsString($kept, $clean);
        }
        $this->assertStringNotContainsString('metadata', $clean);
        $this->assertStringNotContainsString('inkscape:grid', $clean);
        $this->assertStringNotContainsString('inkscape:label', $clean);
        $this->assertStringContainsString('viewBox="0 0 64 32"', $clean, 'viewBox aus width/height ergänzt, damit es skaliert');
    }
}
