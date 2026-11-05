/**
 * Einrichtbare Navigation (Issue #277): baut die Punkte der Tab-Leiste aus der Einstellung des Admins oder, ohne sie,
 * die Standardnavigation wie bisher. Nur Darstellung: Jeder sieht nur Bereiche, die er sehen darf (visible); die Server
 * prüfen wie immer. „Verwaltung“ und „Meins“ hängt App.tsx fest hinten an.
 */

/** Bereiche, die in der Navigation stehen können (wie NavigationService::TARGETS im Kern), in der Standardreihenfolge. */
export const NAV_TARGETS = ['termine', 'news', 'galerie', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog'] as const;
export type NavTarget = (typeof NAV_TARGETS)[number];

/** Symbole für Untermenüs (wie NavigationService::ICONS). */
export const NAV_ICONS = [
  'ordner',
  'termine',
  'news',
  'galerie',
  'mitglieder',
  'dokumente',
  'brett',
  'dienstgrade',
  'punktekatalog',
  'stern',
] as const;
export type NavIcon = (typeof NAV_ICONS)[number];

/** Höchstens so viele Hauptpunkte neben „Verwaltung“ und „Meins“ (wie NavigationService::MAX_MAIN). */
export const NAV_MAX_MAIN = 5;

export type NavMenuSetting = { label: string; icon: string; items: string[] };
/** Gespeicherte Einstellung (/config navigation): Hauptpunkte und ausgeblendete Bereiche. */
export type NavigationSetting = { items: (string | NavMenuSetting)[]; hidden: string[] };

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
  /** Bezeichnung eines Bereichs (aus der Konfiguration der Kompanie). */
  label: (target: NavTarget) => string;
}

const isTarget = (id: unknown): id is NavTarget => typeof id === 'string' && (NAV_TARGETS as readonly string[]).includes(id);

/** Punkte der Tab-Leiste (ohne „Verwaltung“ und „Meins“); ein Untermenü ohne sichtbaren Bereich entfällt. */
export function buildNav(setting: NavigationSetting | null | undefined, ctx: NavContext): NavEntry[] {
  const view = (t: NavTarget): NavEntry => ({ key: t, label: ctx.label(t), icon: t, children: null });
  if (!setting) {
    // Standard: Mitglieder und Dokumente mit dem Recht „Intern“ dort gesammelt, sonst je ein eigener Tab.
    const main: NavEntry[] = (['termine', 'news', 'galerie'] as NavTarget[]).filter(ctx.visible).map(view);
    const intern = INTERN_TARGETS.filter(ctx.visible);
    if (ctx.intern && intern.length > 0) main.push({ key: 'intern', label: 'Intern', icon: 'intern', children: intern });
    else main.push(...OWN_TABS.filter(ctx.visible).map(view));
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
  for (const t of NAV_TARGETS) if (!listed.has(t) && ctx.visible(t)) out.push(view(t));
  return out;
}

/** Untermenü, in dem der Bereich steht (für „‹ Zurück“ und den aktiven Tab); undefined, wenn er selbst ein Tab ist. */
export function menuOf(entries: NavEntry[], target: string): NavEntry | undefined {
  return entries.find((e) => e.children?.includes(target as NavTarget));
}

/** Entwurf für die Seite Einstellungen → Navigation: die gespeicherte Einstellung oder die Standardnavigation. */
export function draftOf(setting: NavigationSetting | null | undefined): NavigationSetting {
  if (setting)
    return { items: setting.items.map((i) => (typeof i === 'string' ? i : { ...i, items: [...i.items] })), hidden: [...setting.hidden] };
  return { items: ['termine', 'news', 'galerie', { label: 'Intern', icon: 'ordner', items: [...INTERN_TARGETS] }], hidden: [] };
}

/** Bereiche, die im Entwurf nirgends stehen (neu dazugekommen): Sie erscheinen hinten in der Leiste. */
export function unplaced(draft: NavigationSetting): NavTarget[] {
  const used = new Set<string>([...draft.hidden, ...draft.items.flatMap((i) => (typeof i === 'string' ? [i] : i.items))]);
  return NAV_TARGETS.filter((t) => !used.has(t));
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
  if (draft.items.length > NAV_MAX_MAIN) return `Unten passen höchstens ${NAV_MAX_MAIN} Hauptpunkte neben Verwaltung und Meins.`;
  for (const i of draft.items) {
    if (typeof i === 'string') continue;
    if (!i.label.trim()) return 'Jedes Untermenü braucht einen Namen.';
    if (i.label.trim().length > labelMax) return `Der Name eines Untermenüs darf höchstens ${labelMax} Zeichen lang sein.`;
    if (i.items.length === 0) return `Das Untermenü „${i.label.trim()}“ braucht mindestens einen Bereich.`;
  }
  return null;
}
