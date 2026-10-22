// Knopf „?“ (src/helpSlug.ts): jede Seite führt zu einer Hilfeseite, die es im Handbuch gibt.
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { helpSlug, type HelpContext } from '../src/helpSlug.ts';

const dir = new URL('../../../docs/handbuch/', import.meta.url);
const pages = new Set(readdirSync(dir).map((f) => f.replace(/\.md$/, '')));

const ALL_FEATURES = [
  'events',
  'calendar',
  'news',
  'gallery',
  'points',
  'penalties',
  'ranks',
  'titles',
  'attendance',
  'documents',
  'board',
  'polls',
  'birthdays',
  'inventory',
  'directory',
  'offices',
  'lists',
];
/** Admin mit allen Rechten und allen Bereichen. */
const admin: HelpContext = { boardWriter: true, can: () => true, features: Object.fromEntries(ALL_FEATURES.map((f) => [f, true])) };
const withRights = (rights: string[], features: string[] = ALL_FEATURES, extra: Partial<HelpContext> = {}): HelpContext => ({
  boardWriter: false,
  can: (c) => rights.includes(c),
  features: Object.fromEntries(features.map((f) => [f, true])),
  ...extra,
});

/** Rechte und Bereiche einer Handbuchseite aus dem Kopfbereich. */
function meta(slug: string): { rechte: string[]; bereiche: string[] } {
  const head = readFileSync(new URL(`${slug}.md`, dir), 'utf8').split('---')[1] ?? '';
  const list = (key: string) =>
    (head.match(new RegExp(`^${key}:\\s*\\[(.*)\\]`, 'm'))?.[1] ?? '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
  return { rechte: list('rechte'), bereiche: list('bereiche') };
}

/** Sieht die Person die Seite (wie visible() in Hilfe.tsx; Mitglieder sehen den internen Kalender)? */
function sees(slug: string, ctx: HelpContext): boolean {
  const { rechte, bereiche } = meta(slug);
  const can = (c: string) => ctx.can(c) || (c === 'calendar' && ctx.can('member'));
  return rechte.every(can) && bereiche.every((b) => !!ctx.features[b]);
}

const PATHS = [
  ['termine'],
  ['termine', 'verwalten'],
  ['termine', 'geburtstage'],
  ['news'],
  ['news', 'abc'],
  ['galerie'],
  ['galerie', '3'],
  ['dokumente'],
  ['dokumente', '5'],
  ['dokumente', 'verwalten'],
  ['mitglieder'],
  ['mitglieder', 'liste'],
  ['mitglieder', 'aemter'],
  ['mitglieder', 'aemter', '7'],
  ['meins'],
  ['meins', 'uebersicht'],
  ['meins', 'punkte'],
  ['meins', 'strafen'],
  ['meins', 'dienstgrad'],
  ['meins', 'anwesenheit'],
  ['meins', 'aemter'],
  ['verwaltung'],
  ['verwaltung', 'mitglieder'],
  ['verwaltung', 'punkte'],
  ['verwaltung', 'katalog'],
  ['verwaltung', 'strafe'],
  ['verwaltung', 'dienstgrade'],
  ['verwaltung', 'befoerderungen'],
  ['verwaltung', 'titel'],
  ['verwaltung', 'aemter'],
  ['verwaltung', 'aemter', 'import'],
  ['verwaltung', 'anwesenheit'],
  ['verwaltung', 'listen'],
  ['verwaltung', 'listen', '3'],
  ['verwaltung', 'neu'],
  ['verwaltung', 'neu-mehrere'],
  ['verwaltung', 'ehemalige'],
  ['verwaltung', 'ehemalige', 'import'],
  ...['mitglied', 'ehemalige'].flatMap((base) =>
    ['uebersicht', 'konto', 'punkte', 'strafen', 'dienstgrad', 'titel', 'aemter', 'geburtstag', 'eintritt'].map((s) => [
      'verwaltung',
      base,
      '7',
      s,
    ]),
  ),
  ['brett'],
  ['umfragen'],
  ['umfragen', '4'],
  ['umfragen', 'verwalten'],
  ['inventar'],
  ['inventar', 'verlauf'],
  ['konto'],
  ['anmelden'],
  ['benachrichtigungen'],
  ['installieren'],
  ['einrichtung'],
  ['einstellungen'],
  ...['bereiche', 'rollen', 'google', 'kalender', 'galerie', 'news', 'benachrichtigungen', 'zustand', 'wartung', 'datensicherung'].map(
    (s) => ['einstellungen', s],
  ),
];

test('Jede Seite führt Admins zu einer vorhandenen Hilfeseite, die sie sehen', () => {
  for (const p of PATHS) {
    const slug = helpSlug(p, admin);
    assert.ok(slug && pages.has(slug), `${p.join('/')} → ${slug}`);
    assert.ok(sees(slug, admin), `${p.join('/')} → ${slug} (nicht sichtbar)`);
  }
});

test('Termine: passend zu Kalender, öffentlichen Terminen und Geburtstagen', () => {
  assert.equal(helpSlug(['termine'], withRights([], ['events'])), 'termine-und-kalender');
  assert.equal(helpSlug(['termine'], withRights(['member'], ['events', 'calendar'])), 'termine-und-kalender');
  assert.equal(helpSlug(['termine'], withRights(['calendar'], ['events', 'calendar'])), 'kalender');
  assert.equal(helpSlug(['termine'], withRights(['member', 'calendar'], ['calendar'])), 'kalender');
  assert.equal(helpSlug(['termine'], withRights(['member'], ['birthdays'])), 'geburtstage');
});

test('Meins: ohne Unterseite die Übersicht, sonst die Seite zur Unterseite', () => {
  const member = withRights(['member'], ['titles']);
  assert.equal(helpSlug(['meins'], member), 'meins-uebersicht');
  assert.ok(sees('meins-uebersicht', member));
  assert.equal(helpSlug(['meins', 'strafen'], withRights(['member'])), 'meine-strafen');
});

test('Verwaltung: Seite zur eigenen Aufgabe, die die Person auch sehen darf', () => {
  const cases: [string[], string[], string][] = [
    [['create_users'], [], 'mitglieder-anlegen'],
    [['promote_users'], [], 'rollen-vergeben'],
    [['manage_points'], ['points'], 'punkte-vergeben'],
    [['manage_penalties'], ['penalties'], 'strafen-verwalten'],
    [['manage_ranks'], ['ranks'], 'dienstgrade-verwalten'],
    [['manage_titles'], ['titles'], 'titel-verwalten'],
    [['manage_attendance'], ['attendance'], 'anwesenheit-erfassen'],
    [['manage_offices'], ['offices'], 'aemter-verwalten'],
    [['manage_lists'], ['lists'], 'listen'],
  ];
  for (const [rights, features, expected] of cases) {
    const ctx = withRights(['member', ...rights], features);
    for (const p of [['verwaltung'], ['verwaltung', 'mitglieder'], ['verwaltung', 'mitglied', '7', 'uebersicht']]) {
      const slug = helpSlug(p, ctx);
      assert.equal(slug, expected, `${rights.join()} ${p.join('/')}`);
      assert.ok(sees(slug!, ctx), `${slug} für ${rights.join()} nicht sichtbar`);
    }
  }
  // Geburtstag eines Mitglieds: Pflegeseite nur mit dem Recht dazu
  assert.equal(helpSlug(['verwaltung', 'mitglied', '7', 'geburtstag'], withRights(['create_users'])), 'geburtstage-pflegen');
  assert.equal(helpSlug(['verwaltung', 'ehemalige'], withRights(['create_users'])), 'ehemalige-mitglieder');
  assert.equal(helpSlug(['verwaltung', 'ehemalige', 'import'], withRights(['create_users'])), 'ehemalige-mitglieder');
  assert.equal(
    helpSlug(['verwaltung', 'ehemalige', '7', 'punkte'], withRights(['create_users', 'manage_points'], ['points'])),
    'punkte-vergeben',
  );
  assert.equal(
    helpSlug(['verwaltung', 'mitglied', '7', 'geburtstag'], withRights(['manage_points'], ['points', 'birthdays'])),
    'punkte-vergeben',
  );
});

test('Schwarzes Brett: lesen oder schreiben', () => {
  assert.equal(helpSlug(['brett'], withRights(['member'])), 'schwarzes-brett');
  assert.equal(helpSlug(['brett'], { ...withRights(['post_board']), boardWriter: true }), 'schwarzes-brett-schreiben');
});

test('Ohne passende Seite das Verzeichnis', () => {
  assert.equal(helpSlug(['hilfe'], admin), null);
  assert.equal(helpSlug(['unbekannt'], admin), null);
});
