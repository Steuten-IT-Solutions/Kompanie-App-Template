<?php
/**
 * Wartungsabo der Kompanien: Nur Kompanien mit laufender Wartung bekommen neue Versionen.
 *
 *   php scripts/wartung.php --filter [--complete] [--today=JJJJ-MM-TT] < repos.txt
 *       liest Kompanie-Repos (Organisation/Name, eins je Zeile) und gibt die aus, deren Wartung heute läuft;
 *       --complete: die Eingabe sind alle Kompanien (dann Warnung für Namen in der Liste, die es nicht gibt)
 *   php scripts/wartung.php --until=Organisation/Name
 *       gibt „Wartung bis“ dieser Kompanie aus (JJJJ-MM-TT oder „unbefristet“), sonst nichts
 *   php scripts/wartung.php --check
 *       prüft nur den Aufbau
 *
 * Die Angaben stehen in der Umgebungsvariable WARTUNG (Variable KOMPANIE_WARTUNG des Kern-Repos), eine Kompanie je
 * Zeile: Name des Kompanie-Repos (mit oder ohne Organisation), dann das letzte Datum mit Wartung (JJJJ-MM-TT, UTC,
 * einschließlich) oder „unbefristet“. Leere Zeilen und Zeilen mit # am Anfang zählen nicht.
 *
 * Ist WARTUNG leer, gilt wie vor dem Wartungsabo: alle Kompanien bekommen Updates (mit Warnung). So bleibt der
 * Ablauf heil, bis die Variable angelegt ist. Ein Fehler im Aufbau bricht ab (Exit 1), statt still alle oder
 * keine Kompanie zu nehmen. Meldungen gehen nach stderr, im Format der Anmerkungen von GitHub Actions.
 */

declare(strict_types=1);

const UNBEFRISTET = 'unbefristet';

/** @return array<string, string>|null Name des Repos (klein) → Datum oder „unbefristet“; null = Variable leer */
function wartung_parse(string $text, array &$errors): ?array
{
    $out = [];
    $any = false;
    foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $any = true;
        $n = $i + 1;
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) !== 2) {
            $errors[] = "Zeile $n: erwartet „Repo Datum“, gefunden „{$line}“.";
            continue;
        }
        [$repo, $until] = $parts;
        $name = wartung_name($repo);
        if (!preg_match('/^[a-z0-9._-]+$/', $name)) {
            $errors[] = "Zeile $n: ungültiger Name des Repos „{$repo}“.";
            continue;
        }
        $until = strtolower($until);
        if ($until !== UNBEFRISTET && !wartung_date($until)) {
            $errors[] = "Zeile $n: ungültiges Datum „{$parts[1]}“ (JJJJ-MM-TT oder „unbefristet“).";
            continue;
        }
        if (isset($out[$name])) {
            $errors[] = "Zeile $n: {$name} steht mehrfach in der Liste.";
            continue;
        }
        $out[$name] = $until;
    }
    return $any ? $out : null;
}

function wartung_date(string $s): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function wartung_name(string $repo): string
{
    return strtolower(str_contains($repo, '/') ? substr($repo, strrpos($repo, '/') + 1) : $repo);
}

/** @param array<string, string> $list */
function wartung_active(array $list, string $repo, string $today): bool
{
    $until = $list[wartung_name($repo)] ?? null;
    return $until !== null && ($until === UNBEFRISTET || strcmp($today, $until) <= 0);
}

$opts = getopt('', ['filter', 'complete', 'until:', 'check', 'today:']);
$errors = [];
$list = wartung_parse((string) getenv('WARTUNG'), $errors);
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "::error::Variable KOMPANIE_WARTUNG: $e\n");
    }
    exit(1);
}

if (isset($opts['check'])) {
    fwrite(STDERR, $list === null ? "Variable KOMPANIE_WARTUNG ist leer.\n" : count($list) . " Kompanien in KOMPANIE_WARTUNG.\n");
    exit(0);
}

if (isset($opts['until'])) {
    if ($list !== null) {
        echo $list[wartung_name((string) $opts['until'])] ?? '';
    }
    exit(0);
}

if (!isset($opts['filter'])) {
    fwrite(STDERR, "Aufruf: php scripts/wartung.php --filter|--until=Repo|--check [--today=JJJJ-MM-TT]\n");
    exit(2);
}

$today = (string) ($opts['today'] ?? gmdate('Y-m-d'));
if (!wartung_date($today)) {
    fwrite(STDERR, "::error::Ungültiges Datum für --today: $today\n");
    exit(2);
}
$repos = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) stream_get_contents(STDIN)) ?: []), fn ($r) => $r !== ''));

if ($list === null) {
    fwrite(STDERR, "::warning::Variable KOMPANIE_WARTUNG fehlt oder ist leer: Alle Kompanien bekommen Updates (wie vor dem Wartungsabo).\n");
    echo $repos === [] ? '' : implode("\n", $repos) . "\n";
    exit(0);
}

$seen = [];
foreach ($repos as $repo) {
    $seen[wartung_name($repo)] = true;
    if (wartung_active($list, $repo, $today)) {
        echo $repo, "\n";
    } else {
        $until = $list[wartung_name($repo)] ?? null;
        fwrite(STDERR, $until === null ? "Übersprungen (kein Wartungsabo): $repo\n" : "Übersprungen (Wartung endete am $until): $repo\n");
    }
}
foreach (array_keys($list) as $name) {
    if (isset($opts['complete']) && !isset($seen[$name])) {
        fwrite(STDERR, "::warning::KOMPANIE_WARTUNG nennt $name, das ist aber kein Kompanie-Repo (öffentlich, Topic kompanie-app, nicht archiviert).\n");
    }
}
