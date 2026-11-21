<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Port\Settings;
use KompanieApp\Core\Storage\UserSettings;

/**
 * Sprachen der App (Plan „Mehrsprachigkeit“).
 *
 * - Welche Sprachen die App anbietet, wählt der Admin (Einstellung „languages“); Deutsch ist immer dabei und Vorgabe.
 *   Solange nur Deutsch an ist, zeigt die App keinen Umschalter.
 * - Die Wahl einer Person steht an ihrem Konto (user_settings „locale“) und gilt auf allen Geräten; Mails und Push
 *   gehen in dieser Sprache raus.
 * - Je Anfrage schickt die App ihre Sprache mit (Header X-Kompanie-Lang); ohne den Header gilt die Wahl am Konto,
 *   sonst Deutsch. Was der Admin nicht eingeschaltet hat, gilt nicht.
 */
final class LanguageService
{
    public const SETTING = 'languages';
    public const USER_SETTING = 'locale';

    public function __construct(private readonly Settings $settings, private readonly UserSettings $userSettings)
    {
    }

    /** @return list<string> eingeschaltete Sprachen, Deutsch zuerst */
    public function enabled(): array
    {
        $stored = $this->settings->get(self::SETTING, '');
        $list = is_string($stored) ? explode(',', $stored) : [];
        return array_values(array_unique(array_merge([I18n::SOURCE_LOCALE], array_filter($list, I18n::isLocale(...)))));
    }

    /**
     * Einstellung prüfen (für SettingsService::update, nur wenn mitgeschickt): Sprachen durch Komma getrennt.
     *
     * @return array<string, string> Einstellung => Wert
     */
    public static function validEnabled(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_string($value)) {
            throw ApiError::invalid(I18n::t('Ungültiger Wert für die Sprachen.'));
        }
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $s): bool => $s !== '')));
        foreach ($list as $locale) {
            if (!I18n::isLocale($locale)) {
                throw ApiError::invalid(I18n::t('Unbekannte Sprache: {locale}', ['locale' => $locale]));
            }
        }
        $list = array_values(array_unique(array_merge([I18n::SOURCE_LOCALE], $list)));
        return [self::SETTING => implode(',', $list)];
    }

    /** Gewählte Sprache am Konto; null = nichts gewählt (dann gilt die Wahl am Gerät bzw. die Vorgabe). */
    public function ofUser(int $userId): ?string
    {
        $value = $this->userSettings->get($userId, self::USER_SETTING);
        return I18n::isLocale($value) ? $value : null;
    }

    /** Sprache, in der jemand Mails und Push bekommt: die am Konto, wenn eingeschaltet, sonst Deutsch. */
    public function forRecipient(int $userId): string
    {
        $locale = $this->ofUser($userId);
        return $locale !== null && in_array($locale, $this->enabled(), true) ? $locale : I18n::SOURCE_LOCALE;
    }

    /** Sprache am Konto speichern; null oder '' löscht die Wahl. */
    public function saveForUser(User $user, mixed $locale): ?string
    {
        if ($locale === null || $locale === '') {
            $this->userSettings->delete($user->id, self::USER_SETTING);
            return null;
        }
        if (!I18n::isLocale($locale)) {
            throw ApiError::invalid(I18n::t('Unbekannte Sprache.'));
        }
        if (!in_array($locale, $this->enabled(), true)) {
            throw ApiError::invalid(I18n::t('Diese Sprache ist in der App nicht eingeschaltet.'));
        }
        $this->userSettings->set($user->id, self::USER_SETTING, $locale);
        return $locale;
    }

    /** Sprache einer Anfrage: Header der App, sonst die am Konto, sonst Deutsch – nur eingeschaltete. */
    public function resolve(?string $requested, ?User $user): string
    {
        $enabled = $this->enabled();
        // Nur Deutsch an (Vorgabe): keine Abfrage der Wahl am Konto
        if (count($enabled) === 1) {
            return I18n::SOURCE_LOCALE;
        }
        foreach ([$requested, $user !== null && $user->id > 0 ? $this->ofUser($user->id) : null] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $enabled, true)) {
                return $candidate;
            }
        }
        return I18n::SOURCE_LOCALE;
    }
}
