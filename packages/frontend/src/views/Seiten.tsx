import { can, type PageSummary } from '../api';
import brand from '../generated/brand';
import BlocksView from '../blocks/BlocksView';
import { t } from '../i18n';
import RichTextView from '../richtext/RichTextView';
import { navigate } from '../router';
import { Caps, Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;
/** „<Seiten> verwalten“: Bezeichnung der Kompanie mit Caps, der Rest übersetzt. */
const MANAGE = t('{pages} verwalten').split('{pages}').concat('');

/** Hinweis für die Pflege: Für wen ist die Seite? */
export function AudienceBadge({ page }: { page: Pick<PageSummary, 'audience'> }) {
  return page.audience === 'members' ? <span className="badge badge-level-guest">{t('nur Mitglieder')}</span> : null;
}

/**
 * Eigene Seiten (Issue #287): Jede Seite ist ein eigener Punkt der Navigation (#/seiten/<id>, Titel in der Kopfzeile).
 * #/seiten ohne Nummer zeigt alle als Kacheln (z. B. für ältere Links). Was nicht für die Person gedacht ist, liefert der
 * Server gar nicht erst.
 */
export default function Seiten({ id }: { id: number | null }) {
  return id === null ? <PageTiles /> : <PageView id={id} />;
}

function PageTiles() {
  const { data, error, loading, reload } = useAsync(sources.pages.load, sources.pages.deps, sources.pages.key);
  if (loading && !data) return <Loading />;
  if (error || !data)
    return <ErrorBox message={error ?? t('Die {pages} konnten nicht geladen werden.', { pages: sec.pages })} onRetry={reload} />;
  const manager = can('manage_pages');
  return (
    <div>
      {manager && (
        <button type="button" className="btn btn-secondary btn-block manage-events" onClick={() => navigate('verwaltung/seiten')}>
          {MANAGE[0]}
          <Caps text={sec.pages} />
          {MANAGE[1]}
        </button>
      )}
      {data.length === 0 ? (
        <Empty>{t('Noch keine {pages}.', { pages: sec.pages })}</Empty>
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
    </div>
  );
}

function PageView({ id }: { id: number }) {
  const { data, error, loading, reload } = useAsync(sources.page(id).load, sources.page(id).deps, sources.page(id).key);
  return (
    <div className="page-view">
      {loading && !data ? (
        <Loading shape="text" />
      ) : error || !data ? (
        <ErrorBox message={error ?? t('Die Seite konnte nicht geladen werden.')} onRetry={reload} />
      ) : (
        <article className="card page-article">
          {can('manage_pages') && (
            <p className="page-meta">
              <AudienceBadge page={data} />
              <button type="button" className="btn btn-small btn-secondary" onClick={() => navigate('verwaltung/seiten')}>
                {MANAGE[0]}
                <Caps text={sec.pages} />
                {MANAGE[1]}
              </button>
            </p>
          )}
          {data.blocks ? (
            data.blocks.length > 0 ? (
              <BlocksView blocks={data.blocks} manager={can('manage_pages')} />
            ) : (
              <p className="muted">{t('Diese Seite hat noch keinen Inhalt.')}</p>
            )
          ) : data.body ? (
            <RichTextView html={data.body} />
          ) : (
            <p className="muted">{t('Diese Seite hat noch keinen Text.')}</p>
          )}
        </article>
      )}
    </div>
  );
}
