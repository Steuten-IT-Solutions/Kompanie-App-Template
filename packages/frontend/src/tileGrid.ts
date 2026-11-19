/**
 * Raster der Kachel-Seiten (Verwaltung, Einrichtungen, Untermenüs, Gruppen der Einstellungen) mit persönlicher
 * Anordnung: Jede Kachel liegt in Spalte col (ab 1) und Zeile row (ab 1) und ist span Spalten breit. Die Spaltenzahl
 * hängt von der Breite des Rasters ab (columnsForWidth: 2, 3 oder 4); je Spaltenzahl kann eine eigene Anordnung gespeichert sein
 * (/config tile_layouts, PUT /me/tiles).
 *
 * Zusammenführen mit der Vorgabe (Reihenfolge des Admins bzw. im Code), ohne DOM:
 * - Ohne Anordnung: der Reihe nach, Breite 1, ohne Lücken (wie früher).
 * - Gespeicherte Kacheln, die die Person gerade nicht sieht, fallen weg; die Kacheln rechts davon in derselben Zeile
 *   rücken um ihre Breite nach links. Keine Kachel wechselt die Zeile, Breiten bleiben; ganz leere Zeilen fallen weg.
 * - Neue Kacheln (nicht gespeichert) kommen in der Reihenfolge der Vorgabe in neue Zeilen unter die letzte, Breite 1.
 * - Fehlt die Anordnung für diese Spaltenzahl, aber eine andere ist gespeichert, gilt deren Lesereihenfolge (Zeile,
 *   dann Spalte) mit gekürzten Breiten, ohne Lücken.
 */

export const GRID_COLUMNS = [2, 3, 4] as const;
export type GridColumns = (typeof GRID_COLUMNS)[number];

/** Lage einer Kachel im Raster */
export type TilePlace = { id: string; col: number; row: number; span: number };

/** Gespeicherte Anordnungen einer Seite: Spaltenzahl (als Text) => Kacheln */
export type TileLayouts = Partial<Record<string, TilePlace[]>>;

/**
 * Spaltenzahl nach der Breite des Rasters selbst (CSS-Pixel): 2, ab 528 px 3 (so breit ist der Inhalt bei 560 px
 * Bildschirm, der früheren Grenze), ab 680 px 4 (vier Kacheln, in die lange Wörter wie „Beförderungen“ passen; erreicht
 * nur der Inhalt über die ganze Breite, z. B. Handy quer). Am Computer ist der Inhalt höchstens 640 px breit: 3 Spalten.
 */
export function columnsForWidth(width: number): GridColumns {
  return width >= 680 ? 4 : width >= 528 ? 3 : 2;
}

const valid = (t: TilePlace, cols: number) =>
  Number.isInteger(t.col) &&
  Number.isInteger(t.row) &&
  Number.isInteger(t.span) &&
  t.col >= 1 &&
  t.row >= 1 &&
  t.span >= 1 &&
  t.col + t.span - 1 <= cols;

export const byPlace = (a: TilePlace, b: TilePlace) => a.row - b.row || a.col - b.col;

/** Der Reihe nach von links oben, ohne Lücken; zu breite Kacheln werden auf die Spaltenzahl gekürzt. */
export function flow(items: { id: string; span?: number }[], cols: number, firstRow = 1): TilePlace[] {
  const out: TilePlace[] = [];
  let row = firstRow;
  let col = 1;
  for (const item of items) {
    const span = Math.max(1, Math.min(item.span ?? 1, cols));
    if (col + span - 1 > cols) {
      row++;
      col = 1;
    }
    out.push({ id: item.id, col, row, span });
    col += span;
    if (col > cols) {
      row++;
      col = 1;
    }
  }
  return out;
}

/** Gespeicherte Anordnung, aus der eine fehlende Spaltenzahl abgeleitet wird: die nächste, bei Gleichstand die breitere. */
function nearest(saved: TileLayouts, cols: number): TilePlace[] | undefined {
  const found = GRID_COLUMNS.filter((c) => c !== cols && Array.isArray(saved[String(c)]));
  found.sort((a, b) => Math.abs(a - cols) - Math.abs(b - cols) || b - a);
  return found.length ? saved[String(found[0])] : undefined;
}

/**
 * Raster einer Seite: ids = sichtbare Kacheln in der Reihenfolge der Vorgabe. Ergebnis nach Zeile und Spalte geordnet
 * (so stehen die Kacheln auch im HTML, für Tastatur und Bildschirmleser).
 */
export function resolveTiles(ids: string[], saved: TileLayouts | undefined, cols: number): TilePlace[] {
  const visible = new Set(ids);
  const own = saved?.[String(cols)];
  if (!saved || !Array.isArray(own)) {
    const other = saved ? nearest(saved, cols) : undefined;
    if (!other)
      return flow(
        ids.map((id) => ({ id })),
        cols,
      );
    const seen = new Set<string>();
    const ordered = [...other].filter((t) => valid(t, 4) && visible.has(t.id) && !seen.has(t.id) && seen.add(t.id)).sort(byPlace);
    const placed = flow(ordered, cols);
    return appendNew(placed, ids, cols);
  }

  // Gespeicherte Lage; doppelte oder kaputte Einträge zählen als nicht gespeichert (kommen als neue Kacheln dazu)
  const seen = new Set<string>();
  const stored = own.filter((t) => valid(t, cols) && !seen.has(t.id) && seen.add(t.id)).sort(byPlace);
  const rows = new Map<number, TilePlace[]>();
  for (const t of stored) rows.set(t.row, [...(rows.get(t.row) ?? []), t]);
  const kept: TilePlace[] = [];
  const taken = new Set<string>();
  for (const [row, tiles] of rows) {
    let shift = 0;
    for (const t of tiles) {
      if (!visible.has(t.id)) {
        shift += t.span;
        continue;
      }
      const col = t.col - shift;
      // Überlappung (nur bei beschädigten Daten möglich): Kachel kommt als neue dazu
      const cells = Array.from({ length: t.span }, (_, i) => `${row}:${col + i}`);
      if (cells.some((c) => taken.has(c))) {
        seen.delete(t.id);
        continue;
      }
      cells.forEach((c) => taken.add(c));
      kept.push({ id: t.id, col, row, span: t.span });
    }
  }
  // Ganz leere Zeilen fallen weg
  const used = [...new Set(kept.map((t) => t.row))].sort((a, b) => a - b);
  const renumber = new Map(used.map((r, i) => [r, i + 1]));
  const compact = kept.map((t) => ({ ...t, row: renumber.get(t.row)! }));
  return appendNew(compact, ids, cols);
}

/** Sichtbare Kacheln, die noch nicht liegen: der Reihe nach in neue Zeilen unter die letzte, Breite 1. */
function appendNew(placed: TilePlace[], ids: string[], cols: number): TilePlace[] {
  const have = new Set(placed.map((t) => t.id));
  const fresh = ids.filter((id, i) => !have.has(id) && ids.indexOf(id) === i);
  const last = placed.reduce((m, t) => Math.max(m, t.row), 0);
  return [
    ...placed,
    ...flow(
      fresh.map((id) => ({ id })),
      cols,
      last + 1,
    ),
  ].sort(byPlace);
}

/** Zahl der Zeilen des Rasters */
export function rowCount(tiles: TilePlace[]): number {
  return tiles.reduce((m, t) => Math.max(m, t.row), 0);
}
