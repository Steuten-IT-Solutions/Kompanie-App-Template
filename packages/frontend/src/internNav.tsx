import { AdminIcon } from './adminNav';
import { navigate } from './router';

/** Bereich von „Intern“: eigene Ansicht (#/<id>); „Intern“ gibt es mit dem Recht view_intern (App.tsx). */
export type InternTab = { id: string; label: string };

/** Startseite von „Intern“: je Bereich, den die Person sieht (Mitglieder, Dokumente), ein Knopf mit Symbol wie in der
 * Verwaltung. */
export function InternMenu({ tabs }: { tabs: InternTab[] }) {
  return (
    <nav aria-label="Intern">
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

/** Zurück zur Startseite von „Intern“. */
export function InternBack() {
  return (
    <button type="button" className="btn btn-link back" onClick={() => navigate('intern')}>
      ‹ Intern
    </button>
  );
}
