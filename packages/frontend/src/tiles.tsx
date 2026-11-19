import {
  createContext,
  useContext,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type PointerEvent,
  type ReactNode,
  type RefObject,
} from 'react';
import { confirmAsk } from './ask';
import { useBackClose } from './backClose';
import { scrollSpeed } from './dragSort';
import { IconButton } from './icons';
import {
  cellAt,
  columnsForWidth,
  emptyCells,
  moveTile,
  resizeTile,
  resolveTiles,
  rowCount,
  sameTiles,
  type GridColumns,
  type TilePlace,
} from './tileGrid';
import { canArrange, layoutsOf, resetLayouts, saveLayout, TILES_CHANGED } from './tileStore';

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

/** Neu zeichnen, wenn sich eine Anordnung ändert (auch aus einem anderen Raster derselben Seite). */
function useTilesChanged(): void {
  const [, setN] = useState(0);
  useEffect(() => {
    const on = () => setN((n) => n + 1);
    window.addEventListener(TILES_CHANGED, on);
    return () => window.removeEventListener(TILES_CHANGED, on);
  }, []);
}

/** Bearbeiten-Modus einer Seite (TileArrange) für alle Raster darin; Fehler beim Speichern zeigt die Leiste. */
type Arrange = { editing: boolean; onError: (message: string | null) => void };
const ArrangeContext = createContext<Arrange | null>(null);

const HINT_ID = 'kacheln-anordnen-hinweis';
/** Meldung an die Leiste: ohne Verbindung vorgemerkt */
const PENDING = 'pending';

/**
 * Knopf „Kacheln anordnen“ über den Rastern einer Seite (Verwaltung, Untermenü, Einstellungen) und der
 * Bearbeiten-Modus dazu. pages: alle Raster der Seite, für „Zurücksetzen“. Jede Änderung speichert sofort; „Fertig“
 * beendet nur den Modus, ebenso die Zurück-Taste.
 */
export function TileArrange({ pages, children }: { pages: string[]; children: ReactNode }) {
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useBackClose(editing, () => setEditing(false));
  if (!canArrange()) return <>{children}</>;

  async function reset() {
    const ok = await confirmAsk('Eigene Anordnung dieser Seite zurücksetzen? Danach gilt wieder die Vorgabe, für alle Bildschirmbreiten.', {
      title: 'Zurücksetzen',
      okLabel: 'Zurücksetzen',
    });
    if (!ok) return;
    try {
      setError((await resetLayouts(pages)) === 'pending' ? PENDING : null);
    } catch (e) {
      setError((e as Error).message);
    }
  }

  return (
    <ArrangeContext.Provider value={{ editing, onError: setError }}>
      {editing ? (
        <div className="tile-arrange card">
          <p id={HINT_ID} className="tile-arrange-hint">
            Zum Verschieben ziehen (am Handy erst kurz halten), mit − und + die Breite ändern. Gilt für diese Bildschirmbreite.
          </p>
          {error && (
            <p className={error === PENDING ? 'tile-arrange-note' : 'form-error'} role="alert">
              {error === PENDING ? 'Keine Verbindung: Die Anordnung ist auf diesem Gerät vorgemerkt und wird nachgeholt.' : error}
            </p>
          )}
          <div className="button-row">
            <button type="button" className="btn btn-secondary" onClick={() => void reset()}>
              Zurücksetzen
            </button>
            <button type="button" className="btn btn-primary" onClick={() => setEditing(false)}>
              Fertig
            </button>
          </div>
        </div>
      ) : (
        <div className="tile-arrange-bar">
          <IconButton
            icon="arrange"
            label="Kacheln anordnen"
            onClick={() => {
              setError(null);
              setEditing(true);
            }}
          />
        </div>
      )}
      {children}
    </ArrangeContext.Provider>
  );
}

/** Eine Kachel des Rasters: Kennung (Bereich bzw. Ziel), Name (für das Anordnen) und Inhalt, meist ein Knopf. */
export type GridTile = { id: string; label: string; node: ReactNode };

/** Laufendes Ziehen: Kachel, Abstand des Zeigers zu ihrer linken Spalte, Lage beim Anfassen, letzte Zeigerposition */
type Drag = {
  id: string;
  pointerId: number;
  grabCol: number;
  base: TilePlace[];
  x: number;
  y: number;
  frame: number;
  active: boolean;
  timer: number;
  /** Hört am Fenster mit: Beim Ziehen ordnet React die Kacheln im HTML um, dabei geht die Zeigererfassung verloren */
  stop: () => void;
};

type Point = { pointerId: number; clientX: number; clientY: number };

/** Halten, bis am Handy das Ziehen beginnt (vorher scrollt die Seite wie gewohnt) */
const HOLD_MS = 300;

const STEPS: Record<string, [number, number]> = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

/**
 * Kachelraster mit fester Spaltenzahl: Lage und Breite je Kachel aus der persönlichen Anordnung der Seite, sonst der
 * Reihe nach (tileGrid.ts). page: z. B. verwaltung, intern, menue/2, einstellungen/betrieb. Im Bearbeiten-Modus
 * (TileArrange) liegt über jeder Kachel ein Griff zum Ziehen (auch mit Pfeiltasten) und Knöpfe für die Breite; die
 * Kachel selbst ist dann nicht bedienbar (inert).
 */
export function TileGrid({ page, tiles, className }: { page: string; tiles: GridTile[]; className: string }) {
  useTilesChanged();
  const arrange = useContext(ArrangeContext);
  const editing = !!arrange?.editing;
  const ref = useRef<HTMLUListElement>(null);
  const cols = useGridColumns(ref);
  const byId = new Map(tiles.map((t) => [t.id, t]));
  const saved = resolveTiles(
    tiles.map((t) => t.id),
    layoutsOf(page),
    cols,
  );
  const [preview, setPreview] = useState<{ cols: number; tiles: TilePlace[] } | null>(null);
  const [dragging, setDragging] = useState<string | null>(null);
  const [focusId, setFocusId] = useState<string | null>(null);
  const drag = useRef<Drag | null>(null);
  const handles = useRef(new Map<string, HTMLButtonElement>());
  /** Neueste Fassung der Zeiger-Funktionen für die Fenster-Listener */
  const live = useRef<{ move: (e: Point) => void; end: (e: Point, cancelled: boolean) => void }>({ move: () => {}, end: () => {} });
  useEffect(() => () => drag.current?.stop(), []);
  const placed = preview && preview.cols === cols ? preview.tiles : saved;

  useEffect(() => {
    if (!editing) {
      setPreview(null);
      setFocusId(null);
    }
  }, [editing]);
  // Nach Verschieben mit der Tastatur bleibt der Fokus auf dem Griff (das Element kann im DOM umziehen).
  useEffect(() => {
    if (focusId && editing && document.activeElement !== handles.current.get(focusId)) handles.current.get(focusId)?.focus();
  }, [focusId, placed, editing]);
  useEffect(
    () => () => {
      cancelAnimationFrame(drag.current?.frame ?? 0);
      clearTimeout(drag.current?.timer);
    },
    [],
  );
  // Ziehen am Handy: Scrollen nur verhindern, solange gezogen wird (dazu muss touchmove aktiv sein, nicht passiv).
  useEffect(() => {
    const el = ref.current;
    if (!el || !editing) return;
    const block = (e: TouchEvent) => {
      if (drag.current?.active) e.preventDefault();
    };
    el.addEventListener('touchmove', block, { passive: false });
    return () => el.removeEventListener('touchmove', block);
  }, [editing]);

  function commit(next: TilePlace[]) {
    setPreview({ cols, tiles: next });
    if (sameTiles(next, saved)) return;
    saveLayout(page, cols, next)
      .then((r) => arrange?.onError(r === 'pending' ? PENDING : null))
      .catch((e: Error) => {
        setPreview(null);
        arrange?.onError(e.message);
      });
  }

  /** Feld unter dem Zeiger (Bildschirmkoordinaten) */
  function cellUnder(x: number, y: number) {
    const ul = ref.current!;
    const r = ul.getBoundingClientRect();
    const z = zoom();
    const style = getComputedStyle(ul);
    const tracks = (v: string) =>
      v
        .split(' ')
        .map(parseFloat)
        .filter((n) => Number.isFinite(n));
    return cellAt(
      (x - r.left) / z,
      (y - r.top) / z,
      tracks(style.gridTemplateColumns),
      tracks(style.gridTemplateRows),
      parseFloat(style.rowGap) || 0,
    );
  }

  function follow() {
    const d = drag.current;
    if (!d?.active) return;
    const cell = cellUnder(d.x, d.y);
    const next = moveTile(d.base, d.id, cell.col - d.grabCol, cell.row, cols);
    setPreview((p) => (p && p.cols === cols && sameTiles(p.tiles, next) ? p : { cols, tiles: next }));
  }

  function autoScroll() {
    const d = drag.current;
    if (!d?.active) return;
    const speed = scrollSpeed(d.y);
    if (speed) {
      window.scrollBy(0, speed);
      follow();
    }
    d.frame = requestAnimationFrame(autoScroll);
  }

  function begin() {
    const d = drag.current;
    if (!d) return;
    d.active = true;
    setDragging(d.id);
    navigator.vibrate?.(10);
    d.frame = requestAnimationFrame(autoScroll);
  }

  function onPointerDown(id: string, e: PointerEvent<HTMLButtonElement>) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    const tile = placed.find((t) => t.id === id);
    if (!tile) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current?.stop();
    const move = (ev: Point) => live.current.move(ev);
    const up = (ev: Point) => live.current.end(ev, false);
    const cancel = (ev: Point) => live.current.end(ev, true);
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', cancel);
    const cell = cellUnder(e.clientX, e.clientY);
    drag.current = {
      id,
      pointerId: e.pointerId,
      grabCol: Math.max(0, Math.min(cell.col - tile.col, tile.span - 1)),
      base: placed,
      x: e.clientX,
      y: e.clientY,
      frame: 0,
      active: false,
      timer: 0,
      stop: () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
      },
    };
    // Maus und Stift sofort, Finger nach kurzem Halten (sonst ließe sich die Seite nicht mehr scrollen)
    if (e.pointerType === 'touch') drag.current.timer = window.setTimeout(begin, HOLD_MS);
    else begin();
  }

  function onPointerMove(e: Point) {
    const d = drag.current;
    if (!d || d.pointerId !== e.pointerId) return;
    if (!d.active) {
      // Vor dem Halten bewegt: Die Person scrollt.
      if (Math.hypot(e.clientX - d.x, e.clientY - d.y) > 8) {
        clearTimeout(d.timer);
        d.stop();
        drag.current = null;
      }
      return;
    }
    d.x = e.clientX;
    d.y = e.clientY;
    follow();
  }

  function end(e: Point, cancelled: boolean) {
    const d = drag.current;
    if (!d || d.pointerId !== e.pointerId) return;
    d.stop();
    clearTimeout(d.timer);
    cancelAnimationFrame(d.frame);
    drag.current = null;
    setDragging(null);
    if (!d.active) return;
    if (cancelled) {
      setPreview(null);
      return;
    }
    // Letzte Lage beim Ziehen (pointerup eines Fingers kann ohne gültige Koordinaten kommen)
    const cell = cellUnder(d.x, d.y);
    commit(moveTile(d.base, d.id, cell.col - d.grabCol, cell.row, cols));
  }

  function onKeyDown(id: string, e: KeyboardEvent<HTMLButtonElement>) {
    const tile = placed.find((t) => t.id === id);
    if (!tile) return;
    let next: TilePlace[] | null = null;
    const step = STEPS[e.key];
    if (step) {
      // Nach oben aus Zeile 1 heraus geht nicht; nach unten unter die letzte Zeile legt eine neue an.
      if (tile.row + step[1] < 1) return;
      next = moveTile(placed, id, tile.col + step[0], tile.row + step[1], cols);
    } else if (e.key === '+' || e.key === '=') next = resizeTile(placed, id, 1, cols);
    else if (e.key === '-') next = resizeTile(placed, id, -1, cols);
    if (!next) return;
    e.preventDefault();
    setFocusId(id);
    commit(next);
  }

  live.current = { move: onPointerMove, end };
  const rows = rowCount(placed) + (editing ? 1 : 0);
  return (
    <ul
      ref={ref}
      className={`${className} tile-grid${editing ? ' tile-grid-editing' : ''}`}
      style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
    >
      {placed.map((p) => {
        const tile = byId.get(p.id);
        const label = tile?.label ?? p.id;
        const classes = [p.span > 1 ? 'wide' : '', dragging === p.id ? 'tile-dragging' : ''].filter(Boolean).join(' ');
        return (
          <li
            key={p.id}
            data-tile={p.id}
            className={classes || undefined}
            style={{ gridColumn: `${p.col} / span ${p.span}`, gridRow: String(p.row) }}
          >
            <div className="tile-body" ref={(el) => el && (el.inert = editing)}>
              {tile?.node}
            </div>
            {editing && (
              <div className="tile-edit">
                <button
                  type="button"
                  className="tile-handle"
                  aria-label={`${label} verschieben (Pfeiltasten), Breite ${p.span} von ${cols} (Plus, Minus)`}
                  aria-describedby={HINT_ID}
                  ref={(el) => {
                    if (el) handles.current.set(p.id, el);
                    else handles.current.delete(p.id);
                  }}
                  onPointerDown={(e) => onPointerDown(p.id, e)}
                  onKeyDown={(e) => onKeyDown(p.id, e)}
                  onContextMenu={(e) => e.preventDefault()}
                />
                <div className="tile-size">
                  <button
                    type="button"
                    aria-label={`${label} schmaler`}
                    disabled={p.span <= 1}
                    onClick={() => commit(resizeTile(placed, p.id, -1, cols))}
                  >
                    −
                  </button>
                  <button
                    type="button"
                    aria-label={`${label} breiter`}
                    disabled={p.span >= cols}
                    onClick={() => commit(resizeTile(placed, p.id, 1, cols))}
                  >
                    +
                  </button>
                </div>
              </div>
            )}
          </li>
        );
      })}
      {editing &&
        emptyCells(placed, cols, rows).map((c) => (
          <li
            key={`frei-${c.row}-${c.col}`}
            className="tile-slot"
            aria-hidden="true"
            style={{ gridColumn: String(c.col), gridRow: String(c.row) }}
          />
        ))}
    </ul>
  );
}
