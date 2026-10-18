import { config } from '../api';
import brand from '../generated/brand';
import { navigate } from '../router';
import Punkte from './Punkte';
import Strafen from './Strafen';

/** „Meins“: eigene Punkte und Strafen, umschaltbar. */
export default function Meins({ sub }: { sub?: string }) {
  const parts = (
    [
      ['punkte', brand.labels.sections.points, config?.features.points],
      ['strafen', brand.labels.sections.penalties, config?.features.penalties],
    ] as const
  ).filter(([, , on]) => on);
  const active = parts.find(([id]) => id === sub)?.[0] ?? parts[0]?.[0];
  return (
    <div>
      {parts.length > 1 && (
        <nav className="segmented" aria-label="Meine Daten">
          {parts.map(([id, label]) => (
            <button
              key={id}
              className={active === id ? 'active' : ''}
              onClick={() => navigate(`meins/${id}`)}
              aria-current={active === id ? 'page' : undefined}
            >
              {label}
            </button>
          ))}
        </nav>
      )}
      {active === 'punkte' ? <Punkte /> : active === 'strafen' ? <Strafen /> : null}
    </div>
  );
}
