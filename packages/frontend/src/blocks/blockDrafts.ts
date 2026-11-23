// Blöcke beim Bearbeiten (ohne React, für Tests): neue Blöcke, aus der Antwort des Servers, Verschieben.
import type {
  Block,
  BlockAudience,
  BlockInput,
  ButtonBlock,
  ContactsBlock,
  CountdownBlock,
  DividerBlock,
  DocumentBlock,
  EventsBlock,
  FactsBlock,
  ImageBlock,
  MapBlock,
  NoticeBlock,
  PollBlock,
  AlbumBlock,
  ScheduleBlock,
  SponsorsBlock,
  TextBlock,
} from '../api';
import { t } from '../i18n';

export type TextDraft = Omit<TextBlock, never>;
export type CountdownDraft = Omit<CountdownBlock, 'starts_at' | 'ends_at' | 'timezone' | 'event_title'> & { event_title?: string | null };
export type ButtonDraft = ButtonBlock;
export type EventsDraft = Omit<EventsBlock, 'events'>;
export type ContactsDraft = Omit<ContactsBlock, 'offices'>;
export type NoticeDraft = NoticeBlock;
export type DividerDraft = DividerBlock;
export type ScheduleDraft = ScheduleBlock;
export type FactsDraft = FactsBlock;
export type PollDraft = Omit<PollBlock, 'poll'> & { poll_title?: string };
export type AlbumDraft = Omit<AlbumBlock, 'album'> & { album_title?: string };
export type DocumentDraft = Omit<DocumentBlock, 'document'> & { document_title?: string };
export type SponsorsDraft = Omit<SponsorsBlock, 'sponsors'>;
export type MapDraft = MapBlock;
export type ImageDraft = ImageBlock;
export type BlockDraft =
  | TextDraft
  | CountdownDraft
  | ButtonDraft
  | EventsDraft
  | ContactsDraft
  | NoticeDraft
  | DividerDraft
  | ScheduleDraft
  | FactsDraft
  | PollDraft
  | AlbumDraft
  | DocumentDraft
  | SponsorsDraft
  | MapDraft
  | ImageDraft;
export type BlockKind = BlockDraft['type'];

/** Blöcke, die man einfügen kann, mit Bezeichnung und Erklärung (Reihenfolge der Auswahl). */
export const BLOCK_KINDS: { type: BlockKind; label: string; hint: string }[] = [
  { type: 'text', label: t('Text'), hint: t('Formatierter Text mit Bildern') },
  { type: 'image', label: t('Bild'), hint: t('Eigenes Bild hochladen, mit Bildunterschrift') },
  { type: 'countdown', label: t('Countdown'), hint: t('Zählt bis zu einem Datum oder Termin herunter') },
  { type: 'button', label: t('Knopf mit Link'), hint: t('Führt zu einer Webseite, Mail, Telefonnummer oder Seite der App') },
  { type: 'events', label: t('Nächste Termine'), hint: t('Was als Nächstes ansteht, immer aktuell') },
  { type: 'contacts', label: t('Ansprechpartner'), hint: t('Wer ein Amt gerade innehat, mit Foto') },
  { type: 'notice', label: t('Hinweis-Kasten'), hint: t('Kurzer Text, farbig hervorgehoben') },
  { type: 'divider', label: t('Trennlinie'), hint: t('Linie oder Abstand zwischen Blöcken') },
  { type: 'schedule', label: t('Ablauf'), hint: t('Uhrzeit und Programmpunkt untereinander') },
  { type: 'facts', label: t('Zahlen und Fakten'), hint: t('Kurze Kennzahlen nebeneinander') },
  { type: 'poll', label: t('Umfrage'), hint: t('Eine Umfrage zum Mitmachen einbinden') },
  { type: 'album', label: t('Album'), hint: t('Einige Bilder aus einem Album') },
  { type: 'document', label: t('Dokument'), hint: t('Eine Datei aus den Dokumenten zum Herunterladen') },
  { type: 'sponsors', label: t('Sponsoren'), hint: t('Alle Logos mit Link, in ihrer Reihenfolge') },
  { type: 'map', label: t('Karte zur Anfahrt'), hint: t('Ort mit Knopf zur Karten-App') },
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
  if (type === 'schedule') return { id, type, audience, title: '', items: [{ time: '', text: '' }] };
  if (type === 'facts') return { id, type, audience, title: '', items: [{ value: '', label: '' }] };
  if (type === 'poll') return { id, type, audience, poll_id: 0 };
  if (type === 'album') return { id, type, audience, album_id: 0, count: 6 };
  if (type === 'document') return { id, type, audience, document_id: 0 };
  if (type === 'sponsors') return { id, type, audience, title: '' };
  if (type === 'map') return { id, type, audience, title: '', place: '' };
  if (type === 'image') return { id, type, audience, image: '', alt: '', caption: '', size: 'full', url: null, width: 0, height: 0 };
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
    if (b.type === 'poll') {
      const { poll, ...rest } = b;
      return { ...rest, poll_title: poll?.title };
    }
    if (b.type === 'album') {
      const { album, ...rest } = b;
      return { ...rest, album_title: album?.title };
    }
    if (b.type === 'document') {
      const { document, ...rest } = b;
      return { ...rest, document_title: document?.title };
    }
    if (b.type === 'sponsors') {
      const { sponsors: _s, ...rest } = b;
      return rest;
    }
    if (b.type !== 'countdown') return b;
    const { starts_at: _s, ends_at: _e, timezone: _z, ...rest } = b;
    return { ...rest, time: rest.time ?? '', end_date: rest.end_date ?? '', end_time: rest.end_time ?? '', date: rest.date ?? '' };
  });
}

/** Zum Speichern: leere Uhrzeiten als null, beim Termin ohne eigenes Datum. */
export function blocksInput(drafts: BlockDraft[]): BlockInput[] {
  return drafts.map((b): BlockInput => {
    if (b.type === 'poll') {
      const { poll_title: _t, ...rest } = b;
      return rest;
    }
    if (b.type === 'album') {
      const { album_title: _t, ...rest } = b;
      return rest;
    }
    if (b.type === 'document') {
      const { document_title: _t, ...rest } = b;
      return rest;
    }
    if (b.type === 'image') {
      const { url: _u, width: _w, height: _h, ...rest } = b;
      return rest;
    }
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
