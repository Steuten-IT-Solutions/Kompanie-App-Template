<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Namen je weiterer Sprache (Plan „Mehrsprachigkeit“, Domain\TranslationService), Tabelle translations: eine Zeile je
 * Art (z. B. „nav“), Kennung, Feld und Sprache. Der deutsche Name bleibt, wo er ist; hier steht nur die Übersetzung.
 */
final class TranslationRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Alle Übersetzungen in einer Abfrage. Fehlt die Tabelle noch (Migration noch nicht gelaufen), gibt es keine.
     *
     * @return array<string, array<string, array<string, array<string, string>>>> Sprache => Art => Kennung => Feld => Text
     */
    public function all(): array
    {
        try {
            $rows = $this->db->select('SELECT kind, ref, field, locale, text FROM {translations}');
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['locale']][(string) $r['kind']][(string) $r['ref']][(string) $r['field']] = (string) $r['text'];
        }
        return $out;
    }

    /** Setzen (anlegen oder ändern). */
    public function save(string $kind, string $ref, string $field, string $locale, string $text, int $by, string $now): void
    {
        $where = ['kind' => $kind, 'ref' => $ref, 'field' => $field, 'locale' => $locale];
        $values = ['text' => $text, 'updated_by' => $by, 'updated_at' => $now];
        $this->db->transaction(function () use ($where, $values): void {
            if ($this->db->update('translations', $values, $where) === 0
                && $this->db->scalar('SELECT id FROM {translations} WHERE kind = ? AND ref = ? AND field = ? AND locale = ?', array_values($where)) === null) {
                $this->db->insert('translations', $values + $where);
            }
        });
    }

    /** Eine Übersetzung entfernen (dann gilt wieder der deutsche Name). */
    public function delete(string $kind, string $ref, string $field, string $locale): void
    {
        $this->db->execute('DELETE FROM {translations} WHERE kind = ? AND ref = ? AND field = ? AND locale = ?', [$kind, $ref, $field, $locale]);
    }

    /** Alle Übersetzungen einer Kennung entfernen (z. B. eine Rolle wurde gelöscht). */
    public function deleteRef(string $kind, string $ref): void
    {
        $this->db->execute('DELETE FROM {translations} WHERE kind = ? AND ref = ?', [$kind, $ref]);
    }

    /** Kennung umbenennen (z. B. wenn die Kennung der deutsche Name ist und dieser sich ändert). */
    public function renameRef(string $kind, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        $this->db->execute('DELETE FROM {translations} WHERE kind = ? AND ref = ?', [$kind, $to]);
        $this->db->execute('UPDATE {translations} SET ref = ? WHERE kind = ? AND ref = ?', [$to, $kind, $from]);
    }
}
