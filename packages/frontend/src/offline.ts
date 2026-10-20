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
