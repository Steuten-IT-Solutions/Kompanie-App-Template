// Nachfrage zum Push-Abo beim Start der App (Issue #18): nur, wenn sich Gerät (Push-Adresse) oder Konto geändert
// haben, und sonst höchstens einmal je Woche. Der Server gibt dabei Abos frei, die einem hier nicht mehr angemeldeten
// Konto gehören (das erkennt die App am geänderten Konto), und vermerkt, dass ein Gast-Abo noch genutzt wird (dafür
// genügt ein Mal je Woche; es verfällt erst nach 180 Tagen).

const KEY = 'kompanie-push-check';
export const PUSH_CHECK_MAX_AGE = 7 * 24 * 3600 * 1000;

/** Fingerabdruck aus Push-Adresse und Konto (FNV-1a), nur zum Erkennen von Änderungen; die Adresse selbst bleibt nicht liegen. */
export function pushFingerprint(endpoint: string, userId: number | null): string {
  let h = 0x811c9dc5;
  const text = `${userId ?? 0}|${endpoint}`;
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h.toString(16).padStart(8, '0');
}

type Store = Pick<Storage, 'getItem' | 'setItem'>;

function store(): Store | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null; // z. B. gesperrter Speicher
  }
}

/** Muss die App den Server nach dem Abo fragen? Ohne Speicher immer. */
export function pushCheckDue(fingerprint: string, now: number = Date.now(), storage: Store | null = store()): boolean {
  try {
    const saved = JSON.parse(storage?.getItem(KEY) ?? 'null') as { f?: unknown; at?: unknown } | null;
    return !saved || saved.f !== fingerprint || typeof saved.at !== 'number' || now - saved.at >= PUSH_CHECK_MAX_AGE || saved.at > now;
  } catch {
    return true;
  }
}

/** Nach einer erfolgreichen Nachfrage merken. */
export function pushCheckDone(fingerprint: string, now: number = Date.now(), storage: Store | null = store()): void {
  try {
    storage?.setItem(KEY, JSON.stringify({ f: fingerprint, at: now }));
  } catch {
    // Speicher voll oder gesperrt: dann eben beim nächsten Start wieder
  }
}
