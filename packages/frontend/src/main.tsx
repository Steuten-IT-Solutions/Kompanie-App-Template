// Zuerst: Desktop-Ansicht im Handy-Browser ausgleichen.
import './viewport';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
// Früh laden: fängt das Android-Installationsangebot ab, bevor React startet.
import { isStandalone } from './install';
import App from './App';
import { config } from './api';
import { checkPushOnStart } from './push';
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

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
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
      .then(() => checkPushOnStart())
      .catch(() => {
        /* Ohne Service Worker läuft die App trotzdem, nur nicht offline. */
      });
  });
}
