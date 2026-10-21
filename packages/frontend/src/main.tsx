// Zuerst: Desktop-Ansicht im Handy-Browser ausgleichen.
import './viewport';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Früh laden: fängt das Android-Installationsangebot ab, bevor React startet.
import { isStandalone } from './install';
import App from './App';
import { config, preparePreview } from './api';
import { dataUpdated } from './dataCache';
import { previewRole } from './preview';
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

// Vorschau „App als Rolle ansehen“ (Issue #63): erst die Konfiguration der Rolle holen, dann starten.
void preparePreview().then((notice) =>
  createRoot(document.getElementById('root')!).render(
    <StrictMode>
      <App notice={notice} />
    </StrictMode>,
  ),
);

const cfg = config;
if ('serviceWorker' in navigator && cfg) {
  // Neue Daten aus dem Hintergrund (public/sw.js, Issue #108): offene Ansichten neu laden.
  navigator.serviceWorker.addEventListener('message', (e: MessageEvent) => {
    if (e.data?.type === 'kompanie-data') dataUpdated();
  });
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
