<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

/**
 * Formatierter Inhalt „wie in Mailprogrammen“ (Issue #172): Bereinigung nach Allowlist, reiner Textauszug und Bilder.
 * Allgemeiner Baustein, nicht an das Schwarze Brett gebunden (Signaturen, später z. B. Neuigkeiten oder Umfragen).
 *
 * Eigene Umsetzung mit DOMDocument (Vorbild SvgSanitizer, der Kern hat keine Laufzeit-Abhängigkeiten). Es bleibt nur,
 * was auf den Listen steht; die Ausgabe wird neu geschrieben (nicht das geparste Dokument zurückgegeben), Texte und
 * Attributwerte immer escaped.
 *
 * - Elemente: Absätze, Zeilenumbrüche, Überschriften, Zitat, fett/kursiv/unterstrichen/durchgestrichen, Markierung,
 *   Listen, Links, Bilder, Trennlinie, einfache Tabellen. Unbekannte Elemente werden aufgelöst (ihr Text bleibt);
 *   gefährliche (DROP) samt Inhalt entfernt.
 * - Attribute: style (nur Eigenschaften aus STYLES, Werte ohne url(), expression(), Escapes, Kommentare oder
 *   spitze Klammern), Links nur mit https:, mailto: oder tel: (öffnen in neuem Tab, rel="noopener noreferrer"),
 *   Bilder nur über die interne Kennung data-image (nie über src: keine externen Ressourcen), Tabellenzellen
 *   colspan/rowspan. Kein on…, keine Klassen, keine IDs.
 * - Formatierung als Inline-Styles, damit sie in Mails ohne zusätzliches CSS gleich aussieht.
 *
 * Es gibt keine Längengrenze; die Schachtelungstiefe ist auf DEPTH begrenzt (Schutz des Stapels beim Durchlaufen,
 * libxml selbst begrenzt auf 256 Ebenen).
 */
final class RichText
{
    /** Kennung eines Bilds in data-image (RichImageService). */
    public const IMAGE_UID = '/^[a-f0-9]{32}$/';

    private const DEPTH = 200;

    /** Erlaubte Elemente => erlaubte Attribute außer style (das ist überall erlaubt). */
    private const ELEMENTS = [
        'p' => [], 'br' => [], 'span' => [], 'div' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'del' => [], 'strike' => [], 'mark' => [],
        'sub' => [], 'sup' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'a' => ['href'],
        'img' => ['data-image', 'width', 'height', 'alt'],
        'hr' => [],
        'table' => [], 'colgroup' => [], 'col' => ['span'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    ];

    private const VOID = ['br', 'img', 'hr', 'col'];

    /** Werden samt Inhalt entfernt (Skripte, eingebettete Inhalte, Formulare, Kopfdaten). */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
        'option', 'textarea', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base', 'audio',
        'video', 'source', 'track', 'canvas', 'picture', 'xml', 'noembed', 'noframes', 'param', 'dialog',
    ];

    /** Blöcke: im Textauszug je eine eigene Zeile. */
    private const BLOCKS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'table', 'tr', 'hr'];

    /** Erlaubte CSS-Eigenschaften. */
    private const STYLES = [
        'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration',
        'text-decoration-line', 'text-align', 'line-height', 'margin-left', 'padding-left', 'text-indent',
        'vertical-align', 'width', 'height', 'max-width', 'min-width', 'border', 'border-top', 'border-right',
        'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style', 'border-collapse', 'padding',
        'padding-top', 'padding-right', 'padding-bottom', 'margin-top', 'margin-bottom', 'white-space',
    ];

    /**
     * Bereinigt formatierten Inhalt. Das Ergebnis enthält nur Erlaubtes und ist unabhängig von der Eingabe gleich
     * aufgebaut (sanitize(sanitize(x)) === sanitize(x)).
     */
    public static function sanitize(string $html): string
    {
        $root = self::parse($html);
        if ($root === null) {
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= self::node($child, 0);
        }
        return self::trimEmpty($out);
    }

    /**
     * Reiner Text aus bereinigtem (oder beliebigem) Inhalt: Blöcke und <br> als Zeilenumbrüche, Listenpunkte mit „•“
     * bzw. Nummer, Links mit Adresse, Bilder als [Bild] bzw. ihr Alternativtext. Für den Text-Teil der Mail und als
     * Auszug (excerpt) in der API.
     */
    public static function excerpt(string $html): string
    {
        $root = self::parse($html);
        if ($root === null) {
            return '';
        }
        $text = self::text($root, 0);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t]+\n/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /** Hat der Inhalt etwas Sichtbares (Text oder Bild)? */
    public static function isEmpty(string $html): bool
    {
        return trim(self::excerpt($html)) === '' && self::imageUids($html) === [] && !str_contains($html, '<hr');
    }

    /** Für reinen Text (z. B. Migration, Signatur aus Text): escaped, Zeilenumbrüche als <br>, in einem Absatz. */
    public static function fromPlain(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        return $text === '' ? '' : '<p>' . str_replace("\n", '<br>', self::esc($text)) . '</p>';
    }

    /**
     * Kennungen der Bilder im (bereinigten) Inhalt, in der Reihenfolge des Auftretens, ohne Doppelte.
     *
     * @return list<string>
     */
    public static function imageUids(string $html): array
    {
        preg_match_all('/<img\b[^>]*\bdata-image="([a-f0-9]{32})"/', $html, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Für die Ausgabe: jedem Bild die Adresse geben, unter der es ausgeliefert wird (src). Gespeichert wird der Inhalt
     * ohne src; nur die Kennung zählt.
     *
     * @param callable(string): ?string $url Kennung => Adresse (null: Bild entfernen, z. B. gelöscht)
     */
    public static function withImageUrls(string $html, callable $url): string
    {
        return (string) preg_replace_callback('/<img data-image="([a-f0-9]{32})"([^>]*)>/', static function (array $m) use ($url): string {
            $src = $url($m[1]);
            return $src === null ? '' : '<img src="' . self::esc($src) . '" data-image="' . $m[1] . '"' . $m[2] . '>';
        }, $html);
    }

    /**
     * Ersetzt Bildkennungen (z. B. nach dem Kopieren der Bilder einer Signatur in eine Nachricht) und entfernt Bilder,
     * deren Kennung nicht in $map steht, wenn $dropUnknown.
     *
     * @param array<string, string> $map alte Kennung => neue
     */
    public static function replaceImageUids(string $html, array $map, bool $dropUnknown = false): string
    {
        return (string) preg_replace_callback('/<img data-image="([a-f0-9]{32})"([^>]*)>/', static function (array $m) use ($map, $dropUnknown): string {
            if (isset($map[$m[1]])) {
                return '<img data-image="' . $map[$m[1]] . '"' . $m[2] . '>';
            }
            return $dropUnknown ? '' : $m[0];
        }, $html);
    }

    private static function parse(string $html): ?\DOMElement
    {
        if ($html === '' || !class_exists(\DOMDocument::class)) {
            return null;
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }
        // Steuerzeichen außer Tabulator und Zeilenumbruch entfernen (wie Text::clean).
        $html = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // Mit Zeichensatz: sonst liest libxml den Text als ISO-8859-1. Ohne Netz, ohne Entitäten von außen.
            $ok = $doc->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="kompanie-rich-root">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok) {
            return null;
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        // Die Wurzel ist das erste Element im body; schließt die Eingabe sie selbst (</div>), hängt der Rest dahinter.
        if (!$body instanceof \DOMElement) {
            return null;
        }
        $root = $doc->createElement('div');
        while ($body->firstChild !== null) {
            $child = $body->firstChild;
            if ($child instanceof \DOMElement && $child->getAttribute('id') === 'kompanie-rich-root' && $root->childNodes->length === 0) {
                while ($child->firstChild !== null) {
                    $root->appendChild($child->firstChild);
                }
                $body->removeChild($child);
                continue;
            }
            $root->appendChild($child);
        }
        return $root;
    }

    private static function node(\DOMNode $node, int $depth): string
    {
        if ($node instanceof \DOMText) {
            return self::esc($node->data);
        }
        if (!$node instanceof \DOMElement) {
            return ''; // Kommentare, Verarbeitungsanweisungen, CDATA
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true) || str_contains($tag, ':') || $depth > self::DEPTH) {
            return '';
        }
        $inner = '';
        if (!in_array($tag, self::VOID, true)) {
            foreach ($node->childNodes as $child) {
                $inner .= self::node($child, $depth + 1);
            }
        }
        if (!isset(self::ELEMENTS[$tag])) {
            return $inner; // unbekannt: auflösen, Text bleibt
        }
        $attrs = self::attributes($node, $tag);
        if ($attrs === null) {
            return $tag === 'a' ? $inner : ''; // Link ohne erlaubte Adresse: nur der Text; Bild ohne Kennung: weg
        }
        if (in_array($tag, self::VOID, true)) {
            return '<' . $tag . $attrs . '>';
        }
        // Leere Inline-Elemente ergeben nichts (z. B. <span style="…"></span> aus dem Editor).
        if ($inner === '' && in_array($tag, ['span', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike', 'mark', 'sub', 'sup', 'a'], true)) {
            return '';
        }
        return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
    }

    /** Erlaubte Attribute in fester Reihenfolge; null, wenn das Element ohne sie nicht stehen bleiben darf. */
    private static function attributes(\DOMElement $el, string $tag): ?string
    {
        $out = '';
        if ($tag === 'img') {
            $uid = strtolower(trim($el->getAttribute('data-image')));
            if (preg_match(self::IMAGE_UID, $uid) !== 1) {
                return null;
            }
            $out .= ' data-image="' . $uid . '"';
        }
        if ($tag === 'a') {
            $href = self::href($el->getAttribute('href'));
            if ($href === null) {
                return null;
            }
            $out .= ' href="' . self::esc($href) . '" target="_blank" rel="noopener noreferrer"';
        }
        foreach (self::ELEMENTS[$tag] as $name) {
            if ($name === 'data-image' || $name === 'href' || !$el->hasAttribute($name)) {
                continue;
            }
            $value = trim($el->getAttribute($name));
            if (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span', 'start'], true)) {
                if (preg_match('/^\d{1,5}$/', $value) !== 1) {
                    continue;
                }
            } else {
                $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
            }
            $out .= ' ' . $name . '="' . self::esc($value) . '"';
        }
        $style = self::style($el->getAttribute('style'));
        if ($style !== '') {
            $out .= ' style="' . self::esc($style) . '"';
        }
        return $out;
    }

    /** Nur https:, mailto: und tel: (auch ohne Schema angegebene Adressen bleiben nicht stehen). */
    private static function href(string $href): ?string
    {
        $href = trim((string) preg_replace('/[\x00-\x20\x7F]+/u', ' ', $href));
        if ($href === '' || preg_match('/^(https:\/\/[^\s<>"]+|mailto:[^\s<>"]+|tel:[0-9+()\/.\- ]+)$/i', $href) !== 1) {
            return null;
        }
        return $href;
    }

    /** Erlaubte Eigenschaften mit unbedenklichen Werten, normalisiert („name: wert; …“). */
    public static function style(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $pos = strpos($decl, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($decl, 0, $pos)));
            $value = trim(substr($decl, $pos + 1));
            $value = trim((string) preg_replace('/\s*!important$/i', '', $value));
            if (!in_array($name, self::STYLES, true) || $value === '' || strlen($value) > 200) {
                continue;
            }
            // Keine Verweise, Ausdrücke, Escapes, Kommentare oder Zeichen, mit denen man aus dem Attribut käme.
            if (preg_match('/[\\\\<>{}@]|\/\*|url\s*\(|expression\s*\(|image-set|javascript:|behavior|-moz-binding|var\s*\(|attr\s*\(/i', $value) === 1
                || preg_match('/^[#a-zA-Z0-9 .,%\'"()\-+]+$/u', $value) !== 1
                || substr_count($value, '"') % 2 === 1 || substr_count($value, "'") % 2 === 1) {
                continue;
            }
            $out[$name] = $name . ': ' . $value;
        }
        return implode('; ', $out);
    }

    private static function text(\DOMNode $node, int $depth, string $listPrefix = ''): string
    {
        if ($node instanceof \DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->data);
        }
        if (!$node instanceof \DOMElement && $depth > 0) {
            return '';
        }
        $tag = $node instanceof \DOMElement ? strtolower($node->tagName) : 'div';
        if (in_array($tag, self::DROP, true) || $depth > self::DEPTH) {
            return '';
        }
        if ($tag === 'br') {
            return "\n";
        }
        if ($tag === 'hr') {
            return "\n----------\n";
        }
        if ($tag === 'img') {
            $alt = $node instanceof \DOMElement ? trim($node->getAttribute('alt')) : '';
            return $alt !== '' ? '[' . $alt . ']' : '[Bild]';
        }
        $inner = '';
        $n = $tag === 'ol' && $node instanceof \DOMElement && preg_match('/^\d+$/', $node->getAttribute('start')) === 1 ? (int) $node->getAttribute('start') : 1;
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                $inner .= self::text($child, $depth + 1, $tag === 'ol' ? ($n++) . '. ' : '• ');
            } elseif ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $inner .= self::text($child, $depth + 1) . "\t";
            } else {
                $inner .= self::text($child, $depth + 1);
            }
        }
        if ($tag === 'a' && $node instanceof \DOMElement) {
            $href = self::href($node->getAttribute('href'));
            $label = trim($inner);
            if ($href !== null) {
                $plain = (string) preg_replace('/^(mailto:|tel:)/i', '', $href);
                $inner = $label === '' || $label === $plain || $label === $href ? $plain : $label . ' (' . $plain . ')';
            }
        }
        if ($tag === 'li') {
            return $listPrefix . trim($inner) . "\n";
        }
        if (in_array($tag, self::BLOCKS, true)) {
            return "\n" . trim($inner, " \t") . "\n";
        }
        return $inner;
    }

    /** Entfernt leere Absätze am Anfang und Ende (Editor hängt gern <p></p> an). */
    private static function trimEmpty(string $html): string
    {
        $empty = '(?:<p(?: style="[^"]*")?>(?:\s|&nbsp;|<br>)*<\/p>|<br>|\s)';
        return (string) preg_replace('/^' . $empty . '+|' . $empty . '+$/u', '', $html);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }
}
