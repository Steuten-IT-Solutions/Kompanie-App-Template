/**
 * Bezeichnungen der Kompanie in der Sprache der App (Plan „Mehrsprachigkeit“, PR 9).
 *
 * Vorgaben werden übersetzt, eigene Namen nicht: Heißt ein Bereich noch „Termine“, zeigt Englisch „Events“; hat die
 * Kompanie ihn in kompanie.json anders genannt („Kasse“), bleibt der Name, außer translations.en nennt einen. Die eigenen
 * Namen der Installation (Einstellungen → Navigation) setzt brand.ts erst danach darüber; sie bleiben in jeder Sprache.
 *
 * Die deutschen Vorgaben stehen auch in packages/branding/brand.mjs (DEFAULTS) und im Kern (KompanieConfig.php).
 */
import { locale, SOURCE_LOCALE, t, tc, type Locale } from './i18n';

type Labels = { roles: Record<string, string>; sections: Record<string, string>; rank: string };
type Translation = { description?: string; greeting?: string; guestWelcome?: string; labels?: Partial<Labels> };

/** Was brand.json mitbringt (generated/brand.ts übergibt es beim Laden). */
export interface LocalizableBrand {
  description: string;
  greeting: string;
  guestWelcome: string;
  labels: Labels;
  translations?: Partial<Record<Locale, Translation>>;
}

/** Vorgaben je Schlüssel: deutscher Name und seine Übersetzung (erst beim Aufruf, in der Sprache der App). */
const roleDefaults = (): Record<string, [string, string]> => ({
  guest: ['Gast', t('Gast')],
  candidate: ['Anwärter', t('Anwärter')],
  member: ['Mitglied', t('Mitglied')],
  organizer: ['Verwalter', t('Verwalter')],
  manager: ['Leitung', t('Leitung')],
  admin: ['Admin', t('Admin')],
});

const sectionDefaults = (): Record<string, [string, string]> => ({
  events: ['Termine', t('Termine')],
  news: ['News', t('News')],
  gallery: ['Galerie', t('Galerie')],
  points: ['Punkte', t('Punkte')],
  penalties: ['Strafen', t('Strafen')],
  ranks: ['Dienstgrade', t('Dienstgrade')],
  titles: ['Titel', tc('Bereich', 'Titel')],
  attendance: ['Anwesenheit', t('Anwesenheit')],
  documents: ['Dokumente', t('Dokumente')],
  board: ['Schwarzes Brett', t('Schwarzes Brett')],
  polls: ['Umfragen', t('Umfragen')],
  birthdays: ['Geburtstage', t('Geburtstage')],
  inventory: ['Inventar', t('Inventar')],
  directory: ['Mitglieder', t('Mitglieder')],
  offices: ['Ämter', t('Ämter')],
  lists: ['Listen', t('Listen')],
  mail: ['E-Mails', t('E-Mails')],
  sponsors: ['Sponsoren', t('Sponsoren')],
  companies: ['Kompanien', t('Kompanien')],
  pages: ['Seiten', t('Seiten')],
  intern: ['Intern', t('Intern')],
});

/** Name einer festen Rolle in der Sprache der App, solange er die Vorgabe ist („Mitglied“ → „Member“); sonst unverändert. */
export function presetRoleLabel(key: string, label: string, lang: Locale = locale()): string {
  if (lang === SOURCE_LOCALE) return label;
  const preset = roleDefaults()[key];
  return preset && label === preset[0] ? preset[1] : label;
}

/**
 * Eigene Namen der Installation als Abschnitte (Einstellungen → Navigation): in einer weiteren Sprache die für sie
 * eingetragenen (/config section_labels_i18n, sonst die deutschen), auf Deutsch section_labels.
 */
export function ownSections(
  cfg:
    | { section_labels?: Record<string, string> | unknown[]; section_labels_i18n?: Record<string, Record<string, string> | unknown[]> }
    | undefined,
  lang: Locale = locale(),
): Record<string, string> {
  const pick = (v: unknown): Record<string, string> | undefined =>
    v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, string>) : undefined;
  return (lang !== SOURCE_LOCALE ? pick(cfg?.section_labels_i18n?.[lang]) : undefined) ?? pick(cfg?.section_labels) ?? {};
}

/** Ein Name: Eintrag in translations, sonst die übersetzte Vorgabe, sonst der eigene Name, wie er ist. */
function pick(own: string | undefined, current: string, preset: [string, string] | undefined): string {
  if (own) return own;
  return preset && current === preset[0] ? preset[1] : current;
}

/** Übersetzt die Bezeichnungen in brand an Ort und Stelle; auf Deutsch bleibt alles, wie es ist. */
export function localizeBrand(brand: LocalizableBrand, lang: Locale = locale()): void {
  if (lang === SOURCE_LOCALE) return;
  const tr: Translation = brand.translations?.[lang] ?? {};
  const roles = roleDefaults();
  for (const key of Object.keys(brand.labels.roles)) {
    brand.labels.roles[key] = pick(tr.labels?.roles?.[key], brand.labels.roles[key], roles[key]);
  }
  const sections = sectionDefaults();
  const points = brand.labels.sections.points;
  for (const key of Object.keys(brand.labels.sections)) {
    if (key === 'catalog') continue;
    brand.labels.sections[key] = pick(tr.labels?.sections?.[key], brand.labels.sections[key], sections[key]);
  }
  // Der Katalog heißt nach den Punkten (brand.mjs withDerived), auch in der anderen Sprache
  if ('catalog' in brand.labels.sections && brand.labels.sections.catalog === `${points}katalog`) {
    brand.labels.sections.catalog = t('{points}katalog', { points: brand.labels.sections.points });
  }
  brand.labels.rank = pick(tr.labels?.rank, brand.labels.rank, ['Dienstgrad', t('Dienstgrad')]);
  brand.greeting = pick(tr.greeting, brand.greeting, ['Hallo, {name}', t('Hallo, {name}')]);
  if (tr.guestWelcome) brand.guestWelcome = tr.guestWelcome;
  if (tr.description) brand.description = tr.description;
}
