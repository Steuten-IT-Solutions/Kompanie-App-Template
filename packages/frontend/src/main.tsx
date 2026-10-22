// Zuerst: Desktop-Ansicht im Handy-Browser ausgleichen.
import './viewport';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Früh laden: fängt das Android-Installationsangebot ab, bevor React startet.
import { isStandalone } from './install';
import App from './App';
import { config, preparePreview } from './api';
import { dataCache } from './dataCache';
import { deviceStore } from './deviceStore';
import { previewRole } from './preview';
import { skeletons } from './skeleton';
import { checkPushOnStart } from './push';
import { watchRuntimeErrors } from './lastError';
// Gewählte Schrift aus kompanie.json, lokal gebündelt (kein Abruf bei Google).
import './generated/fonts';
import './generated/theme.css';
import './styles.css';

// Teilbarer Link …/installieren/ (auch ?kompanie_app=installieren oder ?r=/installieren)
// → Anleitung in der App (#/installieren).
function sharedInstallLink(): boolean {
  const { pathname, search } = window.location;
  const q = new URLSearchParams(search);
  const target = q.get('kompanie_app') ?? q.get('r') ?? pathname;
  return /(^|\/)installieren\/?$/.test(target);
}
if (config && sharedInstallLink()) {
  history.replaceState(null, '', config.appUrl + '#/installieren');
}
// Vom Home-Bildschirm gestartet: nicht auf der Anleitung landen, sondern bei den Terminen.
if (isStandalone() && window.location.hash.startsWith('#/installieren')) {
  history.replaceState(null, '', window.location.pathname);
}

// Letzte Fehlermeldung für „Fehler melden“ (Issue #64)
watchRuntimeErrors();

/**
 * Zuletzt gezeigte Daten und Gerüste der Seiten vom Gerät (dataCache.ts, skeleton.tsx), getrennt nach Version und
 * Benutzer. Höchstens kurz darauf warten: Ein langsamer Speicher soll den Start nicht aufhalten. In der Vorschau nicht:
 * Ihre Daten dürfen sich nicht mit denen des Admins mischen.
 */
function restoreViews(): Promise<unknown> {
  if (!config || previewRole() || !('caches' in window)) return Promise.resolve();
  const scope = `${config.version}|${config.user?.id ?? 'gast'}`;
  const restoring = Promise.all([
    dataCache.restore(scope, deviceStore('kompanie-ansicht-daten-v1', 60)),
    skeletons.restore(scope, deviceStore('kompanie-ansicht-geruest-v1', 80)),
  ]).catch(() => undefined);
  return Promise.race([restoring, new Promise((resolve) => setTimeout(resolve, 300))]);
}

// Vorschau „App als Rolle ansehen“ (Issue #63): erst die Konfiguration der Rolle holen, dann starten.
void Promise.all([preparePreview(), restoreViews()]).then(([notice]) =>
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App notice={notice} />
    </StrictMode>,
  ),
);

const cfg = config;
if ('serviceWorker' in navigator && cfg) {
  window.addEventListener('load', () => {
    // Der Service Worker erfährt so, wo API und Programmdateien liegen.
    const src = document.querySelector<HTMLScriptElement>('script[type="module"][src]')?.src ?? location.href;
    const assets = src.includes('/assets/') ? src.slice(0, src.lastIndexOf('/assets/') + 1) : new URL('.', src).href;
    const sw = new URL(cfg.swUrl, location.href);
    sw.searchParams.set('api', new URL(cfg.apiBase, location.href).href);
    sw.searchParams.set('assets', assets);
    navigator.serviceWorker
      .register(sw.toString(), { scope: cfg.appUrl })
      // In der Vorschau nicht: Der Server lehnt dort jede Änderung ab (auch das Freigeben fremder Abos).
      .then(() => (previewRole() ? undefined : checkPushOnStart()))
      .catch(() => {
        /* Ohne Service Worker läuft die App trotzdem, nur nicht offline. */
      });
  });
}
