// Hinweis „Benachrichtigungen einschalten“ auf der Startseite: für angemeldete Mitglieder auf Geräten, die noch nie
// nach der Erlaubnis gefragt wurden (Notification.permission „default“). Einschalten kann nur die Person selbst, weil
// der Browser die Abfrage nur nach einem Tippen zeigt. „Nicht jetzt“ blendet den Hinweis auf diesem Gerät für
// PUSH_HINT_PAUSE aus.
//
// Runde: Erhöht eine neue Version PUSH_HINT_ROUND, bekommen alle Angemeldeten das Fenster nach dem Update genau einmal,
// auch wenn sie schon „Nicht jetzt“ gewählt oder die Benachrichtigungen früher erlaubt und wieder ausgeschaltet haben.
// Wer sie auf diesem Gerät an hat, bekommt es nicht (prüft PushHint.tsx); wer sie im Browser blockiert hat, auch nicht,
// weil der Browser dann nicht mehr fragt.

export const PUSH_HINT_KEY = 'kompanie-push-hint-later';
export const PUSH_HINT_PAUSE = 30 * 24 * 3600 * 1000;
export const PUSH_HINT_ROUND_KEY = 'kompanie-push-hint-round';
/** Laufende Runde; eine neue Zahl fragt alle einmal erneut. 1 = Fenster nach der Anmeldung (Version 0.12). */
export const PUSH_HINT_ROUND = 1;

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
  if (permission === 'denied') return false;
  if (pushHintRoundPending(storage)) return true;
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

/** Steht die einmalige Frage dieser Runde auf diesem Gerät noch aus? Ohne Speicher nicht (sonst bei jedem Start). */
export function pushHintRoundPending(storage: Store | null = store()): boolean {
  if (!storage) return false;
  try {
    const done = Number(storage.getItem(PUSH_HINT_ROUND_KEY) ?? '0');
    return !Number.isFinite(done) || done < PUSH_HINT_ROUND;
  } catch {
    return false;
  }
}

/** Die Frage dieser Runde ist erledigt (gezeigt und beantwortet oder Benachrichtigungen schon an). */
export function pushHintRoundDone(storage: Store | null = store()): void {
  try {
    storage?.setItem(PUSH_HINT_ROUND_KEY, String(PUSH_HINT_ROUND));
  } catch {
    // Speicher voll oder gesperrt
  }
}
