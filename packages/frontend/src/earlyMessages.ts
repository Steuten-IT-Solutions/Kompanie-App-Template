// Nachrichten des Service Workers (public/sw.js) ab dem ersten Moment annehmen. Die App startet nachgeladen (boot.ts lädt
// erst den Katalog der Sprache, dann main.tsx). Der Browser stellt Nachrichten aber schon zu, sobald die Seite geparst
// ist; ohne Empfänger gingen sie verloren, z. B. die aktuelle App-Seite nach der Anmeldung ({type: 'kompanie-page'},
// danach lädt die App neu). Bis main.tsx übernimmt, warten sie hier. Ohne Abhängigkeiten, damit es sofort läuft.

type Handler = (e: MessageEvent) => void;

let handler: Handler | null = null;
const waiting: MessageEvent[] = [];

if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (e: MessageEvent) => {
    if (handler) handler(e);
    else waiting.push(e);
  });
}

/** Empfänger für alle Nachrichten des Service Workers, auch die, die vorher kamen. */
export function onServiceWorkerMessage(fn: Handler): void {
  handler = fn;
  for (const e of waiting.splice(0)) fn(e);
}
