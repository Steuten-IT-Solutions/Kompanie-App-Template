<?php
/**
 * Gemeinsamer Markdown-Renderer für das Handbuch (scripts/handbuch.php) und das Changelog (scripts/changelog.php).
 */

declare(strict_types=1);

/**
 * Markdown (kleine Auswahl) → HTML. Alles wird maskiert; Platzhalter bleiben als Text stehen.
 * $link bildet seite:name auf eine Adresse ab, $image bild:name auf die Adresse eines Bildes. Ein Bild steht als eigene
 * Zeile: ![Beschreibung](bild:name). Ohne $image (oder wenn es null liefert) entfällt das Bild.
 */
function kompanie_markdown(string $md, callable $link, ?callable $image = null): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $inline = static function (string $s) use ($h, $link): string {
        $s = $h($s);
        $s = (string) preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => '<code>' . $m[1] . '</code>', $s);
        $s = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $s);
        return (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m) use ($link): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($url, 'seite:')) {
                return '<a href="' . htmlspecialchars($link(substr($url, 6)), ENT_QUOTES) . '" data-seite="' . htmlspecialchars(substr($url, 6), ENT_QUOTES) . '">' . $m[1] . '</a>';
            }
            if (!preg_match('#^https://#', $url)) {
                return $m[1]; // nur https-Verweise
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
        }, $s);
    };
    $out = [];
    $para = [];
    $list = null;
    $items = [];
    $flushPara = static function () use (&$para, &$out, $inline): void {
        if ($para) {
            $out[] = '<p>' . $inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$items, &$out, $inline): void {
        if ($list) {
            $out[] = "<$list>" . implode('', array_map(static fn (string $i): string => '<li>' . $inline($i) . '</li>', $items)) . "</$list>";
            $list = null;
            $items = [];
        }
    };
    foreach (explode("\n", $md) as $line) {
        $t = rtrim($line);
        if (trim($t) === '') {
            $flushPara();
            $flushList();
            continue;
        }
        if (preg_match('/^!\[([^\]]+)\]\(bild:([a-z0-9-]+)\)$/', trim($t), $m)) {
            $flushPara();
            $flushList();
            $src = $image === null ? null : $image($m[2]);
            if ($src !== null) {
                $alt = $h($m[1]);
                $out[] = '<figure class="hilfe-bild"><img src="' . $h($src) . '" alt="' . $alt . '" loading="lazy"><figcaption>' . $alt . '</figcaption></figure>';
            }
        } elseif (preg_match('/^(#{2,3})\s+(.+)$/', $t, $m)) {
            $flushPara();
            $flushList();
            $level = strlen($m[1]);
            $out[] = "<h$level>" . $inline($m[2]) . "</h$level>";
        } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $t, $m) || preg_match('/^\s*\d+\.\s+(.+)$/', $t, $m)) {
            $flushPara();
            $type = preg_match('/^\s*\d+\./', $t) ? 'ol' : 'ul';
            if ($list !== $type) {
                $flushList();
                $list = $type;
            }
            $items[] = $m[1];
        } elseif (preg_match('/^>\s?(.*)$/', $t, $m)) {
            $flushPara();
            $flushList();
            $out[] = '<p class="hinweis">' . $inline($m[1]) . '</p>';
        } elseif ($list && preg_match('/^\s{2,}(.+)$/', $t, $m)) {
            $items[count($items) - 1] .= ' ' . $m[1]; // Fortsetzung eines Listenpunkts
        } else {
            $flushList();
            $para[] = trim($t);
        }
    }
    $flushPara();
    $flushList();
    return implode("\n", $out);
}
