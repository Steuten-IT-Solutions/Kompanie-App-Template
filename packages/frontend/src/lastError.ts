/**
 * Für „Fehler melden“ (Issue #64): die letzte Fehlermeldung der App und die Seite, von der aus die Hilfe geöffnet wurde.
 * Nur im Speicher dieser Sitzung (nichts im Browser abgelegt); die Meldung zeigt beides vor dem Absenden an.
 */

const MAX = 500;

let last: { message: string; at: number } | null = null;
let origin: string | null = null;

/** Letzte Fehlermeldung vermerken: Antworten der API mit Fehler und Programmfehler (window.onerror, React). */
export function noteError(message: string): void {
  const text = message.replace(/\s+/g, ' ').trim().slice(0, MAX);
  if (text !== '') last = { message: text, at: Date.now() };
}

/** Die zuletzt vermerkte Fehlermeldung oder null. */
export function lastError(): { message: string; at: number } | null {
  return last;
}

/** Seite der App, auf der jemand „?“ gedrückt hat (z. B. „#/termine/verwalten“). */
export function setReportOrigin(hash: string): void {
  origin = hash;
}

export function reportOrigin(): string | null {
  return origin;
}

/** Programmfehler außerhalb von React mitschreiben (einmal beim Start). */
export function watchRuntimeErrors(): void {
  window.addEventListener('error', (e) => noteError(e.message || 'Unbekannter Programmfehler'));
  window.addEventListener('unhandledrejection', (e) => {
    const reason: unknown = e.reason;
    noteError(reason instanceof Error ? reason.message : typeof reason === 'string' ? reason : 'Unbehandelter Fehler');
  });
}

/** Nur für Tests */
export function resetLastError(): void {
  last = null;
  origin = null;
}
