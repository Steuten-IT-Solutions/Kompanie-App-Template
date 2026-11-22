/**
 * Neue Version der App erkennen und einen Neustart anbieten (Hinweis „Neu starten“ in App.tsx).
 *
 * Wer die installierte App aus dem Hintergrund holt, startet sie nicht neu: Die Programmdateien der Version, mit der sie
 * gestartet wurde, laufen weiter, auch wenn der Server längst eine neue hat. Der Browser sucht nach einem neuen Service
 * Worker (public/sw.js, jeder Build hat einen eigenen) nur beim Öffnen einer Seite. Darum fragt die App beim Zurückholen
 * und, solange sie offen ist, regelmäßig nach (höchstens alle CHECK_AGAIN_MS). Ein neuer Service Worker speichert beim
 * Installieren die neuen Programmdateien und die App-Seite und übernimmt dann die offenen Fenster (controllerchange):
 * Erst dann erscheint der Hinweis. So steht die neue Version beim Neustart schon bereit, auch offline. Übernehmen kann
 * sie erst, wenn der alte Service Worker nichts mehr zu tun hat (z. B. Daten im Hintergrund nachladen); bleibt er
 * beschäftigt, kommt der Hinweis später oder die neue Version gilt beim nächsten Öffnen.
 *
 * Neu geladen wird nur auf Knopfdruck, damit niemand beim Arbeiten unterbrochen wird; nicht gespeicherte Eingaben
 * fragt die App vorher ab (unsaved.ts). Kurz nach dem Start lädt die App für eine neue Seite weiterhin selbst neu
 * (pageUpdate.ts); kommt die Meldung später, bietet sie stattdessen den Neustart an.
 */

/** Frühestens so lange nach der letzten Nachfrage wieder nachfragen (Zurückholen der App). */
export const CHECK_AGAIN_MS = 15 * 60_000;
/** Solange die App offen und zu sehen ist: so oft nachfragen. */
export const CHECK_OPEN_MS = 60 * 60_000;

/** Wieder nachfragen? Auch, wenn die Uhr zurückgestellt wurde (letzte Nachfrage „in der Zukunft“). */
export function shouldCheck(last: number, now: number): boolean {
  return now < last || now - last >= CHECK_AGAIN_MS;
}

let available = false;
const listeners = new Set<() => void>();

function emit(): void {
  for (const fn of listeners) fn();
}

/** Für useSyncExternalStore: Steht eine neue Version bereit? */
export function updateAvailable(): boolean {
  return available;
}

export function subscribeUpdate(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

/** Neue Version erkannt: Hinweis zeigen (auch nach „Später“ wieder, wenn noch eine neuere kommt). */
export function offerRestart(): void {
  available = true;
  emit();
}

/** „Später“: Hinweis ausblenden; beim nächsten Start gilt ohnehin die neue Version. */
export function dismissRestart(): void {
  available = false;
  emit();
}

/** Einmal beim Start (main.tsx), wenn es einen Service Worker gibt. */
export function watchForUpdates(appUrl: string): void {
  const sw = navigator.serviceWorker;
  // Beim allerersten Mal steuerte vorher kein Service Worker die Seite: Das ist keine neue Version, sondern die erste.
  let hadController = !!sw.controller;
  sw.addEventListener('controllerchange', () => {
    if (hadController) offerRestart();
    hadController = true;
  });
  let last = Date.now();
  const check = () => {
    if (document.visibilityState !== 'visible' || !navigator.onLine) return;
    const now = Date.now();
    if (!shouldCheck(last, now)) return;
    last = now;
    void sw
      .getRegistration(appUrl)
      .then((reg) => reg?.update())
      .catch(() => {
        /* Kein Netz oder Server nicht erreichbar: beim nächsten Mal wieder. */
      });
  };
  document.addEventListener('visibilitychange', check);
  window.addEventListener('online', check);
  setInterval(check, CHECK_OPEN_MS);
}
