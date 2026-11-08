// Hinweis „Benachrichtigungen einschalten“ auf der Startseite: für angemeldete Mitglieder auf Geräten, die noch nie
// nach der Erlaubnis gefragt wurden (Notification.permission „default“). Einschalten kann nur die Person selbst, weil
// der Browser die Abfrage nur nach einem Tippen zeigt. „Nicht jetzt“ blendet den Hinweis auf diesem Gerät für
// PUSH_HINT_PAUSE aus.

export const PUSH_HINT_KEY = 'kompanie-push-hint-later';
export const PUSH_HINT_PAUSE = 30 * 24 * 3600 * 1000;

type Store = Pick<Storage, 'getItem' | 'setItem'>;

function store(): Store | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null; // z. B. gesperrter Speicher
  }
}

/** Soll der Hinweis erscheinen? Nur, solange der Browser noch nicht gefragt hat und „Nicht jetzt“ nicht gilt. */
export function pushHintDue(permission: NotificationPermission, now: number = Date.now(), storage: Store | null = store()): boolean {
  if (permission !== 'default') return false;
  try {
    const at = Number(storage?.getItem(PUSH_HINT_KEY) ?? '');
    return !Number.isFinite(at) || at <= 0 || at > now || now - at >= PUSH_HINT_PAUSE;
  } catch {
    return true;
  }
}

/** „Nicht jetzt“: auf diesem Gerät für eine Weile ausblenden. */
export function pushHintLater(now: number = Date.now(), storage: Store | null = store()): void {
  try {
    storage?.setItem(PUSH_HINT_KEY, String(now));
  } catch {
    // Speicher voll oder gesperrt: dann erscheint der Hinweis eben wieder
  }
}
