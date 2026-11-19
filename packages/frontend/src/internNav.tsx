import { AdminIcon } from './adminNav';
import { targetIcon } from './navigation';
import { navigate } from './router';
import { TileArrange, TileGrid } from './tiles';
import { Caps } from './ui';

/** Bereich eines Untermenüs: eigene Ansicht (#/<id>). */
export type InternTab = { id: string; label: string };

/** Startseite eines Untermenüs („Intern“ mit dem Recht view_intern oder eines aus Einstellungen → Navigation, Issue #277):
 * je Bereich, den die Person sieht, ein Knopf mit Symbol wie in der Verwaltung, in der persönlichen Anordnung (page:
 * intern oder menue/<n>). */
export function InternMenu({ tabs, label = 'Intern', page = 'intern' }: { tabs: InternTab[]; label?: string; page?: string }) {
  return (
    <nav aria-label={label}>
      <TileArrange pages={[page]}>
        <TileGrid
          page={page}
          className="admin-tiles"
          tiles={tabs.map((t) => ({
            id: t.id,
            label: t.label,
            node: (
              <button type="button" className="card admin-tile" onClick={() => navigate(t.id)}>
                <AdminIcon id={targetIcon(t.id)} />
                <span>
                  <Caps text={t.label} />
                </span>
              </button>
            ),
          }))}
        />
      </TileArrange>
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
