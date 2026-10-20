import { useState } from 'react';
import type { BoardItem } from './api';
import { canPostBoard, dismiss, useBoard } from './board';
import { formatShortTime } from './format';
import brand from './generated/brand';
import { navigate } from './router';

const sec = brand.labels.sections;

/** Kopfzeile eines Eintrags: Verfasser und Zeit (geändert: Zeit der Änderung). */
export function BoardMeta({ item }: { item: BoardItem }) {
  const edited = item.updated_at !== item.created_at;
  return (
    <span className="board-meta">
      {item.author_name ?? 'Unbekannt'} · {formatShortTime(edited ? item.updated_at : item.created_at)}
      {edited && ' (geändert)'}
    </span>
  );
}

/** Reiner Text mit Zeilenumbrüchen (CSS white-space); Links sind bewusst nicht anklickbar. */
export function BoardText({ text }: { text: string }) {
  return <p className="board-text">{text}</p>;
}

/**
 * Banner oben auf jeder Seite (Issue #5): die neueste gültige Nachricht, weitere aufklappbar („+N weitere“). „×“
 * blendet eine Nachricht auf diesem Gerät aus, bis sie geändert wird. Später kommen weitere Arten von Einträgen dazu
 * (z. B. Umfragen); unbekannte überspringt das Banner.
 */
export default function BoardBanner() {
  const { items, dismissed } = useBoard();
  const [expanded, setExpanded] = useState(false);
  const shown = items.filter((i) => i.kind === 'message' && !dismissed(i));
  if (shown.length === 0) return null;
  const list = expanded ? shown : shown.slice(0, 1);
  return (
    <section className="board-banner" aria-label={sec.board} aria-live="polite">
      {list.map((item) => (
        <article className="board-entry" key={`${item.kind}-${item.id}`}>
          <div className="board-entry-body">
            <div className="board-entry-head">
              <strong>{sec.board}</strong> <BoardMeta item={item} />
            </div>
            <BoardText text={item.text} />
          </div>
          <button className="btn btn-link board-dismiss" onClick={() => dismiss(item)} aria-label="Nachricht ausblenden">
            ×
          </button>
        </article>
      ))}
      <div className="board-banner-foot">
        {shown.length > 1 && (
          <button className="btn btn-link board-more" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}>
            {expanded ? 'Weniger anzeigen' : `+${shown.length - 1} weitere`}
          </button>
        )}
        <button className="btn btn-link board-open" onClick={() => navigate('brett')}>
          {canPostBoard() ? 'Brett öffnen und bearbeiten' : 'Brett öffnen'}
        </button>
      </div>
    </section>
  );
}
