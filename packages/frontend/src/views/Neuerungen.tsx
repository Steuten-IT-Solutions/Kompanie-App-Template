import { config } from '../api';
import changelog from '../generated/changelog.json';
import { Empty } from '../ui';

interface Version {
  version: string;
  /** JJJJ-MM-TT */
  datum: string;
  html: string;
}

/** Kern-Version (1.4.0) aus der Version der Installation (1.4.0.27). */
function coreVersion(): string | null {
  return /^\d+\.\d+\.\d+/.exec(config?.version ?? '')?.[0] ?? null;
}

function datum(d: string): string {
  return new Date(`${d}T12:00:00`).toLocaleDateString('de-DE', { dateStyle: 'long' });
}

/**
 * „Was ist neu“ für Admins (Einstellungen): das Changelog aus CHANGELOG.md (scripts/changelog.php), mit der App gebaut,
 * also passend zur installierten Version und offline verfügbar. Das HTML ist beim Bauen maskiert.
 */
export default function Neuerungen() {
  const versionen = changelog.versionen as Version[];
  const vorab = changelog.unveroeffentlicht as string | null;
  const installiert = coreVersion();
  if (versionen.length === 0 && !vorab) return <Empty>Noch keine Einträge.</Empty>;
  return (
    <>
      {vorab && (
        <section className="card settings-section" aria-labelledby="neu-vorab">
          <h3 id="neu-vorab">
            Vorabversion <span className="badge badge-role">noch nicht für alle freigegeben</span>
          </h3>
          <div className="hilfe-text changelog-text" dangerouslySetInnerHTML={{ __html: vorab }} />
        </section>
      )}
      {versionen.map((v) => (
        <section key={v.version} className="card settings-section" aria-labelledby={`neu-${v.version}`}>
          <h3 id={`neu-${v.version}`}>
            Version {v.version} {v.version === installiert && !vorab && <span className="badge badge-role">installiert</span>}
          </h3>
          <p className="small muted">{datum(v.datum)}</p>
          <div className="hilfe-text changelog-text" dangerouslySetInnerHTML={{ __html: v.html }} />
        </section>
      ))}
    </>
  );
}
