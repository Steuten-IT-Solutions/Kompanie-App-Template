import { api, ApiError, config } from './api';
import { previewRole } from './preview';
import type { TileLayouts, TilePlace } from './tileGrid';

/**
 * Wo die persönliche Anordnung der Kacheln liegt:
 * - Angemeldet: auf dem Server je Konto (PUT/DELETE /me/tiles), in der App aus /config (tile_layouts). Ohne Verbindung
 *   gilt die Änderung sofort und wird auf dem Gerät vorgemerkt (localStorage, je Konto); die App sendet sie, sobald es
 *   wieder Netz gibt oder sie neu startet. Bei zwei Geräten gewinnt, was zuletzt ankommt.
 * - Ohne Anmeldung: nur auf diesem Gerät (localStorage). Beim Anmelden wird sie nicht ins Konto übernommen.
 * - In der Vorschau („App als … ansehen“) kommt null vom Server: Es gilt die Vorgabe, Anordnen ist aus (tiles.tsx).
 */

/** Ereignis nach jeder Änderung einer Anordnung (TileGrid zeichnet neu) */
export const TILES_CHANGED = 'kompanie-kacheln-geaendert';

const GUEST_KEY = 'kompanie-kacheln-gast';
const OUTBOX_KEY = 'kompanie-kacheln-vorgemerkt';

type Pending = { user: number; page: string; cols?: number; tiles?: TilePlace[] };

function read<T>(key: string, fallback: T): T {
  try {
    const raw = globalThis.localStorage?.getItem(key);
    return raw ? (JSON.parse(raw) as T) : fallback;
  } catch {
    return fallback;
  }
}

function write(key: string, value: unknown): void {
  try {
    globalThis.localStorage?.setItem(key, JSON.stringify(value));
  } catch {
    // Speicher voll oder gesperrt (privates Fenster): gilt dann nur bis zum Neuladen
  }
}

const changed = () => window.dispatchEvent(new Event(TILES_CHANGED));
const userId = () => config?.user?.id ?? null;

/** Gespeicherte Anordnung einer Seite; undefined ohne (auch in der Vorschau: dort gilt die Vorgabe). */
export function layoutsOf(page: string): TileLayouts | undefined {
  if (!config || previewRole()) return undefined;
  if (userId() === null) return read<Record<string, TileLayouts>>(GUEST_KEY, {})[page];
  return config.tile_layouts?.[page] ?? undefined;
}

/** Anordnen möglich: nicht in der Vorschau (dort kommt null, angemeldet) */
export function canArrange(): boolean {
  return !!config && !previewRole() && (userId() === null || (config.tile_layouts !== null && config.tile_layouts !== undefined));
}

/** In der geladenen Konfiguration setzen (null: Seite zurückgesetzt) */
function apply(page: string, cols: number | undefined, tiles: TilePlace[] | undefined): void {
  if (!config) return;
  const all = { ...(config.tile_layouts ?? {}) };
  if (cols === undefined || tiles === undefined) delete all[page];
  else all[page] = { ...(all[page] ?? {}), [String(cols)]: tiles };
  config.tile_layouts = all;
}

function queue(entry: Pending): void {
  // Neueres ersetzt Älteres derselben Seite und Spaltenzahl; Zurücksetzen ersetzt alles der Seite (ein vorgemerktes
  // Zurücksetzen bleibt vor einer späteren Anordnung stehen)
  const rest = read<Pending[]>(OUTBOX_KEY, []).filter(
    (p) => !(p.user === entry.user && p.page === entry.page && (entry.cols === undefined || p.cols === entry.cols)),
  );
  write(OUTBOX_KEY, [...rest, entry]);
}

const offline = (e: unknown) => e instanceof ApiError && e.status === 0;

/** Anordnung einer Seite für eine Spaltenzahl speichern. 'pending': ohne Verbindung vorgemerkt. */
export async function saveLayout(page: string, cols: number, tiles: TilePlace[]): Promise<'saved' | 'pending'> {
  const user = userId();
  if (user === null) {
    const all = read<Record<string, TileLayouts>>(GUEST_KEY, {});
    write(GUEST_KEY, { ...all, [page]: { ...(all[page] ?? {}), [String(cols)]: tiles } });
    changed();
    return 'saved';
  }
  apply(page, cols, tiles);
  changed();
  try {
    const res = await api.saveTiles(page, cols, tiles);
    if (config) config.tile_layouts = { ...(config.tile_layouts ?? {}), [page]: res.layouts };
    return 'saved';
  } catch (e) {
    if (!offline(e)) throw e;
    queue({ user, page, cols, tiles });
    return 'pending';
  }
}

/** Anordnung der Seiten zurücksetzen (alle Spaltenzahlen); danach gilt die Vorgabe. */
export async function resetLayouts(pages: string[]): Promise<'saved' | 'pending'> {
  const user = userId();
  if (user === null) {
    const all = read<Record<string, TileLayouts>>(GUEST_KEY, {});
    pages.forEach((p) => delete all[p]);
    write(GUEST_KEY, all);
    changed();
    return 'saved';
  }
  pages.forEach((p) => apply(p, undefined, undefined));
  changed();
  let result: 'saved' | 'pending' = 'saved';
  for (const page of pages) {
    try {
      await api.resetTiles(page);
    } catch (e) {
      if (!offline(e)) throw e;
      queue({ user, page });
      result = 'pending';
    }
  }
  return result;
}

let flushing = false;

/** Vorgemerkte Änderungen des angemeldeten Kontos senden; was der Server ablehnt, fällt weg. */
export async function flushLayouts(): Promise<void> {
  const user = userId();
  if (flushing || user === null || !canArrange()) return;
  flushing = true;
  try {
    for (const entry of read<Pending[]>(OUTBOX_KEY, []).filter((p) => p.user === user)) {
      try {
        if (entry.cols !== undefined && entry.tiles) await api.saveTiles(entry.page, entry.cols, entry.tiles);
        else await api.resetTiles(entry.page);
      } catch (e) {
        if (offline(e)) return;
      }
      write(
        OUTBOX_KEY,
        read<Pending[]>(OUTBOX_KEY, []).filter((p) => p !== entry && JSON.stringify(p) !== JSON.stringify(entry)),
      );
    }
  } finally {
    flushing = false;
  }
}

/** Beim Start: Vorgemerktes in die Konfiguration übernehmen (gilt sofort, auch offline) und senden. */
export function initTileLayouts(): void {
  const user = userId();
  if (user === null || !canArrange()) return;
  for (const p of read<Pending[]>(OUTBOX_KEY, []).filter((e) => e.user === user)) apply(p.page, p.cols, p.tiles);
  window.addEventListener('online', () => void flushLayouts());
  void flushLayouts();
}
