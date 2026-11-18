/**
 * Warteschlangen für Änderungen, die ohne Verbindung gemacht wurden und später an den Server gehen.
 *
 * Allgemeiner Teil: Speicher auf dem Gerät (localStorage, übersteht Neustart und Update der App), je Bereich ein Eintrag
 * `kompanie-offline/<bereich>` mit Einträgen nach Schlüssel (z. B. ID der Liste). Die Einträge gehören zu einem Konto:
 * Was ein anderes Konto abgelegt hat, sieht und sendet die App nicht. Beim Abmelden löscht die App alle Warteschlangen
 * (clearOfflineQueues in App.tsx). Was ein Bereich ablegt, wie er zusammenführt und sendet, regelt er selbst: Anwesenheit
 * (attendanceOutbox.ts), Zu- und Absagen (responseOutbox.ts), Punkte und Strafen (bookingOutbox.ts). Was der Server beim
 * späteren Senden ablehnt, bleibt sichtbar, bis die Person es gelesen hat (offlineRejected.ts).
 *
 * Reine Logik ohne API, damit sie sich ohne Browser testen lässt.
 */

export const OFFLINE_PREFIX = 'kompanie-offline/';

export type KeyValue = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> & Partial<Pick<Storage, 'key' | 'length'>>;

interface Stored<T> {
  user: number;
  items: Record<string, T>;
}

export interface OfflineQueue<T> {
  /** Alle gültigen Einträge des Kontos */
  all(): Record<string, T>;
  /** Ersetzt alle Einträge; false: Das Gerät kann nichts speichern (privater Modus, Speicher voll). */
  replace(items: Record<string, T>): boolean;
  clear(): void;
}

/**
 * @param area Name des Bereichs (Teil des Schlüssels im Speicher)
 * @param valid prüft einen gelesenen Eintrag; null verwirft ihn (alte Fassung, kaputt, leer)
 */
export function createOfflineQueue<T>(
  area: string,
  storage: KeyValue | null | undefined,
  user: () => number,
  valid: (raw: unknown, key: string) => T | null,
): OfflineQueue<T> {
  const key = OFFLINE_PREFIX + area;
  return {
    all() {
      try {
        const raw = JSON.parse(storage?.getItem(key) ?? 'null') as Stored<unknown> | null;
        if (!raw || typeof raw !== 'object' || raw.user !== user() || !raw.items || typeof raw.items !== 'object') return {};
        const items: Record<string, T> = {};
        for (const [k, v] of Object.entries(raw.items)) {
          const item = valid(v, k);
          if (item !== null) items[k] = item;
        }
        return items;
      } catch {
        return {};
      }
    },
    replace(items) {
      try {
        if (!storage) return false;
        if (Object.keys(items).length === 0) storage.removeItem(key);
        else storage.setItem(key, JSON.stringify({ user: user(), items } satisfies Stored<T>));
        return true;
      } catch {
        return false;
      }
    },
    clear() {
      try {
        storage?.removeItem(key);
      } catch {
        // nichts zu tun
      }
    },
  };
}

/** Alle Warteschlangen löschen (Abmelden). */
export function clearOfflineQueues(storage: KeyValue | null | undefined): void {
  try {
    if (!storage || storage.key === undefined || storage.length === undefined) return;
    const keys: string[] = [];
    for (let i = 0; i < storage.length; i++) {
      const k = storage.key(i);
      if (k?.startsWith(OFFLINE_PREFIX)) keys.push(k);
    }
    keys.forEach((k) => storage.removeItem(k));
  } catch {
    // nichts zu tun
  }
}

/**
 * Was tun, wenn das Senden scheitert? `retry`: später noch einmal (keine Verbindung, Server gestört, Sitzung abgelaufen,
 * Wartung). `drop`: Der Server lehnt die Änderung ab (gelöscht, kein Recht mehr, ungültig); ein neuer Versuch brächte
 * dasselbe.
 */
export function failureAction(status: number): 'retry' | 'drop' {
  return status === 0 || status === 401 || status === 408 || status === 429 || status >= 500 ? 'retry' : 'drop';
}

/** HTTP-Status eines Fehlers der API (ApiError); andere Fehler (z. B. im Code der App) haben keinen. */
function statusOf(e: unknown): number | null {
  const status = (e as { status?: unknown } | null)?.status;
  return typeof status === 'number' ? status : null;
}

/**
 * Soll eine Änderung, deren Senden gerade gescheitert ist, auf dem Gerät vorgemerkt werden? Ja bei allem, was ein
 * späterer Versuch beheben kann (failureAction `retry`): keine Verbindung, keine Antwort in der Zeit, Server gestört,
 * Wartung, Sitzung abgelaufen. Nein, wenn der Server die Änderung ablehnt: Dann sieht die Person die Meldung gleich.
 */
export function shouldQueue(e: unknown): boolean {
  const status = statusOf(e);
  return status !== null && failureAction(status) === 'retry';
}

/** Meldung nach dem Vormerken: warum es nicht gleich ging und dass die App es später sendet. */
export function queuedMessage(e: unknown): string {
  const status = statusOf(e) ?? 0;
  const why =
    status === 0
      ? 'Keine Verbindung zum Server.'
      : status === 401
        ? 'Deine Anmeldung ist abgelaufen.'
        : 'Der Server ist gerade nicht erreichbar.';
  return `${why} Auf diesem Gerät gespeichert, die App überträgt es, sobald es wieder geht.`;
}
