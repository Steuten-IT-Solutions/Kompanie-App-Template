/**
 * Schlechte Verbindung (Offline-Prüfung): Der Service Worker beantwortet eine Anfrage mit der gespeicherten Version, weil
 * das Netz nicht rechtzeitig oder gar nicht antwortet (Header X-Kompanie-Stale, public/sw.js). Die App zeigt dann oben
 * einen Hinweis, bis wieder eine Antwort aus dem Netz kommt. Ohne eigene Imports, damit die Tests es direkt laden können.
 */

export const STALE_HEADER = 'X-Kompanie-Stale';
/** Ereignis an window, wenn sich der Zustand ändert. */
export const CONNECTION_CHANGED = 'kompanie-connection';

let stale = false;

/** Kommen gerade gespeicherte Daten statt solcher aus dem Netz? */
export function isStale(): boolean {
  return stale;
}

/** Antwort auf eine Anfrage (GET) der App: aus dem Speicher (Zeitlimit oder kein Netz) oder aus dem Netz? */
export function noteResponse(headers: Pick<Headers, 'get'>): void {
  const next = headers.get(STALE_HEADER) === '1';
  if (next === stale) return;
  stale = next;
  if (typeof window !== 'undefined') window.dispatchEvent(new Event(CONNECTION_CHANGED));
}
