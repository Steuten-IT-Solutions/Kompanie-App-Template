/**
 * Für „Fehler melden“ (Issue #64): die letzte Fehlermeldung der App und die Seite, von der aus die Hilfe geöffnet wurde.
 * Nur im Speicher dieser Sitzung (nichts im Browser abgelegt); die Meldung zeigt beides vor dem Absenden an.
 */

const MAX = 500;

type Entry = { message: string; at: number };
let last: Entry | null = null;
/** Die Meldung vor `last`, für forgetError */
let previous: Entry | null = null;
let origin: string | null = null;

/** Letzte Fehlermeldung vermerken: Antworten der API mit Fehler und Programmfehler (window.onerror, React). */
export function noteError(message: string): Entry | null {
  const text = message.replace(/\s+/g, ' ').trim().slice(0, MAX);
  if (text === '') return null;
  previous = last;
  last = { message: text, at: Date.now() };
  return last;
}

/**
 * Eine vermerkte Meldung wieder vergessen (Fehler beim Vorabladen, Issue #104: die Ansicht lädt dann selbst und meldet
 * ihn, wenn er bleibt). Ist inzwischen eine neuere vermerkt, bleibt diese.
 */
export function forgetError(entry: unknown): void {
  if (last === null || last !== entry) return;
  last = previous;
  previous = null;
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
  previous = null;
  origin = null;
}
