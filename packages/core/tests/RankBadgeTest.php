<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RankService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Infra\GdImageResizer;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Abzeichen der Dienstgrade (Issue #17): Hochladen, Ersetzen, Entfernen, Prüfungen, Transparenz, Auslieferung, badge_url. */
final class RankBadgeTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><script>alert(1)</script><rect width="20" height="10" fill="#fc0"/></svg>';

    private FakeUsers $users;
    private App $app;
    private string $dir;
    private int $admin;
    private int $m;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = []): void
    {
        $this->users = new FakeUsers();
        $this->dir = sys_get_temp_dir() . '/kompanie-badge-' . bin2hex(random_bytes(4));
        $all = ['events' => false, 'calendar' => true, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** Bild mit transparentem Hintergrund und einem roten Rechteck in der Mitte. */
    private static function image(int $w, int $h, string $format = 'png'): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, (int) ($w / 4), (int) ($h / 4), (int) ($w * 3 / 4), (int) ($h * 3 / 4), (int) imagecolorallocate($im, 220, 20, 20));
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($im),
            'webp' => imagewebp($im),
            default => imagepng($im),
        };
        return (string) ob_get_clean();
    }

    /** @return array{name: string, data: string} */
    private static function file(string $name, string $bytes): array
    {
        return ['name' => $name, 'data' => base64_encode($bytes)];
    }

    private function rank(string $title, ?int $min = null): int
    {
        $res = $this->call('POST', '/ranks', ['title' => $title, 'min_points' => $min]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data['id'];
    }

    /** @return array<string, mixed> */
    private function upload(int $id, string $name, string $bytes, ?string $png = null): array
    {
        $res = $this->call('POST', "/ranks/$id/badge", ['file' => self::file($name, $bytes)] + ($png === null ? [] : ['png' => base64_encode($png)]));
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @return list<string> Dateinamen in abzeichen/ */
    private function files(): array
    {
        return array_map('basename', glob($this->dir . '/private/abzeichen/*') ?: []);
    }

    private function badgeFile(int $id, string $suffix): string
    {
        $rank = $this->app->ranks()->repository()->find($id);
        $this->assertNotNull($rank);
        return $this->dir . '/private/abzeichen/' . $rank['badge_file'] . $suffix;
    }

    public function testPngUploadCreatesTransparentVariantsWithoutUpscaling(): void
    {
        $this->setUpApp();
        $id = $this->rank('Gefreiter');
        $r = $this->upload($id, 'Gefreiter.PNG', self::image(600, 300));
        $this->assertSame(['png', true], [$r['badge_type'], $r['badge_push']]);
        $this->assertMatchesRegularExpression('#^https://example\.org/app/abzeichen/' . $id . '\?v=[0-9a-f]{16}&s=128$#', (string) $r['badge_url']);
        $this->assertCount(3, $this->files(), 'drei PNG-Fassungen, das Original wird nicht aufgehoben');
        foreach ([256 => [256, 128], 128 => [128, 64], 64 => [64, 32]] as $size => $dims) {
            $info = getimagesize($this->badgeFile($id, "-$size.png"));
            $this->assertNotFalse($info);
            $this->assertSame([$dims[0], $dims[1], 'image/png'], [$info[0], $info[1], $info['mime']], "$size px: längere Kante, Seitenverhältnis");
        }
        // Transparenz bleibt erhalten: Ecke durchsichtig, Mitte rot und deckend.
        $im = imagecreatefrompng($this->badgeFile($id, '-256.png'));
        $this->assertNotFalse($im);
        $corner = imagecolorsforindex($im, imagecolorat($im, 2, 2));
        $center = imagecolorsforindex($im, imagecolorat($im, 128, 64));
        $this->assertSame(127, $corner['alpha'], 'Hintergrund bleibt transparent');
        $this->assertSame([0, 220], [$center['alpha'], $center['red']]);

        // Kleine Bilder werden nie vergrößert.
        $small = $this->rank('Obergefreiter');
        $this->upload($small, 'klein.png', self::image(40, 20));
        foreach ([64, 128, 256] as $size) {
            $this->assertSame([40, 20], array_slice((array) getimagesize($this->badgeFile($small, "-$size.png")), 0, 2), "$size px: nicht vergrößert");
        }
    }

    public function testGdResizerKeepsAlphaOfPalettePng(): void
    {
        $im = imagecreate(100, 100);
        $bg = imagecolorallocate($im, 255, 255, 255);
        imagecolortransparent($im, (int) $bg);
        imagefilledrectangle($im, 40, 40, 60, 60, (int) imagecolorallocate($im, 0, 0, 200));
        $src = tempnam(sys_get_temp_dir(), 'kp') . '.png';
        $dst = tempnam(sys_get_temp_dir(), 'kp') . '.png';
        imagepng($im, $src);
        $this->assertSame(['width' => 50, 'height' => 50], (new GdImageResizer())->resizeToPng($src, $dst, 50));
        $out = imagecreatefrompng($dst);
        $this->assertNotFalse($out);
        $this->assertSame(127, imagecolorsforindex($out, imagecolorat($out, 1, 1))['alpha'], 'PNG-8 mit transparenter Farbe bleibt transparent');
        @unlink($src);
        @unlink($dst);
    }

    public function testJpgAndWebpAndChecks(): void
    {
        $this->setUpApp();
        $id = $this->rank('Gefreiter');
        $this->assertSame('jpg', $this->upload($id, 'foto.jpeg', self::image(300, 200, 'jpg'))['badge_type']);
        $this->assertSame('webp', $this->upload($id, 'bild.webp', self::image(300, 200, 'webp'))['badge_type']);
        $this->assertCount(3, $this->files(), 'Ersetzen löscht die alten Fassungen');

        $bad = fn (array $file, mixed $png = null): int => $this->call('POST', "/ranks/$id/badge", ['file' => $file] + ($png === null ? [] : ['png' => $png]))->status;
        $this->assertSame(400, $bad(self::file('a.gif', 'GIF89a')), 'Endung');
        $this->assertSame(400, $bad(self::file('a.jpg', self::image(10, 10))), 'PNG als .jpg: Signatur passt nicht');
        $this->assertSame(400, $bad(self::file('a.webp', 'RIFF0000WAVEfmt ')), 'RIFF, aber kein WebP');
        $this->assertSame(400, $bad(self::file('a.png', "\x89PNG\r\n\x1A\nkaputt")), 'kaputtes PNG');
        $this->assertSame(400, $bad(self::file('a.png', self::image(5000, 10))), 'mehr als 4096 Pixel');
        $this->assertSame(400, $bad(self::file('a.png', self::image(10, 10) . str_repeat("\0", RankService::BADGE_MAX_BYTES))), 'über 1 MB');
        $this->assertSame(400, $bad(['name' => 'a.png', 'data' => 'kein base64!!']));
        $this->assertSame(400, $bad([]), 'ohne Datei');
        $this->assertSame(400, $bad(self::file('a.svg', self::SVG), base64_encode('kein png')), 'SVG mit ungültiger PNG-Fassung');
        $this->assertSame(404, $this->call('POST', '/ranks/999/badge', ['file' => self::file('a.png', self::image(10, 10))])->status);
        $this->assertCount(3, $this->files(), 'abgelehnte Dateien werden nicht gespeichert, das bisherige Abzeichen bleibt');
        $this->assertSame('webp', $this->call('GET', '/ranks', [], ['all' => '1'])->data[0]['badge_type']);
    }

    public function testSvgIsSanitizedAndPngVariantOptional(): void
    {
        $this->setUpApp();
        $id = $this->rank('Unteroffizier');
        $r = $this->upload($id, 'uffz.svg', self::SVG);
        $this->assertSame(['svg', false], [$r['badge_type'], $r['badge_push']], 'ohne PNG-Fassung kein Bild für Benachrichtigungen');
        $stored = (string) file_get_contents($this->badgeFile($id, '.svg'));
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringContainsString('<rect', $stored);
        $this->assertNull($this->app->ranks()->badgePushImage($id));

        $r = $this->upload($id, 'uffz.svg', self::SVG, self::image(256, 128));
        $this->assertTrue($r['badge_push']);
        $this->assertCount(4, $this->files(), 'SVG und drei PNG-Fassungen; die vorige Fassung ist gelöscht');
        $this->assertSame([128, 64], array_slice((array) getimagesize($this->badgeFile($id, '-128.png')), 0, 2));
        $this->assertStringEndsWith('&s=256', (string) $this->app->ranks()->badgePushImage($id));
        $this->assertSame(400, $this->call('POST', "/ranks/$id/badge", ['file' => self::file('a.svg', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>')])->status, 'XXE');
    }

    public function testRemoveRightsAndDeactivatedRankKeepsBadge(): void
    {
        $this->setUpApp();
        $id = $this->rank('Gefreiter');
        $png = self::image(64, 64);
        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('POST', "/ranks/$id/badge", ['file' => self::file('a.png', $png)])->status, 'Mitglied lädt nicht hoch');
        $this->users->current = $this->admin;
        $first = $this->upload($id, 'a.png', $png);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $id])->status);

        // Eigene Rolle mit „Dienstgrade verwalten“ darf es auch.
        $this->users->current = $this->users->add('rang', 'ranks');
        $second = $this->upload($id, 'b.png', self::image(64, 32));
        $this->assertNotSame($first['badge_url'], $second['badge_url'], 'neue Fassung, neue Adresse');

        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('PATCH', "/ranks/$id", ['active' => false])->status);
        $this->assertSame($second['badge_url'], $this->call('GET', '/ranks', [], ['all' => '1'])->data[0]['badge_url'], 'deaktiviert: Abzeichen bleibt');
        $this->assertSame($second['badge_url'], $this->call('GET', "/members/{$this->m}")->data['rank']['rank']['badge_url']);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('DELETE', "/ranks/$id/badge")->status);
        $this->users->current = $this->admin;
        $res = $this->call('DELETE', "/ranks/$id/badge");
        $this->assertSame(200, $res->status);
        $this->assertNull($res->data['badge_url']);
        $this->assertNull($res->data['badge_type']);
        $this->assertSame([], $this->files(), 'Entfernen löscht die Dateien');
        $this->assertSame(200, $this->call('DELETE', "/ranks/$id/badge")->status, 'ohne Abzeichen: nichts zu tun');

        $other = $this->rank('Obergefreiter');
        $this->upload($other, 'c.png', $png);
        $this->assertCount(3, $this->files());
        $this->assertSame(200, $this->call('DELETE', "/ranks/$other")->status);
        $this->assertSame([], $this->files(), 'Löschen des Dienstgrads löscht sein Abzeichen');

        $this->app->features()->update(['ranks' => false]);
        $this->assertSame(403, $this->call('POST', "/ranks/$id/badge", ['file' => self::file('a.png', $png)])->status, 'Bereich aus');
    }

    public function testBadgeUrlWhereverARankAppears(): void
    {
        $this->setUpApp(['points' => true]);
        $g = $this->rank('Gefreiter', 10);
        $u = $this->rank('Unteroffizier', 50);
        $this->rank('Feldwebel', 100);
        $gUrl = $this->upload($g, 'g.png', self::image(64, 64))['badge_url'];
        $uUrl = $this->upload($u, 'u.svg', self::SVG)['badge_url'];
        $this->assertSame(201, $this->call('POST', '/points', ['user_ids' => [$this->m], 'points' => 60, 'reason' => 'Test', 'date' => '2026-10-01'])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/rank", ['rank_id' => $g])->status);

        $list = array_column($this->call('GET', '/ranks')->data, 'badge_url', 'title');
        $this->assertSame(['Gefreiter' => $gUrl, 'Unteroffizier' => $uUrl, 'Feldwebel' => null], $list);

        $members = array_column($this->call('GET', '/members')->data, null, 'id');
        $this->assertSame(['Gefreiter', $gUrl], [$members[$this->m]['rank_title'], $members[$this->m]['rank_badge_url']]);
        $this->assertNull($members[$this->admin]['rank_badge_url']);

        $promo = array_column($this->call('GET', '/promotions')->data, null, 'user_id')[$this->m];
        $this->assertSame([$gUrl, $uUrl, $uUrl], [$promo['rank']['badge_url'], $promo['next_rank']['badge_url'], $promo['reachable_rank']['badge_url']]);

        $this->users->current = $this->m;
        $me = $this->call('GET', '/me/rank')->data;
        $this->assertSame([$gUrl, $uUrl, $uUrl], [$me['rank']['badge_url'], $me['next_rank']['badge_url'], $me['reachable_rank']['badge_url']]);
        $this->assertSame($gUrl, $me['history'][0]['to_badge_url']);
        $this->assertSame($gUrl, $this->call('GET', '/me')->data['rank']['badge_url']);
        $this->assertArrayNotHasKey('badge_type', $me['ranks'][0], 'Format nur in der Pflege');

        // Datei fehlt (z. B. Sicherung auf einem anderen Server eingespielt): kein kaputtes Bild, sondern keins.
        @unlink($this->badgeFile($g, '-128.png'));
        $this->assertNull($this->call('GET', '/me/rank')->data['rank']['badge_url']);
    }

    public function testDeliveryRights(): void
    {
        $this->setUpApp();
        $svgRank = $this->rank('Unteroffizier');
        $pngRank = $this->rank('Gefreiter');
        $none = $this->rank('Feldwebel');
        $svg = $this->upload($svgRank, 'u.svg', self::SVG);
        $this->upload($pngRank, 'g.png', self::image(300, 300));
        parse_str((string) parse_url((string) $svg['badge_url'], PHP_URL_QUERY), $q);
        $v = (string) $q['v'];
        $ranks = $this->app->ranks();
        $member = $this->users->find($this->m);
        $candidate = $this->users->find($this->users->add('anwaerter', Roles::CANDIDATE));
        $guest = $this->users->find($this->users->add('gast', Roles::GUEST));

        $res = $ranks->badgeForDelivery($member, $svgRank, $v, '128');
        $this->assertSame(200, $res['status']);
        $this->assertSame('image/svg+xml', $res['headers']['Content-Type']);
        $this->assertSame('nosniff', $res['headers']['X-Content-Type-Options']);
        $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $res['headers']['Content-Security-Policy']);
        $this->assertSame('private, max-age=31536000, immutable', $res['headers']['Cache-Control']);
        $this->assertStringNotContainsString('script', (string) file_get_contents((string) ($res['path'] ?? '')));
        $this->assertSame($res['path'], $ranks->badgeForDelivery($member, $svgRank, $v, '256')['path'] ?? null, 'SVG: dieselbe Datei für alle Größen');
        $this->assertSame('private, no-cache', $ranks->badgeForDelivery($member, $svgRank, 'alt', '128')['headers']['Cache-Control'], 'veraltete Fassung nicht lange speichern');

        $png = $ranks->badgeForDelivery($member, $pngRank, null, '64');
        $this->assertSame([200, 'image/png'], [$png['status'], $png['headers']['Content-Type']]);
        $this->assertArrayNotHasKey('Content-Security-Policy', $png['headers']);
        $this->assertSame([64, 64], array_slice((array) getimagesize((string) ($png['path'] ?? '')), 0, 2), 's wählt die Größe');
        $this->assertSame([128, 128], array_slice((array) getimagesize((string) ($ranks->badgeForDelivery($member, $pngRank, null, null)['path'] ?? '')), 0, 2), 'ohne s: 128');
        $this->assertSame(404, $ranks->badgeForDelivery($member, $pngRank, null, '999')['status'], 'unbekannte Größe');

        $this->assertSame(200, $ranks->badgeForDelivery($candidate, $svgRank, $v, '128')['status'], 'Anwärter sehen Abzeichen');
        $this->assertSame(401, $ranks->badgeForDelivery(null, $svgRank, $v, '128')['status'], 'nicht angemeldet');
        $denied = $ranks->badgeForDelivery($guest, $svgRank, $v, '128');
        $this->assertSame([403, 'no-store'], [$denied['status'], $denied['headers']['Cache-Control']], 'ohne Mitgliederbereich und Kalender');
        $this->assertSame(404, $ranks->badgeForDelivery($member, $none, '', '128')['status'], 'ohne Abzeichen');
        $this->assertSame(404, $ranks->badgeForDelivery($member, 999, '', '128')['status'], 'unbekannter Dienstgrad');

        // Veränderter Dateiname (z. B. aus einer Sicherung): nie anfassen.
        $this->app->db->execute('UPDATE {ranks} SET badge_file = ? WHERE id = ?', ['../../config', $pngRank]);
        $this->assertSame(404, $ranks->badgeForDelivery($member, $pngRank, null, '128')['status']);

        $this->app->maintenance()->set(true, 'Umstellung', $this->users->find($this->admin) ?? throw new \LogicException());
        $this->assertSame(503, $ranks->badgeForDelivery($member, $svgRank, $v, '128')['status'], 'Wartungsmodus');
        $this->assertSame(200, $ranks->badgeForDelivery($this->users->find($this->admin), $svgRank, $v, '128')['status'], 'Admins arbeiten weiter');
        $this->app->maintenance()->set(false, null, $this->users->find($this->admin) ?? throw new \LogicException());

        $this->app->features()->update(['ranks' => false]);
        $this->assertSame(404, $ranks->badgeForDelivery($member, $svgRank, $v, '128')['status'], 'Bereich aus');
    }
}
