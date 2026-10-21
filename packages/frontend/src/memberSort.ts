import type { MemberSummary } from './api';

/** Sortierung der Mitgliederliste in der Verwaltung. */
export type MemberSort = 'last-name' | 'first-name' | 'points';

export const MEMBER_SORT_LABELS: Record<MemberSort, string> = {
  'last-name': 'Nachname',
  'first-name': 'Vorname',
  points: 'Punkte (meiste zuerst)',
};

export const DEFAULT_MEMBER_SORT: MemberSort = 'last-name';

/** Je Gerät gemerkt. */
const SORT_KEY = 'kompanie_member_sort';

const isMemberSort = (v: unknown): v is MemberSort =>
  typeof v === 'string' && Object.prototype.hasOwnProperty.call(MEMBER_SORT_LABELS, v);

export function loadMemberSort(): MemberSort {
  try {
    const v = localStorage.getItem(SORT_KEY);
    return isMemberSort(v) ? v : DEFAULT_MEMBER_SORT;
  } catch {
    return DEFAULT_MEMBER_SORT;
  }
}

export function saveMemberSort(sort: MemberSort): void {
  try {
    localStorage.setItem(SORT_KEY, sort);
  } catch {
    /* egal: gilt dann nur bis zum Neuladen */
  }
}

/**
 * Der Name ist ein Feld („Vorname Nachname“): als Nachname gilt das letzte Wort, als Vorname der Rest davor.
 * Einwortnamen stehen unter diesem Wort.
 */
export function splitName(name: string): { first: string; last: string } {
  const parts = name.trim().split(/\s+/);
  const last = parts.pop() ?? '';
  return { first: parts.join(' '), last };
}

/** Umlaute wie Grundbuchstaben, Zahlen nach Wert. */
const cmp = (a: string, b: string) => a.localeCompare(b, 'de', { sensitivity: 'base', numeric: true });

const byLastName = (a: MemberSummary, b: MemberSummary) => {
  const x = splitName(a.name);
  const y = splitName(b.name);
  return cmp(x.last, y.last) || cmp(x.first, y.first) || a.id - b.id;
};

const byFirstName = (a: MemberSummary, b: MemberSummary) => cmp(a.name, b.name) || a.id - b.id;

/** Meiste Punkte zuerst, bei Gleichstand nach Nachname; ohne sichtbare Punkte (null) zählen sie als 0. */
const byPoints = (a: MemberSummary, b: MemberSummary) => (b.points ?? 0) - (a.points ?? 0) || byLastName(a, b);

const COMPARE: Record<MemberSort, (a: MemberSummary, b: MemberSummary) => number> = {
  'last-name': byLastName,
  'first-name': byFirstName,
  points: byPoints,
};

export function sortMembers(members: MemberSummary[], sort: MemberSort): MemberSummary[] {
  return [...members].sort(COMPARE[sort]);
}
