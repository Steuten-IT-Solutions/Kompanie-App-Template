<?php

declare(strict_types=1);

namespace KompanieApp\Core\Storage;

use KompanieApp\Core\Storage\Sql\Connection;

/**
 * Kontaktangaben eines Kontos, die es in WordPress und der eigenständigen Benutzertabelle nicht gibt: die Handynummer.
 * In der Datenbank der App, damit UserData::purge sie mit dem Konto löscht und die Sicherung sie enthält.
 */
final class MemberContactRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Handynummer oder '' */
    public function phone(int $userId): string
    {
        return (string) ($this->db->scalar('SELECT phone FROM {member_contacts} WHERE user_id = ?', [$userId]) ?? '');
    }

    /** Leer entfernt den Eintrag. */
    public function setPhone(int $userId, string $phone, string $now): void
    {
        $this->db->transaction(function () use ($userId, $phone, $now): void {
            $this->db->execute('DELETE FROM {member_contacts} WHERE user_id = ?', [$userId]);
            if ($phone !== '') {
                $this->db->insert('member_contacts', ['user_id' => $userId, 'phone' => $phone, 'updated_at' => $now]);
            }
        });
    }
}
