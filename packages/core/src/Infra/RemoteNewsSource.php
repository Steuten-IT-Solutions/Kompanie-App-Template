<?php

declare(strict_types=1);

namespace KompanieApp\Core\Infra;

use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\Cache;
use KompanieApp\Core\Port\HttpClient;
use KompanieApp\Core\Port\NewsSource;
use KompanieApp\Core\Port\Settings;

/**
 * Neuigkeiten von einer vorhandenen Webseite der Kompanie:
 * - 'wordpress': Beiträge einer WordPress-Seite über /wp-json/wp/v2/posts,
 * - 'rss': ein RSS- oder Atom-Feed.
 * Eingestellt über news_source und news_url. Antworten werden 10 Minuten zwischengespeichert.
 * Den HTML-Inhalt bereinigt zusätzlich die App (DOMPurify).
 */
final class RemoteNewsSource implements NewsSource
{
    public const SOURCES = ['none', 'wordpress', 'rss'];
    private const CACHE_SECONDS = 600;
    private const MAX_FEED_ITEMS = 100;

    private bool $bypassCache = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClient $http,
        private readonly Cache $cache,
    ) {
    }

    public function type(): string
    {
        $t = (string) $this->settings->get('news_source', 'none');
        return in_array($t, self::SOURCES, true) && $this->url() !== '' ? $t : 'none';
    }

    public function url(): string
    {
        return trim((string) $this->settings->get('news_url', ''));
    }

    public function available(): bool
    {
        return $this->type() !== 'none';
    }

    public function page(int $page, int $perPage): array
    {
        if ($this->type() === 'wordpress') {
            $res = $this->fetch($this->wpUrl('posts', ['per_page' => $perPage, 'page' => max(1, $page), '_embed' => 'wp:featuredmedia']));
            if ($res['status'] === 400) {
                return ['items' => [], 'totalPages' => (int) ($res['headers']['x-wp-totalpages'] ?? 0)]; // Seite hinter dem Ende
            }
            $posts = $this->json($res);
            return [
                'items' => array_map([$this, 'wpItem'], array_values(array_filter($posts, 'is_array'))),
                'totalPages' => (int) ($res['headers']['x-wp-totalpages'] ?? 1),
            ];
        }
        if ($this->type() === 'rss') {
            $all = $this->feed();
            $items = array_map(static function (array $i): array {
                unset($i['content']);
                return $i;
            }, array_slice($all, ($page - 1) * $perPage, $perPage));
            return ['items' => $items, 'totalPages' => (int) ceil(count($all) / max(1, $perPage))];
        }
        return ['items' => [], 'totalPages' => 0];
    }

    public function item(string $id): ?array
    {
        if ($this->type() === 'wordpress') {
            if (!ctype_digit($id)) {
                return null;
            }
            $res = $this->fetch($this->wpUrl('posts/' . $id, ['_embed' => 'wp:featuredmedia']));
            if ($res['status'] === 404 || $res['status'] === 401 || $res['status'] === 403) {
                return null;
            }
            $post = $this->json($res);
            return $this->wpItem($post) + ['content' => (string) ($post['content']['rendered'] ?? '')];
        }
        if ($this->type() === 'rss') {
            foreach ($this->feed() as $i) {
                if ($i['id'] === $id) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Prüft die Einstellung ohne Zwischenspeicher, z. B. im Einrichtungsassistenten.
     *
     * @return int Anzahl der Beiträge auf der ersten Seite
     * @throws ApiError mit verständlicher Meldung
     */
    public function test(): int
    {
        $this->bypassCache = true;
        try {
            return count($this->page(1, 10)['items']);
        } finally {
            $this->bypassCache = false;
        }
    }

    /** @param array<string, int|string> $query */
    private function wpUrl(string $route, array $query): string
    {
        $base = rtrim($this->url(), '/');
        // Mit und ohne URL-Umschreibung erreichbar: ?rest_route= funktioniert immer.
        return $base . '/?rest_route=' . rawurlencode('/wp/v2/' . $route) . '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function fetch(string $url): array
    {
        $key = $this->cacheKey($url);
        $cached = $this->bypassCache ? null : $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $res = $this->http->request('GET', $url, ['Accept' => 'application/json, application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'], null, 10);
        } catch (\RuntimeException $e) {
            throw ApiError::upstream('Die Webseite mit den Neuigkeiten ist gerade nicht erreichbar: ' . $e->getMessage());
        }
        $res['headers'] = array_change_key_case($res['headers']);
        if ($res['status'] === 200 || $res['status'] === 400 || $res['status'] === 404) {
            $this->cache->set($key, $res, self::CACHE_SECONDS);
        }
        return $res;
    }

    private function cacheKey(string $url): string
    {
        return 'news_' . md5($url);
    }

    /** @return array<mixed> */
    private function json(array $res): array
    {
        $data = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($data)) {
            throw ApiError::upstream('Die Webseite lieferte keine Beiträge (HTTP ' . $res['status'] . '). Ist die Adresse richtig und die REST-API erreichbar?');
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function wpItem(array $p): array
    {
        $media = $p['_embedded']['wp:featuredmedia'][0] ?? null;
        $image = is_array($media) ? ($media['media_details']['sizes']['medium_large']['source_url'] ?? $media['source_url'] ?? null) : null;
        return [
            'id' => (string) (int) ($p['id'] ?? 0),
            'title' => Text::line(Text::fromHtml((string) ($p['title']['rendered'] ?? '')), 300),
            'date' => self::isoDate((string) ($p['date_gmt'] ?? ''), true) ?? self::isoDate((string) ($p['date'] ?? ''), false) ?? '',
            'excerpt' => self::excerpt((string) ($p['excerpt']['rendered'] ?? '')),
            'image' => self::httpUrl($image),
            'link' => self::httpUrl($p['link'] ?? null) ?? '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function feed(): array
    {
        $res = $this->fetch($this->url());
        if ($res['status'] !== 200) {
            throw ApiError::upstream('Der Feed lieferte HTTP ' . $res['status'] . '.');
        }
        $previous = libxml_use_internal_errors(true);
        // Ohne externe Entitäten und Netzwerkzugriff (XXE).
        $xml = simplexml_load_string($res['body'], \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw ApiError::upstream('Unter der Adresse liegt kein gültiger RSS- oder Atom-Feed.');
        }
        $items = [];
        if (isset($xml->channel)) { // RSS 2.0
            foreach ($xml->channel->item as $it) {
                $content = $it->children('http://purl.org/rss/1.0/modules/content/');
                $html = (string) ($content->encoded ?? '') ?: (string) $it->description;
                $items[] = $this->feedItem((string) $it->link, (string) $it->title, (string) $it->pubDate, (string) $it->description, $html, self::rssImage($it));
            }
        } else { // Atom
            $xml->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
            foreach ($xml->xpath('//a:entry') ?: [] as $e) {
                $e->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
                $link = '';
                foreach ($e->link as $l) {
                    if ((string) $l['rel'] === '' || (string) $l['rel'] === 'alternate') {
                        $link = (string) $l['href'];
                        break;
                    }
                }
                $html = (string) $e->content ?: (string) $e->summary;
                $items[] = $this->feedItem($link, (string) $e->title, (string) ($e->published ?: $e->updated), (string) $e->summary, $html, null);
            }
        }
        $items = array_values(array_filter($items, static fn (array $i): bool => $i['link'] !== '' || $i['title'] !== ''));
        usort($items, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
        return array_slice($items, 0, self::MAX_FEED_ITEMS);
    }

    /** @return array<string, mixed> */
    private function feedItem(string $link, string $title, string $date, string $summary, string $html, ?string $image): array
    {
        $link = self::httpUrl(trim($link)) ?? '';
        return [
            // Feste, URL-sichere ID aus dem Link (passt zum Muster der API).
            'id' => substr(hash('sha256', $link !== '' ? $link : $title . $date), 0, 16),
            'title' => Text::line(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 300),
            'date' => self::isoDate($date, false) ?? '',
            'excerpt' => self::excerpt($summary !== '' ? $summary : $html),
            'image' => $image,
            'link' => $link,
            'content' => $html,
        ];
    }

    private static function rssImage(\SimpleXMLElement $it): ?string
    {
        if (isset($it->enclosure) && str_starts_with((string) $it->enclosure['type'], 'image/')) {
            return self::httpUrl((string) $it->enclosure['url']);
        }
        $media = $it->children('http://search.yahoo.com/mrss/');
        if (isset($media->content)) {
            return self::httpUrl((string) $media->content->attributes()['url']);
        }
        if (isset($media->thumbnail)) {
            return self::httpUrl((string) $media->thumbnail->attributes()['url']);
        }
        return null;
    }

    private static function excerpt(string $html): string
    {
        $text = Text::line(Text::fromHtml($html));
        $text = (string) preg_replace('/\s*\[(…|&hellip;|\.\.\.)\]\s*$/u', ' …', $text);
        return mb_strlen($text) > 280 ? rtrim(mb_substr($text, 0, 277)) . ' …' : $text;
    }

    private static function isoDate(string $value, bool $utc): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            $dt = $utc ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : new \DateTimeImmutable($value);
            return $dt->format('c');
        } catch (\Exception) {
            return null;
        }
    }

    private static function httpUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : null;
    }
}
