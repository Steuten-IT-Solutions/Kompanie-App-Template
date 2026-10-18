<?php
/**
 * Erzeugt die Download-Seite einer Kompanie (statisch, für GitHub Pages):
 *   php scripts/download-page.php --updates=updates.json --out=site [--screenshots=ordner] [--brand=ordner]
 *
 * Branding (brand.json, theme.css, logo.png, favicon.png) aus --brand, sonst aus dem zuletzt
 * gebauten Stand (packages/frontend/src/generated) bzw. aus site/brand/ einer früheren Seite.
 * Die Dateien werden unter site/brand/ abgelegt, damit spätere Änderungen (Freigeben,
 * Zurückziehen) die Seite ohne neuen Build erzeugen können.
 * Verlinkt die Pakete der Version im Kanal stable. updates.json wird mit TrustedKeys bzw. dem
 * Schlüssel aus KOMPANIE_SIGNING_KEY geprüft. Ohne --updates entsteht eine Vorschau ohne Download.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/adapters/standalone/autoload.php';

use KompanieApp\Core\Update\SignedEnvelope;
use KompanieApp\Core\Update\TrustedKeys;
use KompanieApp\Core\Update\UpdateList;

$opt = getopt('', ['updates::', 'out:', 'screenshots::', 'brand::']);
$out = rtrim((string) ($opt['out'] ?? ''), '/');
if ($out === '') {
    fwrite(STDERR, "Aufruf: php scripts/download-page.php --updates=updates.json --out=ordner\n");
    exit(1);
}
$brandDir = (string) ($opt['brand'] ?? '');
if ($brandDir === '') {
    $brandDir = is_file($out . '/brand/brand.json') ? $out . '/brand' : '';
}
if ($brandDir === '') {
    $gen = $root . '/packages/frontend/src/generated';
    $brandDir = sys_get_temp_dir() . '/kompanie-brand-' . bin2hex(random_bytes(4));
    @mkdir($brandDir, 0775, true);
    foreach (['brand.json' => "$gen/brand.json", 'theme.css' => "$gen/theme.css", 'logo.png' => "$gen/logo.png", 'favicon.png' => "$root/packages/frontend/public/icons/favicon-32.png"] as $n => $src) {
        copy($src, "$brandDir/$n");
    }
}
$brand = json_decode((string) file_get_contents($brandDir . '/brand.json'), true);
$theme = (string) file_get_contents($brandDir . '/theme.css');
// theme.css stammt aus dem Build (dort laufen npm und die Daten der Kompanie) und landet direkt in der
// Seite: nur genau den Aufbau zulassen, den das Branding erzeugt (Farbwerte, Schriftliste), nichts sonst.
foreach (explode("\n", $theme) as $line) {
    if (!preg_match('~^\s*(|/\*[^*<>]*\*/|:root \{|@media \(prefers-color-scheme: dark\) \{|\}|color-scheme: (light|dark);|--[a-z0-9-]{1,40}: (#[0-9a-fA-F]{3,8}|[A-Za-z0-9 ,\'-]{1,200});)\s*$~', $line)) {
        fwrite(STDERR, "Fehler: theme.css enthält Unerwartetes: " . substr($line, 0, 80) . "\n");
        exit(1);
    }
}
if (!is_array($brand) || !is_string($brand['name'] ?? null)) {
    fwrite(STDERR, "Fehler: brand.json ist ungültig.\n");
    exit(1);
}
@mkdir($out . '/screenshots', 0775, true);
@mkdir($out . '/brand', 0775, true);
foreach (['brand.json', 'theme.css', 'logo.png', 'favicon.png'] as $n) {
    if (realpath("$brandDir/$n") !== realpath("$out/brand/$n")) {
        copy("$brandDir/$n", "$out/brand/$n");
    }
}
copy($brandDir . '/logo.png', $out . '/logo.png');
copy($brandDir . '/favicon.png', $out . '/favicon.png');

$stable = null;
if (!empty($opt['updates'])) {
    $trusted = TrustedKeys::all();
    $key = (string) getenv('KOMPANIE_SIGNING_KEY');
    if ($key !== '') {
        [, $public] = SignedEnvelope::keyPairFromSecret($key);
        $trusted[SignedEnvelope::keyId($public)] = base64_encode($public);
    }
    $list = UpdateList::fromPayload(SignedEnvelope::open((string) file_get_contents((string) $opt['updates']), $trusted));
    $stable = $list->channel('stable') !== null ? $list->entry((string) $list->channel('stable')) : null;
}

// Bildschirmfotos: neue aus --screenshots, sonst die schon auf der Seite vorhandenen.
$shots = [];
foreach (['0-start-gast', '1-termine', '1-termine-dunkel', '3-news', '4-galerie', '2-punkte'] as $name) {
    $src = empty($opt['screenshots']) ? '' : $opt['screenshots'] . "/$name.png";
    if ($src !== '' && is_file($src)) {
        copy($src, "$out/screenshots/$name.png");
    }
    if (is_file("$out/screenshots/$name.png")) {
        $shots[] = "screenshots/$name.png";
    }
}

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$name = $h((string) $brand['name']);
$installUrl = $stable ? $h(dirname($stable['files']['standalone']['url']) . '/install.php') : '';
$pluginUrl = $stable ? $h($stable['files']['wordpress']['url']) : '';
$version = $stable ? $h($stable['version']) . ' vom ' . $h(date('d.m.Y', (int) strtotime($stable['published']))) : '';

$button = static fn (string $url, string $label): string => $url !== ''
    ? "<a class=\"btn\" href=\"$url\" download>$label</a>"
    : '<p class="note">Die erste Version wird gerade geprüft. Bitte später noch einmal vorbeischauen.</p>';

$gallery = '';
if ($shots) {
    $gallery = '<section class="shots" aria-label="So sieht die App aus">' . implode('', array_map(
        static fn (string $s): string => '<img src="' . $h($s) . '" alt="Bildschirmfoto der App" loading="lazy" width="390" height="844">',
        $shots,
    )) . '</section>';
}

$html = <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Die App der {$name}</title>
<link rel="icon" type="image/png" href="favicon.png">
<style>
{$theme}
* { box-sizing: border-box; }
body { margin: 0; font: 17px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: var(--bg); color: var(--ink); }
header { background: linear-gradient(180deg, var(--primary), var(--primary-deep)); color: var(--on-primary); border-bottom: 4px solid var(--accent); }
.wrap { max-width: 920px; margin: 0 auto; padding: 0 16px; }
header .wrap { display: flex; gap: 18px; align-items: center; padding-top: 28px; padding-bottom: 28px; }
header img { width: 84px; height: 84px; object-fit: contain; flex: none; }
h1 { margin: 0; font-family: var(--font-head); font-size: 1.9rem; line-height: 1.15; }
header p { margin: 4px 0 0; color: var(--on-primary-soft); }
main { padding: 28px 0 40px; }
.cards { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
.card h2 { margin: 0 0 6px; font-family: var(--font-head); color: var(--primary-text); font-size: 1.3rem; }
.card ol { padding-left: 1.3em; margin: 12px 0 18px; }
.card li { margin: 6px 0; }
.btn { display: inline-block; background: var(--primary); color: var(--on-primary); text-decoration: none; font-weight: 700; padding: 13px 20px; border-radius: 10px; }
.btn:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
.muted, .note { color: var(--muted); font-size: 0.92rem; }
code { background: var(--line); padding: 1px 6px; border-radius: 5px; font-size: 0.9em; overflow-wrap: anywhere; }
.shots { display: flex; gap: 14px; overflow-x: auto; padding: 22px 0 6px; }
.shots img { height: 420px; width: auto; border-radius: 18px; border: 1px solid var(--line); flex: none; }
footer { color: var(--muted); font-size: 0.85rem; padding-bottom: 32px; }
</style>
</head>
<body>
<header><div class="wrap">
  <img src="logo.png" alt="">
  <div><h1>Die App der {$name}</h1><p>Termine, Neuigkeiten, Bilder und mehr – auf dem Handy, mit unserem Logo.</p></div>
</div></header>
<main class="wrap">
  <div class="cards">
    <section class="card">
      <h2>Wir haben eine WordPress-Webseite</h2>
      <p class="muted">Die App wird ein Teil der Webseite. Benutzer, Anmeldung und Neuigkeiten kommen aus WordPress.</p>
      <ol>
        <li>Datei herunterladen (nicht entpacken).</li>
        <li>In WordPress unter <em>Plugins → Neues Plugin hinzufügen → Plugin hochladen</em> die Datei auswählen und <em>Jetzt installieren</em> klicken.</li>
        <li><em>Aktivieren</em> klicken. Die App ist dann unter <code>eure-webseite.de/app/</code> erreichbar.</li>
      </ol>
      {$button($pluginUrl, 'Plugin herunterladen')}
    </section>
    <section class="card">
      <h2>Wir haben keine WordPress-Webseite</h2>
      <p class="muted">Ihr braucht nur Webspace mit PHP – den hat fast jedes Hosting-Paket. Eine Datenbank müsst ihr nicht anlegen.</p>
      <ol>
        <li>Datei <code>install.php</code> herunterladen.</li>
        <li>Im Dateimanager eures Hosters (oder per FTP) einen Ordner <code>app</code> anlegen und die Datei hineinladen.</li>
        <li><code>eure-webseite.de/app/install.php</code> im Browser öffnen. Der Installer erledigt den Rest und führt durch die Einrichtung.</li>
      </ol>
      {$button($installUrl, 'install.php herunterladen')}
    </section>
  </div>
  {$gallery}
  <p class="muted">Updates kommen danach automatisch. {$version}</p>
</main>
<footer class="wrap">Diese Seite wurde automatisch erstellt.</footer>
</body>
</html>
HTML;
file_put_contents($out . '/index.html', $html);
echo "Download-Seite: $out/index.html" . ($stable ? " (Version {$stable['version']})" : ' (noch ohne Download)') . "\n";
