<?php

declare(strict_types=1);

/**
 * Prüft die Übersetzungskataloge (Plan „Mehrsprachigkeit“).
 *
 * Sammelt alle Aufrufe t()/tn() der App (packages/frontend/src, TypeScript) und I18n::t()/I18n::tn() in Kern und
 * Adaptern (PHP) und vergleicht sie mit den Katalogen packages/core/src/I18n/<sprache>.json:
 * - jeder genutzte Text braucht in jedem Katalog einen Eintrag (Text bei t(), Formen one und other bei tn()),
 * - ein Eintrag, den kein Aufruf mehr nutzt, wird gemeldet (der deutsche Text wurde geändert oder entfernt),
 * - eine Übersetzung hat dieselben Platzhalter ({name}) wie ihr deutscher Text,
 * - die Texte sind einfache Zeichenketten (sonst findet sie diese Prüfung nicht).
 *
 *   php scripts/check-i18n.php                 prüfen, Exit-Code 1 bei Fehlern
 *   php scripts/check-i18n.php --missing=en    fehlende Einträge eines Katalogs als JSON ausgeben (leer)
 *
 * Für Tests: --frontend=<ordner> --php=<ordner>[,<ordner>] --catalogs=<ordner>
 */

$root = dirname(__DIR__);
$opts = getopt('', ['frontend:', 'php:', 'catalogs:', 'missing:']);
$frontend = is_string($opts['frontend'] ?? null) ? $opts['frontend'] : $root . '/packages/frontend/src';
$phpDirs = is_string($opts['php'] ?? null) ? explode(',', $opts['php']) : [$root . '/packages/core/src', $root . '/adapters'];
$catalogDir = is_string($opts['catalogs'] ?? null) ? $opts['catalogs'] : $root . '/packages/core/src/I18n';

/** @var list<string> $errors */
$errors = [];
/** @var array<string, array{kind: string, one?: string, where: string}> $used Schlüssel → erster Aufruf */
$used = [];

$use = static function (string $key, string $kind, string $where, ?string $one = null) use (&$used, &$errors): void {
    if ($key === '') {
        $errors[] = "$where: leerer Text";
        return;
    }
    if (isset($used[$key]) && $used[$key]['kind'] !== $kind) {
        $errors[] = "$where: „{$key}“ wird als t() und als tn() genutzt ({$used[$key]['where']})";
        return;
    }
    $used[$key] ??= ['kind' => $kind, 'where' => $where] + ($one !== null ? ['one' => $one] : []);
};

/** @return list<string> */
function i18n_files(string $dir, string $pattern): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = (string) $file;
        if (preg_match($pattern, $path) && !preg_match('#/(generated|node_modules|vendor)/|\.test\.#', $path)) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

/**
 * Liest eine JavaScript-Zeichenkette ab $i ('…', "…" oder `…` ohne ${}); sonst null.
 *
 * @return array{0: string, 1: int}|null Wert und Position dahinter
 */
function i18n_js_string(string $src, int $i): ?array
{
    $q = $src[$i] ?? '';
    if ($q !== "'" && $q !== '"' && $q !== '`') {
        return null;
    }
    $out = '';
    $n = strlen($src);
    for ($j = $i + 1; $j < $n; $j++) {
        $c = $src[$j];
        if ($c === $q) {
            return [$out, $j + 1];
        }
        if ($q === '`' && $c === '$' && ($src[$j + 1] ?? '') === '{') {
            return null;
        }
        if ($c === '\\') {
            $e = $src[++$j] ?? '';
            if ($e === 'u' && preg_match('/\G\{?([0-9a-fA-F]{4,6})\}?/', $src, $m, 0, $j + 1)) {
                $out .= mb_chr((int) hexdec($m[1]), 'UTF-8');
                $j += strlen($m[0]);
                continue;
            }
            $out .= match ($e) {
                'n' => "\n",
                't' => "\t",
                "\n" => '',
                default => $e,
            };
            continue;
        }
        $out .= $c;
    }
    return null;
}

/** Überspringt Leerraum und Kommentare ab $i. */
function i18n_js_skip(string $src, int $i): int
{
    $n = strlen($src);
    while ($i < $n) {
        if (ctype_space($src[$i])) {
            $i++;
        } elseif (substr($src, $i, 2) === '//') {
            $end = strpos($src, "\n", $i);
            $i = $end === false ? $n : $end + 1;
        } elseif (substr($src, $i, 2) === '/*') {
            $end = strpos($src, '*/', $i + 2);
            $i = $end === false ? $n : $end + 2;
        } else {
            break;
        }
    }
    return $i;
}

/**
 * Findet die Aufrufe in einer TypeScript-Datei.
 *
 * @return list<array{kind: string, args: list<string>|null, line: int}> args null = keine einfachen Zeichenketten
 */
function i18n_js_calls(string $src): array
{
    $calls = [];
    $n = strlen($src);
    $i = 0;
    while ($i < $n) {
        $c = $src[$i];
        // Kommentare und Zeichenketten überspringen: Ein „t(“ darin zählt nicht.
        if ($c === '/' && (($src[$i + 1] ?? '') === '/' || ($src[$i + 1] ?? '') === '*')) {
            $i = i18n_js_skip($src, $i);
            continue;
        }
        if ($c === "'" || $c === '"') {
            $i = i18n_js_skip_string($src, $i);
            continue;
        }
        if ($c === '/' && i18n_js_regex_allowed($src, $i)) {
            // Regulärer Ausdruck: Anführungszeichen darin beginnen keine Zeichenkette.
            $i = i18n_js_skip_regex($src, $i);
            continue;
        }
        if ($c === '`') {
            // Aufrufe in ${…} einer Vorlage zählen.
            $end = i18n_js_skip_string($src, $i);
            $body = substr($src, $i + 1, max(0, $end - $i - 2));
            $line = substr_count($src, "\n", 0, $i);
            foreach (i18n_js_template_expressions($body) as [$offset, $expr]) {
                $base = $line + substr_count($body, "\n", 0, $offset);
                foreach (i18n_js_calls($expr) as $call) {
                    $calls[] = ['line' => $call['line'] + $base] + $call;
                }
            }
            $i = $end;
            continue;
        }
        if (preg_match('/\G(tn|t)\s*\(/', $src, $m, 0, $i) && ($i === 0 || !preg_match('/[\w$.]/', $src[$i - 1]) || substr($src, $i - 3, 3) === '...')) {
            $kind = $m[1];
            $j = $i + strlen($m[0]);
            $args = [];
            for ($k = 0; $k < ($kind === 'tn' ? 2 : 1); $k++) {
                $j = i18n_js_skip($src, $j);
                $lit = i18n_js_string($src, $j);
                if ($lit === null) {
                    $args = null;
                    break;
                }
                $args[] = $lit[0];
                $j = i18n_js_skip($src, $lit[1]);
                $next = $src[$j] ?? '';
                if ($next !== ',' && $next !== ')') {
                    // z. B. t('a' + b): keine einfache Zeichenkette
                    $args = null;
                    break;
                }
                $j++;
            }
            $calls[] = ['kind' => $kind, 'args' => $args, 'line' => substr_count($src, "\n", 0, $i) + 1];
            $i += strlen($m[1]);
            continue;
        }
        if (preg_match('/\G[\w$]+/', $src, $m, 0, $i)) {
            $i += strlen($m[0]);
            continue;
        }
        $i++;
    }
    return $calls;
}

/**
 * Ausdrücke in ${…} im Inhalt einer Vorlage (`…`).
 *
 * @return list<array{0: int, 1: string}> Position und Text
 */
function i18n_js_template_expressions(string $body): array
{
    $out = [];
    $n = strlen($body);
    for ($j = 0; $j < $n; $j++) {
        if ($body[$j] === '\\') {
            $j++;
        } elseif ($body[$j] === '$' && ($body[$j + 1] ?? '') === '{') {
            $start = $j + 2;
            $depth = 1;
            for ($j = $start; $j < $n; $j++) {
                $d = $body[$j];
                if ($d === "'" || $d === '"' || $d === '`') {
                    $j = i18n_js_skip_string($body, $j) - 1;
                } elseif ($d === '{') {
                    $depth++;
                } elseif ($d === '}' && --$depth === 0) {
                    break;
                }
            }
            $out[] = [$start, substr($body, $start, $j - $start)];
        }
    }
    return $out;
}

/** Steht ab $i ein regulärer Ausdruck (/…/) und keine Division? Entscheidet das Zeichen oder Wort davor. */
function i18n_js_regex_allowed(string $src, int $i): bool
{
    if ($i > 0 && $src[$i - 1] === '<') {
        return false; // schließendes JSX-Element </…>
    }
    $j = $i - 1;
    while ($j >= 0 && ctype_space($src[$j])) {
        $j--;
    }
    if ($j < 0) {
        return true;
    }
    if (strpbrk($src[$j], '(,=:[!&|?{;+-*%<>~^') !== false) {
        return true;
    }
    if (preg_match('/(?:^|[^\w$])(return|typeof|case|do|else|in|of|void|yield|await|throw|delete|instanceof)$/', substr($src, max(0, $j - 11), $j - max(0, $j - 11) + 1))) {
        return true;
    }
    return false;
}

/** Position hinter dem regulären Ausdruck ab $i (samt Schalter wie /gi). */
function i18n_js_skip_regex(string $src, int $i): int
{
    $n = strlen($src);
    $class = false;
    for ($j = $i + 1; $j < $n; $j++) {
        $c = $src[$j];
        if ($c === '\\') {
            $j++;
        } elseif ($c === "\n") {
            return $j;
        } elseif ($class) {
            $class = $c !== ']';
        } elseif ($c === '[') {
            $class = true;
        } elseif ($c === '/') {
            $j++;
            while ($j < $n && ctype_alpha($src[$j])) {
                $j++;
            }
            return $j;
        }
    }
    return $n;
}

/** Position hinter der Zeichenkette ab $i (Vorlagen samt ${…}). */
function i18n_js_skip_string(string $src, int $i): int
{
    $q = $src[$i];
    $n = strlen($src);
    for ($j = $i + 1; $j < $n; $j++) {
        $c = $src[$j];
        if ($c === '\\') {
            $j++;
        } elseif ($c === $q) {
            return $j + 1;
        } elseif ($q === '`' && $c === '$' && ($src[$j + 1] ?? '') === '{') {
            // Den Ausdruck bis zu seiner schließenden Klammer überspringen, Zeichenketten darin eingeschlossen.
            $depth = 0;
            for ($j += 1; $j < $n; $j++) {
                $d = $src[$j];
                if ($d === "'" || $d === '"' || $d === '`') {
                    $j = i18n_js_skip_string($src, $j) - 1;
                } elseif ($d === '{') {
                    $depth++;
                } elseif ($d === '}' && --$depth === 0) {
                    break;
                }
            }
        } elseif ($q !== '`' && $c === "\n") {
            return $j;
        }
    }
    return $n;
}

/**
 * Findet die Aufrufe I18n::t()/I18n::tn() in einer PHP-Datei.
 *
 * @return list<array{kind: string, args: list<string>|null, line: int}>
 */
function i18n_php_calls(string $src): array
{
    $tokens = array_values(array_filter(token_get_all($src), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $calls = [];
    $count = count($tokens);
    for ($i = 0; $i + 3 < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) || !preg_match('/(^|\\\\)I18n$/', $t[1])) {
            continue;
        }
        $method = $tokens[$i + 2];
        if (!is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON || !is_array($method) || !in_array($method[1], ['t', 'tn'], true) || $tokens[$i + 3] !== '(') {
            continue;
        }
        $kind = $method[1];
        $args = [];
        $j = $i + 4;
        for ($k = 0; $k < ($kind === 'tn' ? 2 : 1); $k++) {
            $lit = $tokens[$j] ?? null;
            $after = $tokens[$j + 1] ?? null;
            if (!is_array($lit) || $lit[0] !== T_CONSTANT_ENCAPSED_STRING || ($after !== ',' && $after !== ')')) {
                $args = null;
                break;
            }
            $args[] = i18n_php_string($lit[1]);
            $j += 2;
        }
        $calls[] = ['kind' => $kind, 'args' => $args, 'line' => $t[2]];
    }
    return $calls;
}

/** Wert einer PHP-Zeichenkette ohne Variablen. */
function i18n_php_string(string $literal): string
{
    $body = substr($literal, 1, -1);
    if ($literal[0] === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
    }
    return stripcslashes(str_replace('\\$', '$', $body));
}

/** @return list<string> Namen der Platzhalter, sortiert */
function i18n_placeholders(string $text): array
{
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_values(array_unique($m[1]));
    sort($names);
    return $names;
}

$rel = static fn (string $path): string => str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;

$collect = static function (array $calls, string $file) use ($use, &$errors, $rel): void {
    foreach ($calls as $call) {
        $where = $rel($file) . ':' . $call['line'];
        if ($call['args'] === null) {
            $errors[] = "$where: {$call['kind']}() braucht den Text als einfache Zeichenkette (kein Ausdruck, keine Variable)";
            continue;
        }
        if ($call['kind'] === 'tn') {
            $use($call['args'][1], 'tn', $where, $call['args'][0]);
        } else {
            $use($call['args'][0], 't', $where);
        }
    }
};

foreach (i18n_files($frontend, '/\.tsx?$/') as $file) {
    if (basename($file) === 'i18n.ts') {
        continue;
    }
    $collect(i18n_js_calls((string) file_get_contents($file)), $file);
}
foreach ($phpDirs as $dir) {
    foreach (i18n_files($dir, '/\.php$/') as $file) {
        if (basename($file) === 'I18n.php') {
            continue;
        }
        $collect(i18n_php_calls((string) file_get_contents($file)), $file);
    }
}
ksort($used, SORT_STRING);

$catalogs = [];
foreach (glob($catalogDir . '/*.json') ?: [] as $path) {
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        $errors[] = $rel($path) . ': kein gültiges JSON-Objekt';
        continue;
    }
    $catalogs[basename($path, '.json')] = $data;
}

if (isset($opts['missing'])) {
    $locale = (string) $opts['missing'];
    $missing = [];
    foreach ($used as $key => $u) {
        if (!array_key_exists($key, $catalogs[$locale] ?? [])) {
            $missing[$key] = $u['kind'] === 'tn' ? ['one' => '', 'other' => ''] : '';
        }
    }
    echo json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

foreach ($catalogs as $locale => $catalog) {
    $file = $rel($catalogDir . "/$locale.json");
    foreach ($used as $key => $u) {
        if (!array_key_exists($key, $catalog)) {
            $errors[] = "$file: fehlt „{$key}“ ({$u['where']})";
            continue;
        }
        $entry = $catalog[$key];
        if ($u['kind'] === 't') {
            if (!is_string($entry) || trim($entry) === '') {
                $errors[] = "$file: „{$key}“ braucht eine Übersetzung als Text";
            } elseif (i18n_placeholders($entry) !== i18n_placeholders($key)) {
                $errors[] = "$file: „{$key}“: Platzhalter der Übersetzung passen nicht zum deutschen Text";
            }
            continue;
        }
        if (!is_array($entry) || !is_string($entry['one'] ?? null) || !is_string($entry['other'] ?? null) || trim($entry['one']) === '' || trim($entry['other']) === '' || count($entry) !== 2) {
            $errors[] = "$file: „{$key}“ braucht die Formen one und other";
            continue;
        }
        // {count} darf in der Einzahl fehlen („one event“ statt „1 event“).
        $expected = array_values(array_diff(i18n_placeholders($key . ' ' . ($u['one'] ?? '')), ['count']));
        foreach (['one', 'other'] as $form) {
            if (array_values(array_diff(i18n_placeholders($entry[$form]), ['count'])) !== $expected) {
                $errors[] = "$file: „{$key}“ ($form): Platzhalter der Übersetzung passen nicht zum deutschen Text";
            }
        }
    }
    foreach (array_keys($catalog) as $key) {
        if (!isset($used[(string) $key])) {
            $errors[] = "$file: „{$key}“ wird nicht mehr genutzt";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    fwrite(STDERR, count($errors) . " Fehler in den Übersetzungen.\n");
    exit(1);
}
echo count($used) . ' Texte, ' . count($catalogs) . " Kataloge vollständig.\n";
