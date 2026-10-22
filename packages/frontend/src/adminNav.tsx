import { useEffect, useRef, useState, type ReactNode } from 'react';
import { can, config } from './api';
import { canManagePolls, canPostBoard } from './board';
import brand from './generated/brand';
import { navigate } from './router';

const sec = brand.labels.sections;
const canPoints = (): boolean => !!config?.features.points && can('manage_points');
const canPenalties = (): boolean => !!config?.features.penalties && can('manage_penalties');
const canRanks = (): boolean => !!config?.features.ranks && can('manage_ranks');
const canTitles = (): boolean => !!config?.features.titles && can('manage_titles');
const canAttendance = (): boolean => !!config?.features.attendance && can('manage_attendance');
const canOffices = (): boolean => !!config?.features.offices && can('manage_offices');
const canLists = (): boolean => !!config?.features.lists && can('manage_lists');

/** Mitgliederliste: für alle, die Punkte oder Strafen vergeben, Dienstgrade, Titel oder Anwesenheit pflegen, Mitglieder
 * anlegen oder Rollen vergeben. */
export function memberListAllowed(): boolean {
  return canPoints() || canPenalties() || canRanks() || canTitles() || canAttendance() || can('create_users') || can('promote_users');
}

type AdminTab = { id: string; label: string; on: boolean; route?: string };

/** Bereiche der Verwaltung (Knöpfe auf ihrer Startseite); Schwarzes Brett, Umfragen, Inventar und Einstellungen sind eigene Seiten (route), stehen aber hier mit. */
export function adminTabs(): AdminTab[] {
  const points = canPoints();
  return (
    [
      { id: 'mitglieder', label: 'Mitglieder', on: memberListAllowed() },
      { id: 'ehemalige', label: 'Ehemalige', on: can('create_users') },
      { id: 'punkte', label: `${sec.points} +`, on: points },
      { id: 'strafe', label: `${sec.penalties} +`, on: canPenalties() },
      { id: 'katalog', label: `${sec.points}katalog`, on: points },
      { id: 'dienstgrade', label: sec.ranks, on: canRanks() },
      { id: 'befoerderungen', label: 'Beförderungen', on: canRanks() && !!config?.features.points },
      { id: 'titel', label: sec.titles, on: canTitles() },
      { id: 'aemter', label: sec.offices, on: canOffices() },
      { id: 'anwesenheit', label: sec.attendance, on: canAttendance() },
      { id: 'listen', label: sec.lists, on: canLists() },
      { id: 'brett', label: sec.board, on: canPostBoard(), route: 'brett' },
      { id: 'umfragen', label: sec.polls, on: canManagePolls(), route: 'umfragen/verwalten' },
      { id: 'inventar', label: sec.inventory, on: !!config?.features.inventory && can('manage_inventory'), route: 'inventar' },
      { id: 'einstellungen', label: 'Einstellungen', on: can('manage_settings'), route: 'einstellungen' },
    ] as AdminTab[]
  ).filter((t) => t.on);
}

export type MenuItem = { id: string; label: string; onSelect: () => void };

/**
 * Aufklappmenü (Burger-Menü): Der Knopf zeigt den aktuellen Eintrag, ein Tipp klappt die Liste aller Einträge auf.
 * Schließt bei Auswahl, Escape oder Tipp daneben. Für „Meins“ und für die Seiten eines Mitglieds.
 */
export function MenuNav({
  label,
  items,
  active,
  className = '',
}: {
  label: string;
  items: MenuItem[];
  active: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLElement>(null);
  const listId = `menu-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  useEffect(() => {
    if (!open) return;
    const onDown = (e: PointerEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);
  if (items.length < 2) return null;
  const current = items.find((t) => t.id === active) ?? items[0];
  return (
    <nav className={`admin-menu ${className}`.trim()} aria-label={label} ref={ref}>
      <button type="button" className="admin-menu-toggle" aria-expanded={open} aria-controls={listId} onClick={() => setOpen((o) => !o)}>
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z" fill="currentColor" />
        </svg>
        <span className="admin-menu-current">{current.label}</span>
        <span className="admin-menu-chevron" aria-hidden="true">
          {open ? '▴' : '▾'}
        </span>
      </button>
      {open && (
        <ul id={listId} className="card admin-menu-list">
          {items.map((t) => (
            <li key={t.id}>
              <button
                type="button"
                className={t.id === current.id ? 'active' : ''}
                aria-current={t.id === current.id ? 'page' : undefined}
                onClick={() => {
                  setOpen(false);
                  t.onSelect();
                }}
              >
                {t.label}
              </button>
            </li>
          ))}
        </ul>
      )}
    </nav>
  );
}

/** Symbole der Verwaltung (24 × 24, Linien in Textfarbe). */
const ADMIN_ICONS: Record<string, ReactNode> = {
  mitglieder: (
    <>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6" />
      <circle cx="17" cy="9" r="2.5" />
      <path d="M16.5 14.1c2.9.3 5 2.4 5 5.4" />
    </>
  ),
  ehemalige: (
    <>
      <circle cx="12" cy="8" r="3.5" />
      <path d="M5.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6" />
      <path d="M18 3.5a3 3 0 0 1 0 4" />
    </>
  ),
  punkte: <path d="M12 3l2.6 5.6 6.1.6-4.6 4.1 1.3 6L12 16.3l-5.4 3 1.3-6-4.6-4.1 6.1-.6z" />,
  strafe: (
    <>
      <rect x="3" y="6" width="18" height="13" rx="2" />
      <path d="M3 10h18M16 14.5h2" />
    </>
  ),
  katalog: <path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01" />,
  dienstgrade: <path d="M5 11l7-5 7 5M5 17l7-5 7 5" />,
  befoerderungen: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 16.5v-9M8 11l4-4 4 4" />
    </>
  ),
  titel: (
    <>
      <circle cx="12" cy="15" r="5" />
      <path d="M8 3l2.5 7.5M16 3l-2.5 7.5M8 3h8" />
    </>
  ),
  aemter: (
    <>
      <rect x="3" y="7" width="18" height="13" rx="2" />
      <path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18" />
    </>
  ),
  anwesenheit: (
    <>
      <rect x="5" y="4" width="14" height="17" rx="2" />
      <path d="M9 4V3h6v1M9 13l2 2 4-4" />
    </>
  ),
  listen: (
    <>
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <path d="M8 8h8M8 12h8M8 16h5" />
    </>
  ),
  brett: (
    <>
      <rect x="3" y="4" width="18" height="15" rx="2" />
      <path d="M7 9h10M7 13h6M12 4V2" />
    </>
  ),
  umfragen: <path d="M5 20v-9M12 20V5M19 20v-6M3 20h18" />,
  inventar: <path d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5zM3 7.5l9 4.5 9-4.5M12 12v9" />,
  einstellungen: (
    <>
      <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12" />
      <circle cx="16" cy="6" r="2" />
      <circle cx="10" cy="12" r="2" />
      <circle cx="18" cy="18" r="2" />
    </>
  ),
};

function AdminIcon({ id }: { id: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      width="30"
      height="30"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {ADMIN_ICONS[id]}
    </svg>
  );
}

/** Startseite der Verwaltung: je Bereich ein Knopf mit Symbol. */
export function AdminMenu() {
  return (
    <nav aria-label="Verwaltung">
      <ul className="admin-tiles">
        {adminTabs().map((t) => (
          <li key={t.id}>
            <button type="button" className="card admin-tile" onClick={() => navigate(t.route ?? `verwaltung/${t.id}`)}>
              <AdminIcon id={t.id} />
              <span>{t.label}</span>
            </button>
          </li>
        ))}
      </ul>
    </nav>
  );
}

/** Zurück zur Startseite der Verwaltung; nur, wenn es dort mehr als einen Bereich gibt. */
export function AdminBack() {
  if (adminTabs().length < 2) return null;
  return (
    <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung')}>
      ‹ Verwaltung
    </button>
  );
}
