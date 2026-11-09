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

/** Anzahl der Einträge einer Version (Punkte der Listen im HTML aus dem Changelog). */
function eintraege(html: string): number {
  return (html.match(/<li>/g) ?? []).length;
}

/**
 * „Was ist neu“ für Admins (Einstellungen): das Changelog aus CHANGELOG.md (scripts/changelog.php), mit der App gebaut,
 * also passend zur installierten Version und offline verfügbar. Das HTML ist beim Bauen maskiert. Aufgeklappt ist nur
 * die Vorabversion bzw. die installierte (sonst die neueste) Version, ältere stehen eingeklappt mit Datum und Anzahl
 * der Änderungen darunter (Issue #352). Wege wie „Einstellungen → Rollen“ sind Verweise auf die Seite der App
 * (app:einstellungen/rollen im Changelog, hier #/einstellungen/rollen).
 */
export default function Neuerungen() {
  const versionen = changelog.versionen as Version[];
  const vorab = changelog.unveroeffentlicht as string | null;
  const installiert = coreVersion();
  if (versionen.length === 0 && !vorab) return <Empty>Noch keine Einträge.</Empty>;
  const offen = vorab ? null : (versionen.find((v) => v.version === installiert) ?? versionen[0])?.version;
  return (
    <>
      {vorab && (
        <section className="card settings-section changelog-version" aria-labelledby="neu-vorab">
          <h3 id="neu-vorab">
            Vorabversion <span className="badge badge-role">noch nicht für alle freigegeben</span>
          </h3>
          <div className="hilfe-text changelog-text" dangerouslySetInnerHTML={{ __html: vorab }} />
        </section>
      )}
      {versionen.map((v) => {
        const n = eintraege(v.html);
        return (
          <details key={v.version} className="card settings-section changelog-version" open={v.version === offen}>
            <summary>
              <span className="changelog-title">
                Version {v.version} {v.version === installiert && !vorab && <span className="badge badge-role">installiert</span>}
              </span>
              <span className="small muted">
                {datum(v.datum)}
                {n > 0 && ` · ${n} ${n === 1 ? 'Änderung' : 'Änderungen'}`}
              </span>
            </summary>
            <div className="hilfe-text changelog-text" dangerouslySetInnerHTML={{ __html: v.html }} />
          </details>
        );
      })}
    </>
  );
}
