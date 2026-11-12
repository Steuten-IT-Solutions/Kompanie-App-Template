<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\App;
use KompanieApp\Core\ChangeLog\Subject;

/**
 * Änderungsprotokoll für die Benutzerverwaltung des WP-Admin (Benutzer anlegen, bearbeiten, Rolle ändern, löschen): Stand
 * der betroffenen Mitglieder beim Laden der Seite (load-…), Vergleich am Ende der Anfrage (shutdown), wie in der App mit
 * dem angemeldeten Konto. Änderungen über die App (REST) erfasst die App selbst; diese Seiten laufen nicht über sie.
 */
final class UserAdminLog
{
    /** @var array{subject: Subject, user: \KompanieApp\Core\Auth\User|string, before: array<string, \KompanieApp\Core\ChangeLog\Item|null>, all: list<string>|null, method: string, path: string, area: string, request: \KompanieApp\Core\Http\Request}|null Stand vorher (Http\Api::beginTrack) */
    private static ?array $state = null;

    public static function init(): void
    {
        foreach (['user-edit.php', 'profile.php', 'users.php', 'user-new.php'] as $page) {
            add_action('load-' . $page, static fn () => self::begin(Plugin::app(), $page));
        }
        // Nach dem Weiterleiten (exit) läuft shutdown noch.
        add_action('shutdown', static fn () => self::finish(Plugin::app()));
    }

    /**
     * IDs der Konten, die eine Seite der Benutzerverwaltung ändern kann; null, wenn sie nichts ändert.
     *
     * @param array<string, mixed> $request $_REQUEST
     * @return list<string>|null
     */
    public static function ids(string $page, string $method, array $request, int $currentUser): ?array
    {
        $ids = [];
        foreach (['users', 'user', 'user_id'] as $key) {
            $v = $request[$key] ?? null;
            foreach (is_array($v) ? $v : [$v] as $id) {
                if (is_scalar($id) && (int) $id > 0) {
                    $ids[] = (string) (int) $id;
                }
            }
        }
        if ($page === 'profile.php') {
            $ids = [(string) $currentUser];
        }
        // Ohne POST ändern nur Aktionen der Liste etwas (Rolle ändern, entfernen); die Seite zum Bestätigen des Löschens nicht.
        if ($method !== 'POST' && !($page === 'users.php' && $ids !== [] && isset($request['action']) && $request['action'] !== 'delete')) {
            return null;
        }
        return array_values(array_unique($ids));
    }

    private static function begin(App $app, string $page): void
    {
        try {
            $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
            $ids = self::ids($page, $method, wp_unslash($_REQUEST), get_current_user_id()); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur gelesen
            if ($ids === null) {
                return;
            }
            $subjects = $app->api()->changeSubjects();
            $members = $subjects->for('POST', '/users');
            if ($members === null) {
                return;
            }
            // Bestimmte Konten (bearbeiten, löschen) und neue (Vergleich aller IDs)
            $subject = new Subject($members->load, static fn (): array => $ids, $members->all);
            self::$state = $app->api()->beginTrack($subject, null);
        } catch (\Throwable $e) {
            error_log('Kompanie-App: Stand für das Änderungsprotokoll nicht gelesen: ' . $e->getMessage());
        }
    }

    private static function finish(App $app): void
    {
        if (self::$state === null) {
            return;
        }
        $state = self::$state;
        self::$state = null;
        $app->api()->finishTrack($state);
    }
}
