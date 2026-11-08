import type { Block } from '../api';
import RichTextView from '../richtext/RichTextView';
import Countdown from './Countdown';

/**
 * Inhalt aus Blöcken (eigene Seiten): jeder Block mit seiner Anzeige. Unbekannte Typen (Daten eines neueren Servers)
 * übergeht die App; wer pflegt, sieht einen Hinweis.
 */
export default function BlocksView({ blocks, manager }: { blocks: Block[]; manager?: boolean }) {
  return (
    <div className="blocks">
      {blocks.map((b) => {
        const members =
          manager && b.audience === 'members' ? <span className="badge badge-level-guest block-badge">nur Mitglieder</span> : null;
        let view;
        switch (b.type) {
          case 'text':
            view = <RichTextView html={b.html} />;
            break;
          case 'countdown':
            view = <Countdown block={b} />;
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
