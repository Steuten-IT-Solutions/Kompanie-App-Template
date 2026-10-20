<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Bereinigt hochgeladene SVG-Dateien (Abzeichen der Dienstgrade, Issue #17).
 *
 * Eigene Umsetzung mit DOMDocument (der Kern hat keine Laufzeit-Abhängigkeiten). Es bleibt nur, was auf der Liste
 * steht: Elemente und Attribute zum Zeichnen. Entfernt werden u. a. <script>, <foreignObject>, Animationen, <image>,
 * Links, alle on*-Attribute, Verweise nach außen (href nur auf „#…“ im selben Dokument, url() nur auf „#…“) und
 * <style> mit @import oder Verweisen nach außen. Dokumente mit DOCTYPE (Entitäten, XXE) werden abgelehnt; geparst
 * wird ohne Netz und ohne Entitäten zu ersetzen.
 *
 * Zusätzlich liefert der Server SVGs mit einer strengen Content-Security-Policy (sandbox) aus.
 */
final class SvgSanitizer
{
    public const NS = 'http://www.w3.org/2000/svg';
    private const XLINK = 'http://www.w3.org/1999/xlink';
    private const XML = 'http://www.w3.org/XML/1998/namespace';

    /** Erlaubte Elemente (SVG-Namensraum). */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'switch',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker',
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feDropShadow', 'feFlood',
        'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology',
        'feOffset', 'feTile', 'feTurbulence',
        'style',
    ];

    /** Elemente, die etwas zeichnen: Bleibt keins übrig, ist das Abzeichen leer. */
    private const DRAWING = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'use'];

    /** Erlaubte Attribute ohne Namensraum (Geometrie, Darstellung, Verweise innerhalb des Dokuments). */
    private const ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'viewBox', 'preserveAspectRatio', 'width', 'height', 'x', 'y', 'x1', 'y1',
        'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'd', 'points', 'pathLength', 'version',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'color',
        'display', 'visibility', 'overflow', 'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits',
        'maskContentUnits', 'filter', 'filterUnits', 'primitiveUnits', 'marker-start', 'marker-mid', 'marker-end',
        'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient', 'stop-color', 'stop-opacity', 'offset',
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'href', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing',
        'text-decoration', 'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'startOffset', 'method', 'spacing',
        'side', 'shape-rendering', 'text-rendering', 'color-interpolation', 'color-interpolation-filters',
        'vector-effect', 'paint-order', 'mix-blend-mode', 'isolation', 'flood-color', 'flood-opacity',
        'lighting-color', 'in', 'in2', 'result', 'mode', 'type', 'values', 'operator', 'k1', 'k2', 'k3', 'k4',
        'stdDeviation', 'edgeMode', 'radius', 'baseFrequency', 'numOctaves', 'seed', 'stitchTiles', 'tableValues',
        'slope', 'intercept', 'amplitude', 'exponent', 'requiredFeatures', 'systemLanguage',
    ];

    /**
     * @return string bereinigtes SVG
     * @throws \InvalidArgumentException mit einer Meldung für die App, wenn die Datei nicht verwendbar ist
     */
    public static function sanitize(string $svg): string
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new \InvalidArgumentException('SVG-Dateien werden auf diesem Server nicht unterstützt (PHP-Erweiterung DOM fehlt). Bitte lade ein PNG hoch.');
        }
        // UTF-8-BOM entfernen; DOCTYPE und Entitäten gar nicht erst parsen (XXE, „Billion Laughs“).
        $svg = (string) preg_replace('/^\xEF\xBB\xBF/', '', $svg);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg)) {
            throw new \InvalidArgumentException('Die SVG-Datei enthält eine DOCTYPE-Angabe; die ist aus Sicherheitsgründen nicht erlaubt.');
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $svg !== '' && $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok || $doc->doctype !== null) {
            throw new \InvalidArgumentException('Die SVG-Datei ist kein gültiges XML.');
        }
        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            throw new \InvalidArgumentException('Die Datei ist kein SVG-Bild (Wurzelelement <svg> im SVG-Namensraum fehlt).');
        }
        self::clean($root, $doc);
        if (!self::draws($root)) {
            throw new \InvalidArgumentException('Im SVG bleibt nach der Prüfung nichts übrig, was sich anzeigen lässt.');
        }
        self::ensureViewBox($root);
        $out = $doc->saveXML($root);
        if ($out === false) {
            throw new \InvalidArgumentException('Die SVG-Datei konnte nicht verarbeitet werden.');
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n";
    }

    private static function clean(\DOMElement $el, \DOMDocument $doc): void
    {
        // Attribute: nur erlaubte, mit unbedenklichen Werten
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            if (!$attr instanceof \DOMAttr || !self::allowedAttribute($el, $attr)) {
                $el->removeAttributeNode($attr);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if ($child->namespaceURI !== self::NS || !in_array($child->localName, self::ELEMENTS, true)) {
                    $el->removeChild($child);
                } elseif ($child->localName === 'style') {
                    if (!self::safeCss($child->textContent)) {
                        $el->removeChild($child);
                    } else {
                        // Nur der Text bleibt (keine Kindelemente, keine Attribute außer type/id).
                        $css = $child->textContent;
                        while ($child->firstChild !== null) {
                            $child->removeChild($child->firstChild);
                        }
                        $child->appendChild($doc->createTextNode($css));
                        self::clean($child, $doc);
                    }
                } else {
                    self::clean($child, $doc);
                }
            } elseif (!$child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                // Kommentare, Verarbeitungsanweisungen, Entitätsverweise
                $el->removeChild($child);
            }
        }
    }

    private static function allowedAttribute(\DOMElement $el, \DOMAttr $attr): bool
    {
        $name = $attr->localName;
        $value = $attr->value;
        if ($attr->namespaceURI === self::XLINK) {
            return $name === 'href' && self::internalRef($value);
        }
        if ($attr->namespaceURI === self::XML) {
            return $name === 'space';
        }
        if ($attr->namespaceURI !== null && $attr->namespaceURI !== '') {
            return false;
        }
        if (!in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }
        if ($name === 'href') {
            return self::internalRef($value);
        }
        if ($el->localName === 'style' && !in_array($name, ['type', 'id'], true)) {
            return false;
        }
        return self::safeValue($value);
    }

    /** Verweis auf ein Element im selben Dokument, z. B. „#verlauf1“. */
    private static function internalRef(string $value): bool
    {
        return (bool) preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', trim($value));
    }

    /** Attributwerte und style: keine Skripte, keine Verweise nach außen. */
    private static function safeValue(string $value): bool
    {
        $v = strtolower($value);
        if (str_contains($v, '\\') || preg_match('/javascript:|vbscript:|data:|expression\s*\(|@import|behavior\s*:|-moz-binding/', $v)) {
            return false;
        }
        // url() nur auf Elemente im selben Dokument: url(#a), url('#a'), url("#a"); jedes „url(“ muss so aussehen.
        $opened = (int) preg_match_all('/url\s*\(/', $v);
        $closed = (int) preg_match_all('/url\s*\(\s*([^)]*)\)/', $v, $m);
        if ($opened !== $closed) {
            return false;
        }
        foreach ($m[1] as $target) {
            if (!self::internalRef(trim($target, " \t\n\r'\""))) {
                return false;
            }
        }
        return true;
    }

    private static function safeCss(string $css): bool
    {
        $v = strtolower($css);
        if (str_contains($v, '<') || preg_match('/@import|@font-face|@namespace/', $v)) {
            return false;
        }
        return self::safeValue($css);
    }

    private static function draws(\DOMElement $root): bool
    {
        foreach (self::DRAWING as $name) {
            foreach ($root->getElementsByTagNameNS(self::NS, $name) as $el) {
                // <use> zeichnet nur mit einem (internen) Verweis.
                if ($name !== 'use' || ($el instanceof \DOMElement && ($el->hasAttribute('href') || $el->hasAttributeNS(self::XLINK, 'href')))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Ohne viewBox skaliert ein SVG in <img> nicht mit; aus width/height ergänzen, wenn das geht. */
    private static function ensureViewBox(\DOMElement $root): void
    {
        if ($root->hasAttribute('viewBox')) {
            return;
        }
        $w = $root->getAttribute('width');
        $h = $root->getAttribute('height');
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $w, $mw) && preg_match('/^\s*(\d+(?:\.\d+)?)\s*(px)?\s*$/', $h, $mh)
            && (float) $mw[1] > 0 && (float) $mh[1] > 0) {
            $root->setAttribute('viewBox', '0 0 ' . $mw[1] . ' ' . $mh[1]);
        }
    }
}
