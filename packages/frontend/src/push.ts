// Benachrichtigungen (Web Push, Issue #19).
//
// Der Browser meldet sich bei seinem Push-Dienst an (PushManager) und schickt die Adresse samt Schlüsseln an
// den Server. Der Server verschlüsselt jede Nachricht für genau dieses Gerät; der Service Worker zeigt sie an.
// iPhone/iPad: nur in der installierten App (ab iOS 16.4), nicht im Browser-Tab.

import { api, config, type PushDevice } from './api';
import { detectPlatform, isStandalone } from './install';
import { pushCheckDone, pushCheckDue, pushFingerprint } from './pushCheck';

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

/** Gehört das Abo dieses Browsers zum aktuellen Schlüssel des Servers? (true ohne Abo) */
export async function matchesServerKey(publicKey: string): Promise<boolean> {
  const sub = await (await registration())?.pushManager.getSubscription();
  return !sub || sameKey(sub, fromB64u(publicKey));
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
 * Beim Abmelden vom Konto, solange die Sitzung noch gilt: Das Abo des Kontos auf dem Server löschen. Gibt eine
 * Funktion zurück, die nach dem Abmelden das Abo im Browser beendet (ok = true) oder, wenn das Abmelden
 * gescheitert ist, das Abo mit den bisherigen Einstellungen wiederherstellt (ok = false). Fehler halten das
 * Abmelden nicht auf; ist die Sitzung schon beendet, gibt die App das Abo beim nächsten Start frei.
 */
export async function forgetPushOnLogout(): Promise<(ok: boolean) => Promise<void>> {
  const nothing = async () => undefined;
  try {
    if (pushSupport() !== 'ok' || !config) return nothing;
    // Nicht auf den Service Worker warten: Ohne Registrierung gibt es auch kein Abo.
    const sub = await (await navigator.serviceWorker.getRegistration(config.appUrl))?.pushManager.getSubscription();
    const device = sub ? toDevice(sub) : null;
    if (!sub || !device) return nothing;
    const before = (await api.pushStatusQuiet(device).catch(() => null))?.subscription ?? null;
    await api.pushForget(device).catch(() => undefined);
    return async (ok) => {
      try {
        if (ok) await sub.unsubscribe();
        else if (before) await api.pushSubscribe(device, before.types, before.details);
      } catch {
        // offline o. ä.
      }
    };
  } catch {
    return nothing; // Kein Push in diesem Browser
  }
}

/**
 * Beim Start der App: Hat dieses Gerät ein Abo, fragt die App den Server danach. Gehört es einem Konto, das hier
 * nicht mehr angemeldet ist (Sitzung abgelaufen, anderswo abgemeldet), gibt der Server es dabei frei. Nicht bei
 * jedem Start (Issue #18): nur nach einer Änderung von Push-Adresse oder Konto, sonst einmal je Woche (pushCheck.ts).
 */
export async function checkPushOnStart(): Promise<void> {
  try {
    const device = await currentDevice();
    if (!device) return;
    const fingerprint = pushFingerprint(device.endpoint, config?.user?.id ?? null);
    if (!pushCheckDue(fingerprint)) return;
    await api.pushStatus(device);
    pushCheckDone(fingerprint);
  } catch {
    // offline o. ä.: beim nächsten Start
  }
}
