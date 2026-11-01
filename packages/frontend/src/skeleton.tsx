import { createElement, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import type { DeviceStore } from './deviceStore';
import { capture, type DomNode, type Part } from './skeletonCapture';

/**
 * Gerüst der Seiten: Statt „Lädt …“ zeigt die App den Aufbau, den der Bereich beim letzten Mal hatte (Karten, Zeilen,
 * Überschriften, Knöpfe), als graue Platzhalter, bis die Daten da sind. So steht die Seite sofort und verschiebt sich
 * danach kaum.
 *
 * Gemerkt wird nur der Aufbau: Elemente, ihre Klassen, ungefähre Textlängen (in Stufen) und die Größe von Bildern und
 * Eingabefeldern – kein Text, keine Bilder, keine Adressen. Jedes Loading merkt sich, was nach dem Laden an seine
 * Stelle getreten ist (Schlüssel: Seite und Stelle im DOM, placeOf). Gibt es noch kein Gerüst (erstes Öffnen auf dem
 * Gerät, Vorschau), zeigt es ein allgemeines Gerüst in der Form der Seite (shape: Liste, News, Kacheln, Formular, Text).
 * Die Gerüste liegen im Speicher und auf dem Gerät (deviceStore, Bereich wie bei den Daten); in der Vorschau
 * „App als Rolle ansehen“ wird nichts gemerkt und nichts gezeigt.
 */

const LINE = 40;

/** Gerüst nicht bedienbar (Knöpfe und Links darin sind nur Form): weder antippen noch mit Tab erreichen. */
const inert = (el: HTMLElement | null) => el?.setAttribute('inert', '');

function render(part: Part, key: number): ReactNode {
  if (typeof part === 'number') {
    // Lange Texte als mehrere Zeilen, damit sie umbrechen wie der Text.
    const bars: ReactNode[] = [];
    for (let rest = part, i = 0; rest > 0; rest -= LINE, i++) {
      bars.push(<span key={i} className="skeleton-text" style={{ width: `${Math.min(rest, LINE)}ch` }} />, ' ');
    }
    return (
      <span key={key} className="skeleton-line">
        {bars}
      </span>
    );
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
  // Gleich nach dem Rendern (die neuen Elemente stehen schon im DOM): Wer sofort weiterwechselt, hat das Gerüst trotzdem.
  queueMicrotask(attempt);
}

const currentRoute = () => window.location.hash.replace(/^#\/?/, '');

/**
 * Stelle eines Loading auf der Seite: Elternelemente bis zum Inhaltsbereich (Tag und Klassen) und welches ladende
 * Element darin es ist. Bleibt gleich, solange die Seite gleich aufgebaut ist (anders als useId, das beim Rendern im
 * Browser einfach weiterzählt).
 */
function placeOf(el: Element): string {
  const parent = el.parentElement!;
  const index = Array.from(parent.children)
    .filter((c) => c.matches(LOADING))
    .indexOf(el);
  const chain: string[] = [];
  for (let p: Element | null = parent; p && p !== document.body && chain.length < 8; p = p.parentElement) {
    const classes = typeof p.className === 'string' ? p.className.trim().split(/\s+/).filter(Boolean) : [];
    chain.unshift([p.tagName.toLowerCase(), ...classes].join('.'));
    if (p.tagName === 'MAIN') break;
  }
  return `${chain.join('>')}#${index}`;
}

/** Form des allgemeinen Gerüsts, solange die App das der Stelle noch nicht kennt. */
export type LoadingShape = 'list' | 'events' | 'people' | 'stat' | 'overview' | 'news' | 'grid' | 'form' | 'detail' | 'text';

const text = (width: string) => <span className="skeleton-text" style={{ width }} />;
const times = (n: number, f: (i: number) => ReactNode) => Array.from({ length: n }, (_, i) => f(i));
// Leicht unterschiedliche Längen, damit es nach Inhalt aussieht und nicht nach Raster.
const WIDTHS = ['14ch', '20ch', '11ch', '17ch'];

/**
 * Allgemeine Gerüste, mit denselben Klassen wie die echten Seiten (Abstände und Größen kommen so aus styles.css):
 * list – Karten untereinander (Titel und Unterzeile; die meisten Seiten), events – Monat und Termine mit Datum,
 * people – Suche und Personen mit Foto, stat – Kachel mit Zahl über einer Liste (Punkte, Kasse), overview – Name,
 * zwei Kacheln und Zeilen (Übersicht unter „Meins“), news – News-Karten, grid – Kacheln
 * der Galerie, form – Karte mit Hinweis und Feldern (Einstellungen, Formulare), detail – „Zurück“, Überschrift und
 * Text (ein Beitrag, ein Album, ein Dokument), text – zwei Zeilen (Teil einer Seite, z. B. unter einer Überschrift).
 */
function shapeParts(shape: LoadingShape): ReactNode {
  switch (shape) {
    case 'news':
      return (
        <ul className="card-list news-list">
          {times(3, (i) => (
            <li key={i} className="card news-card">
              <div className="news-body">
                <div className="news-date">{text('9ch')}</div>
                <h2 className="news-title">{text(WIDTHS[i])}</h2>
                <p className="news-excerpt">
                  {text('100%')} {text('70%')}
                </p>
              </div>
            </li>
          ))}
        </ul>
      );
    case 'events':
      return (
        <section className="month">
          <h2 className="month-title">{text('12ch')}</h2>
          <ul className="card-list">
            {times(3, (i) => (
              <li key={i} className="card event">
                <div className="event-head">
                  <div className="date-badge">
                    <span className="day">{text('2ch')}</span>
                    <span className="mon">{text('3ch')}</span>
                  </div>
                  <div className="event-main">
                    <div className="event-title">{text(WIDTHS[i])}</div>
                    <div className="event-when">{text('22ch')}</div>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </section>
      );
    case 'people':
      return (
        <>
          <span className="skeleton-box" style={{ display: 'block', width: '100%', height: 40, marginBottom: 12 }} />
          <ul className="card-list">
            {times(4, (i) => (
              <li key={i} className="card row member-row">
                <span className="skeleton-box" style={{ width: 44, height: 44, borderRadius: '50%', flex: 'none' }} />
                <div className="row-main">
                  <div className="row-title">{text(WIDTHS[i])}</div>
                </div>
              </li>
            ))}
          </ul>
        </>
      );
    case 'stat':
    case 'overview':
      return (
        <div className={shape === 'overview' ? 'my-overview' : undefined}>
          {shape === 'overview' && <h2 className="member-name member-name-centered">{text('12ch')}</h2>}
          <div className={shape === 'overview' ? 'stats member-stats' : undefined}>
            {times(shape === 'overview' ? 2 : 1, (i) => (
              <div key={i} className="stat stat-neutral">
                <div className="stat-value">{text('3ch')}</div>
                <div className="stat-label">{text('10ch')}</div>
              </div>
            ))}
          </div>
          {shape === 'stat' && <h2 className="section-title">{text('7ch')}</h2>}
          <ul className="card-list">
            {times(3, (i) => (
              <li key={i} className="card row">
                <div className="row-main">
                  <div className="row-title">{text(WIDTHS[i])}</div>
                  <div className="row-sub">{text(WIDTHS[(i + 2) % 4])}</div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      );
    case 'grid':
      return (
        <section>
          <h2 className="section-title">{text('5ch')}</h2>
          <ul className="album-grid">
            {times(4, (i) => (
              <li key={i}>
                <span className="album-card">
                  <span className="album-placeholder" />
                  <span className="album-title">{text(WIDTHS[i])}</span>
                  <span className="album-count">{text('8ch')}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      );
    case 'form':
      return (
        <section className="card settings-section">
          <p className="small muted">
            {text('100%')} {text('45%')}
          </p>
          {times(2, (i) => (
            <div key={i} className="field">
              <span>{text(WIDTHS[i])}</span>
              <span className="skeleton-box" style={{ display: 'block', width: '100%', height: 40 }} />
            </div>
          ))}
        </section>
      );
    case 'detail':
      return (
        <div>
          <p className="back">{text('8ch')}</p>
          <h2 className="member-name">{text('18ch')}</h2>
          <p>
            {text('100%')} {text('100%')} {text('55%')}
          </p>
        </div>
      );
    case 'text':
      return (
        <p>
          {text('100%')} {text('60%')}
        </p>
      );
    default:
      return (
        <ul className="card-list">
          {times(4, (i) => (
            <li key={i} className="card row">
              <div className="row-main">
                <div className="row-title">{text(WIDTHS[i])}</div>
                <div className="row-sub">{text(WIDTHS[(i + 2) % 4])}</div>
              </div>
            </li>
          ))}
        </ul>
      );
  }
}

/**
 * Platzhalter, solange Daten laden. shape: Form des Gerüsts beim ersten Öffnen (siehe shapeParts); danach zeigt die
 * Stelle den Aufbau, den sie zuletzt hatte. chunk: Platzhalter, solange ein Programmteil lädt (Suspense). Er steht an
 * derselben Stelle wie das Loading der Ansicht danach, sieht aber die ganze Ansicht (auch „‹ Zurück“ und Überschriften,
 * die beim Loading der Ansicht schon echt dastehen); daher ein eigener Schlüssel, sonst stünden sie doppelt da.
 */
export function Loading({ shape = 'list', chunk = false }: { shape?: LoadingShape; chunk?: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  const [parts, setParts] = useState<Part[] | undefined>();

  // Vor dem ersten Zeichnen: Die Stelle steht erst im DOM fest; mit gemerktem Gerüst wird das allgemeine so nie sichtbar.
  useLayoutEffect(() => {
    const el = ref.current;
    const parent = el?.parentElement;
    if (!el || !parent || !enabled) return;
    const route = currentRoute();
    const place = `${route}|${placeOf(el)}`;
    const key = chunk ? `${place}|chunk` : place;
    // Hat die Ansicht noch kein eigenes (ihre Daten kamen bisher vorab, ihr Loading erschien nie) und steht sie allein
    // da, passt das Gerüst vom Laden ihres Programmteils: Es zeigt genau diese Ansicht, und nichts steht doppelt.
    const alone = !el.previousElementSibling && !el.nextElementSibling;
    setParts(skeletons.get(key) ?? (!chunk && alone ? skeletons.get(`${place}|chunk`) : undefined));
    // Was schon neben dem Loading steht (z. B. Navigation der Verwaltung), gehört nicht zum Gerüst.
    const before = new Set(Array.from(parent.childNodes).filter((n) => n !== el));
    return () => learn(parent, before, key, route);
  }, []);

  return (
    // data-shape: allgemeines Gerüst (die Stelle ist noch nicht gemerkt)
    <div ref={ref} className="skeleton" role="status" data-loading="" data-shape={parts ? undefined : shape}>
      <span className="visually-hidden">Lädt …</span>
      <div className="skeleton-parts" aria-hidden="true" ref={inert}>
        {parts ? parts.map(render) : shapeParts(shape)}
      </div>
    </div>
  );
}

/**
 * Seite einer Person, solange ihre Daten laden (z. B. schneller gewischt, als die App vorlädt): unter dem Namen, den
 * die Seite schon aus der Liste zeigt (PersonName), Platzhalter für Abzeichen bzw. Foto und eine Zeile. Der Nachbar
 * daneben zeigt kein „Lädt …“ (status).
 */
export function PersonSkeleton({ status = true }: { status?: boolean }) {
  const placeholders = (
    <>
      <div className="member-rank-line">
        <span className="skeleton-box" style={{ width: 96, height: 96, borderRadius: '50%' }} />
      </div>
      <p className="small member-level">
        <span className="skeleton-text" style={{ width: '16ch' }} />
      </p>
    </>
  );
  return status ? (
    <div className="skeleton" role="status" data-loading="">
      <span className="visually-hidden">Lädt …</span>
      <div className="skeleton-parts" aria-hidden="true">
        {placeholders}
      </div>
    </div>
  ) : (
    <div className="skeleton-parts">{placeholders}</div>
  );
}

/** Name einer Person; ohne bekannten Namen (Daten und Liste laden noch) ein Platzhalter. */
export function PersonName({ name }: { name?: string }) {
  return <>{name ?? <span className="skeleton-text" style={{ width: '12ch' }} />}</>;
}
