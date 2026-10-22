import { useRef, type TouchEvent } from 'react';

/** Mindestweg in Pixeln, ab dem eine Wischbewegung zählt. */
const MIN_DISTANCE = 60;

/**
 * Richtung einer Wischbewegung: 1 = nach links gewischt (weiter), -1 = nach rechts (zurück), 0 = keine.
 * Nur deutlich waagerecht zählt, damit normales Scrollen nicht blättert.
 */
export function swipeStep(dx: number, dy: number): -1 | 0 | 1 {
  if (Math.abs(dx) < MIN_DISTANCE || Math.abs(dx) < 2 * Math.abs(dy)) return 0;
  return dx < 0 ? 1 : -1;
}

/** Vorheriger und nächster Eintrag in einer Reihenfolge; am Anfang bzw. Ende keiner. */
export function neighbors<T>(order: readonly T[], current: T): { prev: T | null; next: T | null; index: number } {
  const index = order.indexOf(current);
  if (index < 0) return { prev: null, next: null, index };
  return {
    prev: index > 0 ? order[index - 1] : null,
    next: index < order.length - 1 ? order[index + 1] : null,
    index,
  };
}

/** Rand, an dem iOS und Android selbst „Zurück“ wischen: dort beginnende Gesten bleiben dem System. */
const EDGE = 24;

/**
 * Beginnt die Geste in einem Feld, in etwas, das selbst waagerecht scrollt oder gezogen wird, oder in einem Portal
 * (z. B. vergrößertes Foto): React reicht dessen Ereignisse an die Ansicht weiter, obwohl es über ihr liegt.
 */
function ownsGesture(target: EventTarget | null, root: Element): boolean {
  if (!(target instanceof Node) || !root.contains(target)) return true;
  for (let el = target instanceof Element ? target : null; el && el !== root; el = el.parentElement) {
    if (el.matches('input, textarea, select, [contenteditable], .drag-handle')) return true;
    const overflow = getComputedStyle(el).overflowX;
    if ((overflow === 'auto' || overflow === 'scroll') && el.scrollWidth > el.clientWidth) return true;
  }
  return false;
}

/** Wischen mit einem Finger nach links oder rechts; onSwipe bekommt die Richtung wie swipeStep. */
export function useSwipe(onSwipe: (step: -1 | 1) => void) {
  const start = useRef<{ x: number; y: number } | null>(null);
  return {
    onTouchStart(e: TouchEvent<HTMLElement>) {
      const t = e.touches[0];
      start.current =
        e.touches.length === 1 && t.clientX > EDGE && t.clientX < window.innerWidth - EDGE && !ownsGesture(e.target, e.currentTarget)
          ? { x: t.clientX, y: t.clientY }
          : null;
    },
    onTouchEnd(e: TouchEvent<HTMLElement>) {
      const s = start.current;
      start.current = null;
      if (!s || e.touches.length > 0) return;
      // Markierter Text: Der Finger hat ausgewählt, nicht gewischt.
      if (!window.getSelection()?.isCollapsed) return;
      const t = e.changedTouches[0];
      const step = swipeStep(t.clientX - s.x, t.clientY - s.y);
      if (step !== 0) onSwipe(step);
    },
    onTouchCancel() {
      start.current = null;
    },
  };
}
