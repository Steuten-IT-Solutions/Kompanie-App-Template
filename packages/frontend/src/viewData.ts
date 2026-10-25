import { api, can, config, inBackground, isMember, type CalendarEvent } from './api';
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
  upcomingBirthdays: source('geburtstage-anstehend', () => api.birthdays(7)),
  news: (page: number) => source('news', () => api.news(page), [page]),
  gallery: source('galerie', api.gallery),
  album: (id: number) => source('galerie-album', () => api.galleryAlbum(id), [id]),
  documents: source('documents', api.documents),
  directory: source('directory', api.directory),
  directoryCounts: source('directory-counts', api.directoryCounts),
  directoryFormer: source('directory-former', api.directoryFormer),
  offices: source('offices', api.offices),
  me: source('me', api.me),
  myBirthday: source('me/birthday', api.myBirthday),
  myJoinYear: source('me/join-year', api.myJoinYear),
  poll: (id: number) => source('poll', () => api.poll(id), [id]),
  attendance: (id: number) => source('attendance', () => api.attendance(id), [id]),
  member: (id: number) => source('member', () => api.member(id), [id]),
};

/** Wer Geburtstage sieht und seinen eintragen kann: Bereich an, Mitglieder (wie BirthdayService::isMember im Kern). */
export function birthdaysVisible(): boolean {
  return !!config?.features.birthdays && can('member');
}

/** Termine aus Kalender oder öffentlicher Liste – oder (nur Geburtstage an) gar keine. */
export const hasEvents = (): boolean => !!(config?.features.events || (config?.features.calendar && can('calendar')));

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

export type TabId = 'termine' | 'news' | 'galerie' | 'dokumente' | 'mitglieder' | 'meins' | 'verwaltung';

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
      if (!f?.directory || (f.offices && stored === 'aemter')) return f?.offices ? [sources.offices] : [];
      if (stored === 'ehemalige') return [sources.directoryFormer];
      return [sources.directory, sources.directoryCounts];
    }
    case 'meins': {
      // wie Meins.tsx: Mitglieder sehen zuerst die Übersicht, alle anderen Angemeldeten ihr Konto (lädt nichts vorab)
      if (!isMember()) return [];
      return [
        sources.me,
        ...(birthdaysVisible() ? [sources.myBirthday] : []),
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
