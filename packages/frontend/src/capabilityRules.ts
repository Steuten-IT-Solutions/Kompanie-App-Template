// Regeln der Rechte ohne Texte (testbar ohne Branding): was eines voraussetzt, was Gast und Mitglied haben können,
// Haken einzeln und je Gruppe. Dieselben Regeln wie Capability::requires und RoleService im Kern.
import type { Capability } from './api';

/** Rechte zum Sehen: bei Gast und Mitglied änderbar (wie Capability::isView im Kern). */
const VIEW: Capability[] = [
  'calendar',
  'view_intern',
  'view_events',
  'view_news',
  'view_gallery',
  'view_gallery_internal',
  'view_own_points',
  'view_own_penalties',
  'view_own_rank',
  'view_own_attendance',
  'view_birthdays',
  'vote_polls',
  'view_directory',
  'view_offices',
  'view_former',
  'view_documents',
  'view_board',
  'view_ranks',
  'view_catalog',
];

export const isView = (c: Capability): boolean => VIEW.includes(c);

/** Eigene Daten in „Meins“: nur für Mitglieder. */
const OWN_DATA: Capability[] = ['view_own_points', 'view_own_penalties', 'view_own_rank', 'view_own_attendance'];

/**
 * Recht, das dieses voraussetzt (wie Capability::requires im Kern): eigene Daten und alles zum Verwalten nur mit
 * „Gehört zur Kompanie“ (member).
 */
export function requires(c: Capability): Capability | null {
  if (OWN_DATA.includes(c)) return 'member';
  return isView(c) || c === 'member' ? null : 'member';
}

/** Was eine feste Rolle haben kann: Gast nur Rechte zum Sehen ohne Voraussetzung, Mitglied Rechte zum Sehen. */
export function allowedFor(role: string, c: Capability): boolean {
  if (role === 'guest') return isView(c) && requires(c) === null;
  if (role === 'member') return isView(c);
  return true;
}

/**
 * Ein Recht an- oder abhaken, mit den Voraussetzungen: Wer ein Recht anhakt, bekommt seine Voraussetzung dazu; ohne ein
 * Recht fallen die weg, die es voraussetzen.
 */
export function toggleCap(set: ReadonlySet<Capability>, c: Capability, on: boolean): Set<Capability> {
  const next = new Set(set);
  if (on) {
    next.add(c);
    const needed = requires(c);
    if (needed) next.add(needed);
  } else {
    next.delete(c);
    for (const other of [...next]) if (requires(other) === c) next.delete(other);
  }
  return next;
}

/** Mehrere Rechte auf einmal (Haken einer Gruppe). */
export function toggleMany(set: ReadonlySet<Capability>, keys: Capability[], on: boolean): Set<Capability> {
  return keys.reduce<Set<Capability>>((acc, c) => toggleCap(acc, c, on), new Set(set));
}

export type GroupState = 'all' | 'some' | 'none';

/** Zustand des Hakens einer Gruppe aus ihren Rechten. */
export function groupState(isOn: (c: Capability) => boolean, keys: Capability[]): GroupState {
  const on = keys.filter(isOn).length;
  return on === 0 ? 'none' : on === keys.length ? 'all' : 'some';
}

/**
 * Gesperrte Haken einer Rolle: Admin hat alle Rechte; bei Gast und Mitglied nur Rechte zum Sehen änderbar (allowedFor),
 * Mitglied gehört immer zur Kompanie.
 */
export function lockedFor(role: string, system: boolean, c: Capability): boolean {
  if (role === 'admin') return true;
  return system && (!allowedFor(role, c) || (role === 'member' && c === 'member'));
}

/** Haken einer Rolle setzen (wie toggleMany); was eine feste Rolle nicht haben kann, kommt nicht dazu. */
export function toggleForRole(
  role: string,
  system: boolean,
  set: ReadonlySet<Capability>,
  keys: Capability[],
  on: boolean,
): Set<Capability> {
  const next = toggleMany(set, keys, on);
  return system ? new Set([...next].filter((c) => allowedFor(role, c) || (role === 'member' && c === 'member'))) : next;
}

/** Reihenfolge der Rechte wie Roles::GRANTABLE im Kern (und die Gruppen in capabilities.ts). */
const ORDER: Capability[] = [
  'view_events',
  'view_news',
  'view_gallery',
  'view_intern',
  'member',
  'view_own_points',
  'view_own_penalties',
  'view_own_rank',
  'view_own_attendance',
  'calendar',
  'view_gallery_internal',
  'view_birthdays',
  'vote_polls',
  'view_directory',
  'view_offices',
  'view_former',
  'view_documents',
  'view_board',
  'view_ranks',
  'view_catalog',
  'manage_events',
  'manage_documents',
  'post_board',
  'manage_polls',
  'manage_lists',
  'send_mail',
  'manage_points',
  'manage_penalties',
  'manage_ranks',
  'manage_titles',
  'manage_attendance',
  'manage_offices',
  'manage_inventory',
  'create_users',
  'promote_users',
];

/** Rechte in der Reihenfolge der Gruppen (wie der Kern sie speichert). */
export const orderedCaps = (set: ReadonlySet<Capability>): Capability[] => ORDER.filter((c) => set.has(c));
