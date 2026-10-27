import { config } from '../api';
import brand from '../generated/brand';
import { RankLabel } from '../RankBadge';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const rank = brand.labels.rank;
const sec = brand.labels.sections;

/** „Intern“ → Dienstgrade: alle Dienstgrade vom niedrigsten zum höchsten, mit Abzeichen und (mit Punkten) Schwelle. */
export default function Dienstgrade() {
  const { data, error, loading, reload } = useAsync(sources.ranks.load, sources.ranks.deps, sources.ranks.key);
  // Nur für die Markierung des eigenen Dienstgrads; ohne /me bleibt die Liste ohne Markierung.
  const signedIn = !!config?.user;
  const me = useAsync(() => (signedIn ? sources.me.load() : Promise.resolve(null)), [], signedIn ? sources.me.key : undefined);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const ranks = data ?? [];
  if (ranks.length === 0) return <Empty>Noch keine {sec.ranks} angelegt.</Empty>;
  const withPoints = !!config?.features.points;
  const mine = me.data?.rank?.id;

  return (
    <div>
      {withPoints && (
        <p className="muted small">
          Rechts steht, ab wie vielen {sec.points} ein {rank} erreichbar ist. Befördert wird nicht automatisch, sondern von den
          Verantwortlichen.
        </p>
      )}
      <ul className="card-list rank-list">
        {ranks.map((r) => (
          <li key={r.id} className={`card row${r.id === mine ? ' rank-mine' : ''}`}>
            <div className="row-main">
              <div className="row-title">
                <RankLabel url={r.badge_url} title={r.title} />
              </div>
              {r.id === mine && <div className="row-sub">Dein {rank}</div>}
            </div>
            {withPoints && r.min_points !== null && <div className="row-value">ab {r.min_points.toLocaleString('de-DE')}</div>}
          </li>
        ))}
      </ul>
    </div>
  );
}
