<?php
/**
 * Übernimmt Dateien aus dem Ergebnis des Build-Jobs (nicht vertrauenswürdig: dort liefen npm und
 * die Daten der Kompanie) in den Veröffentlichungs-Job, nur mit erlaubten Dateitypen:
 *
 *   php scripts/import-build.php --from=ordner --to=ordner --types=js,css,html,png
 *
 * Abgelehnt werden: Dateien anderer Typen (z. B. .php, .phtml, .phar, .htaccess, .user.ini),
 * Punktdateien und -ordner, symbolische Links, zu große Dateien. Bei einem Verstoß bricht das
 * Skript ab, statt still auszulassen: Dann stimmt etwas mit dem Build nicht.
 */

declare(strict_types=1);

$opt = getopt('', ['from:', 'to:', 'types:', 'max-mb::']);
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
$from = rtrim((string) ($opt['from'] ?? ''), '/');
$to = rtrim((string) ($opt['to'] ?? ''), '/');
$types = array_filter(array_map('strtolower', explode(',', (string) ($opt['types'] ?? ''))));
$maxBytes = (int) ($opt['max-mb'] ?? 20) * 1048576;
if ($from === '' || $to === '' || $types === [] || !is_dir($from) || is_link($from)) {
    $fail('Aufruf: php scripts/import-build.php --from=ordner --to=ordner --types=js,css,…');
}
foreach ($types as $t) {
    if (!preg_match('/^[a-z0-9]{1,12}$/', $t) || in_array($t, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'htaccess', 'ini', 'cgi', 'pl', 'py', 'sh'], true)) {
        $fail("Dateityp nicht erlaubt: $t");
    }
}

$files = [];
$total = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    $rel = substr($f->getPathname(), strlen($from) + 1);
    if ($f->isLink()) {
        $fail("Symbolischer Link: $rel");
    }
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part[0] === '.' || !preg_match('/^[A-Za-z0-9._@-]+$/', $part)) {
            $fail("Unzulässiger Name: $rel");
        }
    }
    if ($f->isDir()) {
        continue;
    }
    if (!$f->isFile()) {
        $fail("Keine normale Datei: $rel");
    }
    // Nur die letzte Endung zählt nicht allein: „x.php.png“ wird von manchen Servern als PHP ausgeführt.
    $parts = explode('.', strtolower($f->getFilename()));
    $ext = count($parts) > 1 ? array_pop($parts) : '';
    if (!in_array($ext, $types, true)) {
        $fail("Dateityp nicht erlaubt: $rel");
    }
    foreach (array_slice($parts, 1) as $inner) {
        if (preg_match('/^(php\d?|phtml|phar|pht|cgi|pl|py|sh)$/', $inner)) {
            $fail("Unzulässiger Name: $rel");
        }
    }
    if ($f->getSize() > $maxBytes) {
        $fail("Datei zu groß: $rel");
    }
    $total += $f->getSize();
    $files[$rel] = $f->getPathname();
}
if ($total > 5 * $maxBytes) {
    $fail('Zusammen zu groß.');
}
if ($files === []) {
    $fail("$from ist leer.");
}

if (is_dir($to)) {
    exec('rm -rf ' . escapeshellarg($to), $o, $code);
    if ($code !== 0) {
        $fail("$to lässt sich nicht leeren.");
    }
}
ksort($files);
foreach ($files as $rel => $src) {
    $dest = "$to/$rel";
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) {
        $fail("Ordner für $rel lässt sich nicht anlegen.");
    }
    if (!copy($src, $dest)) {
        $fail("$rel lässt sich nicht kopieren.");
    }
    chmod($dest, 0644);
}
echo count($files) . " Dateien übernommen nach $to.\n";
