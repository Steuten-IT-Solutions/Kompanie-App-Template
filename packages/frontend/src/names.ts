/**
 * Eigene Namen je weiterer Sprache (Plan „Mehrsprachigkeit“, „Einrichten je Sprache“): Der Name in der Basissprache
 * steht, wo er immer steht; die Übersetzungen kommen mit /config (name_translations, Domain\TranslationService). Fehlt
 * eine, gilt der Name in der Basissprache.
 *
 * Arten (wie TranslationService::KINDS): nav (Bereiche, eigene Seiten und „Intern“ in der Navigation, Kennung wie in
 * navigation_names), role (Rollen), rank (Dienstgrade, ID), office und office_group (Ämter, ID), event_kind (Terminarten,
 * Kennung = deutscher Name), menu (Untermenüs, Kennung = deutscher Name).
 */
import { baseLocale, locale, type Locale } from './i18n';

export type NameKind = 'nav' | 'role' | 'rank' | 'office' | 'office_group' | 'event_kind' | 'menu';
/** Sprache => Art => Kennung => Feld => Text */
export type NameTranslations = Partial<Record<Locale, Partial<Record<string, Record<string, Record<string, string>>>>>>;

let current: NameTranslations = {};

/** Übersetzungen setzen (beim Laden aus /config, nach dem Speichern aus der Antwort). Leer kommt als [] an. */
export function setNameTranslations(value: unknown): void {
  current = value && typeof value === 'object' && !Array.isArray(value) ? (value as NameTranslations) : {};
}

/** Eingetragene Übersetzung einer Sprache ('' ohne). */
export function translatedName(kind: NameKind, ref: string, lang: Locale, field = 'label'): string {
  if (lang === baseLocale()) return '';
  return current[lang]?.[kind]?.[ref]?.[field] ?? '';
}

/** Name in der Sprache der App: Übersetzung, sonst der deutsche. */
export function nameIn(kind: NameKind, ref: string, german: string, field = 'label', lang: Locale = locale()): string {
  return translatedName(kind, ref, lang, field) || german;
}

/** Name einer Terminart in der Sprache der App (Kennung ist ihr deutscher Name, wie er an Terminen steht). */
export const kindName = (german: string, lang: Locale = locale()): string => nameIn('event_kind', german, german, 'label', lang);
