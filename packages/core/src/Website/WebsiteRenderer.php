<?php

declare(strict_types=1);

namespace KompanieApp\Core\Website;

use KompanieApp\Core\App;
use KompanieApp\Core\Port\WebsiteHost;

/**
 * HTML of the website (Issue #273): one page with header, the blocks (BlockHtml) and a footer with imprint and privacy
 * policy. Plain HTML and one stylesheet from the own server: no scripts, no cookies, nothing from other servers.
 */
final class WebsiteRenderer
{
    public function __construct(private readonly App $app, private readonly WebsiteHost $host)
    {
    }

    /**
     * A page from blocks (as forViewer() returns them for a guest).
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function page(string $title, array $blocks): string
    {
        $html = new BlockHtml($this->app);
        // Pages that show people (contacts) are not indexed by search engines (decision in Issue #273).
        $people = in_array('contacts', array_column($blocks, 'type'), true);
        $body = '<h1 class="site-title">' . self::e($title) . '</h1>' . $html->blocks($blocks);
        return $this->document($title, $body, $people);
    }

    /** While there is no home page: a short note with the way to the App, not indexed. */
    public function placeholder(): string
    {
        $name = $this->app->config->name();
        $body = '<h1 class="site-title">' . self::e($name) . '</h1>'
            . '<p>Hier entsteht unsere Webseite.</p>'
            . '<p class="block-button"><a class="site-btn" href="' . self::e($this->app->env->appUrl()) . '">Zur App</a></p>';
        return $this->document('', $body, true);
    }

    /** CSS of the website: colours of the association, then the fixed rules (website.css). */
    public static function stylesheet(App $app): string
    {
        $color = static fn (string $c, string $fallback): string => preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1 ? $c : $fallback;
        return ':root{--site-primary:' . $color($app->config->primaryColor(), '#2e6b3f') . ';--site-accent:' . $color($app->config->accentColor(), '#c9a24a') . "}\n"
            . (string) file_get_contents(__DIR__ . '/website.css');
    }

    /** Version of the stylesheet for its address (changes with colours and rules). */
    public static function stylesheetVersion(App $app): string
    {
        return substr(sha1(self::stylesheet($app)), 0, 12);
    }

    private function document(string $title, string $body, bool $noindex): string
    {
        $name = $this->app->config->name();
        $description = trim($this->app->config->description());
        $legal = $this->app->settingsService()->legal();
        $links = '';
        foreach (['Impressum' => $legal['imprint_url'], 'Datenschutz' => $legal['privacy_url']] as $label => $url) {
            if ($url !== '') {
                $links .= '<a href="' . self::e($url) . '">' . $label . '</a>';
            }
        }
        $links .= '<a href="' . self::e($this->app->env->appUrl()) . '">App</a>';
        $home = $this->host->entryStatus()['url'] ?? $this->app->env->appUrl();
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title === '' || $title === $name ? $name : $title . ' – ' . $name) . '</title>'
            . ($description !== '' ? '<meta name="description" content="' . self::e($description) . '">' : '')
            . ($noindex ? '<meta name="robots" content="noindex">' : '')
            . '<meta name="theme-color" content="' . self::e($this->app->config->primaryColor()) . '">'
            . '<link rel="stylesheet" href="' . self::e($this->host->stylesheetUrl(self::stylesheetVersion($this->app))) . '">'
            . '<link rel="icon" href="' . self::e($this->host->iconUrl('favicon-32.png')) . '" sizes="32x32">'
            . '<link rel="apple-touch-icon" href="' . self::e($this->host->iconUrl('apple-touch-icon.png')) . '">'
            . '</head><body>'
            . '<header class="site-header"><div class="site-wrap">'
            . '<a class="site-brand" href="' . self::e($home) . '"><img src="' . self::e($this->host->iconUrl('icon-192.png')) . '" alt="" width="40" height="40"><span>' . self::e($name) . '</span></a>'
            . '<a class="site-app" href="' . self::e($this->app->env->appUrl()) . '">Zur App</a>'
            . '</div></header>'
            . '<main class="site-wrap site-main">' . $body . '</main>'
            . '<footer class="site-footer"><div class="site-wrap"><nav class="site-legal" aria-label="Rechtliches">' . $links . '</nav>'
            . '<span>© ' . $this->app->time()->format($this->app->time()->timestamp(), 'Y') . ' ' . self::e($name) . '</span></div></footer>'
            . '</body></html>';
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
