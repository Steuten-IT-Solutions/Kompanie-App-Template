import { useEffect, useRef, useState } from 'react';
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

/** Mitgliederliste: für alle, die Punkte oder Strafen vergeben, Dienstgrade, Titel oder Anwesenheit pflegen, Mitglieder
 * anlegen oder Rollen vergeben. */
export function memberListAllowed(): boolean {
  return canPoints() || canPenalties() || canRanks() || canTitles() || canAttendance() || can('create_users') || can('promote_users');
}

type AdminTab = { id: string; label: string; on: boolean; route?: string };

/** Reiter der Verwaltung; Schwarzes Brett, Umfragen und Inventar sind eigene Seiten (route), stehen aber hier mit. */
export function adminTabs(): AdminTab[] {
  const points = canPoints();
  return (
    [
      { id: 'mitglieder', label: 'Mitglieder', on: memberListAllowed() },
      { id: 'punkte', label: `${sec.points} +`, on: points },
      { id: 'strafe', label: `${sec.penalties} +`, on: canPenalties() },
      { id: 'katalog', label: `${sec.points}katalog`, on: points },
      { id: 'dienstgrade', label: sec.ranks, on: canRanks() },
      { id: 'befoerderungen', label: 'Beförderungen', on: canRanks() && !!config?.features.points },
      { id: 'titel', label: sec.titles, on: canTitles() },
      { id: 'anwesenheit', label: sec.attendance, on: canAttendance() },
      { id: 'brett', label: sec.board, on: canPostBoard(), route: 'brett' },
      { id: 'umfragen', label: sec.polls, on: canManagePolls(), route: 'umfragen/verwalten' },
      { id: 'inventar', label: sec.inventory, on: !!config?.features.inventory && can('manage_inventory'), route: 'inventar' },
    ] as AdminTab[]
  ).filter((t) => t.on);
}

/**
 * Menü der Verwaltung (Burger-Menü), auch über Schwarzem Brett, Umfragen und Inventar: Der Knopf zeigt den aktuellen
 * Bereich, ein Tipp klappt die Liste aller Bereiche auf. Schließt bei Auswahl, Escape oder Tipp daneben.
 */
export function AdminNav({ active }: { active: string }) {
  const tabs = adminTabs();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLElement>(null);
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
  if (tabs.length < 2) return null;
  const current = tabs.find((t) => t.id === active) ?? tabs[0];
  return (
    <nav className="admin-menu" aria-label="Verwaltung" ref={ref}>
      <button
        type="button"
        className="admin-menu-toggle"
        aria-expanded={open}
        aria-controls="admin-menu-list"
        onClick={() => setOpen((o) => !o)}
      >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z" fill="currentColor" />
        </svg>
        <span className="admin-menu-current">{current.label}</span>
        <span className="admin-menu-chevron" aria-hidden="true">
          {open ? '▴' : '▾'}
        </span>
      </button>
      {open && (
        <ul id="admin-menu-list" className="card admin-menu-list">
          {tabs.map((t) => (
            <li key={t.id}>
              <button
                type="button"
                className={t.id === current.id ? 'active' : ''}
                aria-current={t.id === current.id ? 'page' : undefined}
                onClick={() => {
                  setOpen(false);
                  navigate(t.route ?? `verwaltung/${t.id}`);
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
