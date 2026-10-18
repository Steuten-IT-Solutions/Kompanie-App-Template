<?php

declare(strict_types=1);

namespace KompanieApp\Contract;

use KompanieApp\Core\Port\HttpClient;

/**
 * Nur für Tests: simuliert Google Drive, einen Kalender (https://kalender.test/test.ics)
 * und einen News-Feed (https://news.test/feed), damit E2E-Tests ohne Netz laufen.
 * Inhalte wie in der WordPress-Testinstanz (scripts/setup-test-wp.sh, tests/wordpress/drive-mock.php).
 */
final class MockHttp implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): array
    {
        $ok = static fn (string|array $b, string $type = 'application/json'): array => ['status' => 200, 'headers' => ['content-type' => $type], 'body' => is_string($b) ? $b : (string) json_encode($b)];
        if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
            return $ok(['access_token' => 'TEST', 'expires_in' => 3600]);
        }
        if (str_starts_with($url, 'https://kalender.test/')) {
            return $ok(self::ics(), 'text/calendar');
        }
        if (str_starts_with($url, 'https://news.test/')) {
            return $ok(self::rss(), 'application/rss+xml');
        }
        if (!str_starts_with($url, 'https://www.googleapis.com/drive/v3/files')) {
            return ['status' => 404, 'headers' => [], 'body' => ''];
        }
        if (preg_match('#/files/([^/?]+)\?alt=media#', $url, $m)) {
            $colors = ['IMG1' => [47, 93, 138], 'IMG2' => [217, 164, 65], 'IMG3' => [46, 107, 63], 'IMG4' => [120, 60, 120]];
            $c = $colors[$m[1]] ?? [120, 120, 120];
            $im = imagecreatetruecolor(1200, 800);
            imagefill($im, 0, 0, (int) imagecolorallocate($im, $c[0], $c[1], $c[2]));
            ob_start();
            imagejpeg($im, null, 80);
            return $ok((string) ob_get_clean(), 'image/jpeg');
        }
        $folder = 'application/vnd.google-apps.folder';
        $y = gmdate('Y');
        $tree = [
            'TESTROOT' => [['id' => 'Y', 'name' => $y, 'mimeType' => $folder]],
            'Y' => [
                ['id' => 'A1', 'name' => 'Sommerfest', 'mimeType' => $folder, 'createdTime' => $y . '-06-28T00:00:00Z'],
                ['id' => 'A2', 'name' => 'Vereinsabend', 'mimeType' => $folder, 'createdTime' => $y . '-03-01T00:00:00Z'],
            ],
            'A1' => [
                ['id' => 'IMG1', 'name' => '1.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '1'],
                ['id' => 'IMG2', 'name' => '2.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '2'],
                ['id' => 'I1', 'name' => 'intern', 'mimeType' => $folder],
            ],
            'I1' => [['id' => 'IMG3', 'name' => '3.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '3']],
            'A2' => [['id' => 'I2', 'name' => 'intern', 'mimeType' => $folder]],
            'I2' => [['id' => 'IMG4', 'name' => '4.jpg', 'mimeType' => 'image/jpeg', 'md5Checksum' => '4']],
        ];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        preg_match("/'([^']+)' in parents/", (string) ($q['q'] ?? ''), $m);
        return $ok(['files' => isset($m[1], $tree[$m[1]]) ? $tree[$m[1]] : []]);
    }

    private static function ics(): string
    {
        $n = (int) gmdate('Y') + 1;
        $in7 = date('Ymd', time() + 7 * 86400);
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:e1\r\nDTSTART;VALUE=DATE:{$n}0508\r\nDTEND;VALUE=DATE:{$n}0509\r\nSUMMARY:Frühlingsfest\r\nLOCATION:Marktplatz\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:e2\r\nDTSTART;TZID=Europe/Berlin:{$in7}T190000\r\nDTEND;TZID=Europe/Berlin:{$in7}T230000\r\nSUMMARY:Vereinsabend\r\nLOCATION:Vereinslokal\r\nDESCRIPTION:Bitte pünktlich sein!\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:e3\r\nDTSTART;VALUE=DATE:{$n}0625\r\nDTEND;VALUE=DATE:{$n}0629\r\nSUMMARY:Sommerfest\r\nLOCATION:Festplatz\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    private static function rss(): string
    {
        $d1 = gmdate('D, d M Y H:i:s O', time() - 86400);
        $d2 = gmdate('D, d M Y H:i:s O', time() - 5 * 86400);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel><title>Musterkompanie</title><link>https://news.test/</link>
<item><title>Rückblick auf das Frühlingsfest</title><link>https://news.test/rueckblick</link><pubDate>$d1</pubDate>
<description>Ein schöner Tag mit vielen Gästen.</description>
<content:encoded><![CDATA[<p>Ein <strong>schöner</strong> Tag mit vielen Gästen.</p><script>alert(1)</script>]]></content:encoded></item>
<item><title>Neue Termine &amp; Treffpunkte</title><link>https://news.test/termine</link><pubDate>$d2</pubDate>
<description>Alle Termine für das neue Jahr.</description></item>
</channel></rss>
XML;
    }
}
