import { config } from './api';
import brand from './generated/brand';
import type { NavTarget } from './navigation';

/** Bezeichnung jedes Bereichs, der in der Navigation stehen kann (Tab-Leiste und Einstellungen → Navigation). */
export function targetLabels(): Record<NavTarget, string> {
  const sec = brand.labels.sections;
  const f = config?.features;
  return {
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
    punktekatalog: `${sec.points}katalog`,
  };
}
