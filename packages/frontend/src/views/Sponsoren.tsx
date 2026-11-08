import { can, type Sponsor } from '../api';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;

/** Logo bzw. ohne Logo der Name; der Name ist zugleich der Alternativtext. */
export function SponsorLogo({ sponsor }: { sponsor: Sponsor }) {
  return sponsor.logo_url ? (
    <img className="sponsor-logo" src={sponsor.logo_url} alt={sponsor.name} loading="lazy" decoding="async" />
  ) : (
    <span className="sponsor-name">{sponsor.name}</span>
  );
}

/**
 * Sponsoren (Issue #279): Logos in der Reihenfolge der Verwaltung, für alle mit view_sponsors (auch Gäste). Mit Link
 * führt das Logo in einem neuen Tab zur Seite des Sponsors (rel="sponsored", wie für bezahlte Links empfohlen).
 */
export default function Sponsoren() {
  const { data, error, loading, reload } = useAsync(sources.sponsors.load, sources.sponsors.deps, sources.sponsors.key);
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Sponsoren konnten nicht geladen werden.'} onRetry={reload} />;
  return (
    <div>
      {can('manage_sponsors') && (
        <button type="button" className="btn btn-secondary btn-block manage-events" onClick={() => navigate('verwaltung/sponsoren')}>
          {sec.sponsors} verwalten
        </button>
      )}
      {data.length === 0 ? (
        <Empty>Noch keine {sec.sponsors}.</Empty>
      ) : (
        <ul className="sponsor-grid">
          {data.map((s) => (
            <li key={s.id} className="card sponsor-card">
              {s.url ? (
                <a href={s.url} target="_blank" rel="sponsored noopener noreferrer" title={s.name}>
                  <SponsorLogo sponsor={s} />
                </a>
              ) : (
                <SponsorLogo sponsor={s} />
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
