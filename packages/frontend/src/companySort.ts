import type { Company } from './api';
import type { SortDir } from './memberSort';

/**
 * Sortierung der Liste der Kompanien (wie in der Mitgliederliste mit Richtung): in der Reihenfolge der Verwaltung, nach
 * Name, Gründungsjahr oder Zahl der Mitglieder. Aufgelöste stehen immer unten (sortiert wie die übrigen).
 */
export type CompanySort = 'order' | 'name' | 'founded' | 'members';

export const COMPANY_SORT_LABELS: Record<CompanySort, string> = {
  order: 'Wie festgelegt',
  name: 'Name',
  founded: 'Gründung',
  members: 'Mitglieder',
};

export const DEFAULT_COMPANY_SORT: CompanySort = 'order';

/** Richtung, mit der eine Sortierung beginnt: festgelegte Reihenfolge, Name A → Z, älteste zuerst, meiste Mitglieder zuerst. */
export const defaultCompanyDir = (sort: CompanySort): SortDir => (sort === 'members' ? 'desc' : 'asc');

/** Beschriftung der Richtung zur Sortierung. */
export function companyDirLabel(sort: CompanySort, dir: SortDir): string {
  if (sort === 'order') return dir === 'asc' ? 'Wie festgelegt' : 'Umgekehrt';
  if (sort === 'members') return dir === 'desc' ? 'Meiste zuerst' : 'Wenigste zuerst';
  if (sort === 'founded') return dir === 'asc' ? 'Älteste zuerst' : 'Jüngste zuerst';
  return dir === 'asc' ? 'A → Z' : 'Z → A';
}

export interface CompanyOrder {
  sort: CompanySort;
  dir: SortDir;
}

/** Je Gerät gemerkt. */
const SORT_KEY = 'kompanie_company_sort';
const DIR_KEY = 'kompanie_company_sort_dir';

const isCompanySort = (v: unknown): v is CompanySort =>
  typeof v === 'string' && Object.prototype.hasOwnProperty.call(COMPANY_SORT_LABELS, v);

const defaultOrder = (): CompanyOrder => ({ sort: DEFAULT_COMPANY_SORT, dir: defaultCompanyDir(DEFAULT_COMPANY_SORT) });

export function loadCompanyOrder(): CompanyOrder {
  try {
    const sort = localStorage.getItem(SORT_KEY);
    if (!isCompanySort(sort)) return defaultOrder();
    const dir = localStorage.getItem(DIR_KEY);
    return { sort, dir: dir === 'asc' || dir === 'desc' ? dir : defaultCompanyDir(sort) };
  } catch {
    return defaultOrder();
  }
}

export function saveCompanyOrder({ sort, dir }: CompanyOrder): void {
  try {
    localStorage.setItem(SORT_KEY, sort);
    localStorage.setItem(DIR_KEY, dir);
  } catch {
    /* egal: gilt dann nur bis zum Neuladen */
  }
}

/** Gemerkte Sortierung, wenn die Liste sie anbietet (Mitglieder nur, wenn man sie sieht); sonst wie festgelegt. */
export function companyOrderFor(stored: CompanyOrder, options: readonly CompanySort[]): CompanyOrder {
  return options.includes(stored.sort) ? stored : defaultOrder();
}

/** Ohne Groß- und Kleinschreibung und Akzente („muller“ findet „Müller“). */
const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Treffer der Suche: im Namen oder im Kurznamen; leere Suche findet alle. */
export function matchesCompany(c: Pick<Company, 'name' | 'short_name'>, query: string): boolean {
  const q = fold(query.trim());
  return q === '' || fold(c.name).includes(q) || fold(c.short_name ?? '').includes(q);
}

type Sortable = Pick<Company, 'id' | 'name' | 'active' | 'founded_year'> & { member_count?: number };

const byName = (a: Sortable, b: Sortable) => a.name.localeCompare(b.name, 'de', { sensitivity: 'base', numeric: true });

/**
 * Sortiert die Kompanien; die Eingabe ist in der Reihenfolge der Verwaltung. Aufgelöste stehen unten. Ohne Gründungsjahr
 * stehen sie in beiden Richtungen am Ende; bei gleichem Wert entscheidet der Name.
 */
export function sortCompanies<T extends Sortable>(companies: readonly T[], { sort, dir }: CompanyOrder): T[] {
  const sign = dir === 'asc' ? 1 : -1;
  const position = new Map(companies.map((c, i) => [c.id, i]));
  const cmp = (a: T, b: T): number => {
    if (sort === 'order') return sign * ((position.get(a.id) ?? 0) - (position.get(b.id) ?? 0));
    if (sort === 'name') return sign * byName(a, b) || a.id - b.id;
    if (sort === 'founded') {
      if (a.founded_year === null || b.founded_year === null) {
        if (a.founded_year !== b.founded_year) return a.founded_year === null ? 1 : -1;
        return byName(a, b);
      }
      return sign * (a.founded_year - b.founded_year) || byName(a, b);
    }
    return sign * ((a.member_count ?? 0) - (b.member_count ?? 0)) || byName(a, b);
  };
  return [...companies].sort((a, b) => Number(b.active) - Number(a.active) || cmp(a, b));
}
