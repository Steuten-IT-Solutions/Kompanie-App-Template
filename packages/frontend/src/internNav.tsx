import { config } from './api';
import { AdminIcon } from './adminNav';
import { targetIcon } from './navigation';
import { navigate } from './router';
import { tileCount } from './tileCount';
import { Caps } from './ui';

/** Bereich eines Untermenüs: eigene Ansicht (#/<id>). */
export type InternTab = { id: string; label: string };

/** Startseite eines Untermenüs („Intern“ mit dem Recht view_intern oder eines aus Einstellungen → Navigation, Issue #277):
 * je Bereich, den die Person sieht, ein Knopf mit Symbol wie in der Verwaltung. */
export function InternMenu({ tabs, label = 'Intern' }: { tabs: InternTab[]; label?: string }) {
  return (
    <nav aria-label={label}>
      <ul className="admin-tiles">
        {tabs.map((t) => {
          const badge = tileCount(t.id, { responses: config?.awaitingResponses });
          return (
            <li key={t.id}>
              <button type="button" className="card admin-tile" title={badge?.title} onClick={() => navigate(t.id)}>
                <AdminIcon id={targetIcon(t.id)} />
                <span>
                  <Caps text={t.label} />
                </span>
                {badge && (
                  <span className="count-badge" aria-hidden="true">
                    {badge.text}
                  </span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/** Zurück zur Startseite des Untermenüs (to: „intern“ oder „menue/<n>“). */
export function MenuBack({ to, label }: { to: string; label: string }) {
  return (
    <button type="button" className="btn btn-link back" onClick={() => navigate(to)}>
      ‹ {label}
    </button>
  );
}
