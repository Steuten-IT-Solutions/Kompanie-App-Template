<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Port\NewsSource;

/**
 * News, die in der App geschrieben wurden (Quelle „app“, NewsService), neueste zuerst. Datum ist der Zeitpunkt des
 * Anlegens; Vorschaubild ist das Titelbild (cover, Issue #351), sonst das erste Bild im Text. Einen Link auf eine Webseite gibt es nicht (link leer).
 */
final class AppNewsSource implements NewsSource
{
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
        $rows = $this->app->db->select('SELECT id, title, body, cover, created_at FROM {app_news} ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        return ['items' => array_map([$this, 'format'], $rows), 'totalPages' => (int) ceil($total / $perPage)];
    }

    public function item(string $id): ?array
    {
        $row = ctype_digit($id) ? $this->row((int) $id) : null;
        if ($row === null) {
            return null;
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
