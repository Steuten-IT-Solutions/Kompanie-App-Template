<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Website\WebsiteRenderer;

/**
 * Website for visitors (Issue #273, area website): pages of the App that the server renders as plain HTML at the
 * address of the association, without the App and without login.
 *
 * First step: the area switch, the right manage_website, the entry file (Port\WebsiteHost, only the standalone version)
 * and a home page. The home page is one of the own pages (PageService) with audience public; the website shows its
 * blocks as a guest sees them, so nothing for members only ever reaches it. Visitors get no cookies and nothing is
 * loaded from other servers.
 *
 * Settings (setting website): home_page_id. Later steps add more pages, a menu, drafts and the imprint rule.
 */
final class WebsiteService
{
    public const FEATURE = 'website';
    public const SETTING = 'website';

    public function __construct(private readonly App $app)
    {
    }

    /** Is there a place for the website at all? (Not on WordPress, which manages its own pages.) */
    public function available(): bool
    {
        return $this->app->websiteHost !== null;
    }

    /** Area switched on (and available)? */
    public function enabled(): bool
    {
        return $this->available() && $this->app->features()->enabled(self::FEATURE);
    }

    public static function canManage(?User $viewer): bool
    {
        return $viewer !== null && $viewer->id > 0 && $viewer->can(Capability::ManageWebsite);
    }

    public function homePageId(): ?int
    {
        $saved = $this->app->settings->get(self::SETTING);
        $id = is_array($saved) ? Input::integer($saved['home_page_id'] ?? null) : null;
        return $id !== null && $id > 0 ? $id : null;
    }

    /**
     * For the Verwaltung: home page, the pages that can be it (audience public), the entry file and what to do.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $host = $this->host();
        $entry = $host->entryStatus();
        $pages = [];
        foreach ($this->app->db->select('SELECT id, title, audience FROM {app_pages} ORDER BY sort ASC, id ASC') as $p) {
            if ($p['audience'] === 'public') {
                $pages[] = ['id' => (int) $p['id'], 'title' => (string) $p['title']];
            }
        }
        $home = $this->homePageId();
        $title = $home === null ? null : $this->app->db->scalar('SELECT title FROM {app_pages} WHERE id = ?', [$home]);
        return [
            'home_page_id' => $home,
            'home_page_title' => is_string($title) ? $title : null,
            // Home page set, but deleted or no longer public: the website shows a placeholder.
            'home_page_missing' => $home !== null && !in_array($home, array_column($pages, 'id'), true),
            'pages' => $pages,
            'pages_enabled' => $this->app->features()->enabled(PageService::FEATURE),
            'url' => $entry['url'],
            'entry' => [
                'state' => $entry['state'],
                'file' => $entry['file'],
                'folder' => $entry['folder'],
                'content' => $entry['content'],
                'message' => $this->entryMessage($entry),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $in home_page_id: id of a page with audience public, or null (no home page)
     * @return array<string, mixed> like describe()
     */
    public function update(array $in): array
    {
        if (array_key_exists('home_page_id', $in)) {
            $id = $in['home_page_id'] === null ? null : Input::integer($in['home_page_id']);
            if ($in['home_page_id'] !== null && ($id === null || $id <= 0)) {
                throw ApiError::invalid('Ungültige Startseite.');
            }
            if ($id !== null) {
                $page = $this->app->db->selectOne('SELECT audience FROM {app_pages} WHERE id = ?', [$id]);
                if ($page === null) {
                    throw ApiError::invalid('Diese Seite gibt es nicht.');
                }
                if ($page['audience'] !== 'public') {
                    throw ApiError::invalid('Die Startseite muss für alle sichtbar sein. Bitte die Seite unter Verwaltung → Seiten auf „Für alle“ stellen.');
                }
            }
            $saved = $this->app->settings->get(self::SETTING);
            $this->app->settings->set(self::SETTING, ['home_page_id' => $id] + (is_array($saved) ? $saved : []));
        }
        return $this->describe();
    }

    /** @return array<string, mixed> like describe() */
    public function installEntry(): array
    {
        $this->host()->installEntry();
        return $this->describe();
    }

    /**
     * The home page as a guest sees it: title and blocks; null without a home page, when it is gone or no longer public,
     * or while the area Seiten is off.
     *
     * @return array{title: string, blocks: list<array<string, mixed>>}|null
     */
    public function homePage(): ?array
    {
        $id = $this->homePageId();
        if ($id === null || !$this->app->features()->enabled(PageService::FEATURE)) {
            return null;
        }
        $p = $this->app->db->selectOne('SELECT * FROM {app_pages} WHERE id = ?', [$id]);
        if ($p === null || $p['audience'] !== 'public') {
            return null;
        }
        $blocks = \KompanieApp\Core\Domain\Blocks\Blocks::decode($p['blocks'] ?? null, (string) ($p['body'] ?? ''));
        // Always as a guest, also for someone who is logged in to the App: the website is the same for everyone.
        return ['title' => (string) $p['title'], 'blocks' => $this->app->blocks()->forViewer($blocks, null, false, false)];
    }

    /**
     * The website's answer for the home page: status and HTML. While no home page is set (or it is gone) a short page
     * that points to the App, not indexed.
     *
     * @return array{status: int, html: string}
     */
    public function renderHome(): array
    {
        $renderer = new WebsiteRenderer($this->app, $this->host());
        $home = $this->homePage();
        if ($home === null) {
            return ['status' => 200, 'html' => $renderer->placeholder()];
        }
        return ['status' => 200, 'html' => $renderer->page($home['title'], $home['blocks'])];
    }

    /** Stylesheet of the website (served by the adapter, see WebsiteHost::stylesheetUrl()). */
    public function stylesheet(): string
    {
        return WebsiteRenderer::stylesheet($this->app);
    }

    /**
     * For the status page while the area is on: is the entry file there, and is there a home page?
     *
     * @return array{key: string, label: string, level: string, text: string}|null
     */
    public function statusItem(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $item = static fn (string $level, string $text): array => ['key' => 'website', 'label' => 'Webseite', 'level' => $level, 'text' => $text];
        $entry = $this->host()->entryStatus();
        if ($entry['state'] !== 'installed') {
            return $item($entry['state'] === 'root' ? 'error' : 'warn', $this->entryMessage($entry));
        }
        if ($this->homePage() === null) {
            return $item('warn', 'Die Webseite ist unter ' . $entry['url'] . ' erreichbar, hat aber noch keine Startseite. Bitte unter Verwaltung → Webseite eine Seite auswählen, die für alle sichtbar ist.');
        }
        return $item('ok', 'Die Webseite ist unter ' . $entry['url'] . ' erreichbar.');
    }

    /** @param array{state: string, url: string|null, file: string|null, folder: string, content: string} $entry */
    private function entryMessage(array $entry): string
    {
        return match ($entry['state']) {
            'installed' => 'Die Einstiegsdatei ist da. Die Webseite ist unter ' . $entry['url'] . ' erreichbar.',
            'missing' => 'Die Einstiegsdatei fehlt noch. Mit „Einstiegsdatei anlegen“ legt die App sie im Ordner über „' . $entry['folder'] . '“ an; danach ist die Webseite unter ' . $entry['url'] . ' erreichbar.',
            'outdated' => 'Die Einstiegsdatei gehört zu einem anderen Ordnernamen der App. Mit „Einstiegsdatei anlegen“ schreibt die App sie neu.',
            'occupied' => 'Im Ordner über „' . $entry['folder'] . '“ liegt schon eine andere Startseite (' . $entry['file'] . '), zum Beispiel eine bisherige Webseite. Die App ersetzt sie nicht. Wenn die neue Webseite sie ablösen soll, bitte die Datei beim Hoster umbenennen oder sichern und löschen und danach die Einstiegsdatei anlegen.',
            'not_writable' => 'PHP darf im Ordner über „' . $entry['folder'] . '“ keine Dateien anlegen. Bitte die Einstiegsdatei von Hand als index.php dort hochladen (Inhalt unten) oder beim Hoster die Schreibrechte des Ordners prüfen.',
            default => 'Die App liegt direkt unter der Adresse des Webspace und nicht in einem eigenen Ordner wie „app“. Die Webseite braucht diesen Ordner, weil sie selbst unter der Adresse des Webspace liegt; hier geht sie deshalb nicht.',
        };
    }

    private function host(): \KompanieApp\Core\Port\WebsiteHost
    {
        return $this->app->websiteHost ?? throw new ApiError(403, 'feature_disabled', 'Die Webseite gibt es nur in der eigenständigen Version.');
    }
}
