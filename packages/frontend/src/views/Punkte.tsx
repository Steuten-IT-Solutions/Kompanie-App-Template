import { type Me } from '../api';
import brand from '../generated/brand';
import { formatDate, formatPoints } from '../format';
import { Empty, ErrorBox, Loading, StatCard, useAsync } from '../ui';
import { useFocusEntry } from '../focus';
import { sources } from '../viewData';
import { localeTag, t } from '../i18n';

export default function Punkte({ me, focus }: { me: Me; focus?: string }) {
  const { data, error, loading, reload } = useAsync(sources.myPoints.load, sources.myPoints.deps, sources.myPoints.key);
  const flash = useFocusEntry(focus, !error && !!data?.some((p) => String(p.id) === focus));

  if (loading && !data) return <Loading shape="stat" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const points = data!;

  return (
    <div>
      <StatCard
        label={t('Meine {points}', { points: brand.labels.sections.points })}
        value={me.points.toLocaleString(localeTag())}
        tone="good"
      />
      <h2 className="section-title">{t('Verlauf')}</h2>
      {points.length === 0 ? (
        <Empty>{t('Noch keine Einträge.')}</Empty>
      ) : (
        <ul className="card-list">
          {points.map((p) => (
            <li key={p.id} className={`card row ${flash === String(p.id) ? 'focused' : ''}`} data-focus-id={p.id}>
              <div className="row-main">
                <div className="row-title">{p.reason}</div>
                <div className="row-sub">
                  {formatDate(p.date)}
                  {p.created_by_name && ` · ${t('eingetragen von {name}', { name: p.created_by_name })}`}
                </div>
              </div>
              <div className={`row-value ${p.points < 0 ? 'neg' : 'pos'}`}>{formatPoints(p.points)}</div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
