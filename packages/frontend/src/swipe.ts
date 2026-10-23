import { useEffect, useRef, useState, type RefObject, type TouchEvent } from 'react';

/** Mindestweg in Pixeln, ab dem eine Wischbewegung blättert. */
const MIN_DISTANCE = 60;

/** Ab diesem Weg in Pixeln entscheidet sich, ob der Finger waagerecht zieht (blättern) oder scrollt. */
const LOCK_DISTANCE = 10;
/** Ziehen ohne Nachbarn in dieser Richtung (Anfang bzw. Ende der Liste): Die Karte gibt nur etwas nach. */
const RUBBER_BAND = 0.3;
/** Schnelles Wischen (Pixel je Millisekunde) blättert auch mit kürzerem Weg, aber nicht unter FLICK_DISTANCE. */
const FLICK_SPEED = 0.4;
const FLICK_DISTANCE = 40;
/** Abstand zwischen der Karte und ihren Nachbarn daneben (wie der Seitenrand von .content). */
export const PEEK_GAP = 32;

/** Versatz der Karte beim Ziehen um dx: Gibt es in der Richtung keinen Nachbarn, folgt sie dem Finger nur gebremst. */
export function dragOffset(dx: number, canPrev: boolean, canNext: boolean): number {
  const blocked = dx < 0 ? !canNext : !canPrev;
  return blocked ? dx * RUBBER_BAND : dx;
}

/**
 * Beim Loslassen: weiterblättern (1), zurück (-1) oder die Karte zurückschnappen lassen (0). Blättert, wenn sie weit
 * genug gezogen wurde (mindestens ein Viertel der Breite) oder der Finger zuletzt schnell in diese Richtung lief.
 */
export function releaseStep(dx: number, velocity: number, width: number, canPrev: boolean, canNext: boolean): -1 | 0 | 1 {
  const step = dx < 0 ? 1 : dx > 0 ? -1 : 0;
  if (step === 0 || (step === 1 ? !canNext : !canPrev)) return 0;
  const far = Math.abs(dx) >= Math.max(MIN_DISTANCE, width / 4);
  const flick = Math.abs(dx) >= FLICK_DISTANCE && Math.sign(velocity) === Math.sign(dx) && Math.abs(velocity) >= FLICK_SPEED;
  return far || flick ? step : 0;
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

/**
 * Reihenfolge zum Vorladen von der Stelle `index` aus nach außen, abwechselnd: nächster, vorheriger, übernächster,
 * vorvorheriger … bis die ganze Liste dran war (die Person selbst nicht). Steht sie nicht in der Liste, nichts.
 */
export function prefetchOrder<T>(order: readonly T[], index: number): T[] {
  if (index < 0 || index >= order.length) return [];
  const out: T[] = [];
  for (let d = 1; index + d < order.length || index - d >= 0; d++) {
    if (index + d < order.length) out.push(order[index + d]);
    if (index - d >= 0) out.push(order[index - d]);
  }
  return out;
}

/**
 * Alle Einträge der Reihenfolge nacheinander vorladen, von der aktuellen Stelle nach außen (prefetchOrder): Erst wenn
 * einer fertig ist, kommt der nächste, damit immer nur eine Anfrage läuft und die Nachbarn zuerst da sind. Wechselt
 * die Stelle oder `restart` (z. B. nach dem Laden der eigenen Daten, denn eigene Änderungen leeren den Speicher),
 * beginnt die Kette dort neu; schon Geladenes überspringt `load` selbst (prefetch). Fehler halten die Kette nicht auf.
 */
export function usePrefetchChain<T>(order: readonly T[], index: number, load: (item: T) => Promise<unknown>, restart: unknown): void {
  const latest = useRef(load);
  latest.current = load;
  const queue = prefetchOrder(order, index);
  const signature = JSON.stringify(queue);
  useEffect(() => {
    if (restart === null || restart === undefined) return;
    let alive = true;
    void (async () => {
      for (const item of queue) {
        if (!alive) return;
        await latest.current(item).catch(() => {});
      }
    })();
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, restart]);
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

/** Längste Dauer der Animation (CSS .member-track), falls transitionend ausbleibt. */
const SLIDE_TIMEOUT = 400;

/**
 * Karussell zum Blättern mit einem Finger: Die Spur (track) folgt dem Finger, daneben zeigt die Ansicht die Nachbarn,
 * solange `peeking` gilt. Beim Loslassen gleitet die Spur zum Nachbarn und ruft dann onCommit auf (die Ansicht wechselt
 * zum Nachbarn, der genau an dieser Stelle erscheint), oder schnappt zurück. slide() macht dasselbe für die Pfeile.
 * Der Versatz wird direkt am Element gesetzt, nicht über React, damit das Ziehen nicht bei jeder Bewegung neu rendert.
 */
export function useSwipeDrag(
  track: RefObject<HTMLElement>,
  { canPrev, canNext, onCommit }: { canPrev: boolean; canNext: boolean; onCommit: (step: -1 | 1) => void },
) {
  const [peeking, setPeeking] = useState(false);
  const gesture = useRef<{ x: number; y: number; lock: 'x' | null; dx: number; lastX: number; lastT: number; v: number } | null>(null);
  // Während die Spur gleitet, zählen weder Gesten noch Pfeile (sonst doppelte Sprünge).
  const busy = useRef(false);
  const pendingSlide = useRef<-1 | 1 | null>(null);
  // Zählt Bewegungen der Spur: Endet ein Zurückschnappen erst, wenn schon wieder gezogen wird, bleiben die Nachbarn.
  const moves = useRef(0);
  const latest = useRef({ canPrev, canNext, onCommit });
  latest.current = { canPrev, canNext, onCommit };

  function setOffset(px: number, animate: boolean) {
    const el = track.current;
    if (!el) return;
    el.style.transition = animate ? '' : 'none';
    el.style.transform = px === 0 ? '' : `translateX(${px}px)`;
  }

  function showPeeks() {
    // Nachbarn oben im sichtbaren Teil der Karte zeigen (unter der festen Kopfleiste), auch wenn weiter unten gewischt wird.
    const el = track.current;
    const bar = document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0;
    if (el) el.style.setProperty('--peek-top', `${Math.max(0, bar - el.getBoundingClientRect().top)}px`);
    setPeeking(true);
  }

  function afterSlide(done: () => void) {
    const el = track.current;
    let called = false;
    const finish = () => {
      if (called) return;
      called = true;
      el?.removeEventListener('transitionend', onEnd);
      clearTimeout(timer);
      done();
    };
    const onEnd = (e: Event) => e.target === el && finish();
    el?.addEventListener('transitionend', onEnd);
    const timer = setTimeout(finish, SLIDE_TIMEOUT);
  }

  function settle(step: -1 | 0 | 1) {
    const el = track.current;
    if (!el) return;
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (step === 0) {
      // Zurückschnappen hält nichts auf: Ein neuer Finger darf die Karte gleich wieder greifen.
      setOffset(0, true);
      const n = ++moves.current;
      const reset = () => moves.current === n && setPeeking(false);
      return reduced ? reset() : afterSlide(reset);
    }
    busy.current = true;
    moves.current++;
    const commit = () => {
      latest.current.onCommit(step);
      // Die Ansicht des Nachbarn ersetzt diese gleich; bleibt sie doch stehen, die Karte wieder zeigen.
      setTimeout(() => {
        busy.current = false;
        setOffset(0, false);
        setPeeking(false);
      }, 1000);
    };
    if (reduced) return commit();
    setOffset(-step * (el.offsetWidth + PEEK_GAP), true);
    afterSlide(commit);
  }

  // Pfeile: erst die Nachbarn zeigen, dann (nach dem Rendern) hingleiten.
  useEffect(() => {
    const step = pendingSlide.current;
    if (!peeking || step === null) return;
    pendingSlide.current = null;
    void track.current?.offsetWidth; // Ausgangslage festhalten, damit die Animation startet
    settle(step);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [peeking]);

  function slide(step: -1 | 1) {
    if (busy.current || (step === 1 ? !canNext : !canPrev)) return;
    busy.current = true;
    pendingSlide.current = step;
    if (peeking) settle(step);
    else showPeeks();
  }

  const handlers = {
    onTouchStart(e: TouchEvent<HTMLElement>) {
      const t = e.touches[0];
      gesture.current =
        !busy.current &&
        e.touches.length === 1 &&
        t.clientX > EDGE &&
        t.clientX < window.innerWidth - EDGE &&
        !ownsGesture(e.target, e.currentTarget)
          ? { x: t.clientX, y: t.clientY, lock: null, dx: 0, lastX: t.clientX, lastT: e.timeStamp, v: 0 }
          : null;
    },
    onTouchMove(e: TouchEvent<HTMLElement>) {
      const g = gesture.current;
      if (!g) return;
      if (e.touches.length !== 1) {
        gesture.current = null;
        if (g.lock) settle(0);
        return;
      }
      const t = e.touches[0];
      const dx = t.clientX - g.x;
      const dy = t.clientY - g.y;
      if (!g.lock) {
        if (Math.hypot(dx, dy) < LOCK_DISTANCE) return;
        // Senkrecht: Scrollen. Markierter Text: Der Finger wählt aus, statt zu wischen.
        if (Math.abs(dx) < 2 * Math.abs(dy) || !window.getSelection()?.isCollapsed) {
          gesture.current = null;
          return;
        }
        g.lock = 'x';
        moves.current++;
        showPeeks();
      }
      const dt = e.timeStamp - g.lastT;
      if (dt > 0) g.v = (t.clientX - g.lastX) / dt;
      g.lastX = t.clientX;
      g.lastT = e.timeStamp;
      g.dx = dx;
      setOffset(dragOffset(dx, latest.current.canPrev, latest.current.canNext), false);
    },
    onTouchEnd(e: TouchEvent<HTMLElement>) {
      const g = gesture.current;
      if (!g || e.touches.length > 0) return;
      gesture.current = null;
      if (!g.lock) return;
      const width = track.current?.offsetWidth ?? window.innerWidth;
      settle(releaseStep(g.dx, g.v, width, latest.current.canPrev, latest.current.canNext));
    },
    onTouchCancel() {
      const g = gesture.current;
      gesture.current = null;
      if (g?.lock) settle(0);
    },
  };

  return { handlers, peeking, slide };
}
