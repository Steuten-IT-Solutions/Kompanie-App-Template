import { AdminIcon } from './adminNav';
import { navigate } from './router';

/** Bereich eines Untermenüs: eigene Ansicht (#/<id>). */
export type InternTab = { id: string; label: string };

/** Startseite eines Untermenüs („Intern“ mit dem Recht view_intern oder eines aus Einstellungen → Navigation, Issue #277):
 * je Bereich, den die Person sieht, ein Knopf mit Symbol wie in der Verwaltung. */
export function InternMenu({ tabs, label = 'Intern' }: { tabs: InternTab[]; label?: string }) {
  return (
    <nav aria-label={label}>
      <ul className="admin-tiles">
        {tabs.map((t) => (
          <li key={t.id}>
            <button type="button" className="card admin-tile" onClick={() => navigate(t.id)}>
              <AdminIcon id={t.id} />
              <span>{t.label}</span>
            </button>
          </li>
        ))}
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
