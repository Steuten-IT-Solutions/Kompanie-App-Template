import brand from '../generated/brand';
import { formatPoints } from '../format';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;

/** „Intern“ → Punktekatalog: wofür es wie viele Punkte gibt (nur aktive Vorlagen, in der Reihenfolge der Pflege). */
export default function Punktekatalog() {
  const { data, error, loading, reload } = useAsync(sources.catalog.load, sources.catalog.deps, sources.catalog.key);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const items = data ?? [];
  if (items.length === 0) return <Empty>Noch keine Einträge im {sec.points}katalog.</Empty>;

  return (
    <div>
      <p className="muted small">
        So viele {sec.points} gibt es je Eintrag. Hat deine Rolle einen {sec.points}faktor, zählt ein Eintrag entsprechend weniger oder
        mehr.
      </p>
      <ul className="card-list">
        {items.map((c) => (
          <li key={c.id} className="card row">
            <div className="row-main">
              <div className="row-title">{c.title}</div>
            </div>
            <div className={`row-value ${c.points < 0 ? 'neg' : 'pos'}`}>{formatPoints(c.points)}</div>
          </li>
        ))}
      </ul>
    </div>
  );
}
