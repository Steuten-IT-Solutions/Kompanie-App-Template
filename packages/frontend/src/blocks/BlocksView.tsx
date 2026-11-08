import type { Block, ButtonBlock, EventsBlock } from '../api';
import { formatEventWhen } from '../format';
import RichTextView from '../richtext/RichTextView';
import Countdown from './Countdown';

/**
 * Inhalt aus Blöcken (eigene Seiten, News, Schwarzes Brett): jeder Block mit seiner Anzeige. Unbekannte Typen (Daten eines neueren Servers)
 * übergeht die App; wer pflegt, sieht einen Hinweis.
 */
export default function BlocksView({ blocks, manager, textClassName }: { blocks: Block[]; manager?: boolean; textClassName?: string }) {
  return (
    <div className="blocks">
      {blocks.map((b) => {
        const members =
          manager && b.audience === 'members' ? <span className="badge badge-level-guest block-badge">nur Mitglieder</span> : null;
        let view;
        switch (b.type) {
          case 'text':
            view = <RichTextView html={b.html} className={textClassName} />;
            break;
          case 'countdown':
            view = <Countdown block={b} />;
            break;
          case 'button':
            view = <LinkButton block={b} />;
            break;
          case 'events':
            view = <NextEvents block={b} />;
            break;
          default:
            view = manager ? <p className="muted small">Dieser Block wird von dieser Version der App nicht unterstützt.</p> : null;
        }
        if (!view) return null;
        return (
          <div key={(b as { id: string }).id} className={`block-item block-item-${(b as { type: string }).type}`}>
            {members}
            {view}
          </div>
        );
      })}
    </div>
  );
}

/** Knopf mit Link: Seiten der App im selben Fenster, Webseiten in einem neuen. */
function LinkButton({ block }: { block: ButtonBlock }) {
  const external = block.url.startsWith('https://');
  return (
    <p className="block-button">
      <a
        className={`btn${block.style === 'secondary' ? ' btn-secondary' : ''}`}
        href={block.url}
        {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      >
        {block.label}
      </a>
    </p>
  );
}

/** Nächste Termine (vom Server, immer aktuell), mit Link zu allen Terminen. */
function NextEvents({ block }: { block: EventsBlock }) {
  return (
    <section className="block-events">
      {block.title && <h3>{block.title}</h3>}
      {block.events.length === 0 ? (
        <p className="muted">Zurzeit keine anstehenden Termine.</p>
      ) : (
        <ul className="block-events-list">
          {block.events.map((e) => (
            <li key={e.id}>
              <span className="block-events-when">{formatEventWhen(e)}</span>
              <strong className="block-events-title">{e.title}</strong>
              {e.location && <span className="muted small"> · {e.location}</span>}
            </li>
          ))}
        </ul>
      )}
      <a className="btn btn-link block-events-all" href="#/termine">
        Alle Termine
      </a>
    </section>
  );
}
