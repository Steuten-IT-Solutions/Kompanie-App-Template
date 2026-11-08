<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain\Blocks;

use KompanieApp\Core\Auth\User;

/**
 * Ein vorgefertigter Block (Issue Blöcke): eigene Seiten bestehen aus einer Liste von Blöcken. Jeder Typ prüft seine
 * Felder selbst und bereitet sie für den Lesenden auf. Neue Typen kommen in Blocks::types() dazu, in der App in
 * packages/frontend/src/blocks/registry.tsx.
 */
interface BlockType
{
    /** Name im Feld type, z. B. text oder countdown. */
    public function type(): string;

    /**
     * Prüft die Felder des Blocks (ohne id, type und audience) und gibt nur die bekannten, bereinigten zurück.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     * @throws \KompanieApp\Core\Http\ApiError 400 invalid
     */
    public function validate(array $in): array;

    /**
     * Felder für die Ausgabe an $viewer (null = ohne Anmeldung); null: Block für $viewer weglassen.
     *
     * @param array<string, mixed> $data gespeicherte Felder
     * @return array<string, mixed>|null
     */
    public function render(array $data, ?User $viewer, bool $manager): ?array;

    /**
     * Reiner Text, z. B. für E-Mails und Textauszüge; leer, wenn es nichts zu sagen gibt.
     *
     * @param array<string, mixed> $data
     */
    public function plain(array $data): string;
}
