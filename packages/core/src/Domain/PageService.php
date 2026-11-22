<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;

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
 * - Inhalte je Sprache (Plan „Mehrsprachigkeit“, PR 9b, ContentTranslations): je weitere Sprache ein eigener Titel und
 *   die Texte der Blöcke (Blocks::TRANSLATABLE, Feld blocks als JSON Kennung => Feld => Wert); Aufbau, Zielgruppe und
 *   Einstellungen der Blöcke gelten für alle Sprachen. Gelesen wird in der Sprache der Anfrage, was fehlt, auf Deutsch
 *   (untranslated). Zum Bearbeiten (source) gibt es die deutsche Fassung und die Fassungen getrennt.
 */
final class PageService
{
    public const FEATURE = 'pages';
    public const TITLE_MAX = 100;
    public const BODY_MAX = 200000;
    public const AUDIENCES = ['public', 'members'];
    /** Art in translations */
    public const KIND = 'page';

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
        if ($uid === null) {
            return true;
        }
        // Das Bild kann auch in der Fassung einer weiteren Sprache stehen: dort gilt die Zielgruppe des Blocks ebenso.
        $all = $this->app->contentTranslations()->allBlocks(self::KIND, $pageId, Blocks::decode($page['blocks'] ?? null, (string) ($page['body'] ?? '')));
        if (!Blocks::imageOnlyForMembers($all, $uid)) {
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
        $rows = $this->app->db->select('SELECT id, title, audience, sort, body, blocks, updated_by, updated_at FROM {app_pages} ORDER BY sort ASC, id ASC');
        $content = $this->app->contentTranslations();
        $current = $content->current();
        $all = $content->ofMany(self::KIND, array_map(static fn (array $p): int => (int) $p['id'], $rows));
        $out = [];
        foreach ($rows as $p) {
            if (!$this->canRead($viewer, (string) $p['audience'])) {
                continue;
            }
            $id = (int) $p['id'];
            $title = (string) $p['title'];
            if ($current !== null && ($all[$id][$current]['title'] ?? '') !== '') {
                $title = $all[$id][$current]['title'];
            }
            // Titel je weiterer Sprache (die App wählt nach der Sprache des Geräts, /config kennt sie nicht)
            $titles = [];
            foreach ($all[$id] ?? [] as $locale => $fields) {
                if (($fields['title'] ?? '') !== '') {
                    $titles[$locale] = $fields['title'];
                }
            }
            $row = ['id' => $id, 'title' => $title, 'audience' => (string) $p['audience']] + ($titles !== [] ? ['title_i18n' => $titles] : []);
            if ($manager) {
                // Für die Pflege: In welchen weiteren Sprachen fehlt noch etwas?
                $missing = [];
                foreach ($content->locales() as $locale) {
                    [, , $total, $done] = $this->localized($p, $all[$id][$locale] ?? []);
                    if ($done < $total) {
                        $missing[] = $locale;
                    }
                }
                $row += $this->meta($p) + ['untranslated_locales' => $missing];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Seite mit Text (Bilder als Adressen), in der Sprache der Anfrage (fehlt etwas: untranslated). Mit $source (nur wer
     * pflegt): die deutsche Fassung und dazu translations (Sprache => title, blocks) zum Bearbeiten.
     *
     * @return array<string, mixed>
     */
    public function get(int $id, ?User $viewer, bool $source = false): array
    {
        $p = $this->row($id);
        if ($p === null || !$this->canRead($viewer, (string) $p['audience'])) {
            // Für wen die Seite nicht gedacht ist, gibt es sie nicht (verrät auch nicht, dass sie existiert).
            throw ApiError::notFound(I18n::t('Seite nicht gefunden.'));
        }
        $body = (string) ($p['body'] ?? '');
        $manager = $this->canManage($viewer);
        $content = $this->app->contentTranslations();
        $current = $source && $manager ? null : $content->current();
        $title = (string) $p['title'];
        $blocks = Blocks::decode($p['blocks'] ?? null, $body);
        $untranslated = null;
        if ($current !== null) {
            [$title, $blocks, $total, $done] = $this->localized($p, $content->of(self::KIND, $id)[$current] ?? []);
            $untranslated = ContentTranslations::untranslated($total, $done);
        }
        $out = [
            'id' => (int) $p['id'],
            'title' => $title,
            'audience' => (string) $p['audience'],
            'body' => $body === '' ? '' : $this->app->richImages()->render($body),
            'blocks' => $this->app->blocks()->forViewer($blocks, $viewer, $this->isMember($viewer), $manager),
        ] + ($untranslated !== null ? ['untranslated' => $untranslated] : []) + ($manager ? $this->meta($p) : []);
        if ($source && $manager) {
            $out['translations'] = $content->blocksSource(self::KIND, $id);
        }
        return $out;
    }

    /**
     * Titel und Blöcke in einer Fassung ($fields: title, blocks), dazu übersetzbare und übersetzte Felder.
     *
     * @param array<string, mixed> $p Zeile der Seite
     * @param array<string, string> $fields
     * @return array{0: string, 1: list<array<string, mixed>>, 2: int, 3: int}
     */
    private function localized(array $p, array $fields): array
    {
        return ContentTranslations::blocksIn((string) $p['title'], Blocks::decode($p['blocks'] ?? null, (string) ($p['body'] ?? '')), $fields);
    }

    /**
     * @param array<string, mixed> $in title (Pflicht), blocks (oder body: ein Textblock), audience
     * @return array<string, mixed>
     */
    public function create(array $in, User $actor): array
    {
        if (!array_key_exists('title', $in)) {
            throw ApiError::invalid(I18n::t('Bitte einen Titel für die Seite angeben.'));
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
        $page = $this->row($id) ?? throw ApiError::notFound(I18n::t('Seite nicht gefunden.'));
        $loaded = $in['updated_at'] ?? null;
        if (is_string($loaded) && $loaded !== Time::utcToIso((string) $page['updated_at'])) {
            $by = $page['updated_by'] !== null ? $this->app->users->find((int) $page['updated_by'])?->name : null;
            throw new ApiError(409, 'conflict', $by !== null
                ? I18n::t('{name} hat die Seite inzwischen geändert. Bitte die Seite neu laden und deine Änderungen noch einmal eintragen.', ['name' => $by])
                : I18n::t('Jemand hat die Seite inzwischen geändert. Bitte die Seite neu laden und deine Änderungen noch einmal eintragen.'));
        }
        [$data, $blocks] = $this->validate($in);
        $content = $this->app->contentTranslations();
        $given = $content->input($in['translations'] ?? null);
        // Fassungen prüfen (gegen die deutschen Blöcke nach dieser Änderung), Bilder darin gehören der Seite.
        $german = $blocks ?? Blocks::decode($page['blocks'] ?? null, (string) ($page['body'] ?? ''));
        $translations = [];
        foreach ($given as $locale => $fields) {
            $translations[$locale] = $content->blocksInput($fields, $german, $actor, RichImageService::OWNER_PAGE, $id, self::TITLE_MAX, self::BODY_MAX);
        }
        $this->app->db->transaction(function () use ($id, $data, $blocks, $translations, $content, $actor): void {
            foreach ($translations as $locale => $fields) {
                $content->save(self::KIND, $id, $locale, $fields, $actor->id);
            }
            if ($blocks !== null || $translations !== []) {
                // Bilder, die in keinem Block und keiner Fassung mehr stehen, werden gelöscht (es gibt keinen Verlauf).
                $page = $this->row($id) ?? [];
                $data += $this->content($blocks ?? Blocks::decode($page['blocks'] ?? null, (string) ($page['body'] ?? '')), $actor, $id, $content->blockImages(self::KIND, $id));
            }
            $this->app->db->update('app_pages', $data + ['updated_by' => $actor->id, 'updated_at' => $this->app->time()->nowUtc()], ['id' => $id]);
        });
        return $this->get($id, $actor, $translations !== []);
    }

    public function delete(int $id): void
    {
        $this->row($id) ?? throw ApiError::notFound(I18n::t('Seite nicht gefunden.'));
        $this->app->db->transaction(function () use ($id): void {
            $this->app->db->execute('DELETE FROM {app_pages} WHERE id = ?', [$id]);
            $this->app->richImages()->deleteOwner(RichImageService::OWNER_PAGE, $id);
            $this->app->contentTranslations()->delete(self::KIND, $id);
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
            throw ApiError::invalid(I18n::t('Bitte die Reihenfolge aller Seiten angeben.'));
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
     * @param list<string> $keep Bilder der Fassungen in weiteren Sprachen
     * @return array{blocks: string, body: string}
     */
    private function content(array $blocks, User $actor, int $id, array $keep = []): array
    {
        return $this->app->blocks()->columns($blocks, $actor, RichImageService::OWNER_PAGE, $id, true, $keep);
    }

    /**
     * Fassungen in weiteren Sprachen für das Änderungsprotokoll (Subjects): „Titel (en)“, „Inhalt (en)“.
     *
     * @param array<string, mixed> $p Zeile der Seite
     * @return array<string, string|null>
     */
    public function translationFields(array $p): array
    {
        return $this->app->contentTranslations()->blocksLog(self::KIND, (int) $p['id'], Blocks::decode($p['blocks'] ?? null, (string) ($p['body'] ?? '')));
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
                throw ApiError::invalid(I18n::t('Bitte einen Titel für die Seite angeben.'));
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                throw ApiError::invalid(I18n::t('Der Titel darf höchstens {max} Zeichen lang sein.', ['max' => self::TITLE_MAX]));
            }
            $data['title'] = $title;
        }
        if (array_key_exists('audience', $in)) {
            if (!in_array($in['audience'], self::AUDIENCES, true)) {
                throw ApiError::invalid(I18n::t('Für wen ist die Seite? Erlaubt sind public (alle) und members (Mitglieder).'));
            }
            $data['audience'] = $in['audience'];
        }
        $blocks = $this->app->blocks()->fromInput($in, self::BODY_MAX);
        return [$data, $blocks];
    }
}
