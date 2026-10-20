import { useEffect, useRef } from 'react';
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

/** Reiterleiste der Verwaltung, auch über Schwarzem Brett, Umfragen und Inventar. Wischt seitlich, der aktive Reiter
 * bleibt sichtbar. */
export function AdminNav({ active }: { active: string }) {
  const tabs = adminTabs();
  const navRef = useRef<HTMLElement>(null);
  useEffect(() => {
    const nav = navRef.current;
    const button = nav?.querySelector<HTMLElement>('button.active');
    if (nav && button) nav.scrollLeft = Math.max(0, button.offsetLeft - (nav.clientWidth - button.offsetWidth) / 2);
  }, [active]);
  if (tabs.length < 2) return null;
  return (
    <nav className="segmented" aria-label="Verwaltung" ref={navRef}>
      {tabs.map((t) => (
        <button
          key={t.id}
          className={active === t.id ? 'active' : ''}
          onClick={() => navigate(t.route ?? `verwaltung/${t.id}`)}
          aria-current={active === t.id ? 'page' : undefined}
        >
          {t.label}
        </button>
      ))}
    </nav>
  );
}
