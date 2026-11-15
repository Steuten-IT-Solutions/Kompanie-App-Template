/**
 * Offline vorgemerkte Zu- und Absagen bei Terminen (Warteschlange auf dem Gerät, allgemeiner Teil: offlineQueue.ts).
 *
 * Ohne Verbindung scheitert „Zusagen“/„Absagen“ (und das Zurücknehmen oder der Hinweis dazu). Die App merkt sich dann je
 * Termin die gewünschte Antwort, zeigt sie gleich an und sendet sie, sobald wieder Netz da ist (responseSync.ts). Jede
 * Antwort setzt den Stand (kein Hinzufügen): Zweimal gesendet ist sie dieselbe; je Termin zählt nur die letzte.
 *
 * Reine Logik ohne API, damit sie sich ohne Browser testen lässt.
 */
import type { EventResponses, ResponseStatus } from './api';

/** Bereich in der allgemeinen Warteschlange (Speicher `kompanie-offline/zusagen`) */
export const RESPONSES_AREA = 'zusagen';
/** Ereignis nach jeder Änderung der Warteschlange: Ansichten zeigen den neuen Stand. */
export const RESPONSES_CHANGED = 'kompanie-zusagen-offline-changed';

export interface PendingResponse {
  /** Gewünschte Antwort; null: zurücknehmen */
  status: ResponseStatus | null;
  /** Hinweis zur Antwort */
  note: string;
  /** Titel des Termins, für Meldungen */
  label: string;
  /** Zuletzt geändert (ms) */
  at: number;
}

export function validResponse(raw: unknown): PendingResponse | null {
  if (!raw || typeof raw !== 'object') return null;
  const p = raw as Record<string, unknown>;
  if (p.status !== null && p.status !== 'yes' && p.status !== 'no') return null;
  if (typeof p.note !== 'string' || typeof p.label !== 'string' || typeof p.at !== 'number') return null;
  return { status: p.status, note: p.note, label: p.label, at: p.at };
}

/** Stand vom Server mit der vorgemerkten Antwort: eigene Antwort, Hinweis und Zähler wie nach dem Senden. */
export function applyPendingResponse(r: EventResponses, p: PendingResponse | null): EventResponses {
  if (!p) return r;
  const next = { ...r, mine: p.status, note: p.status === null ? '' : p.note };
  if (r.mine === 'yes') next.yes = Math.max(0, next.yes - 1);
  if (r.mine === 'no') next.no = Math.max(0, next.no - 1);
  if (p.status === 'yes') next.yes++;
  if (p.status === 'no') next.no++;
  return next;
}

/**
 * Was vormerken, wenn eine Antwort ohne Verbindung scheitert: null, wenn sie dem Stand auf dem Server entspricht (dann
 * gibt es nichts zu senden, z. B. offline zugesagt und wieder zurückgenommen).
 */
export function pendingFor(
  server: EventResponses,
  status: ResponseStatus | null,
  note: string,
  label: string,
  now: number,
): PendingResponse | null {
  const same = server.mine === status && (status === null || server.note === note);
  return same ? null : { status, note: status === null ? '' : note, label, at: now };
}
