// Fassungen von Blöcken in einer weiteren Sprache (Plan „Mehrsprachigkeit“, PR 9b), ohne React für Tests.
import type { BlockTranslation } from '../api';
import type { BlockDraft } from './blockDrafts';

/** Übersetzbare Felder je Typ, wie auf dem Server (Blocks::TRANSLATABLE). */
export const TRANSLATABLE: Readonly<Partial<Record<BlockDraft['type'], readonly string[]>>> = {
  text: ['html'],
  countdown: ['title', 'running_text', 'after_text'],
  button: ['label'],
  events: ['title'],
  contacts: ['title'],
  notice: ['title', 'text'],
  schedule: ['title', 'items'],
  facts: ['title', 'items'],
  sponsors: ['title'],
  map: ['title'],
};

const isEmpty = (v: unknown): boolean => v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0);

/** Hat der Block auf Deutsch etwas, das man übersetzen kann? */
export function translatableFields(block: BlockDraft): string[] {
  const rec = block as unknown as Record<string, unknown>;
  return (TRANSLATABLE[block.type] ?? []).filter((f) => !isEmpty(rec[f]) && !(f === 'html' && isEmptyHtml(String(rec[f]))));
}

function isEmptyHtml(html: string): boolean {
  return html.replace(/<[^>]*>/g, '').trim() === '' && !/<img\b/i.test(html);
}

type Row = Record<string, string>;

/**
 * Zum Senden: nur Blöcke, die es auf Deutsch gibt, leere Felder fallen weg. In Listen (items) gelten leere Teile als die
 * deutschen, damit jede Zeile vollständig ist; ist eine ganze Liste leer, fällt sie weg.
 */
export function translationInput(german: BlockDraft[], tr: BlockTranslation): BlockTranslation {
  const out: BlockTranslation = {};
  for (const b of german) {
    const own = tr[b.id];
    if (!own) continue;
    const rec = b as unknown as Record<string, unknown>;
    const fields: Record<string, unknown> = {};
    for (const f of TRANSLATABLE[b.type] ?? []) {
      const v = own[f];
      if (f === 'items') {
        const base = (rec.items as Row[] | undefined) ?? [];
        const rows = Array.isArray(v) ? (v as Row[]) : [];
        if (!rows.some((r) => r && Object.values(r).some((x) => typeof x === 'string' && x.trim() !== ''))) continue;
        fields.items = base.map((g, i) => {
          const r = rows[i] ?? {};
          return Object.fromEntries(Object.keys(g).map((k) => [k, (r[k] ?? '').trim() || g[k]]));
        });
      } else if (f === 'html') {
        if (typeof v === 'string' && !isEmptyHtml(v)) fields.html = v;
      } else if (typeof v === 'string' && v.trim() !== '') {
        fields[f] = v.trim();
      }
    }
    if (Object.keys(fields).length > 0) out[b.id] = fields;
  }
  return out;
}

/** Fehlt in der Fassung noch etwas, das es auf Deutsch gibt? (für den Hinweis beim Bearbeiten) */
export function missingCount(german: BlockDraft[], tr: BlockTranslation): number {
  const clean = translationInput(german, tr);
  let n = 0;
  for (const b of german) {
    for (const f of translatableFields(b)) {
      if (clean[b.id]?.[f] === undefined) n += 1;
    }
  }
  return n;
}
