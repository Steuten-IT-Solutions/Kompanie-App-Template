/**
 * Seite als Link verschicken (Knopf oben neben „?“) und nach der Anmeldung dorthin zurückkehren.
 * Ohne Abhängigkeit von api.ts, damit die Tests es ohne Browser laden.
 */

import { t } from './i18n';

const KEY = 'kompanie-after-login';

/**
 * Adresse der angezeigten Seite: appUrl + #/<Ansicht>/<Unterseite>. Fällt die App auf eine andere Ansicht zurück (z. B.
 * fehlendes Recht), gehört die Unterseite nicht zu ihr und bleibt weg. Die Teile kommen roh aus der Adresse (router.ts).
 */
export function shareUrl(appUrl: string, tab: string, requested: string | undefined, path: string[]): string {
  const parts = tab === requested ? [tab, ...path.slice(1)] : [tab];
  return appUrl.split('#')[0] + '#/' + parts.join('/');
}

/** Adresse eines Bildes oder Videos der Galerie: öffnet das Album mit diesem in der Großansicht (#/galerie/<Album>/bild/<Bild>). */
export function photoUrl(appUrl: string, albumId: number, photoId: number): string {
  return appUrl.split('#')[0] + `#/galerie/${albumId}/bild/${photoId}`;
}

/** Bild aus der Adresse galerie/<Album>/bild/<Bild> (path wie in router.ts); sonst undefined. */
export function photoFromPath(path: string[]): number | undefined {
  return path[0] === 'galerie' && /^\d+$/.test(path[1] ?? '') && path[2] === 'bild' && /^\d+$/.test(path[3] ?? '')
    ? Number(path[3])
    : undefined;
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
    onToast(t('Link kopiert'));
  } catch {
    // Erst hier laden: share.ts bleibt ohne Browser-Abhängigkeiten testbar
    const { promptAsk } = await import('./ask');
    await promptAsk(t('Zum Kopieren markieren:'), url, { title: t('Link kopieren'), okLabel: t('Fertig') });
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
