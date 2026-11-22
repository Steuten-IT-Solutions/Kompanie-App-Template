<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\Blocks\Blocks;
use KompanieApp\Core\Port\NewsSource;

/**
 * News, die in der App geschrieben wurden (Quelle „app“, NewsService), neueste zuerst. Datum ist der Zeitpunkt des
 * Anlegens; Vorschaubild ist das Titelbild (cover, Issue #351), sonst das erste Bild im Text. Einen Link auf eine Webseite gibt es nicht (link leer).
 * In einer weiteren Sprache (Plan „Mehrsprachigkeit“, ContentTranslations, Art news) Titel und Text dieser Fassung, was
 * fehlt, auf Deutsch.
 */
final class AppNewsSource implements NewsSource
{
    /** Art in translations */
    public const KIND = 'news';

    public function __construct(private readonly App $app)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function page(int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $offset = (max(1, $page) - 1) * $perPage;
        $total = (int) $this->app->db->scalar('SELECT COUNT(*) FROM {app_news}');
        $rows = $this->app->db->select('SELECT id, title, body, blocks, cover, created_at FROM {app_news} ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $content = $this->app->contentTranslations();
        $current = $content->current();
        $all = $current !== null ? $content->ofMany(self::KIND, array_map(static fn (array $r): int => (int) $r['id'], $rows)) : [];
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format($current !== null ? $this->localized($row, $all[(int) $row['id']][$current] ?? [])[0] : $row);
        }
        return ['items' => $items, 'totalPages' => (int) ceil($total / $perPage)];
    }

    /**
     * Zeile in einer Fassung: Titel und body (Text der Textblöcke für alle) der Fassung, blocks bleibt die gespeicherte
     * Spalte. Dazu die Blöcke der Fassung und übersetzbare und übersetzte Felder.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $fields
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: int, 3: int}
     */
    public function localized(array $row, array $fields): array
    {
        [$title, $blocks, $total, $done] = ContentTranslations::blocksIn((string) $row['title'], Blocks::decode($row['blocks'] ?? null, (string) ($row['body'] ?? '')), $fields);
        return [['title' => $title, 'body' => Blocks::publicBody($blocks)] + $row, $blocks, $total, $done];
    }

    /** Titel eines Beitrags in $locale (für Benachrichtigungen in der Sprache des Empfängers); null ohne Fassung. */
    public function titleIn(string $id, string $locale): ?string
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $title = $this->app->contentTranslations()->of(self::KIND, (int) $id)[$locale]['title'] ?? '';
        return $title !== '' ? $title : null;
    }

    public function item(string $id): ?array
    {
        $row = ctype_digit($id) ? $this->row((int) $id) : null;
        if ($row === null) {
            return null;
        }
        $current = $this->app->contentTranslations()->current();
        if ($current !== null) {
            $row = $this->localized($row, $this->app->contentTranslations()->of(self::KIND, (int) $id)[$current] ?? [])[0];
        }
        $body = (string) ($row['body'] ?? '');
        $cover = self::cover($row);
        return $this->format($row) + [
            'content' => $body === '' ? '' : $this->app->richImages()->render($body),
            // Titelbild groß oben im Beitrag; cover_uid zum Bearbeiten (nur die Kennung, kein Inhalt)
            'cover' => $cover !== null ? $this->app->env->richImageUrl($cover) : null,
            'cover_uid' => $cover,
        ];
    }

    /** @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        return $this->app->db->selectOne('SELECT * FROM {app_news} WHERE id = ?', [$id]);
    }

    /**
     * Stand für die Pflege: wann und von wem zuletzt geändert.
     *
     * @return array{updated_at?: string|null, updated_by_name?: string|null}
     */
    public function meta(int $id): array
    {
        $row = $this->row($id);
        if ($row === null) {
            return [];
        }
        $by = $row['updated_by'] !== null ? $this->app->users->find((int) $row['updated_by']) : null;
        return ['updated_at' => Time::utcToIso((string) $row['updated_at']), 'updated_by_name' => $by?->name];
    }

    /** @param array<string, mixed> $row */
    public static function cover(array $row): ?string
    {
        $uid = $row['cover'] ?? null;
        return is_string($uid) && preg_match('/^[a-f0-9]{32}$/', $uid) === 1 ? $uid : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $body = (string) ($row['body'] ?? '');
        $cover = self::cover($row);
        $uids = $cover !== null ? [$cover] : RichText::imageUids($body);
        $text = Text::line(Text::fromHtml($body));
        return [
            'id' => (string) (int) $row['id'],
            'title' => (string) $row['title'],
            'date' => Time::utcToIso((string) $row['created_at']) ?? '',
            'excerpt' => mb_strlen($text) > 280 ? rtrim(mb_substr($text, 0, 277)) . ' …' : $text,
            'image' => $uids !== [] ? $this->app->env->richImageUrl($uids[0]) : null,
            'link' => '',
        ];
    }
}
