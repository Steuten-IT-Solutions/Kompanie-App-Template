/**
 * Daten der wahrscheinlich nächsten Ansicht vorab laden (Issue #104), damit sie schon beim ersten Öffnen ohne „Lädt …“
 * erscheint. Zwei Wege:
 * - tap: beim Antippen (pointerdown, etwa 100–300 ms vor dem click) bzw. Fokus per Tastatur, vor allem anderen;
 * - idle: nach dem Start, wenn der Browser untätig ist, eins nach dem anderen (nicht im Datensparmodus, nicht in einem
 *   langsamen Netz, nicht offline).
 * Höchstens eine Vorab-Anfrage gleichzeitig. Abgelegt wird über dataCache.prefetch: unter dem Schlüssel der Ansicht,
 * nur wenn seit dem Start nichts geändert wurde, nichts doppelt. Fehler bleiben still (auch nicht unter „Fehler melden“):
 * Die Ansicht lädt dann beim Öffnen selbst.
 * Ohne eigene Imports (dataCache und lastError gibt viewData.ts hinein), damit die Tests es direkt laden können.
 */

/** Was vorab geladen wird: wie useAsync(load, deps, key) (viewData.ts). */
export interface PrefetchSource {
  key: string;
  deps: unknown[];
  load: () => Promise<unknown>;
}

/** Was der Browser über die Verbindung weiß (navigator.connection gibt es nicht überall, z. B. nicht in Safari). */
export interface NetworkInfo {
  onLine?: boolean;
  connection?: { saveData?: boolean; effectiveType?: string };
}

/** Im Leerlauf vorab laden? Nicht offline, nicht im Datensparmodus (saveData) und nicht in einem langsamen Netz (2G). */
export function idleNetworkOk(nav: NetworkInfo): boolean {
  if (nav.onLine === false) return false;
  const c = nav.connection;
  return !c?.saveData && c?.effectiveType !== '2g' && c?.effectiveType !== 'slow-2g';
}

/** Wartet, bis der Browser untätig ist; ohne requestIdleCallback (Safari) eine Sekunde. */
export function whenIdle(): Promise<void> {
  return new Promise((resolve) => {
    if (typeof requestIdleCallback === 'function') requestIdleCallback(() => resolve(), { timeout: 5000 });
    else setTimeout(resolve, 1000);
  });
}

export interface PrefetcherOptions {
  /** Gar nicht vorab laden (Vorschau „App als Rolle ansehen“, Wartungsmodus). */
  blocked: () => boolean;
  /** Im Leerlauf vorab laden (Netz, siehe idleNetworkOk)? Wird vor jeder Anfrage erneut gefragt. */
  idleOk: () => boolean;
  whenIdle?: () => Promise<void>;
  /** dataCache.prefetch */
  prefetch: (key: string, deps: unknown[], load: () => Promise<unknown>) => Promise<void>;
  /** lastError.forgetError: Der Fehler steht an ApiError.noted. */
  forgetError: (noted: unknown) => void;
}

export function createPrefetcher({ blocked, idleOk, whenIdle: idle = whenIdle, prefetch, forgetError }: PrefetcherOptions) {
  const queue: { source: PrefetchSource; idle: boolean }[] = [];
  let running = false;

  const run = (s: PrefetchSource) =>
    prefetch(s.key, s.deps, () =>
      s.load().catch((e: unknown) => {
        forgetError((e as { noted?: unknown } | null)?.noted);
        throw e;
      }),
    );

  async function pump(): Promise<void> {
    if (running) return;
    running = true;
    try {
      while (queue.length > 0) {
        // Erst im Leerlauf; tippt jemand inzwischen, kommt das zuerst dran.
        if (queue[0].idle) await idle();
        const job = queue.shift()!;
        if (blocked() || (job.idle && !idleOk())) continue;
        await run(job.source);
      }
    } finally {
      running = false;
    }
  }

  function add(sources: PrefetchSource[], idleJob: boolean): Promise<void> {
    if (blocked() || (idleJob && !idleOk()) || sources.length === 0) return Promise.resolve();
    const jobs = sources.map((source) => ({ source, idle: idleJob }));
    if (idleJob) queue.push(...jobs);
    else queue.unshift(...jobs);
    return pump();
  }

  return {
    blocked,
    /** Beim Antippen: vor allem, was im Leerlauf noch wartet. */
    tap: (sources: PrefetchSource[]) => add(sources, false),
    /** Im Leerlauf, eins nach dem anderen. */
    idle: (sources: PrefetchSource[]) => add(sources, true),
  };
}
