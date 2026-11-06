import { api, can, type PageSummary } from '../api';
import brand from '../generated/brand';
import RichTextView from '../richtext/RichTextView';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;

/** Hinweis für die Pflege: Für wen ist die Seite? */
export function AudienceBadge({ page }: { page: Pick<PageSummary, 'audience'> }) {
  return page.audience === 'members' ? <span className="badge badge-level-guest">nur Mitglieder</span> : null;
}

/**
 * Eigene Seiten (Issue #287): ein Punkt der Navigation mit einer Kachel je Seite, die die Person lesen darf (in der
 * Reihenfolge der Verwaltung), darunter #/seiten/<id> mit dem Text. Was nicht für sie gedacht ist, liefert der Server
 * gar nicht erst.
 */
export default function Seiten({ id }: { id: number | null }) {
  return id === null ? <PageTiles /> : <PageView id={id} />;
}

function PageTiles() {
  const { data, error, loading, reload } = useAsync(sources.pages.load, sources.pages.deps, sources.pages.key);
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? `Die ${sec.pages} konnten nicht geladen werden.`} onRetry={reload} />;
  const manager = can('manage_pages');
  return (
    <div>
      {data.length === 0 ? (
        <Empty>Noch keine {sec.pages}.</Empty>
      ) : (
        <ul className="page-tiles">
          {data.map((p) => (
            <li key={p.id}>
              <button type="button" className="card page-tile" onClick={() => navigate(`seiten/${p.id}`)}>
                <span className="row-title">{p.title}</span>
                {manager && <AudienceBadge page={p} />}
              </button>
            </li>
          ))}
        </ul>
      )}
      {manager && (
        <p>
          <button type="button" className="btn btn-secondary" onClick={() => navigate('verwaltung/seiten')}>
            {sec.pages} verwalten
          </button>
        </p>
      )}
    </div>
  );
}

function PageView({ id }: { id: number }) {
  const { data, error, loading, reload } = useAsync(() => api.page(id), [id], 'page');
  return (
    <div className="page-view">
      <button className="btn btn-link back" onClick={() => navigate('seiten')}>
        ‹ Alle {sec.pages}
      </button>
      {loading && !data ? (
        <Loading shape="text" />
      ) : error || !data ? (
        <ErrorBox message={error ?? 'Die Seite konnte nicht geladen werden.'} onRetry={reload} />
      ) : (
        <article className="card page-article">
          <h2>
            {data.title} {can('manage_pages') && <AudienceBadge page={data} />}
          </h2>
          {data.body ? <RichTextView html={data.body} /> : <p className="muted">Diese Seite hat noch keinen Text.</p>}
        </article>
      )}
    </div>
  );
}
