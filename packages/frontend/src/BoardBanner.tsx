import { useState } from 'react';
import type { BoardMessage, BoardPoll } from './api';
import { canPostBoard, dismiss, useBoard } from './board';
import { formatShortTime } from './format';
import brand from './generated/brand';
import { navigate } from './router';

const sec = brand.labels.sections;

/** Kopfzeile eines Eintrags: Verfasser und Zeit (geändert: Zeit der Änderung). */
export function BoardMeta({ item }: { item: BoardMessage }) {
  const edited = item.updated_at !== item.created_at;
  return (
    <span className="board-meta">
      {item.author_name ?? 'Unbekannt'} · {formatShortTime(edited ? item.updated_at : item.created_at)}
      {edited && ' (geändert)'}
    </span>
  );
}

/** Titel einer Nachricht im Banner (Issue #172): nur der Titel, lange Titel kürzt CSS; ein Klick öffnet die Nachricht. */
export function BoardTitle({ item }: { item: BoardMessage }) {
  return (
    <button className="btn btn-link board-text board-banner-title" title={item.title} onClick={() => navigate(`brett/${item.id}`)}>
      {item.title}
    </button>
  );
}

/** Umfrage im Banner bzw. am Brett: Frage, Ende und der Weg zur Umfrage. */
export function pollEndText(poll: { ends_at: string | null }): string {
  return poll.ends_at ? `läuft bis ${formatShortTime(poll.ends_at)}` : 'läuft, bis sie beendet wird';
}

/**
 * Banner oben auf jeder Seite (Issue #5): die neueste gültige Nachricht mit ihrem Titel (Issue #172; ein Klick öffnet sie
 * am Brett), weitere aufklappbar („+N weitere“). Dazu für
 * Mitglieder laufende Umfragen, die sie noch nicht beantwortet haben (Issue #4; beantwortete stehen nur auf der Seite
 * des Bretts). „×“ blendet einen Eintrag auf diesem Gerät aus, bis er geändert wird. Sind alle ausgeblendet, bleibt eine
 * schmale Zeile mit dem Weg zum Brett, solange es gültige Einträge gibt. Unbekannte Arten überspringt das Banner.
 */
export default function BoardBanner() {
  const { items, dismissed } = useBoard();
  const [expanded, setExpanded] = useState(false);
  const messages = items.filter((i): i is BoardMessage => i.kind === 'message');
  const polls = items.filter((i): i is BoardPoll => i.kind === 'poll' && !i.has_voted);
  const shown = [...messages, ...polls].filter((i) => !dismissed(i));
  const hidden = messages.length + polls.length - shown.length;
  const openLabel = canPostBoard() ? 'Brett öffnen und bearbeiten' : 'Brett öffnen';
  if (shown.length === 0) {
    if (hidden === 0) return null;
    return (
      <section className="board-banner board-banner-collapsed" aria-label={sec.board}>
        <span>
          <strong>{sec.board}</strong> · {hidden} ausgeblendet
        </span>
        <button className="btn btn-link board-open" onClick={() => navigate('brett')}>
          {openLabel}
        </button>
      </section>
    );
  }
  const list = expanded ? shown : shown.slice(0, 1);
  return (
    <section className="board-banner" aria-label={sec.board} aria-live="polite">
      {list.map((item) =>
        item.kind === 'message' ? (
          <article className="board-entry" key={`${item.kind}-${item.id}`}>
            <div className="board-entry-body">
              <div className="board-entry-head">
                <strong>{sec.board}</strong> <BoardMeta item={item} />
              </div>
              <BoardTitle item={item} />
            </div>
            <button className="btn btn-link board-dismiss" onClick={() => dismiss(item)} aria-label="Nachricht ausblenden">
              ×
            </button>
          </article>
        ) : (
          <article className="board-entry board-entry-poll" key={`${item.kind}-${item.id}`}>
            <div className="board-entry-body">
              <div className="board-entry-head">
                <strong>{sec.polls}</strong> <span className="board-meta">{pollEndText(item)}</span>
              </div>
              <p className="board-text">{item.title}</p>
              <button className="btn btn-small board-poll-open" onClick={() => navigate(`umfragen/${item.id}`)}>
                Teilnehmen
              </button>
            </div>
            <button className="btn btn-link board-dismiss" onClick={() => dismiss(item)} aria-label="Umfrage ausblenden">
              ×
            </button>
          </article>
        ),
      )}
      <div className="board-banner-foot">
        {shown.length > 1 && (
          <button className="btn btn-link board-more" aria-expanded={expanded} onClick={() => setExpanded(!expanded)}>
            {expanded ? 'Weniger anzeigen' : `+${shown.length - 1} weitere`}
          </button>
        )}
        <button className="btn btn-link board-open" onClick={() => navigate('brett')}>
          {openLabel}
        </button>
      </div>
    </section>
  );
}
