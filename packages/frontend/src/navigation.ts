/**
 * Einrichtbare Navigation (Issue #277): baut die Punkte der Tab-Leiste aus der Einstellung des Admins oder, ohne sie,
 * die Standardnavigation wie bisher. Nur Darstellung: Jeder sieht nur Bereiche, die er sehen darf (visible); die Server
 * prüfen wie immer. „Verwaltung“ und „Meins“ hängt App.tsx fest hinten an.
 */

/** Bereiche, die in der Navigation stehen können (wie NavigationService::TARGETS im Kern), in der Standardreihenfolge. */
export const NAV_TARGETS = [
  'termine',
  'news',
  'galerie',
  'kompanien',
  'sponsoren',
  'mitglieder',
  'dokumente',
  'brett',
  'dienstgrade',
  'punktekatalog',
] as const;
/** Feste Bereiche der Navigation. */
export type FixedTarget = (typeof NAV_TARGETS)[number];
/** Eigene Seite (Issue #287): jede steht als eigener Punkt „seiten/<id>“ in der Navigation (wie NavigationService::PAGE_PREFIX). */
export type PageTarget = `seiten/${number}`;
export type NavTarget = FixedTarget | PageTarget;

export const pageTarget = (id: number): PageTarget => `seiten/${id}`;
export const isPageTarget = (t: string): t is PageTarget => /^seiten\/[1-9][0-9]*$/.test(t);
/** Symbol eines Bereichs (eigene Seiten haben alle dasselbe). */
export const targetIcon = (t: string): string => (isPageTarget(t) ? 'seiten' : t);

/** Symbole für Untermenüs und Bereiche (wie NavigationService::ICONS); ab „info“ neutrale ohne festen Bereich (Issue #362). */
export const NAV_ICONS = [
  'ordner',
  'termine',
  'news',
  'galerie',
  'kompanien',
  'sponsoren',
  'mitglieder',
  'dokumente',
  'brett',
  'dienstgrade',
  'punktekatalog',
  'seiten',
  'stern',
  'info',
  'karte',
  'musik',
  'pokal',
  'glocke',
  'fahne',
  'kamera',
  'haus',
  'buch',
  'uhr',
] as const;
export type NavIcon = (typeof NAV_ICONS)[number];

/**
 * Standardnavigation: höchstens so viele Hauptpunkte neben „Verwaltung“ und „Meins“, der Rest steht unter „Intern“. Eine
 * eigene Navigation hat keine Grenze (Issue #362): Die Tab-Leiste verkleinert Schrift und Symbole, je mehr unten stehen.
 */
export const NAV_MAX_MAIN = 5;

/** Eigener Name und eigenes Symbol (Einstellungen → Navigation, Issue #362) je Bereich, eigener Seite oder „intern“. */
export type NavNames = Record<string, { label?: string; icon?: string } | undefined>;
/** Startseite beim Öffnen der App, getrennt für Gäste und Angemeldete (Issue #362); fehlt = erster Punkt der Leiste. */
export type NavStart = { guest?: string; member?: string };

export type NavMenuSetting = { label: string; icon: string; items: string[] };
/** Gespeicherte Einstellung (/config navigation): Hauptpunkte und ausgeblendete Bereiche. */
export type NavigationSetting = { items: (string | NavMenuSetting)[]; hidden: string[] };

/** Bereiche, die in der Standardnavigation nur mit eingeschaltetem Bereich einen Hauptpunkt belegen (Issue #279, #280);
 * danach folgen die eigenen Seiten (Issue #287), jede als eigener Punkt. */
const OPTIONAL_MAIN: NavTarget[] = ['kompanien', 'sponsoren'];

/** Bereiche unter „Intern“ in der Standardnavigation, in dieser Reihenfolge. */
export const INTERN_TARGETS: NavTarget[] = ['mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog'];
/** Ohne das Recht „Intern“ stehen diese in der Standardnavigation unten als eigene Tabs. */
const OWN_TABS: NavTarget[] = ['mitglieder', 'dokumente'];

/**
 * Ein Punkt der Tab-Leiste: ein Bereich (children null) oder ein Untermenü mit den sichtbaren Bereichen darin.
 * key ist zugleich die Adresse (#/<key>): der Bereich, „intern“ oder „menue/<n>“ (n zählt die Untermenüs ab 1).
 */
export type NavEntry = { key: string; label: string; icon: string; children: NavTarget[] | null };

export interface NavContext {
  /** Darf die Person den Bereich sehen (Bereich an und Recht)? */
  visible: (target: NavTarget) => boolean;
  /** Standardnavigation: hat die Person das Recht „Intern“ (view_intern)? */
  intern: boolean;
  /** Bezeichnung eines Bereichs (aus der Konfiguration der Kompanie, bei eigenen Seiten ihr Titel). */
  label: (target: NavTarget) => string;
  /** Eigene Seiten in ihrer Reihenfolge (seiten/<id>), die es gibt; fehlt = keine. */
  pages?: PageTarget[];
  /** Eigene Symbole (Issue #362), je Bereich bzw. „intern“; fehlt = das feste Symbol. */
  icons?: Partial<Record<string, string>>;
  /** Name des Untermenüs „Intern“ der Standardnavigation (umbenennbar, Issue #362); fehlt = „Intern“. */
  internLabel?: string;
  /** Reihenfolge der Kacheln in „Intern“ der Standardnavigation (Einstellungen → Navigation); fehlt = Standard. */
  internOrder?: readonly string[];
}

/**
 * Liste in einer eingestellten Reihenfolge: Was in wanted steht, kommt in dieser Folge; was fehlt (z. B. mit einem Update
 * dazugekommen), bleibt direkt hinter seinem Vorgänger aus der Standardreihenfolge (ohne Vorgänger: ganz vorn).
 */
export function orderBy<T extends string>(list: readonly T[], wanted: readonly string[] | null | undefined): T[] {
  if (!wanted || wanted.length === 0) return [...list];
  const rank = new Map(wanted.map((t, i) => [t, i]));
  let last = -1;
  const keyed = list.map((t, i) => {
    const r = rank.get(t);
    if (r !== undefined) last = r;
    return { t, i, k: r ?? last + 0.5 };
  });
  return keyed.sort((a, b) => a.k - b.k || a.i - b.i).map((x) => x.t);
}

/**
 * Teilliste neu geordnet in die ganze Liste zurückschreiben: Die Einträge von part belegen nacheinander die Plätze, an
 * denen sie in full standen; alle anderen bleiben, wo sie sind. So ordnet man nur die sichtbaren Einträge.
 */
export function mergeOrder<T extends string>(full: readonly T[], part: readonly T[]): T[] {
  const inPart = new Set(part);
  let n = 0;
  return full.map((t) => (inPart.has(t) ? part[n++] : t));
}

const isTarget = (id: unknown): id is NavTarget =>
  typeof id === 'string' && ((NAV_TARGETS as readonly string[]).includes(id) || isPageTarget(id));

/** Punkte der Tab-Leiste (ohne „Verwaltung“ und „Meins“); ein Untermenü ohne sichtbaren Bereich entfällt. */
export function buildNav(setting: NavigationSetting | null | undefined, ctx: NavContext): NavEntry[] {
  const view = (t: NavTarget): NavEntry => ({ key: t, label: ctx.label(t), icon: ctx.icons?.[t] ?? targetIcon(t), children: null });
  const pages = ctx.pages ?? [];
  if (!setting) {
    // Standard: Mitglieder und Dokumente mit dem Recht „Intern“ dort gesammelt, sonst je ein eigener Tab.
    const main: NavEntry[] = (['termine', 'news', 'galerie', ...OPTIONAL_MAIN, ...pages] as NavTarget[]).filter(ctx.visible).map(view);
    const intern = INTERN_TARGETS.filter(ctx.visible);
    if (ctx.intern && intern.length > 0) {
      // Wie draftOf: Passen nicht alle unten hin, kommen die letzten vorne ins Untermenü.
      const overflow = main.splice(NAV_MAX_MAIN - 1).map((e) => e.key as NavTarget);
      main.push({
        key: 'intern',
        label: ctx.internLabel ?? 'Intern',
        icon: ctx.icons?.intern ?? 'intern',
        children: orderBy([...overflow, ...intern], ctx.internOrder),
      });
    } else main.push(...OWN_TABS.filter(ctx.visible).map(view));
    return main;
  }
  const listed = new Set<string>(setting.hidden);
  const out: NavEntry[] = [];
  let menus = 0;
  for (const item of setting.items) {
    if (typeof item === 'string') {
      listed.add(item);
      if (isTarget(item) && ctx.visible(item)) out.push(view(item));
      continue;
    }
    menus++;
    item.items.forEach((t) => listed.add(t));
    const children = item.items.filter(isTarget).filter(ctx.visible);
    if (children.length > 0) out.push({ key: `menue/${menus}`, label: item.label, icon: item.icon, children });
  }
  // Bereiche, die die Einstellung noch nicht kennt (z. B. mit einer neuen Version dazugekommen): hinten anhängen.
  for (const t of [...NAV_TARGETS, ...pages]) if (!listed.has(t) && ctx.visible(t)) out.push(view(t));
  return out;
}

/** Untermenü, in dem der Bereich steht (für „‹ Zurück“ und den aktiven Tab); undefined, wenn er selbst ein Tab ist. */
export function menuOf(entries: NavEntry[], target: string): NavEntry | undefined {
  return entries.find((e) => e.children?.includes(target as NavTarget));
}

/**
 * Entwurf für die Seite Einstellungen → Navigation: die gespeicherte Einstellung oder die Standardnavigation. Kompanien,
 * Sponsoren und eigene Seiten (je Seite ein Punkt) stehen nur darin, wenn ihr Bereich an ist (on), damit sie keinen der Hauptpunkte belegen; wird einer
 * später eingeschaltet, erscheint er hinten in der Leiste wie jeder Bereich, den die Einstellung nicht kennt. Passen
 * nicht alle unten hin, kommen die letzten vorne ins Untermenü „Intern“.
 */
export function draftOf(
  setting: NavigationSetting | null | undefined,
  on: (t: NavTarget) => boolean = () => true,
  pages: PageTarget[] = [],
  internLabel = 'Intern',
  internOrder: readonly string[] = [],
): NavigationSetting {
  if (setting)
    return { items: setting.items.map((i) => (typeof i === 'string' ? i : { ...i, items: [...i.items] })), hidden: [...setting.hidden] };
  const main: NavTarget[] = ['termine', 'news', 'galerie', ...OPTIONAL_MAIN.filter(on), ...pages.filter(on)];
  const overflow = main.splice(NAV_MAX_MAIN - 1);
  return {
    items: [...main, { label: internLabel, icon: 'ordner', items: orderBy([...overflow, ...INTERN_TARGETS], internOrder) }],
    hidden: [],
  };
}

/**
 * Bleibt es bei der Standardnavigation? Ja, wenn der Entwurf dem aus der Standardnavigation (initial, draftOf ohne
 * Einstellung) gleicht, bis auf die Reihenfolge im Untermenü „Intern“ (der letzte Punkt). Dann die neue Reihenfolge der
 * Bereiche darin, sonst null (der Entwurf wird zur eigenen Navigation).
 */
export function internOrderOf(initial: NavigationSetting, draft: NavigationSetting): string[] | null {
  const last = initial.items.length - 1;
  const a = initial.items[last];
  const b = draft.items[last];
  if (draft.items.length !== initial.items.length || typeof a !== 'object' || typeof b !== 'object') return null;
  const same = (x: unknown, y: unknown) => JSON.stringify(x) === JSON.stringify(y);
  if (!same(initial.items.slice(0, last), draft.items.slice(0, last)) || !same(initial.hidden, draft.hidden)) return null;
  if (a.label !== b.label || a.icon !== b.icon || !same([...a.items].sort(), [...b.items].sort())) return null;
  return [...b.items];
}

/** Bereiche, die im Entwurf nirgends stehen (neu dazugekommen): Sie erscheinen hinten in der Leiste. */
export function unplaced(draft: NavigationSetting, pages: PageTarget[] = []): NavTarget[] {
  const used = new Set<string>([...draft.hidden, ...draft.items.flatMap((i) => (typeof i === 'string' ? [i] : i.items))]);
  return [...NAV_TARGETS, ...pages].filter((t) => !used.has(t));
}

/** Platz eines Bereichs im Entwurf: 'main' (unten in der Leiste), Nummer des Untermenüs (ab 0) oder 'hidden'. */
export type NavPlace = 'main' | 'hidden' | number;

export function placeOf(draft: NavigationSetting, target: NavTarget): NavPlace {
  if (draft.hidden.includes(target)) return 'hidden';
  let menu = -1;
  for (const item of draft.items) {
    if (typeof item === 'string') {
      if (item === target) return 'main';
    } else {
      menu++;
      if (item.items.includes(target)) return menu;
    }
  }
  return 'main';
}

/** Bereich an einen anderen Platz verschieben (ans Ende der Leiste, des Untermenüs bzw. der ausgeblendeten). */
export function moveTo(draft: NavigationSetting, target: NavTarget, place: NavPlace): NavigationSetting {
  const items = draft.items
    .filter((i) => i !== target)
    .map((i) => (typeof i === 'string' ? i : { ...i, items: i.items.filter((t) => t !== target) }));
  const hidden = draft.hidden.filter((t) => t !== target);
  if (place === 'hidden') return { items, hidden: [...hidden, target] };
  if (place === 'main') return { items: [...items, target], hidden };
  let menu = -1;
  return {
    items: items.map((i) => {
      if (typeof i === 'string') return i;
      menu++;
      return menu === place ? { ...i, items: [...i.items, target] } : i;
    }),
    hidden,
  };
}

/** Untermenü entfernen; seine Bereiche kommen zu den ausgeblendeten (der Admin verteilt sie dann neu). */
export function removeMenu(draft: NavigationSetting, index: number): NavigationSetting {
  let menu = -1;
  const removed: string[] = [];
  const items = draft.items.filter((i) => {
    if (typeof i === 'string') return true;
    menu++;
    if (menu !== index) return true;
    removed.push(...i.items);
    return false;
  });
  return { items, hidden: [...draft.hidden, ...removed] };
}

/** Prüfung vor dem Speichern (wie der Server); null = in Ordnung. */
export function draftError(draft: NavigationSetting, labelMax = 24): string | null {
  for (const i of draft.items) {
    if (typeof i === 'string') continue;
    if (!i.label.trim()) return 'Jedes Untermenü braucht einen Namen.';
    if (i.label.trim().length > labelMax) return `Der Name eines Untermenüs darf höchstens ${labelMax} Zeichen lang sein.`;
    if (i.items.length === 0) return `Das Untermenü „${i.label.trim()}“ braucht mindestens einen Bereich.`;
  }
  return null;
}

/** Punkte, die als Startseite gehen (Issue #362): was in der Leiste steht, für Angemeldete auch „Meins“ (wie der Server). */
export function startChoices(draft: NavigationSetting | null, member: boolean, pages: PageTarget[] = []): string[] {
  const out: string[] = [];
  if (!draft) {
    out.push('termine', 'news', 'galerie', 'kompanien', 'sponsoren', ...pages, 'intern', ...INTERN_TARGETS);
  } else {
    let menus = 0;
    for (const item of draft.items) {
      if (typeof item === 'string') out.push(item);
      else {
        menus++;
        out.push(`menue/${menus}`, ...item.items);
      }
    }
    out.push(...unplaced(draft, pages));
  }
  if (member) out.push('meins');
  return out;
}

/**
 * Startseite der Person (Issue #362): die eingestellte, wenn sie in ihrer Leiste steht (als Tab oder in einem Untermenü),
 * sonst der erste Punkt. „intern“ und „menue/<n>“ stehen für ein Untermenü.
 */
export function startOf(tabs: NavEntry[], wanted: string | undefined): string | undefined {
  if (wanted && tabs.some((t) => t.key === wanted || t.children?.includes(wanted as NavTarget))) return wanted;
  return tabs[0]?.key;
}

/**
 * Pfad der Ansicht, die tatsächlich gezeigt wird: der aufgerufene, wenn er gilt, sonst der der Startseite. Ohne das
 * öffnete eine eigene Seite als Startseite (seiten/<id>) die Übersicht aller Seiten statt der Seite.
 */
export function viewPath(path: string[], valid: boolean, start: string): string[] {
  return valid ? path : start.split('/');
}

/** Klassen der Tab-Leiste (Issue #362): Stufen nach Zahl der Tabs (styles.css) und ob unten Namen stehen. */
export function tabbarClass(count: number, labels: boolean): string {
  const step = count >= 12 ? ' tabs-8 tabs-10 tabs-12' : count >= 10 ? ' tabs-8 tabs-10' : count >= 8 ? ' tabs-8' : '';
  return `tabbar${step}${labels ? '' : ' tabbar-icons'}`;
}

/*
 * Reihenfolge der Kacheln in der Verwaltung (Einstellungen → Navigation, Abschnitt „Verwaltung“): je Gruppe, Startseite
 * und „Einrichtungen“, für alle gleich. Eine Kachel wechselt dabei nicht die Gruppe. Nur Darstellung: Wer welche Kachel
 * sieht, entscheiden weiter Bereiche und Rechte (adminNav.tsx).
 */

/** Sammelknopf „Einrichtungen“ auf der Startseite der Verwaltung. */
export const SETUP = 'einrichtungen';

/** Kacheln der Startseite in der Standardreihenfolge (wie NavigationService::ADMIN_START im Kern). */
export const ADMIN_START = [
  'mitglieder',
  'ehemalige',
  'anfragen',
  'meldungen',
  'aenderungen',
  'punkte',
  'strafe',
  'befoerderungen',
  'anwesenheit',
  'brett',
  'umfragen',
  'inventar',
  'listen',
  'mail',
  SETUP,
  'einstellungen',
] as const;

/** Kacheln unter „Einrichtungen“ in der Standardreihenfolge (wie NavigationService::ADMIN_SETUP). */
export const ADMIN_SETUP = ['katalog', 'dienstgrade', 'titel', 'aemter', 'kompanien', 'sponsoren', 'seiten'] as const;

export type AdminTileId = (typeof ADMIN_START)[number] | (typeof ADMIN_SETUP)[number];

/** Gespeicherte Reihenfolge (/config admin_order); leer kann als [] kommen. */
export type AdminOrder = { start?: string[]; setup?: string[] };

const groups = (order: AdminOrder | unknown[] | null | undefined): AdminOrder => (order && !Array.isArray(order) ? order : {});

/** Kacheln der Startseite in der eingestellten Reihenfolge (mit „einrichtungen“). */
export function startOrder(order: AdminOrder | unknown[] | null | undefined): string[] {
  return orderBy(ADMIN_START, groups(order).start);
}

/** Kacheln unter „Einrichtungen“ in der eingestellten Reihenfolge. */
export function setupOrder(order: AdminOrder | unknown[] | null | undefined): string[] {
  return orderBy(ADMIN_SETUP, groups(order).setup);
}

/**
 * Alle Kacheln in einer Folge, die Einrichtungen an der Stelle ihres Sammelknopfs (für das Menü der Verwaltung und, wenn
 * es keinen Sammelknopf gibt, für die Startseite); null ohne Einstellung: dann bleibt die Folge im Code.
 */
export function flatOrder(order: AdminOrder | unknown[] | null | undefined): string[] | null {
  const g = groups(order);
  if (!g.start?.length && !g.setup?.length) return null;
  const setup = setupOrder(g);
  return startOrder(g).flatMap((id) => (id === SETUP ? setup : [id]));
}

/** Einträge nach einer Folge von Kennungen sortieren; was darin fehlt, bleibt vorn in seiner Folge. */
export function sortByIds<T extends { id: string }>(tabs: T[], ids: readonly string[] | null): T[] {
  if (!ids) return tabs;
  const at = (id: string) => ids.indexOf(id);
  return tabs
    .map((t, i) => ({ t, i }))
    .sort((a, b) => at(a.t.id) - at(b.t.id) || a.i - b.i)
    .map((x) => x.t);
}

/** Zu speichern: je Gruppe nur, wenn sie von der Standardreihenfolge abweicht. */
export function orderToSave(start: readonly string[], setup: readonly string[]): AdminOrder {
  const differs = (a: readonly string[], b: readonly string[]) => a.join() !== b.join();
  return {
    ...(differs(start, ADMIN_START) ? { start: [...start] } : {}),
    ...(differs(setup, ADMIN_SETUP) ? { setup: [...setup] } : {}),
  };
}
