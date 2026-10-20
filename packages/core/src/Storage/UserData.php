<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Domain\PollService;
use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Alle eigenen Daten einer Person löschen (Issue #24, Löschung nach DSGVO): Punkte, Strafen, Anwesenheit, Dienstgrad mit
 * Verlauf, Titel, Push-Abos samt Warteschlange, Teilnahme und Antworten bei Umfragen (namentliche über user_id, anonyme
 * über das Pseudonym, wenn das Geheimnis der Umfragen mitkommt), Geburtstag. Was sie für andere eingetragen hat (created_by,
 * updated_by), bleibt; ebenso Inventar und Inventar-Verlauf (Issue #6: user_id dort ist nur „geändert von“).
 * Ohne App: WordPress-Multisite löscht damit auch in den Tabellen anderer Seiten des Netzwerks.
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
        'attendance_entries' => 'user_id',
        'poll_votes' => 'user_id',
        'poll_answers' => 'user_id',
        'birthdays' => 'user_id',
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
