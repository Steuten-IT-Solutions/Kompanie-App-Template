// Neu laden für eine geänderte App-Seite (pageUpdate.ts): wann, und wie oft höchstens.

/** So lange nach dem Start darf die App für eine neue Seite neu laden. */
export const RELOAD_WINDOW_MS = 20_000;
/** Höchstens einmal in dieser Zeit (falls sich die Seite bei jedem Aufruf ändern sollte). */
export const RELOAD_AGAIN_MS = 60_000;
/** reload: Meldung des Service Workers, dass sich mehr als die Tokens geändert hat. */
export function shouldReload(
  msg: { reload: boolean },
  sinceStart: number,
  lastReload: number | null,
  now: number,
  preview: boolean,
): boolean {
  if (!msg.reload || preview || sinceStart > RELOAD_WINDOW_MS) return false;
  return lastReload === null || now - lastReload > RELOAD_AGAIN_MS || now < lastReload;
}
