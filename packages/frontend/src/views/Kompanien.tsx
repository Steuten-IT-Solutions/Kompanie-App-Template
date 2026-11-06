import { can, config, type Company, type Office } from '../api';
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

/**
 * Kompanien (Issue #280): Liste mit Logo in der Reihenfolge der Verwaltung, jede Karte aufklappbar mit Beschreibung,
 * Gründungsjahr, Links, den laufenden Ämtern der verknüpften Gruppe und den Mitgliedern (soweit die Mitgliederliste
 * sichtbar ist). Aufgelöste stehen unten als „ehemalig“.
 */
export default function Kompanien() {
  const { data, error, loading, reload } = useAsync(sources.companies.load, sources.companies.deps, sources.companies.key);
  const officesShown = !!config?.features.offices && can('view_offices');
  const offices = useAsync(
    officesShown ? sources.offices.load : () => Promise.resolve(null),
    sources.offices.deps,
    officesShown ? sources.offices.key : undefined,
  );
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? `Die ${sec.companies} konnten nicht geladen werden.`} onRetry={reload} />;
  if (data.length === 0) return <Empty>Noch keine {sec.companies}.</Empty>;
  const ordered = [...data.filter((c) => c.active), ...data.filter((c) => !c.active)];
  return (
    <ul className="card-list company-list">
      {ordered.map((c) => {
        const groupOffices = c.office_group?.shown ? (offices.data?.offices ?? []).filter((o) => o.group_id === c.office_group?.id) : [];
        return (
          <li key={c.id} className={`card company-card${c.active ? '' : ' inactive'}`} data-focus-id={c.id}>
            <details>
              <summary className="company-summary">
                <span className="company-logo-box">
                  <CompanyLogo company={c} />
                </span>
                <span className="company-head">
                  <span className="row-title">
                    {c.name}
                    {!c.active && <span className="badge badge-level-guest">ehemalig</span>}
                  </span>
                  <span className="row-sub">
                    {[
                      c.short_name,
                      c.founded_year ? `gegründet ${c.founded_year}` : '',
                      c.member_count ? `${c.member_count} Mitglieder` : '',
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </span>
                </span>
              </summary>
              <div className="company-body">
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
                    <ul className="company-members">
                      {c.members.map((m) => (
                        <li key={m.id}>
                          <button type="button" className="link-button" onClick={() => navigate(`mitglieder/${m.id}`)}>
                            {m.name}
                          </button>
                        </li>
                      ))}
                    </ul>
                  </>
                )}
              </div>
            </details>
          </li>
        );
      })}
    </ul>
  );
}
