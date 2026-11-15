/**
 * Schutz vor dem Verlust nicht gespeicherter Eingaben (Offline-Prüfung, Befund „kein Schutz beim Verlassen“).
 *
 * Als nicht gespeichert gilt ein Formular, in das jemand etwas eingegeben hat (input/change), solange es noch zu sehen
 * ist und seitdem keine Änderung erfolgreich beim Server ankam. Ansichten ohne Formularfelder (z. B. Knöpfe zum
 * Abhaken) melden ihren Stand mit useUnsaved. Verlässt jemand die Seite, fragt die App vorher:
 * - in der App (navigate, redirect, Links mit #/…): Fenster „Änderungen verwerfen?“;
 * - „Zurück“ (Taste, Geste, Browser): ein eigener Eintrag im Verlauf wie bei offenen Fenstern (backClose.ts), sein
 *   Zurück fragt; bleibt die Person, kommt der Eintrag wieder;
 * - Neuladen oder Schließen des Tabs: die Rückfrage des Browsers (beforeunload).
 * Beendet das Betriebssystem die App im Hintergrund, hilft das nicht; dafür bräuchte es Entwürfe auf dem Gerät.
 * Ohne eigene Imports (backClose.ts und ask.tsx gibt main.tsx hinein), damit die Tests es direkt laden können.
 */

/** Aus backClose.ts */
export interface BackLayers {
  openBackLayer: (close: () => void) => () => void;
  afterBackSync: (fn: () => void) => void;
}

const forms = new Set<Element>();
const flags = new Set<symbol>();
/** Abmelden des Eintrags im Verlauf (openBackLayer), solange einer besteht. */
let releaseGuard: (() => void) | null = null;
/** Adresse, auf der der Eintrag des Schutzes angelegt wurde. */
let guardUrl = '';
let asking = false;
let installed = false;
let ask: (message: string) => Promise<boolean> = async () => true;
let openBackLayer: BackLayers['openBackLayer'] = () => () => undefined;
let afterBackSync: BackLayers['afterBackSync'] = (fn) => fn();

export const LEAVE_MESSAGE = 'Deine Änderungen sind noch nicht gespeichert. Seite trotzdem verlassen? Die Eingaben gehen dann verloren.';

/** Gibt es gerade Eingaben, die noch nicht gespeichert sind? */
export function hasUnsaved(): boolean {
  for (const f of forms) if (!f.isConnected) forms.delete(f);
  return forms.size > 0 || flags.size > 0;
}

/**
 * Eine Änderung kam beim Server an (api.ts): Eingaben gelten als gespeichert, auch die mit useUnsaved gemeldeten (die
 * Ansicht meldet sich wieder, sobald sie erneut etwas Ungespeichertes hat). So fragt ein navigate() direkt nach dem
 * Speichern nicht nach, bevor die Ansicht ihren neuen Stand gezeichnet hat.
 */
export function markSaved(): void {
  forms.clear();
  flags.clear();
}

/** Alles verwerfen (die Person will die Seite trotzdem verlassen). */
function discard(): void {
  forms.clear();
  flags.clear();
}

function ensureGuard(): void {
  if (releaseGuard || asking) return;
  guardUrl = window.location.href;
  releaseGuard = openBackLayer(onBack);
}

/** „Zurück“ hat den Eintrag des Schutzes geschlossen. */
function onBack(): void {
  releaseGuard = null;
  // Andere Adresse: kein „Zurück“, sondern eine Navigation an der App vorbei (eingegebene Adresse, Link von außen). Die
  // lässt sich nicht mehr aufhalten; nicht noch einen Schritt zurück.
  if (window.location.href !== guardUrl) return;
  if (!hasUnsaved()) {
    // Nichts (mehr) offen: Der Schritt galt der Seite davor.
    afterBackSync(() => window.history.back());
    return;
  }
  asking = true;
  void ask(LEAVE_MESSAGE).then((leave) => {
    asking = false;
    if (leave) {
      discard();
      afterBackSync(() => window.history.back());
    } else {
      ensureGuard();
    }
  });
}

/**
 * Vor einer Navigation in der App: ohne nicht gespeicherte Eingaben sofort, sonst erst nach der Rückfrage (und gar nicht,
 * wenn die Person bleibt). Ein Eintrag des Schutzes im Verlauf verschwindet vorher, damit „Zurück“ danach nicht auf
 * derselben Seite landet.
 */
export function beforeLeave(go: () => void): void {
  if (asking) return;
  if (!releaseGuard && !hasUnsaved()) {
    go();
    return;
  }
  const proceed = () => {
    discard();
    const release = releaseGuard;
    releaseGuard = null;
    if (release) {
      release();
      afterBackSync(go);
    } else {
      go();
    }
  };
  if (!hasUnsaved()) {
    proceed();
    return;
  }
  asking = true;
  void ask(LEAVE_MESSAGE).then((leave) => {
    asking = false;
    if (leave) proceed();
  });
}

/** Eingabe in einem Formular, das zählt? Nicht: Suchfelder, Rückfragen der App, ausdrücklich ausgenommene Bereiche. */
export function trackedForm(target: EventTarget | null): Element | null {
  if (!(target instanceof Element)) return null;
  if (target instanceof HTMLInputElement && target.type === 'search') return null;
  if (target.closest('.ask-root, [data-unsaved="ignore"], form[role="search"]')) return null;
  return target.closest('form');
}

function onInput(e: Event): void {
  if (typeof Element === 'undefined') return;
  const form = trackedForm(e.target);
  if (!form) return;
  forms.add(form);
  ensureGuard();
}

function onBeforeUnload(e: BeforeUnloadEvent): void {
  if (!hasUnsaved()) return;
  e.preventDefault();
  // Ältere Browser zeigen die Rückfrage nur damit.
  e.returnValue = '';
}

/** Links innerhalb der App (#/…) laufen über beforeLeave. */
function onClick(e: MouseEvent): void {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  if ((!releaseGuard && !hasUnsaved()) || typeof Element === 'undefined') return;
  const link = e.target instanceof Element ? e.target.closest('a[href^="#"]') : null;
  if (!link || link.getAttribute('target')) return;
  const href = link.getAttribute('href') ?? '';
  if (href === window.location.hash) return;
  e.preventDefault();
  beforeLeave(() => {
    window.location.hash = href;
  });
}

/** Einmal beim Start (main.tsx); confirm: Rückfrage der App (ask.tsx), back: Einträge im Verlauf (backClose.ts). */
export function initUnsaved(confirm: (message: string) => Promise<boolean>, back: BackLayers): void {
  ask = confirm;
  openBackLayer = back.openBackLayer;
  afterBackSync = back.afterBackSync;
  if (installed) return;
  installed = true;
  document.addEventListener('input', onInput, true);
  document.addEventListener('change', onInput, true);
  document.addEventListener('click', onClick, true);
  window.addEventListener('beforeunload', onBeforeUnload);
}

/** Für Ansichten ohne Formularfelder: Solange dirty, gilt die Ansicht als nicht gespeichert. */
export function setUnsaved(token: symbol, dirty: boolean): void {
  if (dirty) {
    flags.add(token);
    ensureGuard();
  } else {
    flags.delete(token);
  }
}

/** Nur für Tests: Zustand zurücksetzen. */
export function resetUnsavedForTests(): void {
  forms.clear();
  flags.clear();
  releaseGuard = null;
  asking = false;
  if (installed) {
    document.removeEventListener('input', onInput, true);
    document.removeEventListener('change', onInput, true);
    document.removeEventListener('click', onClick, true);
    window.removeEventListener('beforeunload', onBeforeUnload);
  }
  installed = false;
  ask = async () => true;
}
