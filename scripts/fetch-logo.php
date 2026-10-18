<?php
/**
 * Lädt das Logo aus einem Issue-Anhang und legt es als assets/logo.<Typ> ab
 * (aus .github/workflows/kompanie-formular.yml):
 *
 *   php scripts/fetch-logo.php --url=https://github.com/user-attachments/assets/… --out=assets
 *
 * Nur Anhänge von GitHub (auch nach Weiterleitungen nur GitHub-Adressen), höchstens 5 MB.
 * Der Typ wird am Inhalt erkannt, nicht an der Adresse: PNG, JPEG, WebP oder SVG.
 */

declare(strict_types=1);

$opt = getopt('', ['url:', 'out:']);
$url = (string) ($opt['url'] ?? '');
$out = rtrim((string) ($opt['out'] ?? ''), '/');
$fail = static function (string $msg): never {
    fwrite(STDERR, "Fehler: $msg\n");
    exit(1);
};
$allowed = static fn (string $u): bool => (bool) preg_match('~^https://(github\.com/user-attachments/|([a-z0-9-]+\.)*githubusercontent\.com/)~', $u);
if ($out === '' || !$allowed($url)) {
    $fail('Das Logo muss ein Anhang auf GitHub sein.');
}
const MAX = 5 * 1048576;

$body = '';
for ($hop = 0; $hop < 4; $hop++) {
    $ch = curl_init($url);
    $data = '';
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'Kompanie-App-Formular',
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$data): int {
            $data .= $chunk;
            return strlen($data) > MAX ? 0 : strlen($chunk);
        },
    ]);
    // Private Anhänge (Repo nicht öffentlich) brauchen ein Token; öffentliche nicht.
    if (getenv('GH_TOKEN') && str_starts_with($url, 'https://github.com/')) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . getenv('GH_TOKEN')]);
    }
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    if (strlen($data) > MAX) {
        $fail('Das Logo ist größer als 5 MB.');
    }
    if ($ok === false) {
        $fail('Das Logo ließ sich nicht laden: ' . curl_error($ch));
    }
    if ($status >= 300 && $status < 400 && $next !== '') {
        if (!$allowed($next)) {
            $fail('Das Logo leitet auf eine fremde Adresse weiter.');
        }
        $url = $next;
        continue;
    }
    if ($status !== 200) {
        $fail("Das Logo ließ sich nicht laden (HTTP $status).");
    }
    $body = $data;
    break;
}
if ($body === '') {
    $fail('Das Logo ließ sich nicht laden (zu viele Weiterleitungen oder leer).');
}

$type = match (true) {
    str_starts_with($body, "\x89PNG\r\n\x1a\n") => 'png',
    str_starts_with($body, "\xFF\xD8\xFF") => 'jpg',
    strlen($body) > 12 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP' => 'webp',
    (bool) preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE svg[^>]*>\s*)?<svg[\s>]/is', substr($body, 0, 4096)) => 'svg',
    default => '',
};
if ($type === '') {
    $fail('Das Logo ist kein PNG, JPEG, WebP oder SVG.');
}
if ($type !== 'svg' && @getimagesizefromstring($body) === false) {
    $fail('Das Logo ist beschädigt.');
}
@mkdir($out, 0755, true);
foreach (glob("$out/logo.*") ?: [] as $old) {
    unlink($old);
}
file_put_contents("$out/logo.$type", $body);
echo "Logo gespeichert: $out/logo.$type (" . round(strlen($body) / 1024) . " KB)\n";
