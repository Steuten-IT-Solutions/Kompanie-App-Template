<?php
/**
 * Changelog: eine Quelle in CHANGELOG.md, angezeigt in der App (Einstellungen → Was ist neu, nur für Admins).
 *
 *   php scripts/changelog.php --check
 *       prüft den Aufbau (Abschnitte, Reihenfolge der Versionen, Datum, Unterabschnitte)
 *   php scripts/changelog.php --check --require-version
 *       zusätzlich für ein Release: Die Version aus VERSION hat einen Abschnitt, „Unveröffentlicht“ ist leer
 *   php scripts/changelog.php --app=packages/frontend/src/generated/changelog.json
 *       Versionen als HTML für die App (passend zur installierten Version, offline)
 *   Für Tests: --src=datei statt CHANGELOG.md, --version=x.y.z statt der Datei VERSION.
 *
 * Aufbau:
 *   # Changelog
 *   Einleitung (beliebiger Text, erscheint nicht in der App)
 *   ## [Unveröffentlicht]
 *   ### Neu
 *   - Eintrag
 *   ## [1.2.0] – 2026-11-03
 *   ### Neu | Geändert | Behoben | Entfernt | Sicherheit
 *   - Eintrag (**fett**, *kursiv*, `Code`, [Text](https://…); Fortsetzung eingerückt)
 */

declare(strict_types=1);

require __DIR__ . '/markdown.php';

const KINDS = ['Neu', 'Geändert', 'Behoben', 'Entfernt', 'Sicherheit'];
const UNRELEASED = 'Unveröffentlicht';

$root = dirname(__DIR__);
$opt = getopt('', ['check', 'require-version', 'app:', 'src::', 'version::']);
$src = (string) ($opt['src'] ?? $root . '/CHANGELOG.md');
$coreVersion = (string) ($opt['version'] ?? trim((string) @file_get_contents($root . '/VERSION')));

/**
 * @return array{0: array{markdown: string}|null, 1: list<array{version: string, datum: string, markdown: string}>, 2: string[]}
 *         Unveröffentlicht, Versionen (neueste zuerst), Fehler
 */
function load(string $file, string $coreVersion): array
{
    if (!is_file($file)) {
        return [null, [], [basename($file) . ' fehlt']];
    }
    $lines = explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($file)));
    $errors = [];
    $unreleased = null;
    $versions = [];
    $current = null; // Index in $sections
    $sections = [];
    $kind = null;
    $kindHasItems = true;
    $closeKind = static function (int $n) use (&$kind, &$kindHasItems, &$errors, &$sections, &$current): void {
        if ($kind !== null && !$kindHasItems) {
            $errors[] = "Zeile $n: „### {$kind}“ in [{$sections[$current]['name']}] hat keine Einträge";
        }
        $kind = null;
        $kindHasItems = true;
    };
    if (($lines[0] ?? '') !== '# Changelog') {
        $errors[] = 'Zeile 1: muss „# Changelog“ sein';
    }
    foreach ($lines as $i => $line) {
        $n = $i + 1;
        $t = rtrim($line);
        if (str_starts_with($t, '## ')) {
            $closeKind($n);
            if (preg_match('/^## \[' . UNRELEASED . '\]$/u', $t)) {
                if ($sections) {
                    $errors[] = "Zeile $n: [" . UNRELEASED . '] muss der erste Abschnitt sein und darf nur einmal vorkommen';
                }
                $sections[] = ['name' => UNRELEASED, 'line' => $n, 'body' => []];
            } elseif (preg_match('/^## \[(\d+\.\d+\.\d+)\] [–-] (\d{4})-(\d{2})-(\d{2})$/u', $t, $m)) {
                if (!checkdate((int) $m[3], (int) $m[4], (int) $m[2])) {
                    $errors[] = "Zeile $n: ungültiges Datum $m[2]-$m[3]-$m[4]";
                }
                $sections[] = ['name' => $m[1], 'datum' => "$m[2]-$m[3]-$m[4]", 'line' => $n, 'body' => []];
            } else {
                $errors[] = "Zeile $n: Abschnitt muss „## [" . UNRELEASED . ']“ oder „## [x.y.z] – JJJJ-MM-TT“ sein';
                $sections[] = ['name' => '?', 'line' => $n, 'body' => []];
            }
            $current = count($sections) - 1;
            continue;
        }
        if ($current === null) {
            continue; // Einleitung
        }
        $sections[$current]['body'][] = $t;
        if (trim($t) === '') {
            continue;
        }
        if (str_starts_with($t, '### ')) {
            $closeKind($n);
            $kind = substr($t, 4);
            $kindHasItems = false;
            if (!in_array($kind, KINDS, true)) {
                $errors[] = "Zeile $n: unbekannter Unterabschnitt „{$kind}“ (erlaubt: " . implode(', ', KINDS) . ')';
            }
        } elseif (str_starts_with($t, '#')) {
            $errors[] = "Zeile $n: nur Überschriften mit ## und ### sind erlaubt";
        } elseif ($kind === null) {
            $errors[] = "Zeile $n: Eintrag ohne Unterabschnitt (### " . implode(', ### ', KINDS) . ')';
        } elseif (preg_match('/^- \S/u', $t)) {
            $kindHasItems = true;
        } elseif (!preg_match('/^\s{2,}\S/u', $t)) {
            $errors[] = "Zeile $n: Einträge als Liste mit „- “ (Fortsetzung eingerückt)";
        }
        if (str_contains($t, '](seite:')) {
            $errors[] = "Zeile $n: Verweise auf Handbuchseiten (seite:) gibt es im Changelog nicht";
        }
    }
    $closeKind(count($lines));

    if (!$sections || $sections[0]['name'] !== UNRELEASED) {
        $errors[] = 'Abschnitt [' . UNRELEASED . '] fehlt (als erster Abschnitt, gern leer)';
    }
    $prev = null;
    foreach ($sections as $s) {
        $markdown = trim(implode("\n", $s['body']));
        if ($s['name'] === UNRELEASED) {
            $unreleased = ['markdown' => $markdown];
            continue;
        }
        if ($s['name'] === '?') {
            continue;
        }
        if ($markdown === '') {
            $errors[] = "Zeile {$s['line']}: [{$s['name']}] hat keine Einträge";
        }
        if ($prev !== null && version_compare($s['name'], $prev['name'], '>=')) {
            $errors[] = "Zeile {$s['line']}: [{$s['name']}] muss älter sein als [{$prev['name']}] darüber (neueste Version zuerst, keine doppelt)";
        } elseif ($prev !== null && $s['datum'] > $prev['datum']) {
            $errors[] = "Zeile {$s['line']}: Datum von [{$s['name']}] liegt nach dem der neueren Version [{$prev['name']}]";
        }
        $prev = $s;
        $versions[] = ['version' => $s['name'], 'datum' => $s['datum'], 'markdown' => $markdown];
    }
    if ($versions && preg_match('/^\d+\.\d+\.\d+$/', $coreVersion) && version_compare($versions[0]['version'], $coreVersion, '>')) {
        $errors[] = "[{$versions[0]['version']}] ist neuer als die Kern-Version $coreVersion in VERSION";
    }
    return [$unreleased, $versions, $errors];
}

/** HTML eines Abschnitts: Unterabschnitte als h4 (die Version selbst ist in der App die h3). */
function html(string $markdown): string
{
    $html = kompanie_markdown($markdown, static fn (string $s): string => '#');
    return str_replace(['<h3>', '</h3>'], ['<h4>', '</h4>'], $html);
}

[$unreleased, $versions, $errors] = load($src, $coreVersion);

if (isset($opt['require-version'])) {
    if (!preg_match('/^\d+\.\d+\.\d+$/', $coreVersion)) {
        $errors[] = "VERSION enthält keine Kern-Version x.y.z („{$coreVersion}“)";
    } elseif (($versions[0]['version'] ?? null) !== $coreVersion) {
        $errors[] = "Für die Kern-Version $coreVersion (Datei VERSION) fehlt der Abschnitt „## [$coreVersion] – JJJJ-MM-TT“ als neueste Version";
    }
    if ($unreleased !== null && $unreleased['markdown'] !== '') {
        $errors[] = '[' . UNRELEASED . '] ist nicht leer: Einträge vor dem Release in den Abschnitt der neuen Version verschieben';
    }
}

if ($errors) {
    fwrite(STDERR, (isset($opt['check']) ? 'Changelog' : 'Changelog fehlerhaft (php scripts/changelog.php --check)') . ":\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}
if (isset($opt['check'])) {
    echo 'Changelog: ' . count($versions) . ' Versionen' . (isset($opt['require-version']) ? ", Kern-Version $coreVersion eingetragen" : '') . ", keine Fehler.\n";
}

if (isset($opt['app'])) {
    $out = [
        'versionen' => array_map(static fn (array $v): array => ['version' => $v['version'], 'datum' => $v['datum'], 'html' => html($v['markdown'])], $versions),
        'unveroeffentlicht' => $unreleased !== null && $unreleased['markdown'] !== '' ? html($unreleased['markdown']) : null,
    ];
    $file = (string) $opt['app'];
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo 'Changelog für die App: ' . count($versions) . " Versionen → $file\n";
}
