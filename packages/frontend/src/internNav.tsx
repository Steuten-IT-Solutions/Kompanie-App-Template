import { useEffect } from 'react';
import { AdminIcon } from './adminNav';
import { navigate, redirect } from './router';

/** Bereich von „Intern“: eigene Ansicht (#/<id>), die die Person sehen darf (App.tsx). */
export type InternTab = { id: string; label: string };

/** Startseite von „Intern“: je Bereich (Mitglieder, Dokumente) ein Knopf mit Symbol wie in der Verwaltung; gibt es nur
 * einen, öffnet er gleich. */
export function InternMenu({ tabs }: { tabs: InternTab[] }) {
  const only = tabs.length === 1 ? tabs[0].id : null;
  useEffect(() => {
    if (only) redirect(only);
  }, [only]);
  if (only) return null;
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

/** Zurück zur Startseite von „Intern“; nur, wenn es dort mehr als einen Bereich gibt. */
export function InternBack({ tabs }: { tabs: InternTab[] }) {
  if (tabs.length < 2) return null;
  return (
    <button type="button" className="btn btn-link back" onClick={() => navigate('intern')}>
      ‹ Intern
    </button>
  );
}
