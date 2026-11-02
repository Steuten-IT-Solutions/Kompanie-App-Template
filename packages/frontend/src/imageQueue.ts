/**
 * Bilder zum Vorladen (z. B. Foto und Abzeichen der Personen beim Blättern), eins nach dem anderen und jedes nur einmal.
 * Läuft neben der Kette der Daten her: Die wartet nicht mehr auf die Bilder, so sind die Daten aller Personen schneller
 * da. Eilige Bilder (die direkten Nachbarn) laden sofort, auch wenn sie schon hinten in der Schlange stehen.
 */
export function createImageQueue() {
  /** Schon begonnen (oder fertig): nicht noch einmal laden. */
  const started = new Map<string, Promise<void>>();
  let last: Promise<void> = Promise.resolve();

  function start(key: string, load: () => Promise<unknown>): Promise<void> {
    const running = started.get(key);
    if (running) return running;
    const p = load().then(
      () => {},
      () => {},
    );
    started.set(key, p);
    return p;
  }

  return {
    /**
     * Bild(er) unter `key` laden. urgent: sofort; sonst hinten anstellen. Das Versprechen erfüllt sich, wenn sie geladen
     * sind (Fehler bleiben still).
     */
    add(key: string, load: () => Promise<unknown>, urgent = false): Promise<void> {
      if (urgent || started.has(key)) return start(key, load);
      const queued = last.then(() => start(key, load));
      last = queued;
      return queued;
    },
    /** Alles vergessen (Tests). */
    reset() {
      started.clear();
      last = Promise.resolve();
    },
  };
}
