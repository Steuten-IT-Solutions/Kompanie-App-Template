import type { BrowserContext, Page } from '@playwright/test';

/**
 * Fenster „Nichts mehr verpassen“ (src/PushHint.tsx) in Tests ausblenden, die es nicht prüfen: Die Tests laufen als
 * iPhone im Browser-Tab, und dort führt das Fenster nach jeder Anmeldung zu „App installieren“. Setzt „Nicht jetzt“ und
 * die laufende Runde (src/pushHint.ts). Andere Geräte bleiben unberührt (dort fehlt ohne fakePush die Erlaubnis).
 * Ein Test, der das Fenster sehen will, setzt localStorage „e2e-keep-push-hint“ und lädt neu.
 */
export async function quietPushHint(target: BrowserContext | Page): Promise<void> {
  await target.addInitScript(() => {
    try {
      if (!/iphone|ipad|ipod/i.test(navigator.userAgent) || localStorage.getItem('e2e-keep-push-hint')) return;
      localStorage.setItem('kompanie-push-hint-later', String(Date.now()));
      localStorage.setItem('kompanie-push-hint-round', '999');
    } catch {
      // z. B. about:blank ohne Speicher
    }
  });
}
