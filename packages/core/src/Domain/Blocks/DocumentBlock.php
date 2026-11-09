<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Http\ApiError;

/**
 * Dokument zum Herunterladen aus dem Bereich Dokumente. Nur für alle, die das Dokument dort sehen dürfen (view_documents,
 * bei „nur Pflege“ manage_documents); ohne Bereich Dokumente, für andere und bei gelöschtem Dokument fällt der Block weg
 * (die Pflege sieht ihn mit document null). Die Datei selbst prüft die Rechte beim Abruf noch einmal.
 */
final class DocumentBlock implements BlockType
{
    public const TYPE = 'document';

    public function __construct(private readonly App $app)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function validate(array $in): array
    {
        $id = Input::integer($in['document_id'] ?? null);
        if ($id === null || $id <= 0 || $this->app->documents()->repository()->find($id) === null) {
            throw ApiError::invalid('Bitte ein Dokument auswählen.');
        }
        return ['document_id' => $id];
    }

    public function render(array $data, ?User $viewer, bool $manager): ?array
    {
        $doc = $this->document($data, $viewer);
        if ($doc === null && !$manager) {
            return null;
        }
        return ['document_id' => (int) ($data['document_id'] ?? 0), 'document' => $doc];
    }

    /** „Dokument: Titel (PDF) – Adresse“. */
    public function plain(array $data, ?User $viewer = null): string
    {
        $doc = $this->document($data, $viewer);
        if ($doc === null) {
            return '';
        }
        return 'Dokument: ' . $doc['title'] . ' (' . strtoupper($doc['ext']) . ') – ' . $doc['url'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: int, title: string, ext: string, kind: string, size: int, date: string, url: string}|null
     */
    private function document(array $data, ?User $viewer): ?array
    {
        $d = $this->app->documents()->visible($viewer, (int) ($data['document_id'] ?? 0));
        if ($d === null) {
            return null;
        }
        return ['id' => (int) $d['id'], 'title' => (string) $d['title'], 'ext' => (string) $d['ext'], 'kind' => (string) $d['kind'], 'size' => (int) $d['size'], 'date' => (string) $d['date'], 'url' => (string) $d['url']];
    }
}
