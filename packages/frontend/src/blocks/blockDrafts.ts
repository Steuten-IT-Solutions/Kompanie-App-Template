// Blöcke beim Bearbeiten (ohne React, für Tests): neue Blöcke, aus der Antwort des Servers, Verschieben.
import type { Block, BlockAudience, BlockInput, CountdownBlock, TextBlock } from '../api';

export type TextDraft = Omit<TextBlock, never>;
export type CountdownDraft = Omit<CountdownBlock, 'starts_at' | 'ends_at' | 'event_title'> & { event_title?: string | null };
export type BlockDraft = TextDraft | CountdownDraft;
export type BlockKind = BlockDraft['type'];

/** Blöcke, die man einfügen kann, mit Bezeichnung und Erklärung (Reihenfolge der Auswahl). */
export const BLOCK_KINDS: { type: BlockKind; label: string; hint: string }[] = [
  { type: 'text', label: 'Text', hint: 'Formatierter Text mit Bildern' },
  { type: 'countdown', label: 'Countdown', hint: 'Zählt bis zu einem Datum oder Termin herunter' },
];

let counter = 0;
/** Neue Kennung (wie der Server sie annimmt: Kleinbuchstaben und Ziffern). */
export function newBlockId(): string {
  counter += 1;
  return `n${Date.now().toString(36).slice(-6)}${counter}`;
}

export function newBlock(type: BlockKind, audience: BlockAudience = 'all'): BlockDraft {
  const id = newBlockId();
  if (type === 'countdown') {
    return {
      id,
      type,
      audience,
      title: '',
      source: 'date',
      date: '',
      time: '',
      end_date: '',
      end_time: '',
      running_text: '',
      after: 'text',
      after_text: '',
    };
  }
  return { id, type: 'text', audience, html: '' };
}

/** Blöcke einer geladenen Seite zum Bearbeiten; ältere Server liefern nur body (ein Textblock). */
export function draftsFrom(blocks: Block[] | undefined, body: string): BlockDraft[] {
  if (!blocks) return body ? [{ id: newBlockId(), type: 'text', audience: 'all', html: body }] : [];
  return blocks.map((b) => {
    if (b.type !== 'countdown') return b;
    const { starts_at: _s, ends_at: _e, ...rest } = b;
    return { ...rest, time: rest.time ?? '', end_date: rest.end_date ?? '', end_time: rest.end_time ?? '', date: rest.date ?? '' };
  });
}

/** Zum Speichern: leere Uhrzeiten als null, beim Termin ohne eigenes Datum. */
export function blocksInput(drafts: BlockDraft[]): BlockInput[] {
  return drafts.map((b) => {
    if (b.type !== 'countdown') return b;
    const { event_title: _t, ...rest } = b;
    if (rest.source === 'event') {
      const { date: _d, time: _ti, end_date: _ed, end_time: _et, ...ev } = rest;
      return ev;
    }
    const { event_id: _id, ...own } = rest;
    return { ...own, time: own.time || null, end_date: own.end_date || null, end_time: own.end_time || null };
  });
}

/** Hat der Inhalt etwas zu zeigen (nicht nur leere Textblöcke)? Wie die Prüfung am Schwarzen Brett auf dem Server. */
export function hasContent(drafts: BlockDraft[]): boolean {
  return drafts.some((b) => b.type !== 'text' || /<(img|hr|table)\b/i.test(b.html) || b.html.replace(/<[^>]*>|&nbsp;/g, '').trim() !== '');
}

/** Block an Stelle $from um $by verschieben (−1 nach oben, +1 nach unten). */
export function moveBlock<T>(list: T[], from: number, by: number): T[] {
  const to = from + by;
  if (to < 0 || to >= list.length) return list;
  const next = list.slice();
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  return next;
}
