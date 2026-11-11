import { useEffect, useRef } from 'react';

/**
 * „Zurück“ (Taste, Geste des Geräts, Knopf des Browsers) schließt zuerst, was offen ist: Fenster (Sheet, Rückfragen),
 * die Großansicht der Galerie, das Auswählen in der Galerie. Erst wenn nichts mehr offen ist, geht es im Verlauf zurück.
 *
 * Jede offene Ebene hat einen Eintrag mit derselben Adresse im Verlauf (kein hashchange, der Router merkt nichts). Im
 * Zustand des Eintrags steht, wie viele Ebenen bei ihm offen waren. Geht der Verlauf zurück, schließen die Ebenen darüber
 * (die oberste zuerst). Schließt eine Ebene anders (✕, Escape, nach dem Speichern), nimmt die App ihren Eintrag wieder
 * weg. Alles Abgleichen geschieht gesammelt nach dem aktuellen Ablauf: Mehrere Ebenen, die zugleich schließen (z. B.
 * Fenster, Großansicht und Auswählen nach dem Verschieben), gehen in einem Schritt zurück statt sich in die Quere zu kommen.
 */

const KEY = 'backLayers';

interface Layer {
  close: () => void;
}

/** Die offenen Ebenen, die oberste zuletzt. */
const layers: Layer[] = [];
let scheduled = false;
/** Die App geht selbst im Verlauf zurück und wartet auf das popstate dazu. */
let traversing = false;
let traverseTimer: ReturnType<typeof setTimeout> | undefined;
let installed = false;
/** Warten darauf, dass der Verlauf zu den offenen Ebenen passt (afterBackSync). */
const waiting: (() => void)[] = [];

/** Wie viele Ebenen beim aktuellen Eintrag des Verlaufs offen waren. */
function depth(): number {
  const v = (window.history.state as Record<string, unknown> | null)?.[KEY];
  return typeof v === 'number' && Number.isInteger(v) && v > 0 ? v : 0;
}

/** Einträge im Verlauf an die offenen Ebenen anpassen: fehlende anlegen, übrige mit einem Schritt zurück entfernen. */
function sync(): void {
  if (traversing) return;
  const d = depth();
  const n = layers.length;
  if (d > n) {
    traversing = true;
    // Kommt kein popstate (sollte nicht vorkommen), nicht für immer warten.
    traverseTimer = setTimeout(() => {
      traversing = false;
      schedule();
    }, 1000);
    window.history.go(n - d);
    return;
  }
  for (let k = d + 1; k <= n; k++) window.history.pushState({ ...(window.history.state as object | null), [KEY]: k }, '');
  for (const fn of waiting.splice(0)) fn();
}

function schedule(): void {
  if (scheduled) return;
  scheduled = true;
  setTimeout(() => {
    scheduled = false;
    sync();
  }, 0);
}

function onPopState(): void {
  const own = traversing;
  if (own) {
    traversing = false;
    clearTimeout(traverseTimer);
  }
  // Selbst zurückgegangen (Einträge geschlossener Ebenen entfernt): Was inzwischen offen ist, bleibt offen.
  if (!own) {
    const d = depth();
    while (layers.length > d) layers.pop()!.close();
  }
  // Übrig gebliebene Einträge (z. B. nach dem Neuladen mit offenem Fenster) überspringen, fehlende anlegen.
  schedule();
}

function install(): void {
  if (installed) return;
  installed = true;
  window.addEventListener('popstate', onPopState);
}

/**
 * Beim Start: Steht die App auf einem Eintrag einer Ebene (neu geladen, während ein Fenster offen war), dorthin zurück,
 * wo nichts offen war. Sonst bewirkte das erste „Zurück“ sichtbar nichts.
 */
export function initBackClose(): void {
  install();
  if (depth() > 0) schedule();
}

/**
 * Eine Ebene öffnen, die „Zurück“ schließt; close wird dann aufgerufen. Die zurückgegebene Funktion meldet die Ebene ab
 * (beim Schließen auf anderem Weg); ihr Eintrag im Verlauf verschwindet dann.
 */
export function openBackLayer(close: () => void): () => void {
  install();
  const layer: Layer = { close };
  layers.push(layer);
  // Gleich anlegen, nicht erst gesammelt: Drückt jemand sofort „Zurück“, gehört der Schritt schon dem Fenster.
  sync();
  return () => {
    const i = layers.indexOf(layer);
    if (i < 0) return; // schon durch „Zurück“ geschlossen
    layers.splice(i, 1);
    schedule();
  };
}

/**
 * fn ausführen, sobald die Einträge geschlossener Ebenen aus dem Verlauf sind. Für Weiterleitungen (redirect) direkt nach
 * dem Schließen: Sonst ersetzte die Weiterleitung den Eintrag des Fensters, und „Zurück“ führte auf die alte Seite.
 */
export function afterBackSync(fn: () => void): void {
  install();
  waiting.push(fn);
  schedule();
}

/** Solange active, schließt „Zurück“ über onClose (statt die Seite zu verlassen). */
export function useBackClose(active: boolean, onClose: () => void): void {
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    if (!active) return;
    return openBackLayer(() => close.current());
  }, [active]);
}

/** Nur für Tests: Zustand zurücksetzen. */
export function resetBackCloseForTests(): void {
  layers.length = 0;
  waiting.length = 0;
  scheduled = false;
  traversing = false;
  clearTimeout(traverseTimer);
  if (installed) window.removeEventListener('popstate', onPopState);
  installed = false;
}
