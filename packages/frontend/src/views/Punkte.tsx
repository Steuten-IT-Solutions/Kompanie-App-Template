import { api } from '../api';
import brand from '../generated/brand';
import { formatDate, formatPoints } from '../format';
import { Empty, ErrorBox, Loading, StatCard, useAsync } from '../ui';

export default function Punkte() {
  const { data, error, loading, reload } = useAsync(() => Promise.all([api.me(), api.myPoints()]));

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [me, points] = data!;

  return (
    <div>
      <StatCard label={`Meine ${brand.labels.sections.points}`} value={me.points.toLocaleString('de-DE')} tone="good" />
      <h2 className="section-title">Verlauf</h2>
      {points.length === 0 ? (
        <Empty>Noch keine Einträge.</Empty>
      ) : (
        <ul className="card-list">
          {points.map((p) => (
            <li key={p.id} className="card row">
              <div className="row-main">
                <div className="row-title">{p.reason}</div>
                <div className="row-sub">{formatDate(p.date)}</div>
              </div>
              <div className={`row-value ${p.points < 0 ? 'neg' : 'pos'}`}>{formatPoints(p.points)}</div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
