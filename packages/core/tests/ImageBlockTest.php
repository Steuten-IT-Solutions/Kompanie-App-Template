<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Website\BlockHtml;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Block „Bild“ (image): eigenes Bild über den Bildspeicher der App, gehört danach dem Inhalt, sichtbar wie der Block,
 * Beschreibung und Bildunterschrift je Sprache, aufgeräumt, wenn der Block wegfällt; Webseite und E-Mail.
 */
final class ImageBlockTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1, ?string $locale = null): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body, [], '', null, $locale));
        $this->users->current = $this->admin;
        I18n::setLocale(I18n::SOURCE_LOCALE);
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?int $as = -1, ?string $locale = null): array
    {
        $res = $this->call($method, $path, $body, $as, $locale);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    private function upload(?int $as = -1): string
    {
        $png = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        return $this->ok('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]], $as)['uid'];
    }

    private function delivery(string $uid, ?int $user): int
    {
        return $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $uid)['status'];
    }

    public function testImageFollowsTheBlock(): void
    {
        $pub = $this->upload();
        $intern = $this->upload();
        $page = $this->ok('POST', '/pages', ['title' => 'Bilder', 'audience' => 'public', 'blocks' => [
            ['id' => 'i1', 'type' => 'image', 'image' => $pub, 'alt' => ' Gruppenfoto ', 'caption' => 'Fest 2027', 'size' => 'medium'],
            ['id' => 'i2', 'type' => 'image', 'audience' => 'members', 'image' => $intern],
        ]]);
        $block = $page['blocks'][0];
        $this->assertSame(['image', $pub, 'Gruppenfoto', 'Fest 2027', 'medium', 40, 30], [$block['type'], $block['image'], $block['alt'], $block['caption'], $block['size'], $block['width'], $block['height']]);
        $this->assertSame($this->app->env->richImageUrl($pub), $block['url']);
        $this->assertSame(['full', ''], [$page['blocks'][1]['size'], $page['blocks'][1]['caption']], 'Vorgaben');
        $this->assertSame('page', $this->app->richImages()->repository()->find($pub)['owner_type'], 'gehört der Seite');

        // Sichtbar wie der Block
        $this->assertSame(200, $this->delivery($pub, null));
        $this->assertSame(401, $this->delivery($intern, null), 'Bild im Mitglieder-Block: ohne Anmeldung nicht');
        $this->assertSame(403, $this->delivery($intern, $this->guest));
        $this->assertSame(200, $this->delivery($intern, $this->member));
        $this->assertSame(['i1'], array_column($this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'], 'id'));

        // Block entfernt: Bild gelöscht; das andere bleibt
        $this->ok('PATCH', "/pages/{$page['id']}", ['blocks' => [['id' => 'i1', 'type' => 'image', 'image' => $pub]]]);
        $this->assertNull($this->app->richImages()->repository()->find($intern));
        $this->assertNotNull($this->app->richImages()->repository()->find($pub));

        // Dasselbe Bild in einer anderen Seite: kopiert, beide unabhängig
        $other = $this->ok('POST', '/pages', ['title' => 'Kopie', 'audience' => 'public', 'blocks' => [['type' => 'image', 'image' => $pub]]]);
        $copy = $other['blocks'][0]['image'];
        $this->assertNotSame($pub, $copy);
        $this->ok('DELETE', "/pages/{$page['id']}");
        $this->assertNull($this->app->richImages()->repository()->find($pub));
        $this->assertSame(200, $this->delivery($copy, null));

        // Gelöscht (z. B. von Hand): der Block fällt weg, die Pflege sieht ihn mit url null
        $this->app->richImages()->repository()->deleteUid($copy);
        $this->assertSame([], $this->ok('GET', "/pages/{$other['id']}", [], null)['blocks']);
        $this->assertNull($this->ok('GET', "/pages/{$other['id']}")['blocks'][0]['url']);
    }

    public function testValidation(): void
    {
        $uid = $this->upload();
        foreach ([[], ['image' => 'abc'], ['image' => str_repeat('0', 32)], ['image' => $uid, 'size' => 'huge'], ['image' => $uid, 'alt' => str_repeat('x', 201)], ['image' => $uid, 'caption' => str_repeat('x', 201)]] as $bad) {
            $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'image'] + $bad]])->status, json_encode($bad));
        }
        // Entwurf eines anderen Kontos, das es nicht sehen darf: nicht übernommen
        $foreign = $this->upload($this->member);
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'image', 'image' => $foreign]]])->status);
    }

    public function testAltAndCaptionPerLanguage(): void
    {
        $uid = $this->upload();
        $this->ok('PATCH', '/admin/settings', ['languages' => 'de,en']);
        $page = $this->ok('POST', '/pages', ['title' => 'Fest', 'audience' => 'public', 'blocks' => [
            ['id' => 'i1', 'type' => 'image', 'image' => $uid, 'alt' => 'Gruppenfoto', 'caption' => 'Unser Fest'],
        ]]);
        $this->ok('PATCH', "/pages/{$page['id']}", ['translations' => ['en' => ['title' => 'Fair', 'blocks' => ['i1' => ['caption' => 'Our fair', 'image' => str_repeat('0', 32), 'size' => 'small']]]]]);
        $en = $this->ok('GET', "/pages/{$page['id']}", [], null, 'en')['blocks'][0];
        $this->assertSame(['Our fair', 'Gruppenfoto', $uid, 'full'], [$en['caption'], $en['alt'], $en['image'], $en['size']], 'Bild und Größe gelten für alle Sprachen; fehlt die Beschreibung, gilt die deutsche');
        $this->assertSame('some', $this->ok('GET', "/pages/{$page['id']}", [], null, 'en')['untranslated']);
    }

    public function testNewsKeepsImageAndMailEmbedsIt(): void
    {
        $uid = $this->upload();
        $blocks = [['type' => 'image', 'image' => $uid, 'alt' => 'Foto', 'caption' => 'Fest & Feier']];
        $html = $this->app->blocks()->html($blocks, true);
        $this->assertSame('<p><img data-image="' . $uid . '" alt="Foto" width="40"></p><p><em>Fest &amp; Feier</em></p>', $html);
        $this->assertSame('Fest & Feier', $this->app->blocks()->plain($blocks, true));

        $site = (new BlockHtml($this->app))->block(['type' => 'image', 'url' => 'https://example.org/bild/x', 'alt' => 'Foto', 'caption' => 'Fest', 'size' => 'small', 'width' => 40, 'height' => 30]);
        $this->assertSame('<figure class="block-image block-image-small"><img src="https://example.org/bild/x" alt="Foto" loading="lazy" width="40" height="30"><figcaption>Fest</figcaption></figure>', $site);
        $this->assertSame('', (new BlockHtml($this->app))->block(['type' => 'image', 'url' => null]));
    }
}
