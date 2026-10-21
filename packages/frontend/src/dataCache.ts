/**
 * Zuletzt geladene Daten der Ansichten (useAsync mit Schlüssel), nur im Speicher.
 * Stale-while-revalidate: Beim nächsten Öffnen erscheinen sie sofort, frische Daten kommen im Hintergrund.
 * Jede eigene Änderung (Anfrage mit anderer Methode als GET) leert den Speicher, damit danach nichts Veraltetes
 * aufblitzt. Beim Abmelden lädt die App neu; damit ist auch der Speicher leer.
 * Höchstens MAX_ENTRIES Einträge (Detailansichten legen je Mitglied, Liste usw. einen an): Der am längsten nicht
 * gespeicherte fällt zuerst heraus.
 */
export const MAX_ENTRIES = 200;
const entries = new Map<string, unknown>();
let generation = 0;

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
    generation++;
  },
};
