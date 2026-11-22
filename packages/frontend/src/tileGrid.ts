/**
 * Raster der Kachel-Seiten (Verwaltung, Einrichtungen, Untermenüs, Gruppen der Einstellungen) mit persönlicher
 * Anordnung: Jede Kachel liegt in Spalte col (ab 1) und Zeile row (ab 1) und ist span Spalten breit. Die Spaltenzahl
 * hängt von der Breite des Rasters ab (columnsForWidth: 2, 3 oder 4); je Spaltenzahl kann eine eigene Anordnung gespeichert sein
 * (/config tile_layouts, PUT /me/tiles).
 *
 * Zusammenführen mit der Vorgabe (Reihenfolge des Admins bzw. im Code), ohne DOM:
 * - Ohne Anordnung: der Reihe nach, Breite 1, ohne Lücken (wie früher).
 * - Gespeicherte Kacheln, die die Person gerade nicht sieht, fallen weg; die schmalste Kachel derselben Zeile (bei
 *   Gleichstand die linke) wird um ihre Breite breiter, die Zeile reicht so weit wie vorher. Keine Kachel wechselt die
 *   Zeile; ganz leere Zeilen fallen weg.
 * - Neue Kacheln (nicht gespeichert) kommen in der Reihenfolge der Vorgabe in neue Zeilen unter die letzte, Breite 1.
 * - Fehlt die Anordnung für diese Spaltenzahl, aber eine andere ist gespeichert, gilt deren Lesereihenfolge (Zeile,
 *   dann Spalte) mit gekürzten Breiten, ohne Lücken.
 */

export const GRID_COLUMNS = [2, 3, 4, 5] as const;
export type GridColumns = (typeof GRID_COLUMNS)[number];
const MAX_COLUMNS = Math.max(...GRID_COLUMNS);

/** Lage einer Kachel im Raster */
export type TilePlace = { id: string; col: number; row: number; span: number };

/** Gespeicherte Anordnungen einer Seite: Spaltenzahl (als Text) => Kacheln */
export type TileLayouts = Partial<Record<string, TilePlace[]>>;

/**
 * Spaltenzahl nach der Breite des Rasters selbst (CSS-Pixel): 2, ab 528 px 3 (so breit ist der Inhalt bei 560 px
 * Bildschirm, der früheren Grenze), ab 680 px 4 (vier Kacheln, in die lange Wörter wie „Beförderungen“ passen; erreicht
 * nur der Inhalt über die ganze Breite, z. B. Handy quer), ab 840 px 5 (gleich breite Kacheln wie 4 bei 680 px). Am
 * Computer sind Seiten mit Kacheln bis 904 px breit (styles.css): 5 Spalten ab etwa 900 px Fensterbreite.
 */
export function columnsForWidth(width: number): GridColumns {
  return width >= 840 ? 5 : width >= 680 ? 4 : width >= 528 ? 3 : 2;
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
    const ordered = [...other].filter((t) => valid(t, MAX_COLUMNS) && visible.has(t.id) && !seen.has(t.id) && seen.add(t.id)).sort(byPlace);
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
    for (const t of closeGaps(tiles, visible)) {
      // Überlappung (nur bei beschädigten Daten möglich): Kachel kommt als neue dazu
      const cells = Array.from({ length: t.span }, (_, i) => `${row}:${t.col + i}`);
      if (cells.some((c) => taken.has(c))) {
        seen.delete(t.id);
        continue;
      }
      cells.forEach((c) => taken.add(c));
      kept.push({ id: t.id, col: t.col, row, span: t.span });
    }
  }
  // Ganz leere Zeilen fallen weg
  const used = [...new Set(kept.map((t) => t.row))].sort((a, b) => a - b);
  const renumber = new Map(used.map((r, i) => [r, i + 1]));
  const compact = kept.map((t) => ({ ...t, row: renumber.get(t.row)! }));
  return appendNew(compact, ids, cols);
}

/**
 * Eine Zeile ohne die nicht sichtbaren Kacheln (nach Spalte geordnet): Die Kacheln rechts einer fehlenden rücken um
 * ihre Breite nach links, dann bekommt die schmalste Kachel der Zeile (bei Gleichstand die linke) die Breite der
 * fehlenden dazu, die Kacheln rechts von ihr rücken dafür wieder nach rechts. Bei mehreren fehlenden der Reihe nach,
 * jeweils an die dann schmalste. So reicht die Zeile so weit wie vorher; gewollte Lücken bleiben.
 */
function closeGaps(tiles: TilePlace[], visible: Set<string>): TilePlace[] {
  const out: TilePlace[] = [];
  const missing: number[] = [];
  let shift = 0;
  for (const t of tiles) {
    if (visible.has(t.id)) out.push({ ...t, col: t.col - shift });
    else {
      shift += t.span;
      missing.push(t.span);
    }
  }
  if (out.length === 0) return out;
  for (const span of missing) {
    const k = out.reduce((best, t, i) => (t.span < out[best].span ? i : best), 0);
    out[k].span += span;
    for (let i = k + 1; i < out.length; i++) out[i].col += span;
  }
  return out;
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

// ---- Anordnen (Bearbeiten-Modus) ----

const overlaps = (a: TilePlace, b: TilePlace) => a.row === b.row && a.col < b.col + b.span && b.col < a.col + a.span;

/**
 * Verdrängen: fixed bleibt, wo es ist; jede Kachel, die mit einer schon gelegten überlappt, rutscht so weit nach unten,
 * bis sie frei ist (und schiebt dabei weitere). Danach fallen ganz leere Zeilen weg. Ergebnis nach Zeile und Spalte.
 */
function settle(fixed: TilePlace, others: TilePlace[]): TilePlace[] {
  const placed: TilePlace[] = [fixed];
  for (const t of [...others].sort(byPlace)) {
    const next = { ...t };
    while (placed.some((p) => overlaps(p, next))) next.row++;
    placed.push(next);
  }
  const used = [...new Set(placed.map((t) => t.row))].sort((a, b) => a - b);
  const renumber = new Map(used.map((r, i) => [r, i + 1]));
  return placed.map((t) => ({ ...t, row: renumber.get(t.row)! })).sort(byPlace);
}

/** Kachel id nach Spalte col, Zeile row legen (zu weit rechts: so weit nach links, dass sie passt); belegt: verdrängen. */
export function moveTile(tiles: TilePlace[], id: string, col: number, row: number, cols: number): TilePlace[] {
  const tile = tiles.find((t) => t.id === id);
  if (!tile) return tiles;
  const span = Math.min(tile.span, cols);
  const moved = { id, span, row: Math.max(1, Math.round(row)), col: Math.max(1, Math.min(Math.round(col), cols - span + 1)) };
  return settle(
    moved,
    tiles.filter((t) => t.id !== id),
  );
}

/** Breite um delta ändern (1 bis cols); passt sie nicht mehr bis zum rechten Rand, rückt die Kachel nach links. */
export function resizeTile(tiles: TilePlace[], id: string, delta: number, cols: number): TilePlace[] {
  const tile = tiles.find((t) => t.id === id);
  if (!tile) return tiles;
  const span = Math.max(1, Math.min(tile.span + delta, cols));
  return settle(
    { ...tile, span, col: Math.min(tile.col, cols - span + 1) },
    tiles.filter((t) => t.id !== id),
  );
}

/** Gleiche Lage aller Kacheln? (Speichern nur bei echter Änderung) */
export function sameTiles(a: TilePlace[], b: TilePlace[]): boolean {
  const key = (l: TilePlace[]) =>
    [...l]
      .sort(byPlace)
      .map((t) => `${t.id}@${t.col},${t.row},${t.span}`)
      .join(';');
  return key(a) === key(b);
}

/**
 * Feld unter einem Punkt: x, y relativ zur linken oberen Ecke des Rasters; Spalten- und Zeilenhöhen aus dem gerechneten
 * Stil (grid-template-columns/-rows in px), gap der Abstand. Unterhalb der letzten Zeile zählen weitere Zeilen in der
 * Höhe der letzten.
 */
export function cellAt(x: number, y: number, columns: number[], rows: number[], gap: number): { col: number; row: number } {
  const index = (pos: number, sizes: number[], open: boolean) => {
    let edge = 0;
    for (let i = 0; i < sizes.length; i++) {
      edge += sizes[i] + gap;
      if (pos < edge - gap / 2) return i + 1;
    }
    if (!open || sizes.length === 0) return Math.max(1, sizes.length);
    const last = sizes[sizes.length - 1] + gap;
    return sizes.length + 1 + Math.floor(Math.max(0, pos - edge + gap / 2) / last);
  };
  return { col: index(Math.max(0, x), columns, false), row: index(Math.max(0, y), rows, true) };
}

/** Leere Felder in den Zeilen 1 bis rows (für die Felder im Bearbeiten-Modus) */
export function emptyCells(tiles: TilePlace[], cols: number, rows: number): { col: number; row: number }[] {
  const taken = new Set(tiles.flatMap((t) => Array.from({ length: t.span }, (_, i) => `${t.row}:${t.col + i}`)));
  const out: { col: number; row: number }[] = [];
  for (let row = 1; row <= rows; row++) for (let col = 1; col <= cols; col++) if (!taken.has(`${row}:${col}`)) out.push({ col, row });
  return out;
}
