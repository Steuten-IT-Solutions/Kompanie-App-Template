import { useEffect, useState, type Dispatch, type SetStateAction } from 'react';

/** Einfaches Hash-Routing: #punkte, #spiess/mitglied/12 … */
export function currentPath(): string[] {
  return window.location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
}

export function navigate(path: string): void {
  window.location.hash = '/' + path;
}

/** Wie navigate, ersetzt aber den Eintrag im Verlauf (Weiterleitung): „Zurück“ führt nicht wieder hierher. */
export function redirect(path: string): void {
  init();
  if (normalize(path) !== currentPath().join('/')) replacing = true;
  window.location.replace('#/' + path);
}

/**
 * Knöpfe „‹ …“ auf einer Seite: Kam man von dort, geht es im Verlauf zurück; die Seite steht dann wieder so da, wie man
 * sie verlassen hat (Scrollposition, nachgeladene Einträge, Suche). Sonst (z. B. über einen geteilten Link oder eine
 * Benachrichtigung geöffnet) öffnet sie neu.
 */
export function goBack(path: string): void {
  init();
  if (current?.prev && current.prev.path === normalize(path)) window.history.back();
  else navigate(path);
}

export function useRoute(): string[] {
  const [path, setPath] = useState(currentPath);
  useEffect(() => {
    init();
    const onChange = () => {
      const next = currentPath();
      setPath((p) => (p.join('/') === next.join('/') ? p : next));
    };
    listeners.add(onChange);
    // Falls sich die Adresse vor dem Anmelden des Listeners geändert hat
    onChange();
    return () => {
      listeners.delete(onChange);
    };
  }, []);
  return path;
}

/**
 * Wie useState, aber je Eintrag im Verlauf gemerkt: Kehrt man (mit „Zurück“) zu diesem Eintrag zurück, steht der Wert
 * wieder so da, wie man die Seite verlassen hat (z. B. nachgeladene Beiträge, Suche, aufgeklappte Abschnitte). Auf
 * einer neu geöffneten Seite, auch über die Leiste unten, gilt der Anfangswert. Nur im Speicher; `name` je Seite eindeutig.
 */
export function useEntryState<T>(name: string, initial: T | (() => T)): [T, Dispatch<SetStateAction<T>>] {
  const [entry] = useState(() => {
    init();
    return current?.id ?? null;
  });
  const [value, setValue] = useState<T>(() => {
    const saved = entry === null ? undefined : states.get(entry);
    if (saved?.has(name)) return saved.get(name) as T;
    return typeof initial === 'function' ? (initial as () => T)() : initial;
  });
  useEffect(() => {
    if (entry === null) return;
    let saved = states.get(entry);
    if (!saved) {
      saved = new Map();
      states.set(entry, saved);
      trim(states);
    }
    saved.set(name, value);
  }, [entry, name, value]);
  return [value, setValue];
}

/**
 * Leiste unten: auf die Seite, die schon offen ist, getippt. Sie fängt wieder vorn an (oben, ohne gemerkten Stand);
 * die App baut die Ansicht dazu neu auf.
 */
export function startOver(): void {
  init();
  cancelRestore();
  if (current) {
    states.delete(current.id);
    scrolls.delete(current.id);
  }
  window.scrollTo(0, 0);
}

/**
 * Schlüssel einer Ansicht: Wechselt er, baut die App die Ansicht neu auf (und lädt ihre Daten neu). Die Seiten eines
 * Mitglieds (bzw. einer ehemaligen Person) und von „Meins“ teilen sich einen Schlüssel: Beim Umschalten bleiben Name, Menü
 * und geladene Daten stehen.
 */
export function viewKey(path: string[]): string {
  if (path[0] === 'verwaltung' && path[1] === 'mitglied' && path[2]) return path.slice(0, 3).join('/');
  if (path[0] === 'verwaltung' && path[1] === 'ehemalige' && /^\d+$/.test(path[2] ?? '')) return path.slice(0, 3).join('/');
  if (path[0] === 'meins') return 'meins';
  return path.join('/');
}

/* ------------------------------------------------------------------ */
/* Verlauf: Jeder Eintrag bekommt eine Kennung (in history.state). Dazu gemerkt werden seine Scrollposition (auch über
 * ein Neuladen hinweg, sessionStorage) und der Stand der Ansicht (useEntryState). Neue Einträge beginnen oben, beim
 * Zurück- und Vorblättern im Verlauf steht die Seite wieder an der Stelle, an der man sie verlassen hat. */

/** Ein Eintrag im Verlauf und der, von dem aus er geöffnet wurde (für goBack). */
export interface Entry {
  id: string;
  path: string;
  prev: { id: string; path: string } | null;
}

const STATE = 'kompanieEntry';
const SCROLL_KEY = 'kompanie-scroll-v1';
/** So viele Einträge merken (Scrollposition bzw. Stand der Ansicht); der älteste fällt zuerst heraus. */
const MAX_ENTRIES = 200;
/** So lange höchstens warten, bis die Seite (Daten, Programmteile) so lang ist, dass die alte Stelle wieder erreichbar ist. */
const RESTORE_MS = 4000;
/** Mindestens so lange die Stelle halten: Bis dahin kann noch die vorige Seite im DOM stehen. */
const RESTORE_MIN_MS = 150;

let initialized = false;
let current: Entry | null = null;
let replacing = false;
const scrolls = new Map<string, number>();
const states = new Map<string, Map<string, unknown>>();
const listeners = new Set<() => void>();
let cancelRestore = () => {};

/** Pfad ohne führende oder doppelte Schrägstriche, wie currentPath().join('/'). */
function normalize(path: string): string {
  return path.split('/').filter(Boolean).join('/');
}

function trim(map: Map<string, unknown>): void {
  while (map.size > MAX_ENTRIES) map.delete(map.keys().next().value!);
}

function newId(): string {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

function readEntry(): Entry | null {
  const e = (window.history.state as Record<string, unknown> | null)?.[STATE] as Entry | undefined;
  return e && typeof e.id === 'string' ? e : null;
}

function writeEntry(e: Entry): void {
  const state = window.history.state && typeof window.history.state === 'object' ? window.history.state : {};
  window.history.replaceState({ ...state, [STATE]: e }, '');
}

/**
 * Nächster Eintrag nach einem Wechsel der Adresse. Hat der Eintrag schon eine Kennung, blättert man im Verlauf (zurück
 * oder vor). Sonst ist er neu: geöffnet von der bisherigen Seite aus, oder (Weiterleitung, `replacing`) an ihrer Stelle.
 */
export function nextEntry(found: Entry | null, before: Entry | null, path: string, replacing: boolean, id: string): Entry {
  if (found) return { ...found, path };
  const prev = replacing ? (before?.prev ?? null) : before ? { id: before.id, path: before.path } : null;
  return { id, path, prev };
}

function loadScrolls(): void {
  try {
    const saved = JSON.parse(sessionStorage.getItem(SCROLL_KEY) ?? '[]') as [string, number][];
    if (Array.isArray(saved)) for (const [id, y] of saved) if (typeof id === 'string' && typeof y === 'number') scrolls.set(id, y);
  } catch {
    // ohne Speicher: nur bis zum Neuladen
  }
}

function persistScrolls(): void {
  try {
    sessionStorage.setItem(SCROLL_KEY, JSON.stringify([...scrolls]));
  } catch {
    // ohne Speicher: nur bis zum Neuladen
  }
}

function remember(): void {
  if (!current) return;
  scrolls.delete(current.id);
  scrolls.set(current.id, window.scrollY);
  trim(scrolls);
}

/**
 * Zur gemerkten Stelle scrollen, sobald die Seite lang genug ist und nichts mehr lädt (Daten, Programmteile). Tippt,
 * wischt oder scrollt man vorher selbst, bleibt es dabei.
 */
function restoreScroll(y: number): void {
  cancelRestore();
  const start = performance.now();
  let frame = 0;
  const events = ['wheel', 'touchstart', 'pointerdown', 'keydown'] as const;
  const stop = () => {
    cancelAnimationFrame(frame);
    events.forEach((ev) => window.removeEventListener(ev, stop, true));
    cancelRestore = () => {};
  };
  const step = () => {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    window.scrollTo(0, Math.max(0, Math.min(y, max)));
    const now = performance.now();
    const done = now - start >= RESTORE_MIN_MS && max >= y && !document.querySelector('main [data-loading]');
    if (done || now - start > RESTORE_MS) stop();
    else frame = requestAnimationFrame(step);
  };
  events.forEach((ev) => window.addEventListener(ev, stop, { capture: true, passive: true }));
  cancelRestore = stop;
  frame = requestAnimationFrame(step);
}

/** Adresse gewechselt (hashchange, popstate; bei einem Wechsel kommen oft beide). */
function sync(): void {
  const found = readEntry();
  if (found && current && found.id === current.id) return;
  // Die vorige Seite steht noch im DOM: ihre Stelle merken.
  remember();
  const path = currentPath().join('/');
  if (replacing && current) {
    scrolls.delete(current.id);
    states.delete(current.id);
  }
  current = nextEntry(found, current, path, replacing, newId());
  replacing = false;
  if (!found) writeEntry(current);
  persistScrolls();
  listeners.forEach((l) => l());
  const y = found ? scrolls.get(found.id) : undefined;
  if (y) restoreScroll(y);
  else {
    cancelRestore();
    window.scrollTo(0, 0);
  }
}

function init(): void {
  if (initialized || typeof window === 'undefined') return;
  initialized = true;
  try {
    // Die App stellt die Stelle selbst wieder her: Der Browser täte es, bevor die Seite neu aufgebaut ist.
    window.history.scrollRestoration = 'manual';
  } catch {
    // älterer Browser: dann wie bisher
  }
  loadScrolls();
  const found = readEntry();
  current = nextEntry(found, null, currentPath().join('/'), false, newId());
  if (!found) writeEntry(current);
  // Neu geladen: wieder an die Stelle von vorher.
  const y = found ? scrolls.get(found.id) : undefined;
  if (y) restoreScroll(y);
  window.addEventListener('hashchange', sync);
  window.addEventListener('popstate', sync);
  window.addEventListener('pagehide', () => {
    remember();
    persistScrolls();
  });
}
