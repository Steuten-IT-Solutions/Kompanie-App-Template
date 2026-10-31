import { config, type RankHistoryEntry } from '../api';
import brand from '../generated/brand';
import { formatDate, formatUtcDate } from '../format';
import { RankBadge, RankLabel } from '../RankBadge';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const rank = brand.labels.rank;
const sec = brand.labels.sections;

/** „Meins“ → Dienstgrad: eigener Dienstgrad, mit Punkten der Weg zum nächsten, die Liste und der eigene Verlauf. */
export default function Dienstgrad() {
  const { data, error, loading, reload } = useAsync(sources.myRank.load, sources.myRank.deps, sources.myRank.key);
  if (loading && !data) return <Loading shape="stat" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const d = data!;
  const withPoints = !!config?.features.points && d.points !== undefined;
  const next = d.next_rank ?? null;
  const missing = d.points_to_next ?? null;
  const progress = withPoints && next?.min_points ? Math.max(0, Math.min(1, (d.points ?? 0) / next.min_points)) : null;

  return (
    <div>
      {d.rank?.badge_url && (
        <div className="rank-badge-hero">
          <RankBadge url={d.rank.badge_url} title={d.rank.title} size="large" />
        </div>
      )}
      <div className="card rank-card rank-progress">
        <div className="rank-card-label">Mein {rank}</div>
        <div className="rank-card-title">{d.rank?.title ?? `Noch kein ${rank}`}</div>
        {withPoints && next && missing !== null && (
          <>
            {progress !== null && (
              <progress className="progress" value={Math.round(progress * 100)} max={100} aria-label={`Fortschritt bis ${next.title}`} />
            )}
            <div className={`rank-next${next.badge_url ? ' with-badge' : ''}`}>
              <RankBadge url={next.badge_url} title={next.title} size="small" />
              {missing <= 0 ? (
                <span>
                  {sec.points} für „{next.title}“ erreicht
                  {d.threshold_reached_since ? ` (seit ${formatDate(d.threshold_reached_since)})` : ''} – die Beförderung steht noch aus.
                </span>
              ) : (
                <span>
                  Noch <strong>{missing.toLocaleString('de-DE')}</strong> {sec.points} bis „{next.title}“.
                </span>
              )}
            </div>
          </>
        )}
        {withPoints && !next && d.rank && <div className="rank-next">Das ist der höchste {rank}.</div>}
      </div>

      <h2 className="section-title">Alle {sec.ranks}</h2>
      <ul className="card-list rank-list">
        {d.ranks.map((r) => (
          <li key={r.id} className={`card row${r.id === d.rank?.id ? ' rank-mine' : ''}`}>
            <div className="row-main">
              <div className="row-title">
                <RankLabel url={r.badge_url} title={r.title} />
              </div>
              {r.id === d.rank?.id && <div className="row-sub">Dein {rank}</div>}
            </div>
            {withPoints && r.min_points !== null && <div className="row-value">ab {r.min_points.toLocaleString('de-DE')}</div>}
          </li>
        ))}
      </ul>

      <h2 className="section-title">Verlauf</h2>
      <RankHistory entries={d.history} />
    </div>
  );
}

const KIND: Record<RankHistoryEntry['kind'], string> = { promotion: 'Beförderung', demotion: 'Degradierung', initial: 'Ersteinstufung' };

export function RankHistory({ entries }: { entries: RankHistoryEntry[] }) {
  if (entries.length === 0) return <Empty>Noch keine Einträge.</Empty>;
  return (
    <ul className="card-list">
      {entries.map((h, i) => (
        <li key={i} className="card row">
          {h.to && <RankBadge url={h.to_badge_url} title={h.to} size="small" />}
          <div className="row-main">
            <div className="row-title">
              {KIND[h.kind]}: {h.from ?? `kein ${rank}`} → {h.to ?? `kein ${rank}`}
            </div>
            <div className="row-sub">
              {formatUtcDate(h.changed_at)}
              {h.changed_by_name && ` · von ${h.changed_by_name}`}
              {h.points_at_change !== null && ` · ${h.points_at_change.toLocaleString('de-DE')} ${sec.points}`}
              {h.reason && ` · ${h.reason}`}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}
