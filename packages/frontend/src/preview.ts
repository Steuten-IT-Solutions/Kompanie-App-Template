/**
 * Vorschau „App als Rolle ansehen“ (Issue #63): Ein Admin sieht die App, wie eine Rolle sie sieht, bzw. ohne Anmeldung.
 *
 * Die Wahl gilt nur in diesem Tab (sessionStorage, übersteht Neuladen). Die App schickt sie bei jeder API-Anfrage im
 * Header X-Kompanie-Preview mit; der Server lädt dann alles mit den Rechten der Rolle und lehnt jede Änderung ab
 * (403 preview_readonly). Start und Ende laden die App neu: So sind zwischengespeicherte Daten (dataCache) und
 * Konfiguration frisch, und nichts aus der Admin-Ansicht mischt sich in die Vorschau. Der Service Worker speichert
 * Antworten der Vorschau nicht (public/sw.js).
 */

const KEY = 'kompanie-preview';
export const PREVIEW_HEADER = 'X-Kompanie-Preview';
/** „App ohne Anmeldung ansehen“ */
export const PUBLIC_PREVIEW = '@public';

function read(): string | null {
  try {
    return sessionStorage.getItem(KEY);
  } catch {
    return null;
  }
}

let current: string | null = read();

/** Gewählte Rolle (oder PUBLIC_PREVIEW), null ohne Vorschau. */
export const previewRole = (): string | null => current;

function store(role: string | null): void {
  try {
    if (role === null) sessionStorage.removeItem(KEY);
    else sessionStorage.setItem(KEY, role);
  } catch {
    // Ohne sessionStorage übersteht die Wahl das Neuladen nicht: Die Vorschau startet dann nicht.
  }
}

/** Vorschau beenden, ohne neu zu laden (z. B. vor dem Abmelden oder wenn der Server sie nicht bestätigt). */
export function stopPreview(): void {
  current = null;
  store(null);
}

/**
 * Neu laden mit anderer Vorschau. Bis dahin bleibt alles wie es ist: Das Ziel setzt replaceState (ohne hashchange, die
 * Ansicht lädt also nichts mehr), und laufende Anfragen behalten ihren Header – sonst fragte die alte Ansicht schon mit
 * der neuen Vorschau und hielte z. B. ein 401 für eine abgelaufene Sitzung.
 */
function reloadWith(role: string | null, hash: string): void {
  store(role);
  history.replaceState(null, '', hash);
  window.location.reload();
}

/** Vorschau starten: neu laden und auf der Startseite der Rolle beginnen. */
export function startPreview(role: string): void {
  reloadWith(role, '#/');
}

/** Vorschau beenden: neu laden, zurück zu Einstellungen → Rollen. */
export function endPreview(): void {
  reloadWith(null, '#/einstellungen/rollen');
}
