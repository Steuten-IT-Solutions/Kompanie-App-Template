import { api, config } from '../api';
import brand from '../generated/brand';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';
import Punkte from './Punkte';
import Strafen from './Strafen';

/** „Meins“: eigene Punkte und Strafen, umschaltbar. Die Summen (/me) lädt Meins einmal für beide. */
export default function Meins({ sub }: { sub?: string }) {
  const me = useAsync(api.me, [], 'me');
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
      {me.loading && !me.data ? (
        <Loading />
      ) : me.error && !me.data ? (
        <ErrorBox message={me.error} onRetry={me.reload} />
      ) : active === 'punkte' ? (
        <Punkte me={me.data!} />
      ) : active === 'strafen' ? (
        <Strafen me={me.data!} />
      ) : null}
    </div>
  );
}
