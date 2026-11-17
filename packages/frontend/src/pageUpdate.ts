import { config, takeTokens, type AppConfig } from './api';
import { previewRole } from './preview';
import { offerRestart } from './appUpdate';
import { recentReloads, shouldReload } from './pageReload';

/**
 * Aktuelle App-Seite aus dem Hintergrund (public/sw.js, appPage): Der Service Worker zeigt beim Start sofort die
 * gespeicherte Seite und meldet danach, was an der aktuellen anders ist. Neue Tokens übernimmt die App still; hat sich
 * mehr geändert (Rechte, Bereiche, Version …), lädt sie einmal neu – nur kurz nach dem Start, damit niemand beim
 * Arbeiten unterbrochen wird, und höchstens dreimal je Minute. Sonst bietet sie den Neustart an (appUpdate.ts), außer
 * in der Vorschau; spätestens beim nächsten Öffnen gilt die neue Seite ohnehin.
 */
export interface PageMessage {
  type: 'kompanie-page';
  reload: boolean;
  csrf: AppConfig['csrf'];
  auth: AppConfig['auth'];
}

const KEY = 'kompanie-seite-neu-geladen';

function reloadTimes(): number[] {
  try {
    const list: unknown = JSON.parse(sessionStorage.getItem(KEY) ?? '[]');
    return Array.isArray(list) ? list.filter((t): t is number => typeof t === 'number') : [];
  } catch {
    return [];
  }
}

export function handlePageMessage(data: unknown): void {
  const msg = data as PageMessage;
  if (!config || msg?.type !== 'kompanie-page') return;
  takeTokens(msg.csrf, msg.auth);
  const now = Date.now();
  const times = reloadTimes();
  const preview = !!previewRole();
  if (!shouldReload(msg, performance.now(), times, now, preview)) {
    if (msg.reload && !preview) offerRestart();
    return;
  }
  try {
    sessionStorage.setItem(KEY, JSON.stringify([...recentReloads(times, now), now]));
  } catch {
    // Ohne sessionStorage nicht selbst neu laden: sonst könnte es eine Schleife geben.
    offerRestart();
    return;
  }
  window.location.reload();
}
