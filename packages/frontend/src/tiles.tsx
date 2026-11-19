import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { config } from './api';
import { columnsForWidth, resolveTiles, type GridColumns, type TileLayouts } from './tileGrid';

/**
 * Spaltenzahl des Kachelrasters nach seiner eigenen Breite (columnsForWidth); folgt Drehen und Größe. Bis zum ersten
 * Messen gilt die Breite des Fensters abzüglich des Seitenrands.
 */
export function useGridColumns(ref: RefObject<HTMLElement | null>): GridColumns {
  const [cols, setCols] = useState<GridColumns>(() => columnsForWidth(Math.min(globalThis.innerWidth ?? 0, 640) - 32));
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => setCols(columnsForWidth(el.getBoundingClientRect().width / zoom()));
    measure();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, [ref]);
  return cols;
}

/** „zoom“ der Seite (viewport.ts): getBoundingClientRect misst in Bildschirm-Pixeln, das Raster rechnet in Seiten-Pixeln. */
function zoom(): number {
  return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--kompanie-zoom')) || 1;
}

/** Gespeicherte persönliche Anordnung einer Seite (aus /config); undefined ohne. */
export function savedTiles(page: string): TileLayouts | undefined {
  return config?.tile_layouts?.[page];
}

/** Eine Kachel des Rasters: Kennung (Bereich bzw. Ziel) und ihr Inhalt, meist ein Knopf. */
export type GridTile = { id: string; node: ReactNode };

/**
 * Kachelraster mit fester Spaltenzahl: Lage und Breite je Kachel aus der persönlichen Anordnung der Seite, sonst der
 * Reihe nach (tileGrid.ts). page: z. B. verwaltung, intern, menue/2, einstellungen/betrieb.
 */
export function TileGrid({ page, tiles, className }: { page: string; tiles: GridTile[]; className: string }) {
  const ref = useRef<HTMLUListElement>(null);
  const cols = useGridColumns(ref);
  const byId = new Map(tiles.map((t) => [t.id, t]));
  const placed = resolveTiles(
    tiles.map((t) => t.id),
    savedTiles(page),
    cols,
  );
  return (
    <ul ref={ref} className={`${className} tile-grid`} style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}>
      {placed.map((p) => (
        <li
          key={p.id}
          className={p.span > 1 ? 'wide' : undefined}
          style={{ gridColumn: `${p.col} / span ${p.span}`, gridRow: String(p.row) }}
        >
          {byId.get(p.id)?.node}
        </li>
      ))}
    </ul>
  );
}
