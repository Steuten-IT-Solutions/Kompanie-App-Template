import { createElement, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import type { DeviceStore } from './deviceStore';
import { capture, type DomNode, type Part } from './skeletonCapture';

/**
 * Gerüst der Seiten: Statt „Lädt …“ zeigt die App den Aufbau, den der Bereich beim letzten Mal hatte (Karten, Zeilen,
 * Überschriften, Knöpfe), als graue Platzhalter, bis die Daten da sind. So steht die Seite sofort und verschiebt sich
 * danach kaum.
 *
 * Gemerkt wird nur der Aufbau: Elemente, ihre Klassen, ungefähre Textlängen (in Stufen) und die Größe von Bildern und
 * Eingabefeldern – kein Text, keine Bilder, keine Adressen. Jedes Loading merkt sich, was nach dem Laden an seine
 * Stelle getreten ist (Schlüssel: Seite und Position im Baum, useId). Gibt es noch kein Gerüst, bleibt es bei „Lädt …“.
 * Die Gerüste liegen im Speicher und auf dem Gerät (deviceStore, Bereich wie bei den Daten); in der Vorschau
 * „App als Rolle ansehen“ wird nichts gemerkt und nichts gezeigt.
 */

const LINE = 40;

function render(part: Part, key: number): ReactNode {
  if (typeof part === 'number') {
    // Lange Texte als mehrere Zeilen, damit sie umbrechen wie der Text.
    const bars: ReactNode[] = [];
    for (let rest = part, i = 0; rest > 0; rest -= LINE, i++) {
      bars.push(<span key={i} className="skeleton-text" style={{ width: `${Math.min(rest, LINE)}ch` }} />, ' ');
    }
    return <span key={key}>{bars}</span>;
  }
  if ('b' in part) {
    const [width, height] = part.b;
    return <span key={key} className={`skeleton-box ${part.c ?? ''}`} style={{ width, height }} />;
  }
  return createElement(part.t, { key, className: part.c }, part.k?.map(render));
}

const layouts = new Map<string, Part[]>();
let device: DeviceStore | null = null;
let enabled = false;

export const skeletons = {
  get: (key: string) => (enabled ? layouts.get(key) : undefined),
  set(key: string, parts: Part[]) {
    if (!enabled) return;
    const before = layouts.get(key);
    if (before && JSON.stringify(before) === JSON.stringify(parts)) return;
    layouts.set(key, parts);
    device?.put(key, parts);
  },
  /** Gerüste vom Gerät laden und ab jetzt merken (beim Start, außer in der Vorschau). */
  async restore(scope: string, store: DeviceStore) {
    enabled = true;
    device = store;
    const found = (await store.open(scope)) as Map<string, Part[]>;
    for (const [key, parts] of found) if (!layouts.has(key) && Array.isArray(parts)) layouts.set(key, parts);
  },
};

const LOADING = '[data-loading]';

function isElement(n: Node): n is Element {
  return n.nodeType === Node.ELEMENT_NODE;
}

/**
 * Nach dem Laden: Was jetzt neu an der Stelle steht, als Gerüst merken. Lädt darin noch etwas (z. B. ein Teil der
 * Seite mit eigenem Loading), kurz warten; eine Fehlermeldung wird nicht gemerkt.
 */
function learn(parent: Element, before: Set<Node>, key: string, route: string) {
  let tries = 0;
  const attempt = () => {
    if (!parent.isConnected || currentRoute() !== route) return;
    const fresh = Array.from(parent.childNodes).filter((n) => !before.has(n));
    const elements = fresh.filter(isElement);
    if (elements.some((n) => n.matches(LOADING) || n.querySelector(LOADING))) {
      if (++tries < 25) setTimeout(attempt, 200);
      return;
    }
    if (elements.some((n) => n.matches('[role="alert"]') || n.querySelector('[role="alert"]'))) return;
    const parts = capture(fresh as unknown as DomNode[]);
    if (parts.length > 0) skeletons.set(key, parts);
  };
  setTimeout(attempt, 50);
}

const currentRoute = () => window.location.hash.replace(/^#\/?/, '');

export function Loading() {
  const ref = useRef<HTMLDivElement>(null);
  const id = useId();
  const [route] = useState(currentRoute);
  const key = `${route}|${id}`;
  const [parts] = useState(() => skeletons.get(key));

  useLayoutEffect(() => {
    const el = ref.current;
    const parent = el?.parentElement;
    if (!el || !parent || !enabled) return;
    // Was schon neben dem Loading steht (z. B. Navigation der Verwaltung), gehört nicht zum Gerüst.
    const before = new Set(Array.from(parent.childNodes).filter((n) => n !== el));
    return () => learn(parent, before, key, route);
  }, [key, route]);

  if (!parts) {
    return (
      <div ref={ref} className="loading" role="status" aria-live="polite" data-loading="">
        <span className="spinner" aria-hidden="true" /> Lädt …
      </div>
    );
  }
  return (
    <div ref={ref} className="skeleton" role="status" data-loading="">
      <span className="visually-hidden">Lädt …</span>
      <div className="skeleton-parts" aria-hidden="true">
        {parts.map(render)}
      </div>
    </div>
  );
}
