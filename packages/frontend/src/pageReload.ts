// Neu laden für eine geänderte App-Seite (pageUpdate.ts): wann, und wie oft höchstens.

/** So lange nach dem Start darf die App für eine neue Seite neu laden. */
export const RELOAD_WINDOW_MS = 20_000;
/**
 * Höchstens RELOAD_MAX Mal in RELOAD_AGAIN_MS: Ändert ein Admin die Einstellungen mehrmals kurz hintereinander, kommt
 * jede Änderung an; sollte sich die Seite dagegen bei jedem Aufruf ändern, gibt es keine Schleife.
 */
export const RELOAD_AGAIN_MS = 60_000;
export const RELOAD_MAX = 3;

/** Zeitpunkte der letzten Neuladungen, die noch zählen (jüngste zuletzt). */
export function recentReloads(times: number[], now: number): number[] {
  return times.filter((t) => t <= now && now - t <= RELOAD_AGAIN_MS);
}

/** reload: Meldung des Service Workers, dass sich mehr als die Tokens geändert hat. */
export function shouldReload(msg: { reload: boolean }, sinceStart: number, times: number[], now: number, preview: boolean): boolean {
  if (!msg.reload || preview || sinceStart > RELOAD_WINDOW_MS) return false;
  return recentReloads(times, now).length < RELOAD_MAX;
}
