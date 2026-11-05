import type { FormerMember, Level, MemberSummary } from './api';

/**
 * Was die Listen schon über eine Person wissen (Mitglieder bzw. Ehemalige der Verwaltung): Damit steht der Kopf ihrer
 * Seite (Abzeichen, Foto, Rolle, Punkte und Offen) sofort, solange ihre eigenen Daten noch laden. Was nur die Seite
 * selbst kennt (Ausnahmen bei den Rechten, ob sich Foto oder Dienstgrad ändern lassen), kommt mit ihren Daten dazu.
 */
export interface MemberPreview {
  id: number;
  name: string;
  rank_title: string | null;
  rank_badge_url: string | null;
  photo_url: string | null;
  points: number | null;
  open_cents: number | null;
  /** Ehemalig: Grundrolle vor dem Ausscheiden; sonst null */
  previous_level: Level | null;
  level: Level | null;
  extra_roles: Level[];
}

type ListEntry = MemberSummary | FormerMember;

function isFormer(e: ListEntry): e is FormerMember {
  return 'previous_level' in e && typeof (e as FormerMember).previous_level === 'string';
}

/**
 * Person `id` aus den Listen (in dieser Reihenfolge gesucht; fehlende Listen sind null). Ohne Treffer null: Dann zeigt
 * die Seite wie bisher das Gerüst.
 */
export function memberPreview(lists: ReadonlyArray<readonly ListEntry[] | null | undefined>, id: number): MemberPreview | null {
  for (const list of lists) {
    if (!Array.isArray(list)) continue;
    const e = list.find((x) => x.id === id);
    if (!e) continue;
    const former = isFormer(e);
    const summary = former ? null : (e as MemberSummary);
    return {
      id: e.id,
      name: e.name,
      rank_title: summary?.rank_title ?? null,
      rank_badge_url: summary?.rank_badge_url ?? null,
      photo_url: e.photo_url ?? null,
      points: e.points ?? null,
      open_cents: e.open_cents ?? null,
      previous_level: former ? e.previous_level : null,
      level: summary?.level ?? null,
      extra_roles: summary?.extra_roles ?? [],
    };
  }
  return null;
}
