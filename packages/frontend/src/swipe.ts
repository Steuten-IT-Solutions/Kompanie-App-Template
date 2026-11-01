import { useEffect, useRef, useState, type RefObject } from 'react';
import { flushSync } from 'react-dom';

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

/**
 * Vorheriger und nächster Eintrag in einer Reihenfolge; am Anfang bzw. Ende keiner. wrap: Die Reihenfolge ist ein Ring
 * (Karussell), vor dem ersten steht der letzte, nach dem letzten der erste; nur mit einem Eintrag gibt es keine Nachbarn.
 */
export function neighbors<T>(order: readonly T[], current: T, wrap = false): { prev: T | null; next: T | null; index: number } {
  const index = order.indexOf(current);
  if (index < 0) return { prev: null, next: null, index };
  const n = order.length;
  if (wrap) {
    return n < 2 ? { prev: null, next: null, index } : { prev: order[(index - 1 + n) % n], next: order[(index + 1) % n], index };
  }
  return {
    prev: index > 0 ? order[index - 1] : null,
    next: index < n - 1 ? order[index + 1] : null,
    index,
  };
}

/**
 * Reihenfolge zum Vorladen von der Stelle `index` aus nach außen, abwechselnd: nächster, vorheriger, übernächster,
 * vorvorheriger … bis die ganze Liste dran war (die Person selbst nicht). Steht sie nicht in der Liste, nichts.
 * wrap: als Ring (wie neighbors), über das Ende hinaus wieder von vorn, jeder Eintrag einmal.
 */
export function prefetchOrder<T>(order: readonly T[], index: number, wrap = false): T[] {
  const n = order.length;
  if (index < 0 || index >= n) return [];
  const out: T[] = [];
  if (wrap) {
    for (let d = 1; 2 * d <= n; d++) {
      out.push(order[(index + d) % n]);
      // Bei gerader Länge ist der gegenüberliegende Eintrag von beiden Seiten gleich weit weg: nur einmal.
      if (2 * d < n) out.push(order[(index - d + n) % n]);
    }
    return out;
  }
  for (let d = 1; index + d < n || index - d >= 0; d++) {
    if (index + d < n) out.push(order[index + d]);
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
export function usePrefetchChain<T>(
  order: readonly T[],
  index: number,
  load: (item: T) => Promise<unknown>,
  restart: unknown,
  wrap = false,
): void {
  const latest = useRef(load);
  latest.current = load;
  const queue = prefetchOrder(order, index, wrap);
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
 * Beginnt die Geste in einem Feld, in etwas, das selbst waagerecht scrollt oder gezogen wird, oder außerhalb der Ansicht
 * (Kopf- und Tab-Leiste, Fenster und vergrößertes Foto über ihr): Dann wischt die Ansicht nicht. Die leere Fläche um
 * sie herum (ihre Eltern, z. B. unter der noch kurzen Seite, solange die Daten laden) gehört dagegen zu ihr.
 */
function ownsGesture(target: EventTarget | null, root: Element): boolean {
  if (!(target instanceof Node)) return true;
  if (target !== root && target.contains(root)) return false;
  if (!root.contains(target)) return true;
  for (let el = target instanceof Element ? target : null; el && el !== root; el = el.parentElement) {
    // Videos bedienen mit dem Finger ihren Player (Großansicht der Galerie).
    if (el.matches('input, textarea, select, [contenteditable], .drag-handle, video')) return true;
    const overflow = getComputedStyle(el).overflowX;
    if ((overflow === 'auto' || overflow === 'scroll') && el.scrollWidth > el.clientWidth) return true;
  }
  return false;
}

/** Längste Dauer der Animation (CSS .member-track, .lb-track), falls transitionend ausbleibt. */
const SLIDE_TIMEOUT = 400;

function reducedMotion(): boolean {
  return !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

/** Ansicht, auf der gerade gewischt werden kann (es gibt höchstens eine). */
let activeRoot: HTMLElement | null = null;
/** Finger auf dem Bildschirm: ob er wischen darf, entschieden beim Aufsetzen. Mehr als ein Finger: keiner wischt. */
const fingers = new Set<number>();
let finger: { id: number; eligible: boolean } | null = null;
let tracking = false;

/**
 * Aufsetzen und Abheben jedes Fingers merken, unabhängig von der Ansicht: Wechselt sie zum Nachbarn, während der Finger
 * noch aufliegt (z. B. gleich wieder aufgesetzt, während die Karte noch gleitet), übernimmt die neue Ansicht ihn.
 * Pointer- statt Touch-Ereignisse: Verschwindet das Element unter dem Finger, kommen Touch-Ereignisse nirgends mehr an,
 * Pointer-Ereignisse dagegen weiter am document.
 */
function trackFingers(): void {
  if (tracking) return;
  tracking = true;
  document.addEventListener(
    'pointerdown',
    (e) => {
      if (e.pointerType !== 'touch') return;
      fingers.add(e.pointerId);
      finger =
        fingers.size === 1
          ? {
              id: e.pointerId,
              eligible: !!activeRoot && e.clientX > EDGE && e.clientX < window.innerWidth - EDGE && !ownsGesture(e.target, activeRoot),
            }
          : null;
    },
    { capture: true, passive: true },
  );
  const up = (e: PointerEvent) => {
    fingers.delete(e.pointerId);
    if (finger?.id === e.pointerId) finger = null;
  };
  // Am window, also nach den Ansichten (sie hören am document): Sie ordnen das Abheben noch dem Finger zu.
  window.addEventListener('pointerup', up, { passive: true });
  window.addEventListener('pointercancel', up, { passive: true });
}

/**
 * Karussell zum Blättern mit einem Finger, auf der Ansicht (root) und der leeren Fläche um sie herum (siehe
 * ownsGesture; daher am document, nicht an der Ansicht), auch mit einem Finger, der schon vor der Ansicht auflag
 * (trackFingers). Die Spur (track) folgt dem Finger, daneben zeigt die Ansicht die Nachbarn, solange `peeking` gilt.
 * Beim Loslassen gleitet die Spur zum Nachbarn und ruft dann onCommit auf (die Ansicht wechselt zum Nachbarn, der
 * genau an dieser Stelle erscheint), oder schnappt zurück. slide() macht dasselbe für die Pfeile. Der Versatz wird
 * direkt am Element gesetzt, nicht über React, damit das Ziehen nicht bei jeder Bewegung neu rendert.
 * enabled: false nimmt keine neuen Gesten an (z. B. vergrößertes Bild, dort verschiebt der Finger das Bild).
 * inPlace: Die Ansicht bleibt stehen und zeigt nach onCommit selbst den Nachbarn (Großansicht der Galerie). Dann
 * rendert sie gleich mit dem neuen Eintrag, und die Spur steht ohne Animation wieder in der Mitte, bevor der Browser
 * zeichnet; sonst (Mitglieder) ersetzt die Ansicht des Nachbarn diese.
 */
export function useSwipeDrag(
  root: RefObject<HTMLElement>,
  track: RefObject<HTMLElement>,
  {
    canPrev,
    canNext,
    onCommit,
    enabled = true,
    inPlace = false,
  }: { canPrev: boolean; canNext: boolean; onCommit: (step: -1 | 1) => void; enabled?: boolean; inPlace?: boolean },
) {
  const [peeking, setPeeking] = useState(false);
  const gesture = useRef<{ x: number; y: number; lock: 'x' | null; dx: number; lastX: number; lastT: number; v: number } | null>(null);
  // Während die Spur gleitet, zählen weder Gesten noch Pfeile (sonst doppelte Sprünge).
  const busy = useRef(false);
  const pendingSlide = useRef<-1 | 1 | null>(null);
  // inPlace: Pfeil, während die Spur noch gleitet (z. B. zweimal schnell gedrückt); er blättert gleich danach weiter.
  const queuedSlide = useRef<-1 | 1 | null>(null);
  // Zählt Bewegungen der Spur: Endet ein Zurückschnappen erst, wenn schon wieder gezogen wird, bleiben die Nachbarn.
  const moves = useRef(0);
  const latest = useRef({ canPrev, canNext, onCommit, enabled, inPlace });
  latest.current = { canPrev, canNext, onCommit, enabled, inPlace };

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
    const reduced = reducedMotion();
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
      if (latest.current.inPlace) {
        flushSync(() => {
          latest.current.onCommit(step);
          setPeeking(false);
        });
        busy.current = false;
        setOffset(0, false);
        const queued = queuedSlide.current;
        queuedSlide.current = null;
        if (queued !== null && (queued === 1 ? latest.current.canNext : latest.current.canPrev)) {
          busy.current = true;
          pendingSlide.current = queued;
          showPeeks();
        }
        return;
      }
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
    if (step === 1 ? !canNext : !canPrev) return;
    if (busy.current) {
      if (inPlace) queuedSlide.current = step;
      return;
    }
    // Ohne Animation wechselt die Ansicht an Ort und Stelle gleich (nicht erst im Effekt, dort ginge flushSync nicht).
    if (inPlace && reducedMotion()) return onCommit(step);
    busy.current = true;
    pendingSlide.current = step;
    if (peeking) settle(step);
    else showPeeks();
  }

  // Geste des aufliegenden Fingers ab dieser Stelle; während die Spur gleitet, keine (der Finger wartet auf die nächste
  // Ansicht, die ihn dann übernimmt).
  function grab(e: PointerEvent) {
    gesture.current =
      finger?.id === e.pointerId && finger.eligible && !busy.current && latest.current.enabled && root.current
        ? { x: e.clientX, y: e.clientY, lock: null, dx: 0, lastX: e.clientX, lastT: e.timeStamp, v: 0 }
        : null;
  }

  const handlers = {
    onPointerDown(e: PointerEvent) {
      if (e.pointerType === 'touch') grab(e);
    },
    onPointerMove(e: PointerEvent) {
      if (e.pointerType !== 'touch') return;
      if (!gesture.current) {
        // Aufgesetzt, bevor diese Ansicht stand oder während die Spur glitt: jetzt übernehmen.
        if (finger?.id === e.pointerId) grab(e);
        return;
      }
      const g = gesture.current;
      if (finger?.id !== e.pointerId) {
        // Zweiter Finger (z. B. zum Zoomen): keine Geste.
        gesture.current = null;
        if (g.lock) settle(0);
        return;
      }
      const dx = e.clientX - g.x;
      const dy = e.clientY - g.y;
      if (!g.lock) {
        if (Math.hypot(dx, dy) < LOCK_DISTANCE) return;
        // Senkrecht: Scrollen. Markierter Text: Der Finger wählt aus, statt zu wischen.
        if (Math.abs(dx) < 2 * Math.abs(dy) || !window.getSelection()?.isCollapsed) {
          gesture.current = null;
          if (finger) finger.eligible = false;
          return;
        }
        g.lock = 'x';
        moves.current++;
        showPeeks();
      }
      const dt = e.timeStamp - g.lastT;
      if (dt > 0) g.v = (e.clientX - g.lastX) / dt;
      g.lastX = e.clientX;
      g.lastT = e.timeStamp;
      g.dx = dx;
      setOffset(dragOffset(dx, latest.current.canPrev, latest.current.canNext), false);
    },
    onPointerUp(e: PointerEvent) {
      const g = gesture.current;
      if (!g || finger?.id !== e.pointerId) return;
      gesture.current = null;
      if (!g.lock) return;
      const width = track.current?.offsetWidth ?? window.innerWidth;
      settle(releaseStep(g.dx, g.v, width, latest.current.canPrev, latest.current.canNext));
    },
    onPointerCancel() {
      const g = gesture.current;
      gesture.current = null;
      if (g?.lock) settle(0);
    },
  };
  const latestHandlers = useRef(handlers);
  latestHandlers.current = handlers;

  useEffect(() => {
    trackFingers();
    const el = root.current;
    activeRoot = el;
    const on = {
      pointerdown: (e: PointerEvent) => latestHandlers.current.onPointerDown(e),
      pointermove: (e: PointerEvent) => latestHandlers.current.onPointerMove(e),
      pointerup: (e: PointerEvent) => latestHandlers.current.onPointerUp(e),
      pointercancel: () => latestHandlers.current.onPointerCancel(),
    };
    for (const [type, fn] of Object.entries(on)) document.addEventListener(type, fn as EventListener, { passive: true });
    return () => {
      if (activeRoot === el) activeRoot = null;
      for (const [type, fn] of Object.entries(on)) document.removeEventListener(type, fn as EventListener);
    };
  }, []);

  return { peeking, slide };
}
