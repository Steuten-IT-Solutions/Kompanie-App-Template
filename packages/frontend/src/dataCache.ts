/**
 * Zuletzt geladene Daten der Ansichten (useAsync mit Schlüssel), nur im Speicher.
 * Stale-while-revalidate: Beim nächsten Öffnen erscheinen sie sofort, frische Daten kommen im Hintergrund.
 * Jede eigene Änderung (Anfrage mit anderer Methode als GET) leert den Speicher, damit danach nichts Veraltetes
 * aufblitzt. Beim Abmelden lädt die App neu; damit ist auch der Speicher leer.
 * Höchstens MAX_ENTRIES Einträge (Detailansichten legen je Mitglied, Liste usw. einen an; beim Blättern lädt die App
 * alle Mitglieder der Liste vor): Der am längsten nicht gespeicherte fällt zuerst heraus.
 */
export const MAX_ENTRIES = 1000;
const entries = new Map<string, unknown>();
let generation = 0;

/** Meldung „neue Daten“ (Service Worker, Issue #108): Ansichten laden neu. */
export const DATA_UPDATED = 'kompanie-data-updated';
let updateTimer: ReturnType<typeof setTimeout> | undefined;

/** Neue Daten im Hintergrund angekommen: offene Ansichten neu laden (mehrere Meldungen kurz hintereinander gebündelt). */
export function dataUpdated(): void {
  // Vorab Geladenes ist dann nicht mehr frisch: Die Ansicht fragt beim Öffnen wieder.
  fresh.clear();
  clearTimeout(updateTimer);
  updateTimer = setTimeout(() => window.dispatchEvent(new Event(DATA_UPDATED)), 100);
}

export const dataCache = {
  has: (key: string) => entries.has(key),
  get: <T>(key: string) => entries.get(key) as T,
  /** Nur speichern, wenn seit `since` (siehe generation()) nichts geändert wurde: sonst sind die Daten evtl. veraltet. */
  set(key: string, value: unknown, since = generation) {
    if (since !== generation) return;
    // Neu einfügen, damit der Eintrag in der Reihenfolge nach hinten rückt.
    entries.delete(key);
    entries.set(key, value);
    while (entries.size > MAX_ENTRIES) entries.delete(entries.keys().next().value!);
  },
  /** Stand der Änderungen; eine Ladeanfrage merkt ihn sich beim Start. */
  generation: () => generation,
  clear() {
    entries.clear();
    fresh.clear();
    generation++;
  },
};

/** Schlüssel im Speicher für useAsync mit `key` und `deps`. */
export function cacheKey(key: string, deps: unknown[]): string {
  return `${key}|${JSON.stringify(deps)}`;
}

const pending = new Map<string, { since: number; promise: Promise<unknown> }>();
/**
 * Beim Antippen vorab geladen, noch nicht angezeigt: Zeitpunkt; bis FRESH_MS danach fragt die Ansicht beim Öffnen nicht
 * noch einmal. Nicht im Leerlauf oder beim Wischen: Bis die Ansicht öffnet, kann sich dann auf dem Server (z. B. an
 * einem anderen Gerät) etwas geändert haben; dort lädt sie wie sonst im Hintergrund neu.
 */
const fresh = new Map<string, number>();
export const FRESH_MS = 10_000;

/**
 * Daten für eine Ansicht schon laden, bevor sie geöffnet wird (z. B. das nächste Mitglied beim Wischen, ein Tab beim
 * Antippen, Issue #104): Sie liegen dann unter demselben Schlüssel wie bei useAsync(fn, deps, key) und erscheinen beim
 * Öffnen sofort. Lädt nur, wenn noch nichts gespeichert ist und keine Anfrage dafür läuft; Fehler bleiben still (die
 * Ansicht lädt dann selbst). markFresh: beim Antippen (siehe `fresh`).
 */
export function prefetch<T>(key: string, deps: unknown[], fn: () => Promise<T>, markFresh = false): Promise<void> {
  const k = cacheKey(key, deps);
  if (entries.has(k)) return Promise.resolve();
  let p = pending.get(k)?.promise;
  if (!p) {
    const since = generation;
    p = fn();
    pending.set(k, { since, promise: p });
    p.then(
      (d) => {
        if (d === null || since !== generation) return;
        dataCache.set(k, d, since);
        if (markFresh) fresh.set(k, Date.now());
      },
      () => {},
    ).finally(() => pending.delete(k));
  }
  return p.then(
    () => {},
    () => {},
  );
}

/**
 * Laufende Vorab-Anfrage für diesen Schlüssel (cacheKey), wenn sie seit der letzten eigenen Änderung begann: Die
 * Ansicht wartet dann auf sie, statt dieselben Daten ein zweites Mal anzufragen.
 */
export function pendingPrefetch(k: string): Promise<unknown> | undefined {
  const p = pending.get(k);
  return p && p.since === generation ? p.promise : undefined;
}

/** Gerade erst beim Antippen vorab geladen (höchstens FRESH_MS her)? Gilt nur einmal: Danach lädt die Ansicht wie sonst im Hintergrund neu. */
export function takeFresh(k: string, now = Date.now()): boolean {
  const at = fresh.get(k);
  fresh.delete(k);
  return at !== undefined && now - at < FRESH_MS;
}
