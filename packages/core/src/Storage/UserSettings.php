<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Einstellungen je Konto (Issue #172), z. B. board_mail = 0: keine Mails vom Schwarzen Brett. Fehlt ein Eintrag, gilt
 * der Standard der aufrufenden Stelle. Beim Löschen des Kontos entfernt (UserData::purge).
 */
final class UserSettings
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function get(int $userId, string $name): ?string
    {
        $v = $this->db->scalar('SELECT value FROM {user_settings} WHERE user_id = ? AND name = ?', [$userId, $name]);
        return $v === null || $v === false ? null : (string) $v;
    }

    public function set(int $userId, string $name, string $value): void
    {
        $this->db->transaction(function () use ($userId, $name, $value): void {
            $this->db->execute('DELETE FROM {user_settings} WHERE user_id = ? AND name = ?', [$userId, $name]);
            $this->db->insert('user_settings', ['user_id' => $userId, 'name' => $name, 'value' => $value]);
        });
    }

    /** @return array<int, string> Benutzer-ID => Wert, für alle Konten mit Eintrag */
    public function all(string $name): array
    {
        $out = [];
        foreach ($this->db->select('SELECT user_id, value FROM {user_settings} WHERE name = ?', [$name]) as $r) {
            $out[(int) $r['user_id']] = (string) $r['value'];
        }
        return $out;
    }
}
