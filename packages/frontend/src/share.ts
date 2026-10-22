/**
 * Seite als Link verschicken (Knopf oben neben „?“) und nach der Anmeldung dorthin zurückkehren.
 * Ohne Abhängigkeit von api.ts, damit die Tests es ohne Browser laden.
 */

const KEY = 'kompanie-after-login';

/**
 * Adresse der angezeigten Seite: appUrl + #/<Ansicht>/<Unterseite>. Fällt die App auf eine andere Ansicht zurück (z. B.
 * fehlendes Recht), gehört die Unterseite nicht zu ihr und bleibt weg. Die Teile kommen roh aus der Adresse (router.ts).
 */
export function shareUrl(appUrl: string, tab: string, requested: string | undefined, path: string[]): string {
  const parts = tab === requested ? [tab, ...path.slice(1)] : [tab];
  return appUrl.split('#')[0] + '#/' + parts.join('/');
}

/** Teilen-Menü des Geräts; sonst in die Zwischenablage; sonst zum Abschreiben anzeigen. */
export async function shareLink(url: string, title: string, onToast: (msg: string) => void, text?: string): Promise<void> {
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title, text, url });
    } catch {
      /* abgebrochen */
    }
    return;
  }
  try {
    await navigator.clipboard.writeText(url);
    onToast('Link kopiert');
  } catch {
    window.prompt('Link kopieren:', url);
  }
}

/** Vor der Anmeldung: Seite merken (nur in diesem Tab), damit ein geteilter Link danach wieder aufgeht. */
export function rememberRoute(hash: string): void {
  const route = hash.replace(/^#\/?/, '');
  try {
    if (route === '' || /^anmelden(\/|$)/.test(route)) sessionStorage.removeItem(KEY);
    else sessionStorage.setItem(KEY, '#/' + route);
  } catch {
    // Ohne sessionStorage startet die App nach der Anmeldung auf der Startseite.
  }
}

/** Nach der Anmeldung: gemerkte Seite abholen (einmalig) oder null. */
export function takeRememberedRoute(): string | null {
  try {
    const route = sessionStorage.getItem(KEY);
    sessionStorage.removeItem(KEY);
    return route && route.startsWith('#/') ? route : null;
  } catch {
    return null;
  }
}
