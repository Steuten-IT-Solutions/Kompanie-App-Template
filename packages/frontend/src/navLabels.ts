import { config } from './api';
import { locale } from './i18n';
import brand from './generated/brand';
import { nameIn } from './names';
import { pageTarget, type FixedTarget, type NavNames, type NavTarget } from './navigation';

/**
 * Bezeichnung jedes Bereichs, der in der Navigation stehen kann (Tab-Leiste und Einstellungen → Navigation); eigene Seiten
 * (seiten/<id>) mit ihrem Tab-Namen (Issue #362) oder Titel, aus /config oder aus pages (Einstellungen, nach dem Anlegen
 * aktueller). Eigene Namen der festen Bereiche stecken schon in brand.labels.sections (generated/brand.ts).
 */
export function targetLabels(
  pages: { id: number; title: string; title_i18n?: Record<string, string> }[] = config?.pages ?? [],
  names: NavNames = config?.navigation_names ?? {},
): Record<FixedTarget, string> & Partial<Record<NavTarget, string>> {
  const sec = brand.labels.sections;
  const f = config?.features;
  // Eigener Tab-Name einer Seite in der Sprache der App (names.ts), sonst der deutsche, sonst der Titel (in der Sprache der
  // App, soweit eingetragen)
  const lang = locale();
  const own = Object.fromEntries(
    pages.map((p) => [
      pageTarget(p.id),
      nameIn('nav', pageTarget(p.id), names[pageTarget(p.id)]?.label || p.title_i18n?.[lang] || p.title),
    ]),
  );
  return {
    ...own,
    termine: sec.events,
    news: sec.news,
    galerie: sec.gallery,
    kompanien: sec.companies,
    sponsoren: sec.sponsors,
    // Nur Ämter ohne Mitgliederliste (Issue #112): Der Bereich heißt dann wie die Ämter.
    mitglieder: f?.directory || !f?.offices ? sec.directory : sec.offices,
    dokumente: sec.documents,
    brett: sec.board,
    dienstgrade: sec.ranks,
    punktekatalog: sec.catalog,
  };
}

/** Eigene Symbole je Bereich bzw. „intern“ (Issue #362), für buildNav. */
export function targetIcons(names: NavNames = config?.navigation_names ?? {}): Partial<Record<string, string>> {
  return Object.fromEntries(Object.entries(names).flatMap(([k, v]) => (v?.icon ? [[k, v.icon]] : [])));
}
