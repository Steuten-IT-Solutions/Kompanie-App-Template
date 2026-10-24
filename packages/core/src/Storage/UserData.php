<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Alle eigenen Daten einer Person löschen (Issue #24, Löschung nach DSGVO): Punkte, Strafen, Anwesenheit, Dienstgrad mit
 * Verlauf, Titel, Zusatzrollen (Issue #66), Push-Abos samt Warteschlange, Teilnahme und Antworten bei Umfragen
 * (namentliche über user_id, anonyme über das Pseudonym, wenn das Geheimnis der Umfragen mitkommt), Geburtstag,
 * Eintrittsjahr, Foto, Eintrag als ehemaliges Mitglied, wartende Fehlermeldungen mit ihrem Namen (Issue #64). Was sie für andere eingetragen
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
        // Fehlermeldungen (Issue #64) mit Namen, die noch nicht versandt sind; ohne Namen steht dort 0.
        'bug_reports' => 'user_id',
    ];

    /** @param string|null $pollSecret Geheimnis der Umfragen (PollService::SECRET_SETTING) dieser Seite, falls vorhanden */
    public static function purge(Connection $db, int $userId, ?string $pollSecret = null): void
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
            $db->execute('UPDATE {office_holders} SET user_id = NULL WHERE user_id = ?', [$userId]);
            $db->execute('UPDATE {office_terms} SET ended_for = NULL WHERE ended_for = ?', [$userId]);
        } catch (\RuntimeException) {
            $ok = false;
        }
        try {
            $db->execute('UPDATE {list_rows} SET user_id = NULL WHERE user_id = ?', [$userId]);
        } catch (\RuntimeException) {
            $ok = false;
        }
        foreach (self::TABLES as $table => $column) {
            try {
                $db->execute('DELETE FROM {' . $table . '} WHERE ' . $column . ' = ?', [$userId]);
            } catch (\RuntimeException) {
                $ok = false;
            }
        }
        if (!$ok) {
            error_log('Kompanie-App: Nicht alle Tabellen gefunden beim Löschen der Daten von Benutzer ' . $userId . '.');
        }
    }
}
