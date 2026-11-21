/**
 * Übersetzungen der Oberfläche (Plan „Mehrsprachigkeit“).
 *
 * Deutsch ist die Ausgangssprache: Der deutsche Text ist der Schlüssel (`t('Speichern')`) und braucht keinen Katalog.
 * Jede weitere Sprache hat einen Katalog in packages/core/src/I18n/<sprache>.json, gemeinsam mit dem PHP-Kern
 * (I18n.php). Fehlt ein Eintrag, erscheint der deutsche Text. scripts/check-i18n.php sammelt alle Aufrufe und meldet
 * fehlende oder nicht mehr genutzte Einträge; deshalb sind das erste Argument von t() und die ersten beiden von tn()
 * immer einfache Zeichenketten.
 *
 * Die Kataloge sind mitgebündelt (nicht nachgeladen): So sieht auch Code auf Modulebene die richtige Sprache, solange
 * sie vor dem Start der App feststeht. Ein Wechsel der Sprache lädt die Seite neu.
 */
import en from '../../core/src/I18n/en.json' with { type: 'json' };

export const LOCALES = ['de', 'en'] as const;
export type Locale = (typeof LOCALES)[number];
export const SOURCE_LOCALE: Locale = 'de';

/** Eintrag im Katalog: ein Text oder die Formen für eins und mehrere (tn). */
export type Entry = string | { one: string; other: string };
export type Catalog = Readonly<Record<string, Entry>>;
export type Params = Readonly<Record<string, string | number>>;

const CATALOGS: Readonly<Record<Locale, Catalog>> = { de: {}, en: en as Catalog };

/** Sprachkennung für Intl (Datum, Zahlen): Englisch in britischer Schreibweise und Form. */
const TAGS: Readonly<Record<Locale, string>> = { de: 'de-DE', en: 'en-GB' };

let current: Locale = SOURCE_LOCALE;

export const isLocale = (value: unknown): value is Locale => typeof value === 'string' && (LOCALES as readonly string[]).includes(value);

/** Aktuelle Sprache der Oberfläche. */
export const locale = (): Locale => current;

/** Sprachkennung der aktuellen Sprache für Intl, z. B. „de-DE“. */
export const localeTag = (): string => TAGS[current];

/** Setzt die Sprache und das Attribut lang der Seite (Silbentrennung, Vorlesen). */
export function setLocale(next: Locale, doc: { documentElement: { lang: string } } | undefined = globalThis.document): void {
  current = next;
  if (doc) doc.documentElement.lang = next;
}

/** Ersetzt {name} durch params.name; unbekannte Platzhalter bleiben stehen. */
export function fill(text: string, params?: Params): string {
  if (!params) return text;
  return text.replace(/\{(\w+)\}/g, (all, name: string) => (name in params ? String(params[name]) : all));
}

/** Text in der aktuellen Sprache; `source` ist der deutsche Text. */
export function t(source: string, params?: Params, catalog: Catalog = CATALOGS[current]): string {
  const entry = catalog[source];
  return fill(typeof entry === 'string' && entry !== '' ? entry : source, params);
}

/**
 * Text mit Anzahl: `tn('{count} Termin', '{count} Termine', n)`. Schlüssel ist die deutsche Mehrzahl; die Anzahl
 * steht als {count} bereit (im Zahlenformat der Sprache).
 */
export function tn(
  one: string,
  other: string,
  count: number,
  params?: Params,
  catalog: Catalog = CATALOGS[current],
  lang: Locale = current,
): string {
  const values = { count: count.toLocaleString(TAGS[lang]), ...params };
  const form = new Intl.PluralRules(TAGS[lang]).select(count) === 'one' ? 'one' : 'other';
  const entry = catalog[other];
  if (entry !== undefined && typeof entry !== 'string' && entry[form] !== '') return fill(entry[form], values);
  return fill(form === 'one' ? one : other, values);
}
