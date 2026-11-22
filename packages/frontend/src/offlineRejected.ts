/**
 * Offline vorgemerkte Änderungen, die der Server beim späteren Senden abgelehnt hat (z. B. Frist vorbei, Termin
 * gelöscht, kein Recht mehr). Sie verschwinden nicht still: Die App zeigt sie oben an, bis die Person sie wegtippt
 * (OfflineRejectedBanner.tsx). Gespeichert wie die Warteschlangen (offlineQueue.ts, Bereich `abgelehnt`), je Konto,
 * beim Abmelden gelöscht.
 *
 * Reine Logik ohne API, damit sie sich ohne Browser testen lässt.
 */

import { t } from './i18n';

/** Bereich in der allgemeinen Warteschlange (Speicher `kompanie-offline/abgelehnt`) */
export const REJECTED_AREA = 'abgelehnt';
/** Ereignis nach jeder Änderung: der Hinweis zeigt den neuen Stand. */
export const REJECTED_CHANGED = 'kompanie-offline-rejected-changed';
/** Höchstens so viele werden aufgehoben (die neuesten). */
export const REJECTED_MAX = 50;

export type RejectedKind = 'attendance' | 'response' | 'booking';
const KINDS: RejectedKind[] = ['attendance', 'response', 'booking'];

export interface RejectedChange {
  kind: RejectedKind;
  /** Titel der Liste bzw. des Termins, Bezeichnung der Buchung */
  label: string;
  /** Meldung des Servers */
  message: string;
  /** Abgelehnt am (ms) */
  at: number;
}

export function validRejected(raw: unknown): RejectedChange | null {
  if (!raw || typeof raw !== 'object') return null;
  const r = raw as Record<string, unknown>;
  if (!KINDS.includes(r.kind as RejectedKind)) return null;
  if (typeof r.label !== 'string' || typeof r.message !== 'string' || typeof r.at !== 'number' || !Number.isFinite(r.at)) return null;
  return { kind: r.kind as RejectedKind, label: r.label, message: r.message, at: r.at };
}

/** Neue Ablehnungen dazunehmen; Schlüssel aus Zeit und laufender Nummer, über REJECTED_MAX fallen die ältesten weg. */
export function withRejected(
  items: Record<string, RejectedChange>,
  added: { kind: RejectedKind; label: string; message: string }[],
  now: number,
): Record<string, RejectedChange> {
  const next = { ...items };
  added.forEach((a, i) => {
    let key = `${now}-${i}`;
    while (next[key]) key += 'x';
    next[key] = { ...a, at: now };
  });
  const keys = Object.keys(next).sort((a, b) => next[b].at - next[a].at || b.localeCompare(a));
  return Object.fromEntries(keys.slice(0, REJECTED_MAX).map((k) => [k, next[k]]));
}

const WHAT: Record<RejectedKind, () => string> = {
  attendance: () => t('Anwesenheit'),
  response: () => t('Zu- oder Absage'),
  booking: () => t('Buchung'),
};

/** Eine Zeile für den Hinweis, z. B. „Zu- oder Absage zu „Schützenfest“: Für diesen Termin …“ */
export function rejectedText(r: RejectedChange): string {
  const kind = WHAT[r.kind]();
  const what =
    r.kind === 'response' && r.label
      ? t('{what} zu „{label}“', { what: kind, label: r.label })
      : r.label
        ? t('{what} „{label}“', { what: kind, label: r.label })
        : kind;
  return t('{what}: {message}', { what, message: r.message });
}

/** Neueste zuerst */
export function sortedRejected(items: Record<string, RejectedChange>): [string, RejectedChange][] {
  return Object.entries(items).sort(([ka, a], [kb, b]) => b.at - a.at || kb.localeCompare(ka));
}
