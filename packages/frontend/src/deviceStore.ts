/**
 * Speicher der Ansichten auf dem Gerät (Cache Storage, wie der Service Worker): Daten und Gerüste der Seiten, damit
 * sie auch nach einem Neustart der App sofort erscheinen. Beim Abmelden löscht die App alle Speicher außer den
 * Programmdateien, also auch diese (App.tsx, clearCaches); der Service Worker behält sie bei einem Update.
 *
 * Jeder Eintrag gehört zu einem Bereich aus Version der App und Benutzer: Was zu einer anderen Version (anderes Format)
 * oder einem anderen Konto gehört (Sitzung abgelaufen, jemand anderes angemeldet), wird beim Laden gelöscht und nie
 * gezeigt. Alle Schreibvorgänge laufen nacheinander, damit ein Leeren nicht von einem älteren Speichern überholt wird.
 */
export interface DeviceStore {
  /** Lädt die Einträge des Bereichs, löscht alle anderen und speichert ab jetzt im Bereich. */
  open(scope: string): Promise<Map<string, unknown>>;
  put(key: string, value: unknown): void;
  /** Alle Einträge löschen; der Bereich bleibt. */
  clear(): void;
}

const PREFIX = '__kompanie-ansicht/';

export function deviceStore(name: string, max: number, storage: CacheStorage | undefined = globalThis.caches): DeviceStore {
  let scope: string | null = null;
  let queue: Promise<unknown> = Promise.resolve();
  let puts = 0;

  const base = () => `${globalThis.location?.origin ?? 'https://app.invalid'}/${PREFIX}${encodeURIComponent(scope!)}/`;
  const run = (job: (cache: Cache) => Promise<unknown>) => {
    if (scope === null || !storage) return;
    queue = queue
      .then(() => storage.open(name))
      .then(job)
      .catch(() => undefined);
  };

  async function trim(cache: Cache) {
    const keys = await cache.keys();
    // In der Reihenfolge des Speicherns: Die ältesten fallen zuerst heraus.
    await Promise.all(keys.slice(0, Math.max(0, keys.length - max)).map((k) => cache.delete(k)));
  }

  return {
    async open(next) {
      scope = next;
      const found = new Map<string, unknown>();
      const prefix = base();
      run(async (cache) => {
        for (const req of await cache.keys()) {
          if (!req.url.startsWith(prefix)) {
            await cache.delete(req);
            continue;
          }
          const res = await cache.match(req);
          const value = res ? await res.json().catch(() => null) : null;
          if (value !== null) found.set(decodeURIComponent(req.url.slice(prefix.length)), value);
        }
      });
      await queue;
      return found;
    },
    put(key, value) {
      run(async (cache) => {
        const url = base() + encodeURIComponent(key);
        // Neu einfügen statt ersetzen: Dann steht der Eintrag hinten und fällt zuletzt heraus.
        await cache.delete(url);
        await cache.put(url, new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } }));
        if (++puts % 10 === 0) await trim(cache);
      });
    },
    clear() {
      run(async (cache) => {
        await Promise.all((await cache.keys()).map((k) => cache.delete(k)));
      });
    },
  };
}
