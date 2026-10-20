<?php
/**
 * Handbuch (Issue #12): eine Quelle in docs/handbuch/*.md, zwei Ausgaben.
 *
 *   php scripts/handbuch.php --check
 *       prüft Kopfbereich, Platzhalter, Verweise und ob jeder Bereich mit eigenen Rechten eine Seite hat
 *   php scripts/handbuch.php --app=packages/frontend/src/generated/handbuch.json
 *       Seiten für die Hilfe in der App (offline, passend zur Version); gefiltert wird in der App
 *   php scripts/handbuch.php --site=ordner [--brand=ordner mit brand.json und theme.css]
 *       statische Seiten für GitHub Pages der Kompanie (unter /handbuch/), mit ihren Bezeichnungen
 *
 * Seitenaufbau:
 *   ---
 *   titel: Punkte vergeben
 *   reihenfolge: 40
 *   rechte: [manage_points]       wer die Seite in der App sieht ([] = alle, auch Gäste; [member] = Mitglieder)
 *   bereiche: [points]            nur, wenn diese Bereiche eingeschaltet sind (optional)
 *   variante: wordpress           nur in dieser Variante (optional: wordpress oder standalone)
 *   ---
 *   Markdown: ## und ### Überschriften, Absätze, Listen (- oder 1.), **fett**, *kursiv*, `Code`,
 *   [Text](https://…), [Text](seite:name) für eine andere Handbuchseite, > Hinweis.
 *   Platzhalter: {kompanie.name}, {bereich.events|calendar|news|gallery|points|penalties|ranks|titles|attendance|documents|board}, {begriff.rank},
 *   {rolle.guest|candidate|member|admin}; App und Webseite setzen die Bezeichnungen der Kompanie ein.
 */

declare(strict_types=1);

const CAPABILITIES = ['member', 'calendar', 'manage_points', 'manage_penalties', 'manage_ranks', 'manage_titles', 'manage_attendance', 'manage_events', 'manage_documents', 'post_board', 'create_users', 'promote_users', 'manage_settings'];
const FEATURES = ['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents', 'board'];
/** Bereiche mit eigenen Rechten: Jeder braucht eine Handbuchseite (siehe Domain\Features, Feld role). */
const FEATURES_WITH_ROLE = ['events' => 'manage_events', 'points' => 'manage_points', 'penalties' => 'manage_penalties', 'ranks' => 'manage_ranks', 'titles' => 'manage_titles', 'attendance' => 'manage_attendance', 'documents' => 'manage_documents', 'board' => 'post_board'];
/** Rechte ohne eigenen Bereich, die eine Rolle bekommen kann: Auch dafür braucht es eine Seite. */
const RIGHTS_WITH_PAGE = ['create_users', 'promote_users'];
const PLACEHOLDERS = ['kompanie.name', 'bereich.events', 'bereich.calendar', 'bereich.news', 'bereich.gallery', 'bereich.points', 'bereich.penalties', 'bereich.ranks', 'bereich.titles', 'bereich.attendance', 'bereich.documents', 'bereich.board', 'begriff.rank', 'rolle.guest', 'rolle.candidate', 'rolle.member', 'rolle.admin'];
const DEFAULT_LABELS = [
    'kompanie.name' => 'Kompanie',
    'bereich.events' => 'Termine', 'bereich.calendar' => 'Kalender', 'bereich.news' => 'News', 'bereich.gallery' => 'Galerie',
    'bereich.points' => 'Punkte', 'bereich.penalties' => 'Strafen', 'bereich.ranks' => 'Dienstgrade', 'bereich.titles' => 'Titel', 'bereich.attendance' => 'Anwesenheit', 'bereich.documents' => 'Dokumente', 'bereich.board' => 'Schwarzes Brett', 'begriff.rank' => 'Dienstgrad',
    'rolle.guest' => 'Gast', 'rolle.candidate' => 'Anwärter', 'rolle.member' => 'Mitglied', 'rolle.admin' => 'Admin',
];

$root = dirname(__DIR__);
$opt = getopt('', ['check', 'app:', 'site:', 'brand::', 'src::']);
$src = (string) ($opt['src'] ?? $root . '/docs/handbuch');

/** @return array{0: array<string, mixed>, 1: string[]} Seiten nach Name, Fehler */
function load(string $dir): array
{
    $pages = [];
    $errors = [];
    foreach (glob($dir . '/*.md') ?: [] as $file) {
        $slug = basename($file, '.md');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors[] = "$slug.md: Dateiname nur mit a–z, 0–9 und -";
            continue;
        }
        $text = str_replace("\r\n", "\n", (string) file_get_contents($file));
        if (!preg_match('/^---\n(.*?)\n---\n(.*)$/s', $text, $m)) {
            $errors[] = "$slug.md: Kopfbereich (--- … ---) fehlt";
            continue;
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (!preg_match('/^([a-z]+):\s*(.*)$/', $line, $kv)) {
                $errors[] = "$slug.md: unlesbare Zeile im Kopfbereich: $line";
                continue;
            }
            $v = trim($kv[2]);
            // "…" erlaubt Titel, die mit { beginnen
            if (preg_match('/^"(.*)"$/', $v, $q)) {
                $v = $q[1];
            }
            $meta[$kv[1]] = preg_match('/^\[(.*)\]$/', $v, $l) ? array_values(array_filter(array_map('trim', explode(',', $l[1])), 'strlen')) : $v;
        }
        foreach (['titel', 'reihenfolge', 'rechte'] as $required) {
            if (!array_key_exists($required, $meta)) {
                $errors[] = "$slug.md: „{$required}“ fehlt im Kopfbereich";
            }
        }
        // Ohne [ ] wäre es ein Text und die Seite stünde für alle offen: lieber als Fehler melden.
        foreach (['rechte', 'bereiche'] as $listKey) {
            if (array_key_exists($listKey, $meta) && !is_array($meta[$listKey])) {
                $errors[] = "$slug.md: „{$listKey}“ muss eine Liste in [ ] sein, z. B. {$listKey}: [member]";
            }
        }
        $rechte = is_array($meta['rechte'] ?? null) ? $meta['rechte'] : [];
        $bereiche = is_array($meta['bereiche'] ?? null) ? $meta['bereiche'] : [];
        foreach (array_diff($rechte, CAPABILITIES) as $c) {
            $errors[] = "$slug.md: unbekanntes Recht „{$c}“";
        }
        foreach (array_diff($bereiche, FEATURES) as $f) {
            $errors[] = "$slug.md: unbekannter Bereich „{$f}“";
        }
        $variante = (string) ($meta['variante'] ?? '');
        if ($variante !== '' && !in_array($variante, ['wordpress', 'standalone'], true)) {
            $errors[] = "$slug.md: variante nur wordpress oder standalone";
        }
        preg_match_all('/\{([a-z]+\.[a-z]+)\}/', $m[2] . ' ' . (string) ($meta['titel'] ?? ''), $ph);
        foreach (array_diff(array_unique($ph[1]), PLACEHOLDERS) as $p) {
            $errors[] = "$slug.md: unbekannter Platzhalter {{$p}}";
        }
        $pages[$slug] = [
            'slug' => $slug,
            'titel' => (string) ($meta['titel'] ?? $slug),
            'reihenfolge' => (int) ($meta['reihenfolge'] ?? 0),
            'rechte' => $rechte,
            'bereiche' => $bereiche,
            'variante' => $variante,
            'markdown' => $m[2],
        ];
    }
    uasort($pages, static fn (array $a, array $b): int => [$a['reihenfolge'], $a['slug']] <=> [$b['reihenfolge'], $b['slug']]);
    foreach ($pages as $slug => $p) {
        // Jeder Verweis seite:… muss ein gültiger Seitenname sein (sonst entstünde ein toter oder fremder Link).
        preg_match_all('/\]\(seite:([^)]*)\)/', $p['markdown'], $all);
        foreach ($all[1] as $target) {
            if (!preg_match('/^[a-z0-9-]+$/', $target)) {
                $errors[] = "$slug.md: ungültiger Verweis seite:{$target}";
            }
        }
        preg_match_all('/\]\(seite:([a-z0-9-]+)\)/', $p['markdown'], $links);
        foreach ($links[1] as $target) {
            if (!isset($pages[$target])) {
                $errors[] = "$slug.md: Verweis auf fehlende Seite „{$target}“";
            } elseif (!reachable($p, $pages[$target])) {
                $errors[] = "$slug.md: Verweis auf „{$target}“, die nicht jeder sieht, der diese Seite sieht (Rechte, Bereiche oder Variante)";
            }
        }
    }
    foreach (RIGHTS_WITH_PAGE as $cap) {
        if (!array_filter($pages, static fn (array $p): bool => $p['rechte'] === [$cap])) {
            $errors[] = "Für das Recht „{$cap}“ fehlt eine eigene Handbuchseite (rechte: [{$cap}])";
        }
    }
    foreach (FEATURES_WITH_ROLE as $feature => $cap) {
        if (!array_filter($pages, static fn (array $p): bool => in_array($feature, $p['bereiche'], true) && in_array($cap, $p['rechte'], true))) {
            $errors[] = "Bereich „{$feature}“ hat eigene Rechte ($cap), aber keine Handbuchseite dafür";
        }
    }
    return [$pages, $errors];
}

/**
 * Sieht jeder, der $from sieht, auch $to? Admins (manage_settings) haben alle Rechte. Bereiche und
 * Variante des Ziels dürfen nicht enger sein.
 */
function reachable(array $from, array $to): bool
{
    $held = in_array('manage_settings', $from['rechte'], true) ? CAPABILITIES : $from['rechte'];
    // Der Mitgliederbereich schließt den internen Kalender ein.
    if (in_array('member', $held, true)) {
        $held[] = 'calendar';
    }
    return array_diff($to['rechte'], $held) === []
        && array_diff($to['bereiche'], $from['bereiche']) === []
        && ($to['variante'] === '' || $to['variante'] === $from['variante']);
}

/** Gruppe für die Übersicht. */
function group(array $page): string
{
    $r = $page['rechte'];
    return match (true) {
        $r === [] => 'Für alle',
        in_array('manage_settings', $r, true) => 'Für Admins',
        $r === ['member'], $r === ['calendar'] => 'Für Mitglieder',
        default => 'Für Aufgaben in der Verwaltung',
    };
}

/**
 * Markdown (kleine Auswahl) → HTML. Alles wird maskiert; Platzhalter bleiben als Text stehen.
 * $link bildet seite:name auf eine Adresse ab.
 */
function render(string $md, callable $link): string
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
        if (preg_match('/^(#{2,3})\s+(.+)$/', $t, $m)) {
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

[$pages, $errors] = load($src);

if (isset($opt['check'])) {
    if ($errors) {
        fwrite(STDERR, "Handbuch:\n  - " . implode("\n  - ", $errors) . "\n");
        exit(1);
    }
    echo 'Handbuch: ' . count($pages) . " Seiten, keine Fehler.\n";
    exit(0);
}
if ($errors) {
    fwrite(STDERR, "Handbuch fehlerhaft (php scripts/handbuch.php --check):\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}

if (isset($opt['app'])) {
    $out = [];
    foreach ($pages as $p) {
        $out[] = [
            'slug' => $p['slug'],
            'titel' => $p['titel'],
            'gruppe' => group($p),
            'rechte' => $p['rechte'],
            'bereiche' => $p['bereiche'],
            'variante' => $p['variante'] === '' ? null : $p['variante'],
            'html' => render($p['markdown'], static fn (string $s): string => '#/hilfe/' . $s),
        ];
    }
    $file = (string) $opt['app'];
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode(['seiten' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo 'Handbuch für die App: ' . count($out) . " Seiten → $file\n";
}

if (isset($opt['site'])) {
    $dir = rtrim((string) $opt['site'], '/');
    $brandDir = (string) ($opt['brand'] ?? '');
    $labels = DEFAULT_LABELS;
    $theme = '';
    if ($brandDir !== '' && is_file($brandDir . '/brand.json')) {
        $brand = json_decode((string) file_get_contents($brandDir . '/brand.json'), true);
        if (is_array($brand)) {
            $labels['kompanie.name'] = (string) ($brand['name'] ?? $labels['kompanie.name']);
            foreach ((array) ($brand['labels']['sections'] ?? []) as $k => $v) {
                if (isset($labels['bereich.' . $k]) && is_string($v)) {
                    $labels['bereich.' . $k] = $v;
                }
            }
            if (is_string($brand['labels']['rank'] ?? null)) {
                $labels['begriff.rank'] = $brand['labels']['rank'];
            }
            foreach ((array) ($brand['labels']['roles'] ?? []) as $k => $v) {
                if (isset($labels['rolle.' . $k]) && is_string($v)) {
                    $labels['rolle.' . $k] = $v;
                }
            }
        }
        // theme.css hat download-page.php schon streng geprüft; hier nur die gleichen Zeilen übernehmen.
        $theme = is_file($brandDir . '/theme.css') ? (string) file_get_contents($brandDir . '/theme.css') : '';
        foreach (explode("\n", $theme) as $line) {
            if (!preg_match('~^\s*(|/\*[^*<>]*\*/|:root \{|@media \(prefers-color-scheme: dark\) \{|\}|color-scheme: (light|dark);|--[a-z0-9-]{1,40}: (#[0-9a-fA-F]{3,8}|[A-Za-z0-9 ,\'-]{1,200});)\s*$~', $line)) {
                fwrite(STDERR, "Fehler: theme.css enthält Unerwartetes.\n");
                exit(1);
            }
        }
    }
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Bezeichnungen nach dem Maskieren einsetzen (selbst maskiert).
    $fill = static function (string $html) use ($labels, $h): string {
        return (string) preg_replace_callback('/\{([a-z]+\.[a-z]+)\}/', static fn (array $m): string => isset($labels[$m[1]]) ? $h($labels[$m[1]]) : $m[0], $html);
    };
    $name = $h($labels['kompanie.name']);
    $layout = static function (string $title, string $body) use ($theme, $name, $h): string {
        $t = $h($title);
        return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t} – Handbuch der App der {$name}</title>
<link rel="icon" type="image/png" href="../favicon.png">
<style>
{$theme}
* { box-sizing: border-box; }
body { margin: 0; font: 17px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg, #f4f5f2); color: var(--ink, #1c2321); }
header { background: var(--primary, #2f5d8a); color: var(--on-primary, #fff); border-bottom: 4px solid var(--accent, #d9a441); }
header .wrap { display: flex; gap: 14px; align-items: center; padding-top: 16px; padding-bottom: 16px; }
header img { width: 48px; height: 48px; object-fit: contain; }
header a { color: inherit; text-decoration: none; font-weight: 700; }
.wrap { max-width: 760px; margin: 0 auto; padding: 0 16px; }
main { padding: 24px 0 40px; }
h1 { font-family: var(--font-head, inherit); font-size: 1.7rem; line-height: 1.2; margin: 0 0 14px; }
h2 { font-family: var(--font-head, inherit); font-size: 1.25rem; margin: 26px 0 8px; color: var(--primary-text, inherit); }
h3 { font-size: 1.05rem; margin: 20px 0 6px; }
a { color: var(--primary-text, #2f5d8a); }
code { background: var(--line, #e3e6e1); padding: 1px 6px; border-radius: 5px; font-size: 0.9em; overflow-wrap: anywhere; }
.hinweis { background: var(--card, #fff); border-left: 4px solid var(--accent, #d9a441); padding: 10px 14px; border-radius: 6px; }
.gruppe { margin-top: 26px; }
.gruppe ul { padding-left: 1.2em; }
.wenn { color: var(--muted, #5b6560); font-size: 0.9rem; }
footer { color: var(--muted, #5b6560); font-size: 0.85rem; padding-bottom: 32px; }
</style>
</head>
<body>
<header><div class="wrap"><img src="../logo.png" alt=""><a href="index.html">Handbuch der App der {$name}</a></div></header>
<main class="wrap">
{$body}
</main>
<footer class="wrap">Dieses Handbuch beschreibt alle Bereiche der App. Welche eure Kompanie nutzt, legt ihr Admin fest. <a href="../index.html">Zur Download-Seite</a></footer>
</body>
</html>
HTML;
    };
    @mkdir($dir, 0775, true);
    foreach (glob($dir . '/*.html') ?: [] as $old) {
        unlink($old);
    }
    $featureNames = static fn (array $b): string => implode(', ', array_map(static fn (string $f): string => $labels['bereich.' . $f] ?? $f, $b));
    $groups = [];
    foreach ($pages as $p) {
        $note = [];
        if ($p['bereiche']) {
            $note[] = 'nur, wenn ' . $featureNames($p['bereiche']) . ' eingeschaltet ' . (count($p['bereiche']) > 1 ? 'sind' : 'ist');
        }
        if ($p['variante'] !== '') {
            $note[] = $p['variante'] === 'wordpress' ? 'nur mit WordPress' : 'nur ohne WordPress';
        }
        $wenn = $note ? '<p class="wenn">' . $h(ucfirst(implode('; ', $note))) . '.</p>' : '';
        $body = '<h1>' . $fill($h($p['titel'])) . '</h1>' . $wenn . $fill(render($p['markdown'], static fn (string $s): string => $s . '.html'));
        $title = strtr($p['titel'], array_combine(array_map(static fn (string $k): string => '{' . $k . '}', array_keys($labels)), $labels));
        file_put_contents("$dir/{$p['slug']}.html", $layout($title, $body));
        $groups[group($p)][] = '<li><a href="' . $h($p['slug']) . '.html">' . $fill($h($p['titel'])) . '</a>' . ($note ? ' <span class="wenn">(' . $h(implode('; ', $note)) . ')</span>' : '') . '</li>';
    }
    $index = '<h1>Handbuch</h1><p>Hilfe zur App der ' . $name . ': für alle, für Mitglieder, für Aufgaben in der Verwaltung und für Admins. In der App findest du dieselben Seiten hinter dem „?“ oben rechts, passend zu deinen Rechten.</p>';
    foreach (['Für alle', 'Für Mitglieder', 'Für Aufgaben in der Verwaltung', 'Für Admins'] as $g) {
        if (isset($groups[$g])) {
            $index .= '<section class="gruppe"><h2>' . $h($g) . '</h2><ul>' . implode('', $groups[$g]) . '</ul></section>';
        }
    }
    file_put_contents("$dir/index.html", $layout('Übersicht', $index));
    echo 'Handbuch-Webseite: ' . count($pages) . " Seiten → $dir/\n";
}
