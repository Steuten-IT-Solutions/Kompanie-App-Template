import { useEffect } from 'react';
import { api, can, config, inBackground, isMember, type AttendanceEvent, type AttendanceSummary, type CalendarEvent } from './api';
import { prefetch } from './dataCache';
import { createPrefetcher, idleNetworkOk, type NetworkInfo } from './prefetch';
import { previewRole } from './preview';

/**
 * Daten der Ansichten an einer Stelle (Issue #104): Schlüssel und Ladefunktion wie in useAsync(load, deps, key). Die
 * Ansichten laden damit, und das Vorabladen (prefetch.ts) legt die Daten unter demselben Schlüssel ab; so können beide
 * nicht auseinanderlaufen. tabSources sagt, was eine Ansicht beim Öffnen braucht.
 */
export interface Source<T = unknown> {
  key: string;
  deps: unknown[];
  load: () => Promise<T>;
}

const source = <T>(key: string, load: () => Promise<T>, deps: unknown[] = []): Source<T> => ({ key, deps, load });

export type EventLike = CalendarEvent & { url?: string };

/** Mitglieder und Anwärter sehen den Kalender mit allen Terminen, Gäste die öffentliche Terminliste. */
async function loadEvents(): Promise<EventLike[]> {
  if (can('calendar') && config?.features.calendar) return api.calendar();
  const list = await api.events();
  return list.map((ev) => ({ ...ev, id: `p${ev.id}` }));
}

export const sources = {
  termine: source('termine', loadEvents),
  upcomingBirthdays: source('geburtstage-anstehend', () => api.birthdays(upcomingBirthdayDays())),
  news: (page: number) => source('news', () => api.news(page), [page]),
  gallery: source('galerie', api.gallery),
  album: (id: number) => source('galerie-album', () => api.galleryAlbum(id), [id]),
  documents: source('documents', api.documents),
  directory: source('directory', api.directory),
  directoryCounts: source('directory-counts', api.directoryCounts),
  directoryFormer: source('directory-former', api.directoryFormer),
  offices: source('offices', api.offices),
  ranks: source('ranks', () => api.ranks()),
  catalog: source('catalog', () => api.catalog()),
  me: source('me', api.me),
  myBirthday: source('me/birthday', api.myBirthday),
  myJoinYear: source('me/join-year', api.myJoinYear),
  poll: (id: number) => source('poll', () => api.poll(id), [id]),
  attendance: (id: number) => source('attendance', () => api.attendance(id), [id]),
  member: (id: number) => source('member', () => api.member(id), [id]),
  // Unterseiten von „Meins“
  myPoints: source('me/points', api.myPoints),
  myPenalties: source('me/penalties', api.myPenalties),
  myRank: source('me/rank', api.myRank),
  myAttendance: source('my-attendance', api.myAttendance),
  ownAccount: source('me/account', api.ownAccount),
  // Seiten der Verwaltung
  members: source('members', () => api.members(false, true), [false]),
  formerMembers: source('former-members', () => api.formerMembers()),
  membersAndCatalog: source('members-catalog', () => Promise.all([api.members(), api.catalog()])),
  catalogAll: source('catalog-all', () => api.catalog(true)),
  ranksAll: source('ranks-all', () => api.ranks(true)),
  promotions: source('promotions', api.promotions),
  titles: source('titles', api.titles),
  attendanceOverview: source('attendance-overview', loadAttendanceOverview),
  polls: source('polls', () => api.polls()),
  inventory: source('inventory', api.inventory),
  inventoryLocations: source('inventory-locations', api.inventoryLocations),
  lists: source('lists', api.lists),
  events: source('events', api.events),
  // Einstellungen (/admin/…: nur im Speicher der App, nie im Service Worker)
  settings: source('settings', () => api.settings()),
  features: source('features', () => api.features()),
  roles: source('admin/roles', () => api.roles(), [0]),
  status: source('admin/status', () => api.status()),
  maintenance: source('admin/maintenance', () => api.maintenance()),
};

/** Anwesenheit: Übersicht mit Terminen aus dem Kalender und den letzten Listen (Anwesenheit.tsx). */
async function loadAttendanceOverview(): Promise<[AttendanceEvent[] | string, AttendanceSummary[]]> {
  // Ohne erreichbaren Kalender bleiben freie Termine und die letzten Listen nutzbar.
  const events = config?.features.calendar ? api.attendanceEvents().catch((e: Error) => e.message) : Promise.resolve([]);
  return Promise.all([events, api.attendanceLists()]);
}

/**
 * Daten der Seiten hinter einem Menü (Issue #104, Fortsetzung): Sobald das Menü zu sehen ist, lädt die App sie im
 * Leerlauf vorab, beim Antippen sofort. Gemessen (0,8 s Serverzeit je Anfrage): Ohne das wartete jede dieser Seiten beim
 * ersten Öffnen eine ganze Serverantwort. Welche Einträge es gibt (Rechte, Bereiche), entscheidet das Menü selbst.
 * - meins/<Seite>: Punkte, Strafen, Dienstgrad, Anwesenheit, Konto
 * - verwaltung/<Bereich> (adminTabs in adminNav.tsx), dazu Umfragen, Inventar und Einstellungen
 * - einstellungen/<Seite>: Bereiche, Rollen, Zustand, Wartungsmodus und die Seiten aus settings
 */
export function menuSources(menu: 'meins' | 'verwaltung' | 'einstellungen', id: string): Source[] {
  const s = sources;
  const pages: Record<string, Record<string, Source[]>> = {
    meins: {
      punkte: [s.myPoints],
      strafen: [s.myPenalties],
      dienstgrad: [s.myRank],
      anwesenheit: [s.myAttendance],
      konto: [s.ownAccount],
    },
    verwaltung: {
      mitglieder: [s.members],
      ehemalige: [s.formerMembers],
      punkte: [s.membersAndCatalog],
      katalog: [s.catalogAll],
      dienstgrade: [s.ranksAll],
      befoerderungen: [s.promotions],
      titel: [s.titles],
      aemter: [s.offices],
      anwesenheit: [s.attendanceOverview],
      umfragen: [s.polls],
      inventar: [s.inventory, s.inventoryLocations],
      listen: [s.lists],
      einstellungen: [s.settings],
    },
    einstellungen: {
      bereiche: [s.features],
      rollen: [s.roles],
      zustand: [s.status],
      wartung: [s.maintenance],
      google: [s.settings],
      kalender: [s.settings],
      galerie: [s.settings],
      news: [s.settings],
    },
  };
  return pages[menu][id] ?? [];
}

/** Im Leerlauf vorab laden, was hinter den Einträgen eines Menüs steht (einmal je Anzeige des Menüs). */
export function usePrefetchMenu(menu: 'meins' | 'verwaltung' | 'einstellungen', ids: string[]): void {
  const list = ids.join(',');
  useEffect(() => {
    void prefetcher.idle(list.split(',').flatMap((id) => menuSources(menu, id)));
  }, [menu, list]);
}

/** Wer die Geburtstage anderer sieht: Bereich an und Recht view_birthdays (wie BirthdayService::canSee im Kern). */
export function birthdaysVisible(): boolean {
  return !!config?.features.birthdays && can('view_birthdays');
}

/** Anstehende Geburtstage: heute und die nächsten N Tage, wie ein Admin es einstellt (ältere Server: 7). */
export function upcomingBirthdayDays(): number {
  return config?.birthdays?.upcomingDays ?? 7;
}

/** Wer seinen eigenen Geburtstag einträgt: Bereich an, Mitglieder (wie BirthdayService::isMember im Kern). */
export function ownBirthday(): boolean {
  return !!config?.features.birthdays && can('member');
}

/** Termine aus Kalender oder öffentlicher Liste – oder (nur Geburtstage an) gar keine. */
export const hasEvents = (): boolean =>
  !!((config?.features.events && can('view_events')) || (config?.features.calendar && can('calendar')));

const SECTION_KEY = 'kompanie-members-section';

/** Zuletzt gewählter Abschnitt unter „Mitglieder“ (Liste, Ämter, Ehemalige). */
export function storedMembersSection(): string | null {
  try {
    return localStorage.getItem(SECTION_KEY);
  } catch {
    return null;
  }
}

export function storeMembersSection(section: string): void {
  try {
    localStorage.setItem(SECTION_KEY, section);
  } catch {
    // ohne Speicher: beim nächsten Öffnen wieder die Liste
  }
}

export type TabId =
  | 'termine'
  | 'news'
  | 'galerie'
  | 'dokumente'
  | 'mitglieder'
  | 'brett'
  | 'dienstgrade'
  | 'punktekatalog'
  | 'meins'
  | 'verwaltung';

/**
 * Was die Startseite eines Tabs beim Öffnen lädt, mit denselben Bedingungen wie die Ansicht. Ob die Person den Tab sehen
 * darf, prüft App.tsx (allowed). Die Verwaltung zeigt zuerst nur ihr Menü; ihre Daten (/admin/…) lädt die App nicht vorab.
 */
export function tabSources(tab: TabId): Source[] {
  const f = config?.features;
  switch (tab) {
    case 'termine':
      // wie Termine.tsx: Termine und die Karte „Anstehende Geburtstage“
      return [...(hasEvents() ? [sources.termine] : []), ...(birthdaysVisible() ? [sources.upcomingBirthdays] : [])];
    case 'news':
      return [sources.news(1)];
    case 'galerie':
      return [sources.gallery];
    case 'dokumente':
      return [sources.documents];
    case 'mitglieder': {
      // wie Mitglieder.tsx ohne Unterseite: der zuletzt gewählte Abschnitt, sonst die Liste
      const stored = storedMembersSection();
      const list = !!f?.directory && can('view_directory');
      const former = !!f?.directory && can('view_former');
      const offices = !!f?.offices && can('view_offices');
      if (offices && (stored === 'aemter' || (!list && !(former && stored === 'ehemalige')))) return [sources.offices];
      if (former && (stored === 'ehemalige' || !list)) return [sources.directoryFormer];
      return list ? [sources.directory, sources.directoryCounts] : [];
    }
    case 'brett':
      // Das Brett lädt board.ts beim Start und bei jeder Rückkehr in die App.
      return [];
    case 'dienstgrade':
      // wie Dienstgrade.tsx: die Liste und /me für den eigenen Dienstgrad
      return [sources.ranks, ...(config?.user ? [sources.me] : [])];
    case 'punktekatalog':
      return [sources.catalog];
    case 'meins': {
      // wie Meins.tsx: Mitglieder sehen zuerst die Übersicht, alle anderen Angemeldeten ihr Konto (lädt nichts vorab)
      if (!isMember()) return [];
      return [
        sources.me,
        ...(ownBirthday() ? [sources.myBirthday] : []),
        sources.myJoinYear,
        ...(f?.offices && config?.user ? [sources.offices] : []),
      ];
    }
    case 'verwaltung':
      return [];
  }
}

/**
 * Vorabladen (prefetch.ts, Issue #104): nicht in der Vorschau „App als Rolle ansehen“ (Issue #63) und nicht im
 * Wartungsmodus (Issue #42).
 */
export const prefetcher = createPrefetcher({
  blocked: () => !!previewRole() || !!config?.preview || !!config?.maintenance?.on,
  idleOk: () => idleNetworkOk(navigator as NetworkInfo),
  prefetch,
  inBackground,
});

/** Handler für einen Eintrag, der eine Ansicht öffnet: beim Antippen (bzw. Fokus per Tastatur) ihre Daten laden. */
export function prefetchOnTap(...list: Source[]) {
  const start = () => void prefetcher.tap(list);
  return { onPointerDown: start, onFocus: start };
}
