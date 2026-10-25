<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;

/**
 * Signatur je Konto (Issue #172): formatierter Inhalt mit Text und Bildern, eingerichtet unter Konto → Signatur
 * (GET/PUT/DELETE /me/signature). Allgemeiner Baustein, unabhängig vom Schwarzen Brett: Wer schreibt, fügt sie als
 * Kopie in seinen Inhalt ein (die App, beim Speichern kopiert RichImageService::claim ihre Bilder), spätere Änderungen
 * wirken nicht auf schon geschriebene Inhalte.
 *
 * Einrichten können Mitglieder und Anwärter (alle mit internem Kalender) sowie alle, die am Brett schreiben dürfen.
 * Beim Löschen des Kontos verschwindet sie samt Bildern (UserData::purge).
 */
final class SignatureService
{
    public function __construct(private readonly App $app)
    {
    }

    public static function allowed(?User $user): bool
    {
        return $user !== null && ($user->isMember() || $user->can(Capability::Calendar) || $user->can(Capability::PostBoard));
    }

    /** @return array{content: string, updated_at: string|null}|null eigene Signatur mit Bildadressen */
    public function get(User $user): ?array
    {
        $row = $this->app->db->selectOne('SELECT content, updated_at FROM {user_signatures} WHERE user_id = ?', [$user->id]);
        if ($row === null || (string) $row['content'] === '') {
            return null;
        }
        return ['content' => $this->app->richImages()->render((string) $row['content']), 'updated_at' => Time::utcToIso((string) $row['updated_at'])];
    }

    /** @return array{content: string, updated_at: string|null}|null */
    public function save(User $user, mixed $content): ?array
    {
        if (!is_string($content)) {
            throw ApiError::invalid('Bitte eine Signatur eingeben.');
        }
        $html = RichText::sanitize($content);
        if (RichText::isEmpty($html)) {
            $this->delete($user);
            return null;
        }
        $html = $this->app->richImages()->claim($user, $html, RichImageService::OWNER_SIGNATURE, $user->id, true);
        $now = $this->app->time()->nowUtc();
        $this->app->db->transaction(function () use ($user, $html, $now): void {
            $this->app->db->execute('DELETE FROM {user_signatures} WHERE user_id = ?', [$user->id]);
            $this->app->db->insert('user_signatures', ['user_id' => $user->id, 'content' => $html, 'updated_at' => $now]);
        });
        return $this->get($user);
    }

    public function delete(User $user): void
    {
        $this->app->db->execute('DELETE FROM {user_signatures} WHERE user_id = ?', [$user->id]);
        $this->app->richImages()->deleteOwner(RichImageService::OWNER_SIGNATURE, $user->id);
    }
}
