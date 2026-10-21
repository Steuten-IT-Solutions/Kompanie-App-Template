import type { MemberSummary } from './api';

/** Was die Sortierung braucht: Mitgliederliste der Verwaltung und die für alle (DirectoryEntry). */
type Sortable = Pick<MemberSummary, 'id' | 'name' | 'first_name' | 'last_name'> & { points?: number | null };

/** Sortierung der Mitgliederliste in der Verwaltung und der für alle. */
export type MemberSort = 'last-name' | 'first-name' | 'points';

/** Richtung: auf- oder absteigend (Namen A → Z bzw. Z → A, Punkte wenigste bzw. meiste zuerst). */
export type SortDir = 'asc' | 'desc';

export const MEMBER_SORT_LABELS: Record<MemberSort, string> = {
  'last-name': 'Nachname',
  'first-name': 'Vorname',
  points: 'Punkte',
};

export const DEFAULT_MEMBER_SORT: MemberSort = 'last-name';

/** Richtung, mit der eine Sortierung beginnt: Namen A → Z, Punkte die meisten zuerst. */
export const defaultDir = (sort: MemberSort): SortDir => (sort === 'points' ? 'desc' : 'asc');

/** Beschriftung der Richtung zur Sortierung. */
export function dirLabel(sort: MemberSort, dir: SortDir): string {
  if (sort === 'points') return dir === 'desc' ? 'Meiste zuerst' : 'Wenigste zuerst';
  return dir === 'asc' ? 'A → Z' : 'Z → A';
}

/** Je Gerät gemerkt. */
const SORT_KEY = 'kompanie_member_sort';
const DIR_KEY = 'kompanie_member_sort_dir';

const isMemberSort = (v: unknown): v is MemberSort => typeof v === 'string' && Object.prototype.hasOwnProperty.call(MEMBER_SORT_LABELS, v);

export interface MemberOrder {
  sort: MemberSort;
  dir: SortDir;
}

export function loadMemberOrder(): MemberOrder {
  try {
    const sort = localStorage.getItem(SORT_KEY);
    if (!isMemberSort(sort)) return { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
    const dir = localStorage.getItem(DIR_KEY);
    return { sort, dir: dir === 'asc' || dir === 'desc' ? dir : defaultDir(sort) };
  } catch {
    return { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
  }
}

export function saveMemberOrder(order: MemberOrder): void {
  try {
    localStorage.setItem(SORT_KEY, order.sort);
    localStorage.setItem(DIR_KEY, order.dir);
  } catch {
    /* egal: gilt dann nur bis zum Neuladen */
  }
}

/**
 * Nur ein Namensfeld (Server vor der Trennung in Vor- und Nachname): das letzte Wort ist der Nachname, ein einzelnes
 * Wort der Vorname – wie der Server bestehende Konten teilt.
 */
export function splitName(name: string): { first: string; last: string } {
  const parts = name.trim().split(/\s+/);
  const last = parts.length > 1 ? (parts.pop() ?? '') : '';
  return { first: parts.join(' '), last };
}

function nameParts(m: Sortable): { first: string; last: string } {
  return m.first_name !== undefined ? { first: m.first_name, last: m.last_name ?? '' } : splitName(m.name);
}

/** In der Liste: bei Sortierung nach Nachname „Nachname, Vorname“, sonst der Anzeigename. */
export function listName(m: Sortable, sort: MemberSort): string {
  const { first, last } = nameParts(m);
  return sort === 'last-name' && last !== '' ? `${last}, ${first}` : m.name;
}

/** Umlaute wie Grundbuchstaben, Zahlen nach Wert. */
const cmp = (a: string, b: string) => a.localeCompare(b, 'de', { sensitivity: 'base', numeric: true });

/** Ohne Nachnamen (z. B. Funktionskonto) zählt beim Nachnamen der Vorname. */
const byLastName = (a: Sortable, b: Sortable) => {
  const x = nameParts(a);
  const y = nameParts(b);
  return cmp(x.last || x.first, y.last || y.first) || cmp(x.first, y.first) || a.id - b.id;
};

const byFirstName = (a: Sortable, b: Sortable) => {
  const x = nameParts(a);
  const y = nameParts(b);
  return cmp(x.first, y.first) || cmp(x.last, y.last) || a.id - b.id;
};

/** Ohne sichtbare Punkte (null) zählen sie als 0. */
const byPoints = (a: Sortable, b: Sortable) => (a.points ?? 0) - (b.points ?? 0);

export function sortMembers<T extends Sortable>(members: T[], { sort, dir }: MemberOrder): T[] {
  const sign = dir === 'asc' ? 1 : -1;
  // Namen kehren sich ganz um; bei gleichen Punkten bleibt es beim Nachnamen A → Z.
  const compare =
    sort === 'points'
      ? (a: T, b: T) => sign * byPoints(a, b) || byLastName(a, b)
      : (a: T, b: T) => sign * (sort === 'last-name' ? byLastName : byFirstName)(a, b);
  return [...members].sort(compare);
}
