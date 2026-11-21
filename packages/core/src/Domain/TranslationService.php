<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Storage\TranslationRepository;

/**
 * Eigene Namen je weiterer Sprache (Plan „Mehrsprachigkeit“, „Einrichten je Sprache“).
 *
 * Der deutsche Name bleibt die Hauptfassung, wo er heute steht (Einstellungen, Tabellen). Für jede weitere eingeschaltete
 * Sprache kann der Admin einen Namen dazu eintragen; fehlt er, erscheint der deutsche. Die App wählt oben in Verwaltung
 * und Einstellungen, auf welcher Sprache sie einrichtet („Einrichten auf“), und schreibt die Namen dieser Sprache über
 * PUT /admin/translations.
 *
 * Arten (KINDS): Kennung und wer sie pflegen darf.
 * - nav: Name eines Bereichs, einer eigenen Seite oder von „Intern“ in der Navigation (Kennung wie in navigation_names),
 * - role: Name einer Rolle (Kennung der Rolle),
 * - rank: Bezeichnung eines Dienstgrads (ID), office und office_group: Amt bzw. Gruppe der Ämter (ID),
 * - event_kind: Terminart (Kennung ist ihr deutscher Name, wie er an Terminen steht),
 * - menu: Untermenü der Navigation (Kennung ist sein deutscher Name).
 */
final class TranslationService
{
    /** Art => [Recht zum Pflegen, erlaubte Felder, längster Text] */
    public const KINDS = [
        'nav' => [Capability::ManageSettings, ['label'], NavigationService::LABEL_MAX],
        'role' => [Capability::ManageSettings, ['label'], RoleService::LABEL_MAX],
        'rank' => [Capability::ManageRanks, ['label'], RankService::TITLE_MAX],
        'office' => [Capability::ManageOffices, ['label'], OfficeService::TITLE_MAX],
        'office_group' => [Capability::ManageOffices, ['label'], OfficeService::TITLE_MAX],
        'event_kind' => [Capability::ManageSettings, ['label'], EventKindService::NAME_MAX],
        'menu' => [Capability::ManageSettings, ['label'], NavigationService::LABEL_MAX],
    ];

    private ?TranslationRepository $repo = null;

    /**
     * Rechte, von denen eines für PUT /admin/translations reicht (welche Art jemand pflegen darf, prüft save()).
     *
     * @return list<Capability>
     */
    public static function capabilities(): array
    {
        $out = [];
        foreach (self::KINDS as [$capability]) {
            if (!in_array($capability, $out, true)) {
                $out[] = $capability;
            }
        }
        return $out;
    }

    /** @var array<string, array<string, array<string, array<string, string>>>>|null */
    private ?array $all = null;

    public function __construct(private readonly App $app)
    {
    }

    public function repository(): TranslationRepository
    {
        return $this->repo ??= new TranslationRepository($this->app->db);
    }

    /**
     * Übersetzungen der eingeschalteten weiteren Sprachen (für /config): Sprache => Art => Kennung => Feld => Text. Ohne
     * weitere Sprache leer.
     *
     * @return array<string, array<string, array<string, array<string, string>>>>
     */
    public function all(): array
    {
        $enabled = array_diff($this->app->languages()->enabled(), [I18n::SOURCE_LOCALE]);
        if ($enabled === []) {
            return [];
        }
        $this->all ??= $this->repository()->all(array_keys(self::KINDS));
        return array_intersect_key($this->all, array_flip($enabled));
    }

    /** Übersetzung in der Sprache der Anfrage, null ohne (dann gilt der deutsche Name). */
    public function text(string $kind, string $ref, string $field = 'label', ?string $locale = null): ?string
    {
        $locale ??= I18n::locale();
        if ($locale === I18n::SOURCE_LOCALE) {
            return null;
        }
        $text = $this->all()[$locale][$kind][$ref][$field] ?? null;
        return is_string($text) && $text !== '' ? $text : null;
    }

    /** Name in der Sprache der Anfrage: Übersetzung, sonst der deutsche. */
    public function name(string $kind, string $ref, string $german, string $field = 'label'): string
    {
        return $this->text($kind, $ref, $field) ?? $german;
    }

    /**
     * Namen einer Sprache speichern. Jeder Eintrag: kind, ref, field (Vorgabe label), text ('' löscht die Übersetzung).
     * Jede Art verlangt ihr eigenes Recht.
     *
     * @return array<string, array<string, array<string, array<string, string>>>> alle Übersetzungen danach (wie all())
     */
    public function save(User $user, mixed $locale, mixed $items): array
    {
        if (!is_string($locale) || !I18n::isLocale($locale) || $locale === I18n::SOURCE_LOCALE) {
            throw ApiError::invalid(I18n::t('Übersetzungen gibt es nur für weitere Sprachen, nicht für Deutsch.'));
        }
        if (!in_array($locale, $this->app->languages()->enabled(), true)) {
            throw ApiError::invalid(I18n::t('Diese Sprache ist in der App nicht eingeschaltet.'));
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $clean = [];
        foreach ($items as $item) {
            $clean[] = $this->valid($user, $item);
        }
        $now = $this->app->time()->nowUtc();
        $repo = $this->repository();
        $this->app->db->transaction(function () use ($clean, $repo, $locale, $user, $now): void {
            foreach ($clean as [$kind, $ref, $field, $text]) {
                $text === ''
                    ? $repo->delete($kind, $ref, $field, $locale)
                    : $repo->save($kind, $ref, $field, $locale, $text, $user->id, $now);
            }
        });
        $this->all = null;
        return $this->all();
    }

    /**
     * Übersetzungen für das Änderungsprotokoll, eine Zeile je Name („en nav termine label“ => „Dates“).
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->repository()->all(array_keys(self::KINDS)) as $locale => $kinds) {
            foreach ($kinds as $kind => $refs) {
                foreach ($refs as $ref => $fields) {
                    foreach ($fields as $field => $text) {
                        $out["$locale $kind $ref $field"] = $text;
                    }
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} Art, Kennung, Feld, Text */
    private function valid(User $user, mixed $item): array
    {
        if (!is_array($item)) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $kind = $item['kind'] ?? null;
        if (!is_string($kind) || !isset(self::KINDS[$kind])) {
            throw ApiError::invalid(I18n::t('Unbekannte Art einer Übersetzung.'));
        }
        [$capability, $fields, $max] = self::KINDS[$kind];
        if (!$user->can($capability)) {
            throw ApiError::forbidden(I18n::t('Diese Namen darfst du nicht übersetzen.'));
        }
        $ref = $item['ref'] ?? null;
        if (!is_string($ref) || trim($ref) === '' || mb_strlen($ref) > 100) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $field = $item['field'] ?? 'label';
        if (!is_string($field) || !in_array($field, $fields, true)) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $text = $item['text'] ?? '';
        if (!is_string($text)) {
            throw ApiError::invalid(I18n::t('Ungültige Übersetzungen.'));
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $max) {
            throw ApiError::invalid(I18n::t('Ein Name darf höchstens {max} Zeichen lang sein („{name}“).', ['max' => $max, 'name' => $text]));
        }
        return [$kind, $ref, $field, $text];
    }
}
