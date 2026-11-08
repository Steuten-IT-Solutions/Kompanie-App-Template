// Blöcke beim Bearbeiten (ohne React, für Tests): neue Blöcke, aus der Antwort des Servers, Verschieben.
import type {
  Block,
  BlockAudience,
  BlockInput,
  ButtonBlock,
  ContactsBlock,
  CountdownBlock,
  DividerBlock,
  EventsBlock,
  NoticeBlock,
  TextBlock,
} from '../api';

export type TextDraft = Omit<TextBlock, never>;
export type CountdownDraft = Omit<CountdownBlock, 'starts_at' | 'ends_at' | 'event_title'> & { event_title?: string | null };
export type ButtonDraft = ButtonBlock;
export type EventsDraft = Omit<EventsBlock, 'events'>;
export type ContactsDraft = Omit<ContactsBlock, 'offices'>;
export type NoticeDraft = NoticeBlock;
export type DividerDraft = DividerBlock;
export type BlockDraft = TextDraft | CountdownDraft | ButtonDraft | EventsDraft | ContactsDraft | NoticeDraft | DividerDraft;
export type BlockKind = BlockDraft['type'];

/** Blöcke, die man einfügen kann, mit Bezeichnung und Erklärung (Reihenfolge der Auswahl). */
export const BLOCK_KINDS: { type: BlockKind; label: string; hint: string }[] = [
  { type: 'text', label: 'Text', hint: 'Formatierter Text mit Bildern' },
  { type: 'countdown', label: 'Countdown', hint: 'Zählt bis zu einem Datum oder Termin herunter' },
  { type: 'button', label: 'Knopf mit Link', hint: 'Führt zu einer Webseite, Mail, Telefonnummer oder Seite der App' },
  { type: 'events', label: 'Nächste Termine', hint: 'Die nächsten Termine, immer aktuell' },
  { type: 'contacts', label: 'Ansprechpartner', hint: 'Wer ein Amt gerade innehat, mit Foto' },
  { type: 'notice', label: 'Hinweis-Kasten', hint: 'Kurzer Text, farbig hervorgehoben' },
  { type: 'divider', label: 'Trennlinie', hint: 'Linie oder Abstand zwischen Blöcken' },
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
  if (type === 'button') return { id, type, audience, label: '', url: 'https://', style: 'primary' };
  if (type === 'events') return { id, type, audience, title: '', count: 3 };
  if (type === 'contacts') return { id, type, audience, title: '', office_ids: [] };
  if (type === 'notice') return { id, type, audience, title: '', text: '', tone: 'info' };
  if (type === 'divider') return { id, type, audience, style: 'line' };
  return { id, type: 'text', audience, html: '' };
}

/** Blöcke einer geladenen Seite zum Bearbeiten; ältere Server liefern nur body (ein Textblock). */
export function draftsFrom(blocks: Block[] | undefined, body: string): BlockDraft[] {
  if (!blocks) return body ? [{ id: newBlockId(), type: 'text', audience: 'all', html: body }] : [];
  return blocks.map((b): BlockDraft => {
    if (b.type === 'events') {
      const { events: _e, ...rest } = b;
      return rest;
    }
    if (b.type === 'contacts') {
      const { offices: _o, ...rest } = b;
      return rest;
    }
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
