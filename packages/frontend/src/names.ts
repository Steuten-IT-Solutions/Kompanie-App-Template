/**
 * Eigene Namen je weiterer Sprache (Plan „Mehrsprachigkeit“, „Einrichten je Sprache“): Der deutsche Name steht, wo er
 * immer steht; die Übersetzungen kommen mit /config (name_translations, Domain\TranslationService). Fehlt eine, gilt der
 * deutsche Name.
 *
 * Arten: nav (Bereiche, eigene Seiten und „Intern“ in der Navigation, Kennung wie in navigation_names), role (Rollen).
 */
import { locale, SOURCE_LOCALE, type Locale } from './i18n';

export type NameKind = 'nav' | 'role';
/** Sprache => Art => Kennung => Feld => Text */
export type NameTranslations = Partial<Record<Locale, Partial<Record<string, Record<string, Record<string, string>>>>>>;

let current: NameTranslations = {};

/** Übersetzungen setzen (beim Laden aus /config, nach dem Speichern aus der Antwort). Leer kommt als [] an. */
export function setNameTranslations(value: unknown): void {
  current = value && typeof value === 'object' && !Array.isArray(value) ? (value as NameTranslations) : {};
}

/** Eingetragene Übersetzung einer Sprache ('' ohne). */
export function translatedName(kind: NameKind, ref: string, lang: Locale, field = 'label'): string {
  if (lang === SOURCE_LOCALE) return '';
  return current[lang]?.[kind]?.[ref]?.[field] ?? '';
}

/** Name in der Sprache der App: Übersetzung, sonst der deutsche. */
export function nameIn(kind: NameKind, ref: string, german: string, field = 'label', lang: Locale = locale()): string {
  return translatedName(kind, ref, lang, field) || german;
}
