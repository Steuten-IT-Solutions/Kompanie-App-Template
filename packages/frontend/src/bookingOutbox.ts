/**
 * Punkte und Strafen, die ohne Verbindung gebucht wurden (Warteschlange, offlineQueue.ts). Jede Buchung trägt eine
 * Kennung (request_id), die die App beim Absenden erzeugt: Kommt dieselbe Buchung noch einmal beim Server an, weil die
 * Antwort unterwegs verloren ging, liefert er die Einträge von damals, statt doppelt zu buchen (ScoreService im Kern).
 *
 * Reine Logik ohne API, damit sie sich ohne Browser testen lässt; senden: bookingSync.ts.
 */

export const BOOKINGS_AREA = 'buchungen';
/** Ereignis am Fenster, wenn sich die Warteschlange ändert */
export const BOOKINGS_CHANGED = 'kompanie-bookings-changed';

export type BookingKind = 'points' | 'penalty';

export interface PendingBooking {
  kind: BookingKind;
  /** Inhalt der Anfrage (POST /points bzw. /penalties), mit request_id */
  body: Record<string, unknown> & { request_id: string; user_ids: number[] };
  /** Für Meldungen, z. B. „3 Punkte: Arbeitsdienst“ */
  label: string;
  /** Zeitpunkt des Vormerkens (ms) */
  at: number;
}

const ID = /^[A-Za-z0-9-]{8,36}$/;

/** Gelesener Eintrag, oder null, wenn er nicht passt (alte Fassung, kaputt). */
export function validBooking(raw: unknown, key: string): PendingBooking | null {
  if (!raw || typeof raw !== 'object') return null;
  const b = raw as Partial<PendingBooking>;
  if (b.kind !== 'points' && b.kind !== 'penalty') return null;
  if (!b.body || typeof b.body !== 'object') return null;
  const body = b.body as PendingBooking['body'];
  if (typeof body.request_id !== 'string' || !ID.test(body.request_id) || body.request_id !== key) return null;
  if (!Array.isArray(body.user_ids) || body.user_ids.length === 0 || !body.user_ids.every((id) => Number.isInteger(id))) return null;
  if (typeof b.at !== 'number' || !Number.isFinite(b.at)) return null;
  return { kind: b.kind, body, label: typeof b.label === 'string' ? b.label : '', at: b.at };
}

/** Neue Kennung einer Buchung (UUID; ohne crypto.randomUUID aus Zufallszahlen). */
export function newRequestId(random: () => number = Math.random, uuid?: (() => string) | null): string {
  const make =
    uuid !== undefined ? uuid : typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? () => crypto.randomUUID() : null;
  if (make) return make();
  const hex = (n: number) => Array.from({ length: n }, () => Math.floor(random() * 16).toString(16)).join('');
  return `${hex(8)}-${hex(4)}-4${hex(3)}-a${hex(3)}-${hex(12)}`;
}
