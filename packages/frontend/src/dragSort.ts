import { useRef, useState, type CSSProperties, type KeyboardEvent, type PointerEvent } from 'react';

/** Laufender Zug: welcher Eintrag, wohin er fiele und wie weit er verschoben ist (Bildschirm-Pixel). */
type Drag = { from: number; to: number; dy: number };

/** „zoom“ der Seite (viewport.ts): Pointer und Maße sind in Bildschirm-Pixeln, transform rechnet in Seiten-Pixeln. */
function zoom(): number {
  return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--kompanie-zoom')) || 1;
}

/**
 * Reihenfolge einer Liste per Ziehen am Griff ändern, mit Maus und Finger (Pointer-Events statt HTML-Drag-and-drop,
 * das auf Touch-Geräten nicht geht). Der Eintrag folgt dem Finger, die anderen rücken zur Seite; beim Loslassen
 * bekommt onMove die neue Reihenfolge. Am fokussierten Griff verschieben Pfeil hoch/runter um eine Stelle.
 */
export function useDragSort<T>(items: T[], onMove: (next: T[]) => void, disabled = false) {
  const [drag, setDrag] = useState<Drag | null>(null);
  const els = useRef<(HTMLElement | null)[]>([]);
  const start = useRef<{ y: number; rects: DOMRect[]; shift: number } | null>(null);

  function moved(from: number, to: number): T[] {
    const next = items.slice();
    next.splice(to, 0, ...next.splice(from, 1));
    return next;
  }

  function onPointerDown(index: number, e: PointerEvent<HTMLElement>) {
    if (disabled || (e.pointerType === 'mouse' && e.button !== 0)) return;
    e.preventDefault();
    e.currentTarget.setPointerCapture(e.pointerId);
    const rects = els.current.slice(0, items.length).map((el) => el!.getBoundingClientRect());
    // Platz, den der gezogene Eintrag freimacht: seine Höhe plus Abstand zum Nachbarn
    const gap = rects.length > 1 ? rects[1].top - rects[0].bottom : 0;
    start.current = { y: e.clientY, rects, shift: rects[index].height + gap };
    setDrag({ from: index, to: index, dy: 0 });
  }

  function onPointerMove(e: PointerEvent<HTMLElement>) {
    if (!drag || !start.current) return;
    const { rects, y } = start.current;
    const dy = e.clientY - y;
    const centre = rects[drag.from].top + rects[drag.from].height / 2 + dy;
    const mid = (i: number) => rects[i].top + rects[i].height / 2;
    let to = drag.from;
    while (to < rects.length - 1 && centre > mid(to + 1)) to++;
    while (to > 0 && centre < mid(to - 1)) to--;
    setDrag({ ...drag, to, dy });
  }

  function onPointerUp() {
    if (!drag) return;
    setDrag(null);
    start.current = null;
    if (drag.to !== drag.from) onMove(moved(drag.from, drag.to));
  }

  function onPointerCancel() {
    setDrag(null);
    start.current = null;
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

  /** Für den Griff. */
  function handleProps(index: number) {
    return {
      onPointerDown: (e: PointerEvent<HTMLElement>) => onPointerDown(index, e),
      onPointerMove,
      onPointerUp,
      onPointerCancel,
      onKeyDown: (e: KeyboardEvent<HTMLElement>) => onKeyDown(index, e),
    };
  }

  return { itemProps, handleProps, dragging: drag !== null };
}
