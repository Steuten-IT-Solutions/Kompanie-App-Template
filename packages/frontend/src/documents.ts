import type { DocCategory, DocumentInfo } from './api';

/** Sortierung der Dokumente innerhalb einer Kategorie. */
export type DocSort = 'name-asc' | 'name-desc' | 'date-desc' | 'date-asc';

export const DOC_SORT_LABELS: Record<DocSort, string> = {
  'name-asc': 'Name A–Z',
  'name-desc': 'Name Z–A',
  'date-desc': 'Stand neu → alt',
  'date-asc': 'Stand alt → neu',
};

export const DEFAULT_DOC_SORT: DocSort = 'date-desc';

/** Gemeinsam für Mitgliederansicht und „Dokumente verwalten“, je Gerät. */
const SORT_KEY = 'kompanie_doc_sort';

const isDocSort = (v: unknown): v is DocSort => typeof v === 'string' && Object.prototype.hasOwnProperty.call(DOC_SORT_LABELS, v);

export function loadDocSort(): DocSort {
  try {
    const v = localStorage.getItem(SORT_KEY);
    return isDocSort(v) ? v : DEFAULT_DOC_SORT;
  } catch {
    return DEFAULT_DOC_SORT;
  }
}

export function saveDocSort(sort: DocSort): void {
  try {
    localStorage.setItem(SORT_KEY, sort);
  } catch {
    /* egal: gilt dann nur bis zum Neuladen */
  }
}

/** Umlaute wie Grundbuchstaben, Zahlen nach Wert („Protokoll 2“ vor „Protokoll 10“). */
const byName = (a: DocumentInfo, b: DocumentInfo) =>
  a.title.localeCompare(b.title, 'de', { sensitivity: 'base', numeric: true }) || a.id - b.id;

/** Stand als YYYY-MM-DD lässt sich als Zeichenkette vergleichen. */
const byDate = (a: DocumentInfo, b: DocumentInfo) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0);

const COMPARE: Record<DocSort, (a: DocumentInfo, b: DocumentInfo) => number> = {
  'name-asc': byName,
  'name-desc': (a, b) => byName(b, a),
  'date-desc': (a, b) => byDate(b, a) || byName(a, b),
  'date-asc': (a, b) => byDate(a, b) || byName(a, b),
};

export interface DocGroup {
  /** Kategorie; null = ohne (bekannte) Kategorie. */
  id: number | null;
  /** Leer, wenn es gar keine Kategorien gibt (dann ohne Überschrift und ohne Einklappen). */
  title: string;
  docs: DocumentInfo[];
}

/**
 * Gruppiert nach Kategorie (in deren festgelegter Reihenfolge) und sortiert innerhalb jeder Gruppe.
 * Dokumente ohne oder mit unbekannter Kategorie stehen zuletzt unter „Weitere Dokumente“.
 */
export function groupDocuments(docs: DocumentInfo[], categories: DocCategory[], sort: DocSort = DEFAULT_DOC_SORT): DocGroup[] {
  const sorted = [...docs].sort(COMPARE[sort]);
  const groups: DocGroup[] = [];
  for (const c of categories) {
    const inCat = sorted.filter((d) => d.category_id === c.id);
    if (inCat.length > 0) groups.push({ id: c.id, title: c.title, docs: inCat });
  }
  const known = new Set(categories.map((c) => c.id));
  const rest = sorted.filter((d) => d.category_id === null || !known.has(d.category_id));
  if (rest.length > 0) groups.push({ id: null, title: groups.length > 0 ? 'Weitere Dokumente' : '', docs: rest });
  return groups;
}

/** Schlüssel der Gruppe, in der ein Dokument mit dieser Kategorie steht (zum Aufklappen). */
export function docGroupKey(categoryId: number | null, categories: DocCategory[]): string {
  return categoryId !== null && categories.some((c) => c.id === categoryId) ? String(categoryId) : 'ohne';
}

export const groupKey = (g: DocGroup) => (g.id === null ? 'ohne' : String(g.id));
