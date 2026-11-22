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
     * Alle Übersetzungen der Arten $kinds in einer Abfrage. Fehlt die Tabelle noch (Migration noch nicht gelaufen), gibt
     * es keine.
     *
     * @param list<string> $kinds
     * @return array<string, array<string, array<string, array<string, string>>>> Sprache => Art => Kennung => Feld => Text
     */
    public function all(array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }
        try {
            $rows = $this->db->select(
                'SELECT kind, ref, field, locale, text FROM {translations} WHERE kind IN (' . implode(', ', array_fill(0, count($kinds), '?')) . ')',
                array_values($kinds),
            );
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['locale']][(string) $r['kind']][(string) $r['ref']][(string) $r['field']] = (string) $r['text'];
        }
        return $out;
    }

    /**
     * Fassungen eines Inhalts (z. B. einer Seite) je Sprache. Fehlt die Tabelle noch, gibt es keine.
     *
     * @return array<string, array<string, string>> Sprache => Feld => Text
     */
    public function forRef(string $kind, string $ref): array
    {
        return $this->forRefs($kind, [$ref])[$ref] ?? [];
    }

    /**
     * Fassungen mehrerer Inhalte einer Art in einer Abfrage.
     *
     * @param list<string> $refs
     * @return array<string, array<string, array<string, string>>> Kennung => Sprache => Feld => Text
     */
    public function forRefs(string $kind, array $refs): array
    {
        if ($refs === []) {
            return [];
        }
        try {
            $rows = $this->db->select(
                'SELECT ref, field, locale, text FROM {translations} WHERE kind = ? AND ref IN (' . implode(', ', array_fill(0, count($refs), '?')) . ')',
                [$kind, ...array_values($refs)],
            );
        } catch (\RuntimeException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['ref']][(string) $r['locale']][(string) $r['field']] = (string) $r['text'];
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
