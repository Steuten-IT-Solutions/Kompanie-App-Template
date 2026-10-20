// Installation als App (PWA).
//
// Android (Chrome, Edge, Samsung Internet) meldet über „beforeinstallprompt“, dass die
// App installierbar ist. Das Ereignis kommt oft schon, bevor React gestartet ist,
// deshalb wird es hier beim Laden des Moduls abgefangen und aufbewahrt.
// iPhone/iPad bieten diese Möglichkeit nicht: Dort geht es nur über „Teilen →
// Zum Home-Bildschirm“, wofür die App eine bebilderte Anleitung zeigt.
//
// Samsung Internet: Die dort erzeugte App-Hülle (WebAPK) ist für eine veraltete Android-Version gebaut; Google Play
// Protect blockiert sie auf Android 14+ als „Unsichere App“. Daran lässt sich von der Webseite aus nichts ändern –
// deshalb wird dort keine Installation angeboten, sondern auf Chrome verwiesen.

import { useEffect, useState } from 'react';
import { config } from './api';

/** Adresse der Installationsanleitung – zum Weiterschicken per WhatsApp, E-Mail und in den Zugangsdaten. */
export function installUrl(): string {
  const app = config?.appUrl ?? '/app/';
  // Ohne URL-Umschreibung (…?kompanie_app=index) direkt über die Sprungmarke #/installieren.
  return app.includes('?') ? `${app}#/installieren` : `${app}installieren/`;
}

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

let deferred: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((l) => l());

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault(); // eigenen Knopf statt der kleinen Browser-Leiste zeigen
  if (isSamsungInternet()) return;
  deferred = e as BeforeInstallPromptEvent;
  notify();
});
window.addEventListener('appinstalled', () => {
  deferred = null;
  notify();
});

export type Platform = 'ios' | 'android' | 'other';

export function detectPlatform(ua = navigator.userAgent): Platform {
  // iPadOS meldet sich als Mac; erkennbar an Touch-Unterstützung.
  if (/iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
  if (/android/i.test(ua)) return 'android';
  // „Desktop-Website“ auf Android meldet sich als Linux-Rechner („X11; Linux x86_64“). Ein Linux-Rechner mit Finger als
  // Hauptbedienung ist praktisch immer ein Handy oder Tablet.
  if (/linux/i.test(ua) && !/cros/i.test(ua) && navigator.maxTouchPoints > 0 && window.matchMedia?.('(pointer: coarse)').matches) {
    return 'android';
  }
  return 'other';
}

export function isSamsungInternet(ua = navigator.userAgent): boolean {
  return /SamsungBrowser/i.test(ua);
}

/** Link, der die Seite unter Android direkt in Chrome öffnet (fehlt Chrome, bietet Android den Play Store an). */
export function chromeUrl(url: string): string {
  const u = new URL(url, window.location.href);
  return `intent://${u.host}${u.pathname}${u.search}#Intent;scheme=${u.protocol.replace(':', '')};package=com.android.chrome;end`;
}

/** Links aus Facebook/Instagram öffnen sich in deren eigenem Browser – dort kann man nicht installieren. */
export function isInAppBrowser(ua = navigator.userAgent): boolean {
  return /FBAN|FBAV|FB_IAB|Instagram|Line\/|Snapchat|TikTok/i.test(ua);
}

export function isStandalone(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

/** true, sobald der Browser die Ein-Tipp-Installation anbietet. */
export function useCanPrompt(): boolean {
  const [can, setCan] = useState(!!deferred);
  useEffect(() => {
    const update = () => setCan(!!deferred);
    listeners.add(update);
    update();
    return () => {
      listeners.delete(update);
    };
  }, []);
  return can;
}

/** Öffnet den Installationsdialog des Browsers. */
export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  if (!deferred) return 'unavailable';
  const ev = deferred;
  deferred = null; // darf nur einmal verwendet werden
  notify();
  await ev.prompt();
  const { outcome } = await ev.userChoice;
  return outcome;
}
