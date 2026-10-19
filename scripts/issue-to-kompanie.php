<?php
/**
 * Übersetzt ein ausgefülltes Formular „Neue Kompanie“ bzw. „Kompanie ändern“ (Text des Issues) in
 * kompanie.json (aus .github/workflows/kompanie-formular.yml):
 *
 *   php scripts/issue-to-kompanie.php --mode=neu|aendern --body=issue.md --out=ordner [--current=kompanie.json]
 *   php scripts/issue-to-kompanie.php --mode=aendern --body=issue.md --out=ordner --repo-only   nur repo.txt
 *
 * Schreibt nach --out: kompanie.json, repo.txt und (falls angegeben) logo-url.txt.
 * Bei ungültigen Angaben: Meldung in fehler.txt und auf STDERR, Exit-Code 1.
 * Die vollständige Prüfung gegen das Schema (und den Kontrast) macht danach das Branding beim Bauen.
 */

declare(strict_types=1);

$opt = getopt('', ['mode:', 'body:', 'out:', 'current::', 'repo-only']);
$out = rtrim((string) ($opt['out'] ?? ''), '/');
$mode = (string) ($opt['mode'] ?? '');
if ($out === '' || !in_array($mode, ['neu', 'aendern'], true) || !is_file((string) ($opt['body'] ?? ''))) {
    fwrite(STDERR, "Aufruf: php scripts/issue-to-kompanie.php --mode=neu|aendern --body=issue.md --out=ordner [--current=kompanie.json]\n");
    exit(2);
}
@mkdir($out, 0755, true);
$errors = [];
$fail = static function (array $errors) use ($out): never {
    $msg = implode("\n", array_map(static fn (string $e): string => "- $e", $errors));
    file_put_contents("$out/fehler.txt", $msg . "\n");
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

/** Felder des Formulars: Überschrift im Issue => Schlüssel */
const FIELDS = [
    'Name der Kompanie' => 'name',
    'Name des Repos' => 'repo',
    'Kurzname' => 'shortName',
    'Hauptfarbe' => 'primary',
    'Akzentfarbe' => 'accent',
    'Begrüßung' => 'greeting',
    'Willkommenstext für Gäste' => 'guestWelcome',
    'Bezeichnung für Verwalter' => 'manager',
    'Bezeichnung für Strafen' => 'penalties',
    'Logo' => 'logo',
    'Neues Logo' => 'logo',
];

// Issue-Formulare: „### Überschrift“, Leerzeile, Wert; leere Felder als „_No response_“.
$body = str_replace("\r\n", "\n", (string) file_get_contents((string) $opt['body']));
// Nur bekannte Überschriften trennen Felder; eine andere „### …“-Zeile gehört zum Wert davor.
// Taucht ein Feld doppelt auf (z. B. eine Überschrift im Freitext), ist der Text mehrdeutig: ablehnen.
$parts = preg_split('/^### (.+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
$raw = [];
$current = null;
for ($i = 1; $i + 1 < count($parts); $i += 2) {
    $key = FIELDS[trim($parts[$i])] ?? null;
    if ($key === null) {
        if ($current !== null) {
            $raw[$current] .= '### ' . $parts[$i] . $parts[$i + 1];
        }
        continue;
    }
    if (isset($raw[$key])) {
        $errors[] = 'Das Feld „' . trim($parts[$i]) . '“ kommt doppelt vor (steht eine Überschrift mit ### im Text?).';
    }
    $raw[$key] = $parts[$i + 1];
    $current = $key;
}
$f = [];
foreach ($raw as $key => $value) {
    $value = trim($value);
    if ($value !== '_No response_' && $value !== 'None') {
        $f[$key] = $value;
    }
}
$text = static fn (string $k): string => trim((string) preg_replace('/\s+/u', ' ', $f[$k] ?? ''));

$repo = strtolower($text('repo'));
if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $repo) || strlen($repo) > 60) {
    $errors[] = 'Name des Repos: nur Kleinbuchstaben a–z, Ziffern und einzelne Bindestriche (höchstens 60 Zeichen).';
} elseif (in_array($repo, ['kompanie-app-template', 'kompanie-app-vorlage'], true)) {
    $errors[] = "Der Repo-Name „{$repo}“ ist reserviert.";
}

if (isset($opt['repo-only'])) {
    if ($errors) {
        $fail($errors);
    }
    file_put_contents("$out/repo.txt", $repo . "\n");
    exit(0);
}
if ($mode === 'aendern') {
    $k = json_decode((string) @file_get_contents((string) ($opt['current'] ?? '')), true);
    if (!is_array($k)) {
        $errors[] = 'Die bisherige kompanie.json des Repos ließ sich nicht lesen (gibt es das Repo?).';
        $fail($errors);
    }
} else {
    $k = ['$schema' => 'https://raw.githubusercontent.com/Steuten-IT-Solutions/Kompanie-App-Template/v1/config/kompanie.schema.json'];
}

// „-“ entfernt einen optionalen Wert (nur beim Ändern sinnvoll).
$set = static function (array &$k, array $path, string $value, bool $removable = true): void {
    $ref = &$k;
    foreach (array_slice($path, 0, -1) as $p) {
        $ref[$p] = is_array($ref[$p] ?? null) ? $ref[$p] : [];
        $ref = &$ref[$p];
    }
    $last = end($path);
    if ($value === '-' && $removable) {
        unset($ref[$last]);
    } elseif ($value !== '') {
        $ref[$last] = $value;
    }
};

if ($text('name') === '-' || $text('primary') === '-') {
    $errors[] = 'Name und Hauptfarbe lassen sich nicht entfernen, nur ändern.';
}
foreach (['name' => ['name'], 'shortName' => ['shortName'], 'greeting' => ['greeting'], 'manager' => ['labels', 'roles', 'manager'], 'penalties' => ['labels', 'sections', 'penalties']] as $field => $path) {
    $set($k, $path, $text($field), $field !== 'name');
}
$welcome = trim((string) preg_replace("/[ \t]+/", ' ', $f['guestWelcome'] ?? ''));
$set($k, ['guestWelcome'], $welcome);
foreach (['primary', 'accent'] as $c) {
    $v = strtolower($text($c));
    if ($v !== '' && $v !== '-' && !preg_match('/^#[0-9a-f]{6}$/', $v)) {
        $errors[] = ($c === 'primary' ? 'Hauptfarbe' : 'Akzentfarbe') . ": „{$v}“ ist kein Farbcode wie #2e6b3f.";
        continue;
    }
    $set($k, ['colors', $c], $v, $c === 'accent');
}
if ($mode === 'neu') {
    if ($text('name') === '') {
        $errors[] = 'Der Name der Kompanie fehlt.';
    }
    if ($text('primary') === '') {
        $errors[] = 'Die Hauptfarbe fehlt.';
    }
}
// Leere Gruppen entfernen (sonst entstünde [] statt eines Objekts).
$prune = static function (array $a) use (&$prune): array {
    foreach ($a as $key => $v) {
        if (is_array($v)) {
            $v = $prune($v);
            if ($v === []) {
                unset($a[$key]);
                continue;
            }
            $a[$key] = $v;
        }
    }
    return $a;
};
$k = $prune($k);

// Logo: der erste Anhang (GitHub legt hineingezogene Bilder unter user-attachments ab)
$logo = '';
if (preg_match('~https://(github\.com/user-attachments/assets/[0-9a-f-]{36}|(private-)?user-images\.githubusercontent\.com/[A-Za-z0-9/._-]+)~', $f['logo'] ?? '', $m)) {
    $logo = $m[0];
} elseif ($mode === 'neu') {
    $errors[] = 'Das Logo fehlt: bitte das Bild in das Feld „Logo“ ziehen.';
} elseif (trim($f['logo'] ?? '') !== '') {
    $errors[] = 'Im Feld „Neues Logo“ steht kein hochgeladenes Bild.';
}

if ($errors) {
    $fail($errors);
}
file_put_contents("$out/kompanie.json", json_encode($k, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents("$out/repo.txt", $repo . "\n");
if ($logo !== '') {
    file_put_contents("$out/logo-url.txt", $logo . "\n");
}
echo "kompanie.json für $repo erstellt.\n";
