import { config, takeTokens, type AppConfig } from './api';
import { previewRole } from './preview';
import { recentReloads, shouldReload } from './pageReload';

/**
 * Aktuelle App-Seite aus dem Hintergrund (public/sw.js, appPage): Der Service Worker zeigt beim Start sofort die
 * gespeicherte Seite und meldet danach, was an der aktuellen anders ist. Neue Tokens übernimmt die App still; hat sich
 * mehr geändert (Rechte, Bereiche, Version …), lädt sie einmal neu – nur kurz nach dem Start, damit niemand beim
 * Arbeiten unterbrochen wird (sonst gilt die neue Seite beim nächsten Öffnen), und höchstens dreimal je Minute.
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
  if (!shouldReload(msg, performance.now(), times, now, !!previewRole())) return;
  try {
    sessionStorage.setItem(KEY, JSON.stringify([...recentReloads(times, now), now]));
  } catch {
    // Ohne sessionStorage nicht neu laden: sonst könnte es eine Schleife geben.
    return;
  }
  window.location.reload();
}
