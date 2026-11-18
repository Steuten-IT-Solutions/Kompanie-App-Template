import { useRef, type ReactNode } from 'react';
import { navigate, redirect } from './router';
import { neighbors, useSwipeDrag } from './swipe';
import { Empty } from './ui';

/**
 * Liste mit Namen und Karte je Eintrag zum Blättern (wie die Seite eines Mitglieds): zuerst bei den Passiven, dann bei
 * den Anfragen auf einen Zugang. Die Liste zeigt nur den Namen; Antippen öffnet `<base>/<id>`. Auf der Karte geht es oben
 * zurück zur Liste und per Pfeil oder Wischen zum vorherigen bzw. nächsten Eintrag (im Kreis).
 */

/** Reihenfolge der Liste beim Öffnen je Liste (gefiltert und sortiert): Auf der Karte blättert man darin weiter. */
const orders = new Map<string, number[]>();

/** Liste nur mit Namen; Antippen öffnet die Karte. */
export function BrowseList<T extends { id: number }>({
  base,
  items,
  className,
  title,
}: {
  base: string;
  items: T[];
  className: string;
  title: (item: T) => ReactNode;
}) {
  function open(id: number) {
    orders.set(
      base,
      items.map((x) => x.id),
    );
    navigate(`${base}/${id}`);
  }
  return (
    <ul className={`card-list ${className}`}>
      {items.map((x) => (
        <li key={x.id} className="card row clickable member-row" onClick={() => open(x.id)}>
          <div className="row-main">
            <div className="row-title">{title(x)}</div>
          </div>
          <span className="chevron" aria-hidden="true">
            ›
          </span>
        </li>
      ))}
    </ul>
  );
}

/**
 * Karte eines Eintrags: oben zurück zur Liste und blättern, darunter `render`. Beim Ziehen zeigen die Nachbarn `peek`
 * (nur zum Ansehen). Die Aufrufer bauen sie je Eintrag neu auf (key): Sonst steht die Spur nach dem Gleiten zum Nachbarn
 * noch verschoben und zeigt kurz dessen Nachbarn, bis sie zurückspringt.
 */
export function BrowseCard<T extends { id: number }>({
  base,
  id,
  items,
  className,
  back,
  prevLabel,
  nextLabel,
  missing,
  render,
  peek,
}: {
  base: string;
  id: number;
  items: T[];
  /** zusätzliche Klasse der Karte, z. B. passive-card */
  className: string;
  /** Text des Knopfs zurück zur Liste, z. B. „‹ Alle Passiven“ */
  back: string;
  prevLabel: string;
  nextLabel: string;
  /** Hinweis, wenn es den Eintrag nicht (mehr) gibt */
  missing: string;
  render: (item: T) => ReactNode;
  peek: (item: T) => ReactNode;
}) {
  const known = items.map((x) => x.id);
  const remembered = orders.get(base);
  const order = remembered && remembered.includes(id) ? remembered.filter((x) => known.includes(x)) : known;
  const { prev, next, index } = neighbors(order, id, true);
  const show = (other: number | null) => other !== null && redirect(`${base}/${other}`);
  const root = useRef<HTMLDivElement>(null);
  const track = useRef<HTMLDivElement>(null);
  const swipe = useSwipeDrag(root, track, {
    canPrev: prev !== null,
    canNext: next !== null,
    onCommit: (step) => show(step === 1 ? next : prev),
  });
  const item = items.find((x) => x.id === id);
  const nav = (position: number, live: boolean) => (
    <div className="member-nav">
      <button type="button" className="btn btn-link back" onClick={live ? () => navigate(base) : undefined}>
        {back}
      </button>
      {order.length > 1 && (
        <span className="member-step">
          <button type="button" className="btn btn-link" onClick={live ? () => swipe.slide(-1) : undefined} aria-label={prevLabel}>
            ‹
          </button>
          <span className="muted small">
            {position} / {order.length}
          </span>
          <button type="button" className="btn btn-link" onClick={live ? () => swipe.slide(1) : undefined} aria-label={nextLabel}>
            ›
          </button>
        </span>
      )}
    </div>
  );
  const peekAt = (other: number | null, side: 'prev' | 'next') => {
    const o = items.find((x) => x.id === other);
    if (!o) return null;
    return (
      <div className={`member-peek member-peek-${side}`} aria-hidden="true" ref={(el) => el?.setAttribute('inert', '')}>
        {nav(order.indexOf(o.id) + 1, false)}
        {peek(o)}
      </div>
    );
  };
  if (!item)
    return (
      <div>
        {nav(0, true)}
        <Empty>{missing}</Empty>
      </div>
    );
  return (
    <div className={`member-detail browse-card ${className}`} ref={root}>
      <div className={`member-track${swipe.peeking ? ' peeking' : ''}`} ref={track}>
        {swipe.peeking && peekAt(prev, 'prev')}
        {swipe.peeking && peekAt(next, 'next')}
        {nav(index + 1, true)}
        {render(item)}
      </div>
    </div>
  );
}
