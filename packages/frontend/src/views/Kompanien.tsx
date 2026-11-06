import { useLayoutEffect, useMemo, useState } from 'react';
import { can, config, type Company, type Office } from '../api';
import {
  COMPANY_SORT_LABELS,
  companyDirLabel,
  companyOrderFor,
  defaultCompanyDir,
  loadCompanyOrder,
  matchesCompany,
  saveCompanyOrder,
  sortCompanies,
  type CompanyOrder,
  type CompanySort,
} from '../companySort';
import brand from '../generated/brand';
import RichTextView from '../richtext/RichTextView';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;

/** Logo bzw. ohne Logo der Kurzname (oder die Anfangsbuchstaben); der Name ist zugleich der Alternativtext. */
export function CompanyLogo({ company }: { company: Pick<Company, 'name' | 'short_name' | 'logo_url'> }) {
  return company.logo_url ? (
    <img className="company-logo" src={company.logo_url} alt={company.name} loading="lazy" decoding="async" />
  ) : (
    <span className="company-logo company-logo-empty" aria-hidden="true">
      {(company.short_name || company.name).slice(0, 4)}
    </span>
  );
}

/** Laufende Amtszeiten der verknüpften Gruppe der Ämter, z. B. Hauptmann und Fähnrich. */
function CurrentOffices({ offices }: { offices: Office[] }) {
  const rows = offices.flatMap((o) =>
    o.terms.filter((t) => t.current).map((t) => ({ id: t.id, office: o.holder_title || o.title, names: t.holders.map((h) => h.name) })),
  );
  if (rows.length === 0) return null;
  return (
    <dl className="company-offices">
      {rows.map((r) => (
        <div key={r.id}>
          <dt>{r.office}</dt>
          <dd>{r.names.join(', ')}</dd>
        </div>
      ))}
    </dl>
  );
}

/** Kurzname, Gründungsjahr und Zahl der Mitglieder in einer Zeile. */
function facts(c: Company): string {
  return [c.short_name, c.founded_year ? `gegründet ${c.founded_year}` : '', c.member_count ? `${c.member_count} Mitglieder` : '']
    .filter(Boolean)
    .join(' · ');
}

/**
 * Scrollposition der Liste beim Öffnen einer Kompanie: Zurück (im Browser, per Wischen oder mit „‹ Alle …“) führt wieder
 * dorthin statt an den Anfang (wie bei den Alben der Galerie).
 */
let listScroll: number | null = null;
/** Suche der Liste: bleibt beim Öffnen einer Kompanie und beim Zurückkehren stehen (bis zum Neuladen der App). */
let listQuery = '';

/**
 * Kompanien (Issue #280): Liste mit Logo, Suche und Sortierung (wie in der Mitgliederliste; Standard: Reihenfolge der
 * Verwaltung); Antippen öffnet die eigene Seite der Kompanie (#/kompanien/<id>). Aufgelöste stehen unten als „ehemalig“.
 */
export default function Kompanien({ id }: { id?: number }) {
  return id === undefined ? <CompanyList /> : <CompanyDetail id={id} />;
}

function CompanyList() {
  const { data, error, loading, reload } = useAsync(sources.companies.load, sources.companies.deps, sources.companies.key);
  const [query, setQueryState] = useState(listQuery);
  const setQuery = (q: string) => {
    listQuery = q;
    setQueryState(q);
  };
  const [stored, setStored] = useState<CompanyOrder>(loadCompanyOrder);
  // Nach Mitgliedern nur, wenn der Server die Zahlen schickt (wer die Mitgliederliste sieht).
  const sorts: CompanySort[] = (data ?? []).some((c) => c.member_count !== undefined)
    ? ['order', 'name', 'founded', 'members']
    : ['order', 'name', 'founded'];
  const order = companyOrderFor(stored, sorts);
  const changeOrder = (next: CompanyOrder) => {
    setStored(next);
    saveCompanyOrder(next);
  };
  const list = useMemo(
    () =>
      sortCompanies(
        (data ?? []).filter((c) => matchesCompany(c, query)),
        order,
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, query, order.sort, order.dir],
  );
  const ready = !!data;
  useLayoutEffect(() => {
    if (!ready || listScroll === null) return;
    window.scrollTo(0, listScroll);
    listScroll = null;
  }, [ready]);
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? `Die ${sec.companies} konnten nicht geladen werden.`} onRetry={reload} />;
  if (data.length === 0) return <Empty>Noch keine {sec.companies}.</Empty>;
  return (
    <div className="company-directory">
      {data.length > 1 && (
        <>
          <input
            className="search"
            type="search"
            placeholder="Suchen …"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label={`${sec.companies} suchen`}
          />
          <div className="field-row member-sort">
            {/* Kein umschließendes <label>: dessen Text enthielte sonst alle Optionen. */}
            <div className="field">
              <span id="companies-sort-label">Sortierung</span>
              <select
                aria-labelledby="companies-sort-label"
                value={order.sort}
                onChange={(e) => {
                  const sort = e.target.value as CompanySort;
                  changeOrder({ sort, dir: defaultCompanyDir(sort) });
                }}
              >
                {sorts.map((s) => (
                  <option key={s} value={s}>
                    {COMPANY_SORT_LABELS[s]}
                  </option>
                ))}
              </select>
            </div>
            <div className="field member-sort-dir">
              <span id="companies-sort-dir-label">Reihenfolge</span>
              <button
                type="button"
                className="btn btn-secondary"
                aria-labelledby="companies-sort-dir-label companies-sort-dir-value"
                title="Reihenfolge umkehren"
                onClick={() => changeOrder({ ...order, dir: order.dir === 'asc' ? 'desc' : 'asc' })}
              >
                <span id="companies-sort-dir-value">{companyDirLabel(order.sort, order.dir)}</span>
              </button>
            </div>
          </div>
        </>
      )}
      <ul className="card-list company-list">
        {list.map((c) => (
          <li key={c.id} className={`card company-card${c.active ? '' : ' inactive'}`} data-focus-id={c.id}>
            <button
              type="button"
              className="company-summary"
              onClick={() => {
                listScroll = window.scrollY;
                navigate(`kompanien/${c.id}`);
              }}
            >
              <span className="company-logo-box">
                <CompanyLogo company={c} />
              </span>
              <span className="company-head">
                <span className="row-title">
                  {c.name}
                  {!c.active && <span className="badge badge-level-guest">ehemalig</span>}
                </span>
                <span className="row-sub">{facts(c)}</span>
              </span>
              <span className="chevron" aria-hidden="true">
                ›
              </span>
            </button>
          </li>
        ))}
        {list.length === 0 && <Empty>Nichts gefunden.</Empty>}
      </ul>
    </div>
  );
}

/**
 * Seite einer Kompanie: Logo, Name, Beschreibung, laufende Ämter der verknüpften Gruppe, Links und die Mitglieder (die
 * schickt der Server nur, wenn der Betrachter die Mitgliederliste sieht, und nur mit Namen).
 */
function CompanyDetail({ id }: { id: number }) {
  const { data, error, loading, reload } = useAsync(sources.companies.load, sources.companies.deps, sources.companies.key);
  const officesShown = !!config?.features.offices && can('view_offices');
  const offices = useAsync(
    officesShown ? sources.offices.load : () => Promise.resolve(null),
    sources.offices.deps,
    officesShown ? sources.offices.key : undefined,
  );
  const back = (
    <button type="button" className="btn btn-link back" onClick={() => navigate('kompanien')}>
      ‹ Alle {sec.companies}
    </button>
  );
  if (loading && !data) return <Loading shape="detail" />;
  if (error || !data)
    return (
      <>
        {back}
        <ErrorBox message={error ?? `Die ${sec.companies} konnten nicht geladen werden.`} onRetry={reload} />
      </>
    );
  const c = data.find((x) => x.id === id);
  if (!c)
    return (
      <>
        {back}
        <Empty>Diesen Eintrag gibt es nicht (mehr).</Empty>
      </>
    );
  const groupOffices = c.office_group?.shown ? (offices.data?.offices ?? []).filter((o) => o.group_id === c.office_group?.id) : [];
  return (
    <div className={`company-page${c.active ? '' : ' inactive'}`}>
      {back}
      <header className="company-page-head">
        <span className="company-logo-box company-page-logo">
          <CompanyLogo company={c} />
        </span>
        <div className="company-head">
          <h2 className="member-name">
            {c.name}
            {!c.active && <span className="badge badge-level-guest">ehemalig</span>}
          </h2>
          {facts(c) && <p className="row-sub">{facts(c)}</p>}
        </div>
      </header>
      {c.description && <RichTextView html={c.description} />}
      <CurrentOffices offices={groupOffices} />
      {(c.website_url || c.app_url) && (
        <p className="company-links">
          {c.website_url && (
            <a className="btn btn-secondary btn-small" href={c.website_url} target="_blank" rel="noopener noreferrer">
              Webseite
            </a>
          )}
          {c.app_url && (
            <a className="btn btn-secondary btn-small" href={c.app_url} target="_blank" rel="noopener noreferrer">
              App der Kompanie
            </a>
          )}
        </p>
      )}
      {c.members && c.members.length > 0 && (
        <>
          <h3 className="section-title">Mitglieder</h3>
          <ul className="card-list company-members">
            {c.members.map((m) => (
              <li key={m.id} className="card row member-row-link">
                <div className="row-main">
                  {/* Die ganze Zeile öffnet die Seite der Person (wie in der Mitgliederliste). */}
                  <button type="button" className="row-title member-row-open" onClick={() => navigate(`mitglieder/${m.id}`)}>
                    {m.name}
                  </button>
                </div>
                <span className="chevron" aria-hidden="true">
                  ›
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
