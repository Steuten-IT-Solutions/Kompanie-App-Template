// Benachrichtigungen (Web Push, Issue #19).
//
// Der Browser meldet sich bei seinem Push-Dienst an (PushManager) und schickt die Adresse samt Schlüsseln an
// den Server. Der Server verschlüsselt jede Nachricht für genau dieses Gerät; der Service Worker zeigt sie an.
// iPhone/iPad: nur in der installierten App (ab iOS 16.4), nicht im Browser-Tab.

import { api, config, type PushDevice } from './api';
import { detectPlatform, isStandalone } from './install';

export type PushSupport = 'ok' | 'unsupported' | 'ios-install';

export function pushSupport(): PushSupport {
  const apis = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  if (detectPlatform() === 'ios' && !isStandalone()) return 'ios-install';
  return apis ? 'ok' : 'unsupported';
}

/** Der Service Worker der App; null, wenn er (noch) nicht läuft. */
async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator) || !config) return null;
  const reg = await navigator.serviceWorker.getRegistration(config.appUrl);
  if (reg) return reg;
  // Gleich nach dem ersten Laden: kurz auf die Registrierung warten.
  return Promise.race([navigator.serviceWorker.ready, new Promise<null>((resolve) => setTimeout(() => resolve(null), 5000))]);
}

function toDevice(sub: PushSubscription): PushDevice | null {
  const json = sub.toJSON();
  const p256dh = json.keys?.p256dh;
  const auth = json.keys?.auth;
  return json.endpoint && p256dh && auth ? { endpoint: json.endpoint, keys: { p256dh, auth } } : null;
}

/** Das Abo dieses Browsers (unabhängig davon, ob der Server es kennt). */
export async function currentDevice(): Promise<PushDevice | null> {
  if (pushSupport() !== 'ok') return null;
  const reg = await registration();
  const sub = await reg?.pushManager.getSubscription();
  return sub ? toDevice(sub) : null;
}

function fromB64u(text: string): Uint8Array<ArrayBuffer> {
  const b64 = text.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (text.length % 4)) % 4);
  const raw = atob(b64);
  const out = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

function sameKey(sub: PushSubscription, key: Uint8Array): boolean {
  const current = sub.options.applicationServerKey;
  if (!current) return false;
  const a = new Uint8Array(current);
  return a.length === key.length && a.every((b, i) => b === key[i]);
}

/**
 * Fragt nach der Erlaubnis und meldet das Gerät an. Ein Abo mit einem anderen Serverschlüssel (z. B. nach
 * einer Neuinstallation) wird ersetzt, weil der Push-Dienst Nachrichten sonst ablehnt.
 */
export async function enablePush(publicKey: string): Promise<PushDevice> {
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error(
      permission === 'denied'
        ? 'Benachrichtigungen sind für diese Seite blockiert. Du kannst sie in den Einstellungen des Browsers erlauben.'
        : 'Ohne deine Erlaubnis kann die App keine Benachrichtigungen zeigen.',
    );
  }
  const reg = await registration();
  if (!reg) throw new Error('Die App ist noch nicht bereit. Bitte die Seite neu laden und erneut versuchen.');
  const key = fromB64u(publicKey);
  let sub = await reg.pushManager.getSubscription();
  if (sub && !sameKey(sub, key)) {
    await sub.unsubscribe();
    sub = null;
  }
  sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
  const device = toDevice(sub);
  if (!device) throw new Error('Der Browser hat keine gültige Push-Adresse geliefert.');
  return device;
}

/** Meldet das Gerät beim Server und im Browser ab. */
export async function disablePush(device: PushDevice): Promise<void> {
  await api.pushUnsubscribe(device);
  const sub = await (await registration())?.pushManager.getSubscription();
  await sub?.unsubscribe();
}

/**
 * Beim Abmelden vom Konto: Das Gerät bekommt keine Nachrichten des Kontos mehr. Fehler (z. B. offline) halten
 * das Abmelden nicht auf; der Server entfernt das Abo spätestens, wenn sich jemand anderes darauf anmeldet.
 */
export async function forgetPushOnLogout(): Promise<void> {
  try {
    const device = await currentDevice();
    if (!device) return;
    await api.pushUnsubscribe(device).catch(() => undefined);
    const sub = await (await registration())?.pushManager.getSubscription();
    await sub?.unsubscribe();
  } catch {
    // Kein Push in diesem Browser
  }
}
