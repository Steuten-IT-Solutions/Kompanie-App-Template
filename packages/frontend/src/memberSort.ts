import type { MemberSummary } from './api';

/**
 * Was die Sortierung braucht: Mitgliederliste der Verwaltung und die für alle (DirectoryEntry), dazu die Ehemaligen.
 * Eintritt: join_date (JJJJ-MM-TT oder JJJJ) bzw. join_year bei Mitgliedern, joined_on (JJJJ-MM-TT oder JJJJ) bzw. joined_year und founder bei Ehemaligen.
 * Austritt (Ausscheiden): left_on (JJJJ-MM-TT oder JJJJ) bzw. left_year, nur bei Ehemaligen.
 */
type Sortable = Pick<MemberSummary, 'id' | 'name' | 'first_name' | 'last_name'> & {
  points?: number | null;
  join_year?: number | null;
  join_date?: string | null;
  joined_on?: string;
  joined_year?: string;
  founder?: boolean;
  left_on?: string;
  left_year?: string;
};
/** Was der Name braucht; ohne first_name wird name geteilt (z. B. freie Zeilen einer Liste). */
type Named = Pick<Sortable, 'name' | 'first_name' | 'last_name'>;

/** Sortierung der Mitgliederliste in der Verwaltung und der für alle (Austritt nur bei den Ehemaligen). */
export type MemberSort = 'last-name' | 'first-name' | 'joined' | 'left' | 'points';

/** Richtung: auf- oder absteigend (Namen A → Z bzw. Z → A, Eintritt und Austritt früheste bzw. neueste zuerst, Punkte wenigste bzw. meiste zuerst). */
export type SortDir = 'asc' | 'desc';

export const MEMBER_SORT_LABELS: Record<MemberSort, string> = {
  'last-name': 'Nachname',
  'first-name': 'Vorname',
  joined: 'Eintritt',
  left: 'Austritt',
  points: 'Punkte',
};

export const DEFAULT_MEMBER_SORT: MemberSort = 'last-name';

/** Richtung, mit der eine Sortierung beginnt: Namen A → Z, Eintritt und Austritt die früheste zuerst, Punkte die meisten zuerst. */
export const defaultDir = (sort: MemberSort): SortDir => (sort === 'points' ? 'desc' : 'asc');

/** Beschriftung der Richtung zur Sortierung. */
export function dirLabel(sort: MemberSort, dir: SortDir): string {
  if (sort === 'points') return dir === 'desc' ? 'Meiste zuerst' : 'Wenigste zuerst';
  if (sort === 'joined' || sort === 'left') return dir === 'asc' ? 'Früheste zuerst' : 'Neueste zuerst';
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

/** Gemerkte Sortierung, wenn die Liste sie anbietet; sonst nach Nachname (die gemerkte Wahl bleibt). */
export function orderFor(stored: MemberOrder, options: readonly MemberSort[]): MemberOrder {
  return options.includes(stored.sort) ? stored : { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
}

/** Liste, aus der eine Person geöffnet wird: aktive Mitglieder oder Ehemalige (wie in der Adresse). */
export type BrowseList = 'mitglied' | 'ehemalige';

/** Reihenfolge der Liste beim Öffnen einer Person (gefiltert und sortiert): Auf ihrer Seite blättert man darin weiter. */
let browse: { list: BrowseList; ids: number[] } | null = null;

export function setBrowseOrder(list: BrowseList, ids: number[]): void {
  browse = { list, ids };
}

/** Gemerkte Reihenfolge, wenn die Person darin steht; sonst null. */
export function browseOrder(list: BrowseList, id: number): number[] | null {
  return browse && browse.list === list && browse.ids.includes(id) ? browse.ids : null;
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

function nameParts(m: Named): { first: string; last: string } {
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
const byName = (a: Named, b: Named) => {
  const x = nameParts(a);
  const y = nameParts(b);
  return cmp(x.last || x.first, y.last || y.first) || cmp(x.first, y.first);
};

const byLastName = (a: Sortable, b: Sortable) => byName(a, b) || a.id - b.id;

const byFirstName = (a: Sortable, b: Sortable) => {
  const x = nameParts(a);
  const y = nameParts(b);
  return cmp(x.first, y.first) || cmp(x.last, y.last) || a.id - b.id;
};

/** Ohne sichtbare Punkte (null) zählen sie als 0. */
const byPoints = (a: Sortable, b: Sortable) => (a.points ?? 0) - (b.points ?? 0);

/**
 * Eintritt zum Vergleichen: JJJJ oder JJJJ-MM-TT (nur das Jahr steht vor den Tagen desselben Jahres), Gründungsmitglieder
 * ohne Datum vor allen anderen; null ohne Angabe (oder nicht sichtbar).
 */
export function joinKey(m: Sortable): string | null {
  const v = m.joined_on || m.joined_year || m.join_date || (m.join_year ? String(m.join_year) : '');
  return v !== '' ? v : m.founder ? '' : null;
}

/** Austritt zum Vergleichen: JJJJ oder JJJJ-MM-TT (nur das Jahr vor den Tagen desselben Jahres); null ohne Angabe. */
export function leftKey(m: Sortable): string | null {
  return m.left_on || m.left_year || null;
}

/** Wer kein Datum hat, steht in beiden Richtungen am Ende; sonst nach Datum, bei gleichem nach Nachname A → Z. */
const byDate = (key: (m: Sortable) => string | null, sign: number) => (a: Sortable, b: Sortable) => {
  const x = key(a);
  const y = key(b);
  if (x === null || y === null) return (x === null ? 1 : 0) - (y === null ? 1 : 0) || byLastName(a, b);
  return sign * (x < y ? -1 : x > y ? 1 : 0) || byLastName(a, b);
};

export function sortMembers<T extends Sortable>(members: T[], { sort, dir }: MemberOrder): T[] {
  const sign = dir === 'asc' ? 1 : -1;
  // Namen kehren sich ganz um; bei gleichen Punkten bzw. gleichem Eintritt oder Austritt bleibt es beim Nachnamen A → Z.
  const compare =
    sort === 'points'
      ? (a: T, b: T) => sign * byPoints(a, b) || byLastName(a, b)
      : sort === 'joined'
        ? byDate(joinKey, sign)
        : sort === 'left'
          ? byDate(leftKey, sign)
          : (a: T, b: T) => sign * (sort === 'last-name' ? byLastName : byFirstName)(a, b);
  return [...members].sort(compare);
}

/**
 * „Nach Namen sortieren“ in Listen (Issue #121): Zeilen mit Namen nach Nachname wie die Mitgliederliste, leere Zeilen
 * und Zeilen gelöschter Konten danach, in ihrer Reihenfolge.
 */
export function sortRowsByName<T extends Named & { deleted?: boolean }>(rows: T[]): T[] {
  const named = rows.filter((r) => !r.deleted && r.name.trim() !== '');
  const rest = rows.filter((r) => r.deleted || r.name.trim() === '');
  return [...[...named].sort(byName), ...rest];
}
