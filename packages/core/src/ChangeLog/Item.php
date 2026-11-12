<?php

declare(strict_types=1);

namespace KompanieApp\Core\ChangeLog;

/**
 * Stand eines Eintrags für das Änderungsprotokoll (Domain\ChangeLogService): Art (z. B. „Mitglied“), ID, Bezeichnung und
 * die Felder, die sich vergleichen lassen, als Anzeigename => Wert. Persönliche Angaben (personal) zeigt die Ansicht nur
 * mit view_personal; geheime Felder (secret) werden verglichen, ihr Wert aber nie gespeichert.
 */
final class Item
{
    /**
     * @param array<string, mixed> $fields Anzeigename => Wert (Text, Zahl, ja/nein, Liste oder null)
     * @param list<string> $personal Anzeigenamen der Felder mit persönlichen Angaben
     * @param list<string> $secret Anzeigenamen der Felder, deren Werte nie ins Protokoll kommen
     * @param int|null $subject Konto, um das es geht (Mitglied), für Löschen und Auskunft
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $label,
        public readonly array $fields = [],
        public readonly array $personal = [],
        public readonly array $secret = [],
        public readonly ?int $subject = null,
    ) {
    }
}
