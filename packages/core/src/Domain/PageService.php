<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\ApiError;

/**
 * Eigene Seiten in der App (Issue #287, Bereich pages): freie Texte wie Geschichte, Festprogramm oder Anfahrt, mit
 * formatiertem Text (RichText, kein eigenes HTML, CSS oder JavaScript) und Bildern (rich_images, Besitzer page).
 *
 * - audience public: alle, auch ohne Anmeldung; members: Mitglieder und Rollen mit internem Kalender (wer das Schwarze
 *   Brett liest, wie BoardService::isRecipient). Lesen prüft der Server danach.
 * - Pflegen mit manage_pages (Standardrolle „Seiten verwalten“); wer pflegt, sieht alle Seiten.
 * - In der Navigation steht jede Seite als eigener Punkt „seiten/<id>“ (NavigationService), den der Admin in die
 *   Leiste oder ein Untermenü schiebt; gelöschte Seiten fallen dort heraus.
 * - Inhalt aus Blöcken (Domain\Blocks\Blocks, Spalte blocks): Text, Countdown …, jeder Block für alle oder nur
 *   Mitglieder. body enthält weiter den Text der Textblöcke für alle (ältere Versionen und Apps).
 * - Speichern mit updated_at (Stand, den die App geladen hat): 409 conflict, wenn jemand dazwischen gespeichert hat
 *   (wie bei den Listen). Reihenfolge per Ziehen (sort).
 */
final class PageService
{
    public const FEATURE = 'pages';
    public const TITLE_MAX = 100;
    public const BODY_MAX = 200000;
    public const AUDIENCES = ['public', 'members'];

    public function __construct(private readonly App $app)
    {
    }

    public function canManage(?User $viewer): bool
    {
        return $viewer !== null && $viewer->id > 0 && $viewer->can(Capability::ManagePages);
    }

    /** Darf $viewer (null = ohne Anmeldung) eine Seite mit dieser Zielgruppe lesen? */
    public function canRead(?User $viewer, string $audience): bool
    {
        if (!$this->app->features()->enabled(self::FEATURE)) {
            return false;
        }
        if ($this->canManage($viewer) || $audience === 'public') {
            return true;
        }
        return BoardService::isRecipient($viewer);
    }

    /**
     * Für Bilder der Seite (RichImageService): sieht $viewer die Seite und den Block, in dem das Bild steht? Ein Bild
     * nur in Blöcken für Mitglieder sehen auch auf einer öffentlichen Seite nur Mitglieder.
     */
    public function canSeeImage(?User $viewer, int $pageId, ?string $uid = null): bool
    {
        $page = $this->row($pageId);
        if ($page === null || !$this->canRead($viewer, (string) $page['audience'])) {
            return false;
        }
        if ($uid === null || !Blocks::imageOnlyForMembers(Blocks::decode($page['blocks'] ?? null, (string) ($page['body'] ?? '')), $uid)) {
            return true;
        }
        return $this->isMember($viewer);
    }

    /** Sieht $viewer Blöcke für Mitglieder? (Wer pflegt, sieht alles.) */
    private function isMember(?User $viewer): bool
    {
        return $this->canManage($viewer) || BoardService::isRecipient($viewer);
    }

    /** @return list<array<string, mixed>> sichtbare Seiten in ihrer Reihenfolge (ohne Text) */
    public function list(?User $viewer): array
    {
        if (!$this->app->features()->enabled(self::FEATURE)) {
            return [];
        }
        $manager = $this->canManage($viewer);
        $out = [];
        foreach ($this->app->db->select('SELECT id, title, audience, sort, updated_by, updated_at FROM {app_pages} ORDER BY sort ASC, id ASC') as $p) {
            if (!$this->canRead($viewer, (string) $p['audience'])) {
                continue;
            }
            $out[] = ['id' => (int) $p['id'], 'title' => (string) $p['title'], 'audience' => (string) $p['audience']]
                + ($manager ? $this->meta($p) : []);
        }
        return $out;
    }

    /** @return array<string, mixed> Seite mit Text (Bilder als Adressen) */
    public function get(int $id, ?User $viewer): array
    {
        $p = $this->row($id);
        if ($p === null || !$this->canRead($viewer, (string) $p['audience'])) {
            // Für wen die Seite nicht gedacht ist, gibt es sie nicht (verrät auch nicht, dass sie existiert).
            throw ApiError::notFound('Seite nicht gefunden.');
        }
        $body = (string) ($p['body'] ?? '');
        $manager = $this->canManage($viewer);
        $blocks = Blocks::decode($p['blocks'] ?? null, $body);
        return [
            'id' => (int) $p['id'],
            'title' => (string) $p['title'],
            'audience' => (string) $p['audience'],
            'body' => $body === '' ? '' : $this->app->richImages()->render($body),
            'blocks' => $this->app->blocks()->forViewer($blocks, $viewer, $this->isMember($viewer), $manager),
        ] + ($manager ? $this->meta($p) : []);
    }

    /**
     * @param array<string, mixed> $in title (Pflicht), blocks (oder body: ein Textblock), audience
     * @return array<string, mixed>
     */
    public function create(array $in, User $actor): array
    {
        if (!array_key_exists('title', $in)) {
            throw ApiError::invalid('Bitte einen Titel für die Seite angeben.');
        }
        [$data, $blocks] = $this->validate($in);
        $now = $this->app->time()->nowUtc();
        $db = $this->app->db;
        $id = $db->transaction(function () use ($db, $data, $blocks, $actor, $now): int {
            $sort = (int) $db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {app_pages}') + 10;
            $id = $db->insert('app_pages', ['body' => ''] + $data + ['audience' => 'members', 'sort' => $sort, 'updated_by' => $actor->id, 'updated_at' => $now]);
            // Bilder der Textblöcke gehören danach der Seite (dafür braucht es ihre ID).
            if ($blocks !== null) {
                $db->update('app_pages', $this->content($blocks, $actor, $id), ['id' => $id]);
            }
            return $id;
        });
        return $this->get($id, $actor);
    }

    /**
     * @param array<string, mixed> $in wie create(), jeweils optional; updated_at: Stand, den die App geladen hat
     * @return array<string, mixed>
     */
    public function update(int $id, array $in, User $actor): array
    {
        $page = $this->row($id) ?? throw ApiError::notFound('Seite nicht gefunden.');
        $loaded = $in['updated_at'] ?? null;
        if (is_string($loaded) && $loaded !== Time::utcToIso((string) $page['updated_at'])) {
            $by = $page['updated_by'] !== null ? $this->app->users->find((int) $page['updated_by'])?->name : null;
            throw new ApiError(409, 'conflict', ($by ?? 'Jemand') . ' hat die Seite inzwischen geändert. Bitte die Seite neu laden und deine Änderungen noch einmal eintragen.');
        }
        [$data, $blocks] = $this->validate($in);
        if ($blocks !== null) {
            // Bilder, die in keinem Block mehr stehen, werden gelöscht (es gibt keinen Verlauf).
            $data += $this->content($blocks, $actor, $id);
        }
        $this->app->db->update('app_pages', $data + ['updated_by' => $actor->id, 'updated_at' => $this->app->time()->nowUtc()], ['id' => $id]);
        return $this->get($id, $actor);
    }

    public function delete(int $id): void
    {
        $this->row($id) ?? throw ApiError::notFound('Seite nicht gefunden.');
        $this->app->db->transaction(function () use ($id): void {
            $this->app->db->execute('DELETE FROM {app_pages} WHERE id = ?', [$id]);
            $this->app->richImages()->deleteOwner(RichImageService::OWNER_PAGE, $id);
        });
        $this->app->navigation()->removeTarget(NavigationService::PAGE_PREFIX . $id);
    }

    /** @return list<array<string, mixed>> */
    public function reorder(mixed $ids, User $actor): array
    {
        $known = array_map(static fn (array $r): int => (int) $r['id'], $this->app->db->select('SELECT id FROM {app_pages}'));
        $order = is_array($ids) ? array_map(static fn (mixed $v): ?int => Input::integer($v), array_values($ids)) : [];
        $given = $order;
        sort($given);
        sort($known);
        if ($given !== $known) {
            throw ApiError::invalid('Bitte die Reihenfolge aller Seiten angeben.');
        }
        foreach ($order as $i => $id) {
            $this->app->db->update('app_pages', ['sort' => ($i + 1) * 10], ['id' => (int) $id]);
        }
        return $this->list($actor);
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return $this->app->db->selectOne('SELECT * FROM {app_pages} WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function meta(array $p): array
    {
        $by = $p['updated_by'] !== null ? $this->app->users->find((int) $p['updated_by']) : null;
        return ['updated_at' => Time::utcToIso((string) $p['updated_at']), 'updated_by_name' => $by?->name];
    }

    /**
     * Spalten blocks und body für gespeicherte Blöcke (Bilder gehören danach der Seite).
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{blocks: string, body: string}
     */
    private function content(array $blocks, User $actor, int $id): array
    {
        $blocks = $this->app->blocks()->claim($blocks, $actor, RichImageService::OWNER_PAGE, $id);
        $body = Blocks::publicBody($blocks);
        return ['blocks' => Blocks::encode($blocks, $body), 'body' => $body];
    }

    /**
     * Geprüfte Spalten (ohne Inhalt) und die Blöcke (null: Inhalt nicht angegeben). blocks hat Vorrang; nur body
     * (ältere App) ergibt einen Textblock.
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
                throw ApiError::invalid('Bitte einen Titel für die Seite angeben.');
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                throw ApiError::invalid(sprintf('Der Titel darf höchstens %d Zeichen lang sein.', self::TITLE_MAX));
            }
            $data['title'] = $title;
        }
        if (array_key_exists('audience', $in)) {
            if (!in_array($in['audience'], self::AUDIENCES, true)) {
                throw ApiError::invalid('Für wen ist die Seite? Erlaubt sind public (alle) und members (Mitglieder).');
            }
            $data['audience'] = $in['audience'];
        }
        $blocks = null;
        if (array_key_exists('blocks', $in)) {
            $blocks = $this->app->blocks()->validate($in['blocks'], self::BODY_MAX);
        } elseif (array_key_exists('body', $in)) {
            if ($in['body'] !== null && !is_string($in['body'])) {
                throw ApiError::invalid('Ungültiger Text.');
            }
            $html = RichText::sanitize((string) $in['body']);
            $html = RichText::isEmpty($html) ? '' : $html;
            if (strlen($html) > self::BODY_MAX) {
                throw ApiError::invalid('Der Text ist zu lang.');
            }
            $blocks = Blocks::fromBody($html);
        }
        return [$data, $blocks];
    }
}
