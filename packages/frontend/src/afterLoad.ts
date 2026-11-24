/**
 * Nach dem Ereignis „load“ ausführen, auch wenn es schon vorbei ist: Die App startet nachgeladen (boot.ts lädt erst den
 * Katalog der Sprache, dann main.tsx); bis dahin kann die Seite fertig geladen sein, und ein neuer Listener käme nie dran
 * (z. B. bliebe der Service Worker unregistriert).
 */
export function afterLoad(
  fn: () => void,
  doc: Pick<Document, 'readyState'> = document,
  win: Pick<Window, 'addEventListener'> = window,
): void {
  if (doc.readyState === 'complete') {
    setTimeout(fn, 0);
    return;
  }
  win.addEventListener('load', () => fn(), { once: true });
}
