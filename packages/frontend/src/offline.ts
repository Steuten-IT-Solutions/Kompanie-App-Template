// Hilfen für die Offline-Verfügbarkeit über den Service Worker.

/**
 * Wartet, bis der Service Worker die Seite steuert. Beim allerersten Aufruf der App
 * dauert das einen Moment; ohne ihn landet die Datei nicht im Offline-Speicher.
 */
export async function whenControlled(timeoutMs = 3000): Promise<void> {
  if (!('serviceWorker' in navigator) || navigator.serviceWorker.controller) return;
  await new Promise<void>((resolve) => {
    const timer = setTimeout(resolve, timeoutMs);
    navigator.serviceWorker.addEventListener(
      'controllerchange',
      () => {
        clearTimeout(timer);
        resolve();
      },
      { once: true },
    );
  });
}

/** Name des Speichers für Dokumente im Service Worker (public/sw.js). */
const DOCS_CACHE = 'kompanie-docs-v1';

/**
 * Gespeicherte Dokumente entfernen, die es nicht mehr gibt oder die man nicht mehr sehen darf (nicht mehr in der Liste),
 * und ältere Fassungen. Sonst blieben sie bis zum Abmelden auf dem Gerät.
 */
export async function pruneDocumentCache(urls: string[]): Promise<void> {
  try {
    if (!('caches' in window) || !(await caches.has(DOCS_CACHE))) return;
    const keep = new Set(urls.map((u) => new URL(u, location.href).href));
    const cache = await caches.open(DOCS_CACHE);
    await Promise.all((await cache.keys()).filter((r) => !keep.has(r.url)).map((r) => cache.delete(r)));
  } catch {
    // Speicher nicht verfügbar: nichts zu tun.
  }
}
