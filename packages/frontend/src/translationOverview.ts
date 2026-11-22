/** Hilfen für Einstellungen → Sprachen → Übersetzungen (views/Uebersetzungen.tsx). */
import type { BlocksKind, ContentKind, TranslationItem } from './api';
import type { NameKind } from './names';

/** Eindeutige Kennung eines Eintrags (Art, Kennung, Feld). */
export const keyOf = (i: Pick<TranslationItem, 'kind' | 'ref' | 'field'>): string => `${i.kind}\u0000${i.ref}\u0000${i.field}`;

/** Fehlt die Übersetzung? Nicht, wenn Leser ohne Eintrag schon eine eigene Fassung sehen (z. B. die übersetzte Vorgabe). */
export const isMissing = (i: TranslationItem, text = i.text): boolean =>
  isBlocks(i) ? (i.done ?? 0) < (i.total ?? 0) : text.trim() === '' && i.fallback === i.source;

const BLOCKS_KINDS: readonly string[] = ['page', 'board'] satisfies BlocksKind[];

/** Inhalt aus Blöcken (eigene Seite, Nachricht am Brett): übersetzt in einem eigenen Formular, nicht über „Speichern“. */
export const isBlocks = <T extends Pick<TranslationItem, 'kind'>>(i: T): i is T & { kind: BlocksKind } => BLOCKS_KINDS.includes(i.kind);

/** Passt der Eintrag zur Suche (Text der Basissprache, Übersetzung, Vorgabe oder Titel des Inhalts)? */
export function matches(i: TranslationItem, query: string, text = i.text): boolean {
  const q = query.trim().toLocaleLowerCase();
  return q === '' || [i.source, text, i.fallback, i.context ?? ''].some((s) => s.toLocaleLowerCase().includes(q));
}

const NAME_KINDS: readonly string[] = ['nav', 'role', 'rank', 'office', 'office_group', 'event_kind', 'menu'] satisfies NameKind[];

/** Eigener Name (PUT /admin/translations) oder Feld eines Inhalts (Route des Inhalts)? */
export const isName = <T extends Pick<TranslationItem, 'kind'>>(i: T): i is T & { kind: NameKind } => NAME_KINDS.includes(i.kind);

export interface ContentRequest {
  kind: ContentKind;
  id: string;
  /** Deutscher Titel (für Kategorien, deren Route ihn verlangt) */
  title: string;
  fields: Record<string, string | string[]>;
  /** Kennungen (keyOf) der Einträge, die damit gespeichert sind */
  keys: string[];
}

/**
 * Anfragen für geänderte Inhalte: je Inhalt eine, mit allen seinen Feldern in der Sprache (leer = Basissprache), damit
 * nichts anderes verloren geht. Antwortmöglichkeiten (option:<n>) gehen als Liste options in ihrer Reihenfolge mit; sind
 * alle leer, als leere Liste (dann gilt die deutsche).
 */
export function contentRequests(all: TranslationItem[], value: (i: TranslationItem) => string): ContentRequest[] {
  const changed = new Set(
    all.filter((i) => !isName(i) && !isBlocks(i) && value(i).trim() !== i.text).map((i) => `${i.kind}\u0000${i.ref}`),
  );
  const out = new Map<string, ContentRequest>();
  for (const i of all) {
    const ref = `${i.kind}\u0000${i.ref}`;
    if (!changed.has(ref)) continue;
    const req = out.get(ref) ?? { kind: i.kind as ContentKind, id: i.ref, title: '', fields: {}, keys: [] };
    const text = value(i).trim();
    if (i.field.startsWith('option:')) {
      const list = (req.fields.options as string[] | undefined) ?? [];
      list[Number(i.field.slice(7))] = text;
      req.fields.options = list;
    } else {
      req.fields[i.field] = text;
      if (i.field === 'title') req.title = i.source;
    }
    req.keys.push(keyOf(i));
    out.set(ref, req);
  }
  return [...out.values()].map((r) => {
    const options = r.fields.options;
    if (Array.isArray(options)) {
      const list = Array.from(options, (o) => o ?? '');
      r.fields.options = list.every((o) => o === '') ? [] : list;
    }
    return r;
  });
}
