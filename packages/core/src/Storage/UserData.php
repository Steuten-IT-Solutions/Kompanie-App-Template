<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Alle eigenen Daten einer Person löschen (Issue #24, Löschung nach DSGVO): Punkte, Strafen, Anwesenheit, Dienstgrad mit
 * Verlauf, Titel, Zusatzrollen (Issue #66), Push-Abos samt Warteschlange, Teilnahme und Antworten bei Umfragen
 * (namentliche über user_id, anonyme über das Pseudonym, wenn das Geheimnis der Umfragen mitkommt), Geburtstag,
 * Eintrittsjahr, Foto, Handynummer, Eintrag als ehemaliges Mitglied, wartende Fehlermeldungen mit ihrem Namen (Issue #64), Einträge der
 * Nutzungsauswertung (Issue #175), Protokollzeilen der E-Mails aus der Verwaltung an sie. Was sie für andere eingetragen
 * hat (created_by, updated_by), bleibt; ebenso Inventar und Inventar-Verlauf (Issue #6: user_id dort ist nur „geändert von“). Amtszeiten (Issue #112) bleiben als Chronik
 * mit dem gespeicherten Namen stehen; nur die Verknüpfung mit dem Konto wird gelöst. Zeilen in Listen (Issue #121) bleiben
 * ohne Verknüpfung und ohne Namen stehen, bis die Liste aktualisiert wird. Ohne App: WordPress-Multisite löscht
 * damit auch in den Tabellen anderer Seiten des Netzwerks.
 */
final class UserData
{
    /** Tabelle => Spalte mit der Person, in einer Reihenfolge, die beim Löschen passt. */
    private const TABLES = [
        'points' => 'user_id',
        'penalties' => 'user_id',
        'rank_history' => 'user_id',
        'member_ranks' => 'user_id',
        'member_titles' => 'user_id',
        'member_roles' => 'user_id',
        'attendance_entries' => 'user_id',
        'poll_votes' => 'user_id',
        'poll_answers' => 'user_id',
        'birthdays' => 'user_id',
        'join_years' => 'user_id',
        'member_photos' => 'user_id',
        // Eintrag als ehemaliges Mitglied (Grund, Eintritt, Ausscheiden)
        'former_members' => 'user_id',
        'member_contacts' => 'user_id',
        // Fehlermeldungen (Issue #64) mit Namen, die noch nicht versandt sind; ohne Namen steht dort 0.
        'bug_reports' => 'user_id',
        // Schwarzes Brett (Issue #172): Signatur, Einstellungen des Kontos, Mails an sie (mit Adresse)
        'user_signatures' => 'user_id',
        'user_settings' => 'user_id',
        'board_mails' => 'user_id',
        // E-Mails aus der Verwaltung: Protokoll der Mails an sie (mit Adresse); die Mails selbst bleiben.
        'mailing_recipients' => 'user_id',
        // Nutzungsauswertung (Issue #175): Einträge je Person und „zuletzt aktiv“ (das Pseudonym löscht ActivityService).
        'activity' => 'user_id',
        'activity_last' => 'user_id',
    ];

    /**
     * Was bei der Aufbewahrungsfrist für Ehemalige bleibt (Chronik, purge mit $keepChronicle): Punkte, Dienstgrad mit
     * Verlauf, Titel, Foto, Eintrittsjahr und der Eintrag als ehemaliges Mitglied; dazu Ämter und Zeilen in Listen mit
     * ihrer Verknüpfung.
     */
    public const CHRONICLE = ['points', 'rank_history', 'member_ranks', 'member_titles', 'member_photos', 'join_years', 'former_members'];

    /**
     * Tabelle => Spalte mit der Person; auch für die Auskunft (Domain\PersonalDataExport), damit Löschen und Auskunft
     * dieselben Tabellen kennen.
     *
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return self::TABLES;
    }

    /**
     * Wer von $userIds hat noch persönliche Angaben (Tabellen außer CHRONICLE, Push-Abos, eigene Bilder, Entwürfe)? Für
     * die Aufbewahrungsfrist: nur bei ihnen erneut löschen. Eine Abfrage je Tabelle, nicht je Person.
     *
     * @param list<int> $userIds
     * @return list<int>
     */
    public static function withPersonalData(Connection $db, array $userIds): array
    {
        $queries = [];
        foreach (self::TABLES as $table => $column) {
            if (!in_array($table, self::CHRONICLE, true)) {
                $queries[] = 'SELECT DISTINCT ' . $column . ' AS id FROM {' . $table . '} WHERE ' . $column . ' IN (%s)';
            }
        }
        // Dieselben Stellen außerhalb von TABLES wie in purge() (dort ändern, dann auch hier)
        $queries[] = 'SELECT DISTINCT user_id AS id FROM {push_subscriptions} WHERE user_id IN (%s)';
        $queries[] = "SELECT DISTINCT owner_id AS id FROM {rich_images} WHERE owner_type IN ('signature', 'draft') AND owner_id IN (%s)";
        $queries[] = "SELECT DISTINCT owner_id AS id FROM {attachments} WHERE owner_type = 'draft' AND owner_id IN (%s)";
        $found = [];
        foreach (array_chunk(array_values(array_unique($userIds)), 500) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($queries as $sql) {
                try {
                    foreach ($db->select(sprintf($sql, $in), $chunk) as $r) {
                        $found[(int) $r['id']] = true;
                    }
                } catch (\RuntimeException) {
                    // Tabelle fehlt: dort ist nichts
                }
            }
        }
        return array_keys($found);
    }

    /**
     * @param string|null $pollSecret Geheimnis der Umfragen (PollService::SECRET_SETTING) dieser Seite, falls vorhanden
     * @param bool $keepChronicle nur die persönlichen Angaben (Aufbewahrungsfrist für Ehemalige): CHRONICLE bleibt
     * @return bool ob alles geklappt hat (sonst steht es im Fehlerprotokoll)
     */
    public static function purge(Connection $db, int $userId, ?string $pollSecret = null, bool $keepChronicle = false): bool
    {
        $ok = true;
        if ($pollSecret !== null) {
            try {
                $db->execute('DELETE FROM {poll_answers} WHERE vote_ref = ?', [PollService::pseudonym($pollSecret, $userId)]);
            } catch (\RuntimeException) {
                $ok = false;
            }
        }
        try {
            $db->execute('DELETE FROM {push_queue} WHERE subscription_id IN (SELECT id FROM {push_subscriptions} WHERE user_id = ?)', [$userId]);
            $db->execute('DELETE FROM {push_subscriptions} WHERE user_id = ?', [$userId]);
        } catch (\RuntimeException) {
            $ok = false; // Tabelle fehlt (ältere Version oder Seite ohne die App)
        }
        try {
            if (!$keepChronicle) {
                $db->execute('UPDATE {office_holders} SET user_id = NULL WHERE user_id = ?', [$userId]);
                $db->execute('UPDATE {office_terms} SET ended_for = NULL WHERE ended_for = ?', [$userId]);
            }
        } catch (\RuntimeException) {
            $ok = false;
        }
        try {
            // Bilder der Signatur und nie gespeicherte Entwürfe; Bilder in Nachrichten gehören der Nachricht.
            $db->execute("DELETE FROM {rich_images} WHERE owner_type IN ('signature', 'draft') AND owner_id = ?", [$userId]);
        } catch (\RuntimeException) {
            $ok = false;
        }
        try {
            if (!$keepChronicle) {
                $db->execute('UPDATE {list_rows} SET user_id = NULL WHERE user_id = ?', [$userId]);
            }
        } catch (\RuntimeException) {
            $ok = false;
        }
        foreach (self::TABLES as $table => $column) {
            if ($keepChronicle && in_array($table, self::CHRONICLE, true)) {
                continue;
            }
            try {
                $db->execute('DELETE FROM {' . $table . '} WHERE ' . $column . ' = ?', [$userId]);
            } catch (\RuntimeException) {
                $ok = false;
            }
        }
        if (!$ok) {
            error_log('Kompanie-App: Nicht alle Tabellen gefunden beim Löschen der Daten von Benutzer ' . $userId . '.');
        }
        return $ok;
    }
}
