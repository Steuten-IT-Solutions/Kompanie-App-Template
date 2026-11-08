<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Infra\RemoteNewsSource;
use KompanieApp\Core\Port\NewsSource;
use KompanieApp\Core\Push\PushService;

/**
 * Woher die News kommen und News in der App (Einstellung news_source).
 *
 * - Quelle des Adapters ($app->news): im WordPress-Plugin die Beiträge der eigenen Seite („site“), in der
 *   eigenständigen App eine fremde WordPress-Seite oder ein Feed (RemoteNewsSource: none, wordpress, rss).
 * - Quelle „app“: Beiträge werden in der App geschrieben (Tabelle app_news, AppNewsSource), aus Blöcken wie eigene
 *   Seiten (Domain\Blocks\Blocks: Text mit Bildern in rich_images, Besitzer news; Countdown …; je Block für alle oder
 *   nur Mitglieder). body enthält den Text der Textblöcke für alle; das erste Bild darin ist das Vorschaubild der Liste.
 * - Entweder oder, kein Mischen: Eine Quelle bestimmt, was in der Liste steht. Beim Wechsel bleiben die Beiträge der
 *   App erhalten (nur nicht sichtbar), die Einstellungen eines Feeds ebenso; zurückwechseln zeigt sie wieder.
 * - Lesen wie bisher mit view_news (auch ohne Anmeldung, wenn Gast das Recht hat). Schreiben, ändern und löschen mit
 *   manage_news, nur mit der Quelle „app“. Die Standardrolle „News verwalten“ entsteht beim Wechsel auf „app“
 *   (Features::roleOf) und verschwindet beim Wechsel weg, wenn niemand sie hat.
 * - Benachrichtigungen wie bei jeder Quelle über PushTriggers::checkNews (nach dem Schreiben sofort angestoßen).
 */
final class NewsService
{
    public const SOURCE_SETTING = 'news_source';
    public const SOURCE_APP = 'app';
    /** Beiträge der eigenen WordPress-Seite (nur im WordPress-Plugin). */
    public const SOURCE_SITE = 'site';
    public const TITLE_MAX = 150;
    public const BODY_MAX = 200000;
    /** Rechte der Standardrolle „News verwalten“ (nur mit der Quelle app). */
    public const ROLE = ['member', 'manage_news'];

    private ?AppNewsSource $appSource = null;

    public function __construct(private readonly App $app)
    {
    }

    /** Läuft die App mit einer Quelle, die eine Adresse braucht (eigenständige Version)? */
    public function remote(): bool
    {
        return $this->app->news instanceof RemoteNewsSource;
    }

    /** @return list<string> mögliche Werte für news_source in dieser Installation */
    public function sources(): array
    {
        return $this->remote() ? [...RemoteNewsSource::SOURCES, self::SOURCE_APP] : [self::SOURCE_SITE, self::SOURCE_APP];
    }

    public function inApp(): bool
    {
        return $this->app->settings->get(self::SOURCE_SETTING, '') === self::SOURCE_APP;
    }

    /** Eingestellte Quelle: app, sonst site (WordPress) bzw. none, wordpress oder rss. */
    public function sourceType(): string
    {
        if ($this->inApp()) {
            return self::SOURCE_APP;
        }
        return $this->app->news instanceof RemoteNewsSource ? $this->app->news->type() : self::SOURCE_SITE;
    }

    /** Die Quelle, aus der die Liste gerade kommt. */
    public function source(): NewsSource
    {
        return $this->inApp() ? $this->appSource() : $this->app->news;
    }

    public function available(): bool
    {
        return $this->source()->available();
    }

    public function canManage(?User $viewer): bool
    {
        return $viewer !== null && $viewer->id > 0 && $this->inApp() && $viewer->can(Capability::ManageNews)
            && $this->app->features()->enabled('news');
    }

    /**
     * Eine Seite der Liste aus der eingestellten Quelle.
     *
     * @return array{items: array<int, array<string, mixed>>, totalPages: int}
     */
    public function page(int $page, int $perPage): array
    {
        $source = $this->source();
        return $source->available() ? $source->page($page, $perPage) : ['items' => [], 'totalPages' => 0];
    }

    /**
     * Ein Beitrag mit Inhalt; wer die News in der App pflegt, bekommt den Stand dazu (updated_at, für 409 conflict).
     *
     * @return array<string, mixed>
     */
    public function item(string $key, ?User $viewer): array
    {
        $source = $this->source();
        $item = $source->available() ? $source->item($key) : null;
        if ($item === null) {
            throw ApiError::notFound('Beitrag nicht gefunden.');
        }
        if ($this->inApp()) {
            $row = $this->appSource()->row((int) $key);
            $blocks = Blocks::decode($row['blocks'] ?? null, (string) ($row['body'] ?? ''));
            $item['blocks'] = $this->app->blocks()->forViewer($blocks, $viewer, $this->isMember($viewer), $this->canManage($viewer));
        }
        if ($this->canManage($viewer)) {
            $item += $this->appSource()->meta((int) $key);
        }
        return $item;
    }

    /** Sieht $viewer Blöcke nur für Mitglieder? (Wer die News pflegt, sieht alles.) */
    private function isMember(?User $viewer): bool
    {
        return $this->canManage($viewer) || BoardService::isRecipient($viewer);
    }

    /**
     * Für Bilder eines Beitrags (RichImageService): sieht $viewer (null = ohne Anmeldung) die News der App und den Block,
     * in dem das Bild steht?
     */
    public function canSeeImage(?User $viewer, int $newsId, ?string $uid = null): bool
    {
        if (!$this->inApp() || !$this->app->features()->enabled('news')) {
            return false;
        }
        $who = $viewer ?? $this->app->anonymous();
        if (!$who->can(Capability::ViewNews) && !$who->can(Capability::ManageNews)) {
            return false;
        }
        $row = $this->appSource()->row($newsId);
        if ($row === null) {
            return false;
        }
        if ($uid !== null && Blocks::imageOnlyForMembers(Blocks::decode($row['blocks'] ?? null, (string) ($row['body'] ?? '')), $uid)) {
            return $this->isMember($viewer);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $in title (Pflicht), blocks (oder body: ein Textblock)
     * @return array<string, mixed> der neue Beitrag wie item()
     */
    public function create(array $in, User $actor): array
    {
        $this->requireInApp();
        if (!array_key_exists('title', $in)) {
            throw ApiError::invalid('Bitte einen Titel für den Beitrag angeben.');
        }
        [$data, $blocks] = $this->validate($in);
        $now = $this->app->time()->nowUtc();
        $db = $this->app->db;
        $id = $db->transaction(function () use ($db, $data, $blocks, $actor, $now): int {
            $id = $db->insert('app_news', ['body' => ''] + $data + ['created_by' => $actor->id, 'created_at' => $now, 'updated_by' => $actor->id, 'updated_at' => $now]);
            // Bilder der Textblöcke gehören danach dem Beitrag (dafür braucht es seine ID).
            if ($blocks !== null) {
                $db->update('app_news', $this->app->blocks()->columns($blocks, $actor, RichImageService::OWNER_NEWS, $id), ['id' => $id]);
            }
            return $id;
        });
        return $this->item((string) $id, $actor);
    }

    /**
     * @param array<string, mixed> $in title, body (jeweils optional); updated_at: Stand, den die App geladen hat
     * @return array<string, mixed>
     */
    public function update(int $id, array $in, User $actor): array
    {
        $this->requireInApp();
        $row = $this->appSource()->row($id) ?? throw ApiError::notFound('Beitrag nicht gefunden.');
        $loaded = $in['updated_at'] ?? null;
        if (is_string($loaded) && $loaded !== Time::utcToIso((string) $row['updated_at'])) {
            $by = $row['updated_by'] !== null ? $this->app->users->find((int) $row['updated_by'])?->name : null;
            throw new ApiError(409, 'conflict', ($by ?? 'Jemand') . ' hat den Beitrag inzwischen geändert. Bitte neu laden und deine Änderungen noch einmal eintragen.');
        }
        [$data, $blocks] = $this->validate($in);
        if ($blocks !== null) {
            // Bilder, die in keinem Block mehr stehen, werden gelöscht (es gibt keinen Verlauf).
            $data += $this->app->blocks()->columns($blocks, $actor, RichImageService::OWNER_NEWS, $id);
        }
        $this->app->db->update('app_news', $data + ['updated_by' => $actor->id, 'updated_at' => $this->app->time()->nowUtc()], ['id' => $id]);
        return $this->item((string) $id, $actor);
    }

    public function delete(int $id): void
    {
        $this->requireInApp();
        $this->appSource()->row($id) ?? throw ApiError::notFound('Beitrag nicht gefunden.');
        $this->app->db->transaction(function () use ($id): void {
            $this->app->db->execute('DELETE FROM {app_news} WHERE id = ?', [$id]);
            $this->app->richImages()->deleteOwner(RichImageService::OWNER_NEWS, $id);
        });
    }

    /**
     * Nach dem Wechsel der Quelle (SettingsService): Standardrolle anlegen bzw. entfernen und den Stand für
     * Benachrichtigungen neu beginnen, damit die Beiträge der anderen Quelle nicht auf einmal als neu gemeldet werden.
     */
    public function sourceChanged(string $before): void
    {
        $now = $this->sourceType();
        if ($now === $before) {
            return;
        }
        $features = $this->app->features();
        if ($features->enabled('news') && ($now === self::SOURCE_APP || $before === self::SOURCE_APP)) {
            $this->app->roles()->featureChanged('news', $now === self::SOURCE_APP, $features->roleLabel('news'), self::ROLE);
        }
        $this->app->settings->delete(PushService::NEWS_SETTING);
        if ($now === self::SOURCE_APP) {
            // Ohne Abruf von außen: gleich merken, was schon da ist; der nächste Beitrag wird dann gemeldet.
            $this->app->pushTriggers()->checkNews();
        }
    }

    public function appSource(): AppNewsSource
    {
        return $this->appSource ??= new AppNewsSource($this->app);
    }

    private function requireInApp(): void
    {
        if (!$this->inApp()) {
            throw new ApiError(409, 'not_in_app', 'Die News kommen hier nicht aus der App. Ein Admin kann das unter Einstellungen → Neuigkeiten ändern.');
        }
    }

    /**
     * Geprüfter Titel und die Blöcke (null: Inhalt nicht angegeben).
     *
     * @param array<string, mixed> $in
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>|null}
     */
    private function validate(array $in): array
    {
        $data = [];
        if (array_key_exists('title', $in)) {
            $title = Text::line(is_string($in['title']) ? $in['title'] : '', self::TITLE_MAX + 1);
            if ($title === '') {
                throw ApiError::invalid('Bitte einen Titel für den Beitrag angeben.');
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                throw ApiError::invalid(sprintf('Der Titel darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
            }
            $data['title'] = $title;
        }
        return [$data, $this->app->blocks()->fromInput($in, self::BODY_MAX)];
    }
}
