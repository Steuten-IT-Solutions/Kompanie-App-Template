import { useEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type PointerEvent } from 'react';

/** Laufender Zug: welcher Eintrag, wohin er fiele und wie weit er verschoben ist (Bildschirm-Pixel). */
type Drag = { from: number; to: number; dy: number };

/** Maße beim Anfassen, relativ zur Oberkante der Liste – so stimmen sie auch, wenn die Seite beim Ziehen scrollt. */
type Start = {
  from: number;
  to: number;
  list: HTMLElement;
  /** Abstand des Zeigers zur Oberkante der Liste beim Anfassen */
  y: number;
  rects: { top: number; height: number }[];
  /** Platz, den der gezogene Eintrag freimacht: seine Höhe plus Abstand zum Nachbarn */
  shift: number;
  /** Letzte Zeigerposition (Bildschirm) */
  pointerY: number;
  frame: number;
};

/** „zoom“ der Seite (viewport.ts): Pointer und Maße sind in Bildschirm-Pixeln, transform rechnet in Seiten-Pixeln. */
function zoom(): number {
  return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--kompanie-zoom')) || 1;
}

/** Randzone, in der die Seite mitscrollt, und Höchstgeschwindigkeit (Pixel je Bild) */
const EDGE = 56;
const MAX_SPEED = 14;

/**
 * Scrollgeschwindigkeit für die Zeigerposition: in der Randzone unter der Kopfleiste nach oben, über der Tab-Leiste
 * nach unten, je näher am Rand, desto schneller. Kopf- und Tab-Leiste bleiben stehen und verdecken die Seite.
 */
function scrollSpeed(y: number): number {
  const bar = (sel: string) => {
    const r = document.querySelector(sel)?.getBoundingClientRect();
    return r && r.height > 0 ? r : null;
  };
  const top = Math.max(0, bar('.topbar')?.bottom ?? 0);
  // Quer steht die Tab-Leiste links (styles.css); dann verdeckt unten nichts die Seite.
  const tabbar = bar('.tabbar');
  const bottom = tabbar && tabbar.width >= window.innerWidth / 2 ? Math.min(window.innerHeight, tabbar.top) : window.innerHeight;
  if (y < top + EDGE) return -Math.ceil(MAX_SPEED * Math.min(1, (top + EDGE - y) / EDGE));
  if (y > bottom - EDGE) return Math.ceil(MAX_SPEED * Math.min(1, (y - bottom + EDGE) / EDGE));
  return 0;
}

/**
 * Speichert Reihenfolgen nacheinander; nur das Ergebnis des zuletzt gestarteten Aufrufs zählt. So bleibt Verschieben
 * auch möglich, während noch gespeichert wird, und eine ältere Antwort überschreibt keine neuere Reihenfolge.
 */
export function useSaveQueue() {
  const chain = useRef<Promise<unknown>>(Promise.resolve());
  const latest = useRef(0);
  return function queue<R>(save: () => Promise<R>, onSaved: (result: R) => void, onError: (e: Error) => void) {
    const n = ++latest.current;
    chain.current = chain.current.then(async () => {
      try {
        const result = await save();
        if (n === latest.current) onSaved(result);
      } catch (e) {
        if (n === latest.current) onError(e as Error);
      }
    });
  };
}

/**
 * Reihenfolge einer Liste per Ziehen am Griff ändern, mit Maus und Finger (Pointer-Events statt HTML-Drag-and-drop,
 * das auf Touch-Geräten nicht geht). Der Eintrag folgt dem Finger, die anderen rücken zur Seite; nahe an Kopf- oder
 * Tab-Leiste scrollt die Seite mit. Beim Loslassen bekommt onMove die neue Reihenfolge. Am fokussierten Griff
 * verschieben Pfeil hoch/runter um eine Stelle.
 */
export function useDragSort<T>(items: T[], onMove: (next: T[]) => void, disabled = false) {
  const [drag, setDrag] = useState<Drag | null>(null);
  const els = useRef<(HTMLElement | null)[]>([]);
  const start = useRef<Start | null>(null);

  useEffect(() => () => cancelAnimationFrame(start.current?.frame ?? 0), []);

  function moved(from: number, to: number): T[] {
    const next = items.slice();
    next.splice(to, 0, ...next.splice(from, 1));
    return next;
  }

  /** Lage des gezogenen Eintrags und Zielplatz aus der letzten Zeigerposition neu berechnen. */
  function update() {
    const s = start.current;
    if (!s) return;
    const dy = s.pointerY - s.list.getBoundingClientRect().top - s.y;
    const { rects, from } = s;
    const centre = rects[from].top + rects[from].height / 2 + dy;
    const mid = (i: number) => rects[i].top + rects[i].height / 2;
    let to = from;
    while (to < rects.length - 1 && centre > mid(to + 1)) to++;
    while (to > 0 && centre < mid(to - 1)) to--;
    s.to = to;
    setDrag({ from, to, dy });
  }

  /** Läuft während des Ziehens: scrollt in der Randzone, auch wenn der Finger stillhält. */
  function autoScroll() {
    const s = start.current;
    if (!s) return;
    const speed = scrollSpeed(s.pointerY);
    if (speed) {
      window.scrollBy(0, speed);
      update();
    }
    s.frame = requestAnimationFrame(autoScroll);
  }

  function onPointerDown(index: number, e: PointerEvent<HTMLElement>) {
    const list = els.current[index]?.parentElement;
    if (disabled || !list || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault();
    e.currentTarget.setPointerCapture(e.pointerId);
    const listTop = list.getBoundingClientRect().top;
    const rects = els.current.slice(0, items.length).map((el) => {
      const r = el!.getBoundingClientRect();
      return { top: r.top - listTop, height: r.height };
    });
    const gap = rects.length > 1 ? rects[1].top - (rects[0].top + rects[0].height) : 0;
    start.current = {
      from: index,
      to: index,
      list,
      y: e.clientY - listTop,
      rects,
      shift: rects[index].height + gap,
      pointerY: e.clientY,
      frame: 0,
    };
    setDrag({ from: index, to: index, dy: 0 });
    start.current.frame = requestAnimationFrame(autoScroll);
  }

  function onPointerMove(e: PointerEvent<HTMLElement>) {
    if (!start.current) return;
    start.current.pointerY = e.clientY;
    update();
  }

  /** Ziehen beenden; gibt den Zug zurück, falls einer lief. */
  function stop() {
    const s = start.current;
    if (s) cancelAnimationFrame(s.frame);
    start.current = null;
    setDrag(null);
    return s;
  }

  function onPointerUp() {
    const s = stop();
    if (s && s.to !== s.from) onMove(moved(s.from, s.to));
  }

  function onPointerCancel() {
    stop();
  }

  function onKeyDown(index: number, e: KeyboardEvent<HTMLElement>) {
    const to = e.key === 'ArrowUp' ? index - 1 : e.key === 'ArrowDown' ? index + 1 : -1;
    if (disabled || to < 0 || to >= items.length) return;
    e.preventDefault();
    onMove(moved(index, to));
  }

  /** Für das Listenelement: Ref, Klassen und Verschiebung während des Ziehens. */
  function itemProps(
    index: number,
    className: string,
  ): { ref: (el: HTMLElement | null) => void; style?: CSSProperties; className: string } {
    const ref = (el: HTMLElement | null) => {
      els.current[index] = el;
    };
    if (!drag || !start.current) return { ref, className };
    const z = zoom();
    if (index === drag.from) return { ref, style: { transform: `translateY(${drag.dy / z}px)` }, className: `${className} dragging` };
    const { shift } = start.current;
    const offset = drag.from < index && index <= drag.to ? -shift : drag.to <= index && index < drag.from ? shift : 0;
    return { ref, style: { transform: `translateY(${offset / z}px)` }, className: `${className} drag-shift` };
  }

  /** Griff (sechs Punkte) zum Ziehen; name für die Beschriftung, z. B. der Titel des Eintrags. */
  function handle(index: number, name: string) {
    return (
      <button
        type="button"
        className="drag-handle"
        aria-label={`${name} verschieben (ziehen oder Pfeiltasten)`}
        title="Zum Verschieben ziehen"
        onPointerDown={(e) => onPointerDown(index, e)}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerCancel}
        onKeyDown={(e) => onKeyDown(index, e)}
      >
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
          <circle cx="9" cy="6" r="1.6" />
          <circle cx="15" cy="6" r="1.6" />
          <circle cx="9" cy="12" r="1.6" />
          <circle cx="15" cy="12" r="1.6" />
          <circle cx="9" cy="18" r="1.6" />
          <circle cx="15" cy="18" r="1.6" />
        </svg>
      </button>
    );
  }

  return { itemProps, handle, dragging: drag !== null };
}
