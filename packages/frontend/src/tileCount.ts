import type { EventResponses } from './api';

/**
 * Zahl im Kreis an einer Kachel: wie viel dort gerade ansteht (Beförderungen, Anfragen auf einen Zugang,
 * Fehlermeldungen in der Verwaltung; Termine ohne eigene Zu- oder Absage an der Kachel bzw. dem Knopf des Kalenders).
 * Die Zahlen liefert der Server nur an Berechtigte (sonst null, siehe /config und /admin/promotions).
 */
export type TileCounts = {
  /** Mitglieder, die zur Beförderung bereit sind */
  promotions?: number | null;
  /** offene Anfragen auf einen Zugang */
  registrations?: number | null;
  /** offene Fehlermeldungen */
  reports?: number | null;
  /** kommende Termine ohne eigene Zu- oder Absage */
  responses?: number | null;
};

export type TileCount = { count: number; text: string; title: string };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** Zahl und Erklärung (title) für eine Kachel; null, wenn dort nichts ansteht oder die Kachel keine Zahl hat. */
export function tileCount(id: string, counts: TileCounts): TileCount | null {
  const make = (n: number | null | undefined, title: (n: number) => string): TileCount | null =>
    n && n > 0 ? { count: n, text: n > 99 ? '99+' : String(n), title: title(n) } : null;
  switch (id) {
    case 'befoerderungen':
      return make(counts.promotions, (n) => `${plural(n, 'Mitglied', 'Mitglieder')} zur Beförderung bereit`);
    case 'anfragen':
      return make(counts.registrations, (n) => `${plural(n, 'offene Anfrage', 'offene Anfragen')} auf einen Zugang`);
    case 'meldungen':
      return make(counts.reports, (n) => plural(n, 'offene Fehlermeldung', 'offene Fehlermeldungen'));
    case 'termine':
      return make(counts.responses, (n) => `${plural(n, 'Termin', 'Termine')} ohne deine Zu- oder Absage`);
    default:
      return null;
  }
}

/** Wie EventResponseService::BADGE_DAYS: gezählt werden Termine, deren Frist in so vielen Tagen endet. */
export const RESPONSE_BADGE_DAYS = 30;

/** Kommende Termine, bei denen man noch zu- oder absagen kann und es noch nicht getan hat (wie awaitingCount im Kern). */
export function awaitingResponses(events: { responses?: EventResponses }[], now = Date.now()): number {
  const until = now + RESPONSE_BADGE_DAYS * 86400_000;
  return events.filter((ev) => {
    const r = ev.responses;
    if (!r || !r.can_respond || !r.open || r.mine !== null) return false;
    const end = Date.parse(r.until);
    return end > now && end <= until;
  }).length;
}
