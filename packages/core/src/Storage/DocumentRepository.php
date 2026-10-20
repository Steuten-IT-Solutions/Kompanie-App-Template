<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Dokumente und ihre Kategorien (Issue #2).
 *
 * @phpstan-type Document array{id: int, title: string, category_id: int|null, doc_date: string, visibility: string,
 *     file: string, ext: string, size: int, version: string, uploaded_by: int, created_at: string, updated_at: string}
 * @phpstan-type Category array{id: int, title: string, sort: int}
 */
final class DocumentRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<Document> nach Titel (feste Reihenfolge; sortiert wird in der App) */
    public function all(): array
    {
        return array_values(array_map(self::document(...), $this->db->select('SELECT * FROM {documents} ORDER BY title ASC, id ASC')));
    }

    /** @return Document|null */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {documents} WHERE id = ?', [$id]);
        return $row === null ? null : self::document($row);
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        return $this->db->insert('documents', $row);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('documents', $data, ['id' => $id]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {documents} WHERE id = ?', [$id]);
    }

    /** @return list<Category> in der festgelegten Reihenfolge */
    public function categories(): array
    {
        return array_values(array_map(self::categoryRow(...), $this->db->select('SELECT * FROM {doc_categories} ORDER BY sort ASC, id ASC')));
    }

    /** @return Category|null */
    public function category(int $id): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM {doc_categories} WHERE id = ?', [$id]);
        return $row === null ? null : self::categoryRow($row);
    }

    public function insertCategory(string $title, int $sort): int
    {
        return $this->db->insert('doc_categories', ['title' => $title, 'sort' => $sort]);
    }

    /** @param array{title?: string, sort?: int} $data */
    public function updateCategory(int $id, array $data): void
    {
        if ($data !== []) {
            $this->db->update('doc_categories', $data, ['id' => $id]);
        }
    }

    /** Kategorie löschen; ihre Dokumente stehen danach ohne Kategorie da. */
    public function deleteCategory(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('UPDATE {documents} SET category_id = NULL WHERE category_id = ?', [$id]);
            $this->db->execute('DELETE FROM {doc_categories} WHERE id = ?', [$id]);
        });
    }

    public function maxCategorySort(): int
    {
        return (int) $this->db->scalar('SELECT COALESCE(MAX(sort), 0) FROM {doc_categories}');
    }

    /**
     * @param array<string, mixed> $r
     * @return Document
     */
    private static function document(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'],
            'doc_date' => substr((string) $r['doc_date'], 0, 10),
            'visibility' => (string) $r['visibility'],
            'file' => (string) $r['file'],
            'ext' => (string) $r['ext'],
            'size' => (int) $r['size'],
            'version' => (string) $r['version'],
            'uploaded_by' => (int) $r['uploaded_by'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return Category
     */
    private static function categoryRow(array $r): array
    {
        return ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'sort' => (int) $r['sort']];
    }
}
