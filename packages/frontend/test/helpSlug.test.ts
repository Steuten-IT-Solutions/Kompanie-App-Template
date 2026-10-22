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
function meta(slug: string): { rechte: string[]; einrecht: string[]; bereiche: string[] } {
  const head = readFileSync(new URL(`${slug}.md`, dir), 'utf8').split('---')[1] ?? '';
  const list = (key: string) =>
    (head.match(new RegExp(`^${key}:\\s*\\[(.*)\\]`, 'm'))?.[1] ?? '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean);
  return { rechte: list('rechte'), einrecht: list('einrecht'), bereiche: list('bereiche') };
}

/** Sieht die Person die Seite (wie visible() in Hilfe.tsx; Mitglieder sehen den internen Kalender)? */
function sees(slug: string, ctx: HelpContext): boolean {
  const { rechte, einrecht, bereiche } = meta(slug);
  const can = (c: string) => ctx.can(c) || (c === 'calendar' && ctx.can('member'));
  return rechte.every(can) && (einrecht.length === 0 || einrecht.some(can)) && bereiche.every((b) => !!ctx.features[b]);
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
  ['meins', 'konto'],
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
  ['verwaltung', 'anwesenheit', '3'],
  ['verwaltung', 'punkte', 'anwesenheit-3'],
  ['verwaltung', 'strafe', 'anwesenheit-3'],
  ['verwaltung', 'listen'],
  ['verwaltung', 'listen', '3'],
  ['verwaltung', 'neu'],
  ['verwaltung', 'neu-mehrere'],
  ['verwaltung', 'import'],
  ['verwaltung', 'ehemalige'],
  ['verwaltung', 'ehemalige', 'import'],
  ['verwaltung', 'ehemalige', 'neu'],
  ...['mitglied', 'ehemalige'].flatMap((base) =>
    ['uebersicht', 'konto', 'punkte', 'strafen', 'dienstgrad', 'titel', 'aemter', 'geburtstag', 'eintritt', 'austritt'].map((s) => [
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
  ['inventar', 'verlauf', '4'],
  ['konto'],
  ['anmelden'],
  ['benachrichtigungen'],
  ['installieren'],
  ['einrichtung'],
  ['einstellungen'],
  ...[
    'bereiche',
    'rollen',
    'google',
    'kalender',
    'galerie',
    'news',
    'benachrichtigungen',
    'zustand',
    'wartung',
    'datensicherung',
    'neuerungen',
  ].map((s) => ['einstellungen', s]),
  ['hilfe', 'melden'],
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
  // Konto unter „Meins“; ohne eigene Daten (z. B. Anwärter) ist es die einzige Unterseite.
  assert.equal(helpSlug(['meins', 'konto'], member), 'anmelden-und-konto');
  assert.equal(helpSlug(['meins'], withRights(['calendar'])), 'anmelden-und-konto');
});

test('Eine eigene Seite je Bildschirm', () => {
  const cases: [string[], string][] = [
    [['news', 'abc'], 'neuigkeiten-beitrag'],
    [['galerie', '3'], 'galerie-album'],
    [['dokumente', '5'], 'dokument-ansehen'],
    [['umfragen', '4'], 'umfrage-beantworten'],
    [['inventar', 'verlauf', '4'], 'inventar-verlauf'],
    [['anmelden'], 'anmelden'],
    [['hilfe', 'melden'], 'fehler-melden'],
    [['verwaltung'], 'verwaltung'],
    [['verwaltung', 'mitglieder'], 'mitgliederliste'],
    [['verwaltung', 'katalog'], 'punktekatalog'],
    [['verwaltung', 'befoerderungen'], 'befoerderungen'],
    [['verwaltung', 'neu-mehrere'], 'mitglieder-mehrere-anlegen'],
    [['verwaltung', 'import'], 'mitglieder-import'],
    [['verwaltung', 'anwesenheit', '3'], 'anwesenheitsliste'],
    [['verwaltung', 'listen', '3'], 'liste-bearbeiten'],
    [['verwaltung', 'punkte', 'anwesenheit-3'], 'punkte-vergeben'],
    [['verwaltung', 'strafe', 'anwesenheit-3'], 'strafen-verwalten'],
    [['verwaltung', 'mitglied', '7'], 'mitglied-uebersicht'],
    [['verwaltung', 'mitglied', '7', 'konto'], 'rollen-vergeben'],
    [['verwaltung', 'mitglied', '7', 'punkte'], 'mitglied-punkte'],
    [['verwaltung', 'ehemalige', '7', 'titel'], 'mitglied-titel'],
    [['verwaltung', 'ehemalige', '7'], 'ehemalige-mitglieder'],
    [['verwaltung', 'ehemalige', 'neu'], 'ehemalige-anlegen'],
    [['einstellungen', 'kalender'], 'admin-kalender'],
    [['einstellungen', 'neuerungen'], 'admin-neuerungen'],
  ];
  for (const [p, expected] of cases) assert.equal(helpSlug(p, admin), expected, p.join('/'));
});

test('Mitgliederliste und Seite eines Mitglieds: für jede Aufgabe, die sie öffnet, sichtbar', () => {
  const cases: [string, string][] = [
    ['create_users', ''],
    ['promote_users', ''],
    ['manage_points', 'points'],
    ['manage_penalties', 'penalties'],
    ['manage_ranks', 'ranks'],
    ['manage_titles', 'titles'],
    ['manage_attendance', 'attendance'],
  ];
  for (const [right, feature] of cases) {
    const ctx = withRights(['member', right], feature ? [feature] : []);
    for (const p of [
      ['verwaltung', 'mitglieder'],
      ['verwaltung', 'mitglied', '7'],
      ['verwaltung', 'mitglied', '7', 'konto'],
    ]) {
      const slug = helpSlug(p, ctx);
      assert.ok(slug && sees(slug, ctx), `${right} ${p.join('/')} → ${slug} nicht sichtbar`);
    }
  }
  // Geburtstag eines Mitglieds: Pflegeseite nur mit dem Recht dazu, sonst die Seite des Mitglieds
  assert.equal(helpSlug(['verwaltung', 'mitglied', '7', 'geburtstag'], withRights(['create_users'])), 'geburtstage-pflegen');
  assert.equal(
    helpSlug(['verwaltung', 'mitglied', '7', 'geburtstag'], withRights(['manage_points'], ['points', 'birthdays'])),
    'mitglied-uebersicht',
  );
  assert.equal(helpSlug(['verwaltung', 'ehemalige'], withRights(['create_users'])), 'ehemalige-mitglieder');
  assert.equal(helpSlug(['verwaltung', 'ehemalige', 'import'], withRights(['create_users'])), 'ehemalige-mitglieder');
});

test('Schwarzes Brett: lesen oder schreiben', () => {
  assert.equal(helpSlug(['brett'], withRights(['member'])), 'schwarzes-brett');
  assert.equal(helpSlug(['brett'], { ...withRights(['post_board']), boardWriter: true }), 'schwarzes-brett-schreiben');
});

test('Ohne passende Seite das Verzeichnis', () => {
  assert.equal(helpSlug(['hilfe'], admin), null);
  assert.equal(helpSlug(['hilfe', 'punkte-vergeben'], admin), null);
  assert.equal(helpSlug(['unbekannt'], admin), null);
});
