import type { DeviceStore } from './deviceStore';

/**
 * Zuletzt geladene Daten der Ansichten (useAsync mit Schlüssel): im Speicher und, nach restore(), auch auf dem Gerät.
 * Stale-while-revalidate: Beim nächsten Öffnen erscheinen sie sofort, frische Daten kommen im Hintergrund – auch nach
 * einem Neustart der App (restore() lädt die vom Gerät, bevor die App startet).
 * Jede eigene Änderung (Anfrage mit anderer Methode als GET) leert beide Speicher, damit danach nichts Veraltetes
 * aufblitzt. Beim Abmelden lädt die App neu und löscht die Speicher auf dem Gerät.
 */
const entries = new Map<string, unknown>();
let generation = 0;
let device: DeviceStore | null = null;

export const dataCache = {
  has: (key: string) => entries.has(key),
  get: <T>(key: string) => entries.get(key) as T,
  /**
   * Nur speichern, wenn seit `since` (siehe generation()) nichts geändert wurde: sonst sind die Daten evtl. veraltet.
   * `onDevice: false` behält sie nur im Speicher (z. B. Verwaltung für Admins, die auch der Service Worker nie speichert).
   */
  set(key: string, value: unknown, since = generation, onDevice = true) {
    if (since !== generation) return;
    entries.set(key, value);
    if (onDevice) device?.put(key, value);
  },
  /** Stand der Änderungen; eine Ladeanfrage merkt ihn sich beim Start. */
  generation: () => generation,
  clear() {
    entries.clear();
    device?.clear();
    generation++;
  },
  /** Daten vom Gerät laden (Bereich: Version und Benutzer, siehe deviceStore) und ab jetzt dort mitspeichern. */
  async restore(scope: string, store: DeviceStore) {
    device = store;
    const since = generation;
    const found = await store.open(scope);
    // Inzwischen Geladenes ist neuer; nach einer eigenen Änderung gilt nichts mehr davon.
    if (since !== generation) return;
    for (const [key, value] of found) if (!entries.has(key)) entries.set(key, value);
  },
};
