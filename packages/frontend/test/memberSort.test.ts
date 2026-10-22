// Sortierung der Mitgliederliste (src/memberSort.ts): nach Nachname, Vorname oder Punkten, auf- oder absteigend; die Wahl
// merkt sich das Gerät.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { MemberSummary } from '../src/api.ts';
import {
  defaultDir,
  dirLabel,
  listName,
  loadMemberOrder,
  saveMemberOrder,
  sortMembers,
  sortRowsByName,
  splitName,
} from '../src/memberSort.ts';

const m = (id: number, first: string, last: string, points: number | null = null): MemberSummary => ({
  id,
  name: `${first} ${last}`.trim(),
  first_name: first,
  last_name: last,
  level: 'member',
  member: true,
  points,
  open_cents: null,
});

const list = [m(1, 'Anna', 'Zimmer', 5), m(2, 'Ötzi', 'Becker', 20), m(3, 'Bernd', 'Becker', 20), m(4, 'Maria', 'von Berg', null)];
const names = (sorted: MemberSummary[]) => sorted.map((x) => x.name);

test('ohne getrennte Felder (älterer Server): letztes Wort ist der Nachname, ein Wort der Vorname', () => {
  assert.deepEqual(splitName('  Hans Peter  Schulz '), { first: 'Hans Peter', last: 'Schulz' });
  assert.deepEqual(splitName('Kassenwart'), { first: 'Kassenwart', last: '' });
  const old: MemberSummary = { id: 9, name: 'Hans Peter Schulz', level: 'member', member: true, points: null, open_cents: null };
  assert.equal(listName(old, 'last-name'), 'Schulz, Hans Peter');
});

test('nach Nachname: auf- und absteigend, bei Gleichstand nach Vorname', () => {
  assert.deepEqual(names(sortMembers(list, { sort: 'last-name', dir: 'asc' })), [
    'Bernd Becker',
    'Ötzi Becker',
    'Maria von Berg',
    'Anna Zimmer',
  ]);
  assert.deepEqual(names(sortMembers(list, { sort: 'last-name', dir: 'desc' })), [
    'Anna Zimmer',
    'Maria von Berg',
    'Ötzi Becker',
    'Bernd Becker',
  ]);
});

test('nach Vorname: Umlaute wie Grundbuchstaben', () => {
  assert.deepEqual(names(sortMembers(list, { sort: 'first-name', dir: 'asc' })), [
    'Anna Zimmer',
    'Bernd Becker',
    'Maria von Berg',
    'Ötzi Becker',
  ]);
  assert.deepEqual(names(sortMembers(list, { sort: 'first-name', dir: 'desc' })), [
    'Ötzi Becker',
    'Maria von Berg',
    'Bernd Becker',
    'Anna Zimmer',
  ]);
});

test('nach Punkten: Richtung umkehrbar, bei Gleichstand immer Nachname A → Z', () => {
  assert.deepEqual(names(sortMembers(list, { sort: 'points', dir: 'desc' })), [
    'Bernd Becker',
    'Ötzi Becker',
    'Anna Zimmer',
    'Maria von Berg',
  ]);
  assert.deepEqual(names(sortMembers(list, { sort: 'points', dir: 'asc' })), [
    'Maria von Berg',
    'Anna Zimmer',
    'Bernd Becker',
    'Ötzi Becker',
  ]);
});

test('ohne Nachnamen (Funktionskonto) zählt beim Nachnamen der Vorname', () => {
  const withRole = [...list, m(5, 'Kassenwart', '')];
  assert.deepEqual(names(sortMembers(withRole, { sort: 'last-name', dir: 'asc' })).slice(2, 4), ['Kassenwart', 'Maria von Berg']);
});

test('Anzeige: bei Nachname „Nachname, Vorname“, sonst der Anzeigename', () => {
  assert.equal(listName(list[3], 'last-name'), 'von Berg, Maria');
  assert.equal(listName(list[3], 'first-name'), 'Maria von Berg');
  assert.equal(listName(list[3], 'points'), 'Maria von Berg');
  assert.equal(listName(m(5, 'Kassenwart', ''), 'last-name'), 'Kassenwart');
});

test('Richtung: Namen beginnen A → Z, Punkte mit den meisten', () => {
  assert.equal(defaultDir('last-name'), 'asc');
  assert.equal(defaultDir('points'), 'desc');
  assert.equal(dirLabel('first-name', 'desc'), 'Z → A');
  assert.equal(dirLabel('points', 'asc'), 'Wenigste zuerst');
});

test('ändert die übergebene Liste nicht', () => {
  const copy = [...list];
  sortMembers(list, { sort: 'points', dir: 'desc' });
  assert.deepEqual(list, copy);
});

test('Wahl wird gemerkt, Unbekanntes fällt auf den Standard zurück', () => {
  const store = new Map<string, string>();
  Object.assign(globalThis, {
    localStorage: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) },
  });
  assert.deepEqual(loadMemberOrder(), { sort: 'last-name', dir: 'asc' });
  saveMemberOrder({ sort: 'points', dir: 'asc' });
  assert.deepEqual(loadMemberOrder(), { sort: 'points', dir: 'asc' });
  // Gemerkte Sortierung aus der Version ohne Richtung: übliche Richtung.
  store.delete('kompanie_member_sort_dir');
  assert.deepEqual(loadMemberOrder(), { sort: 'points', dir: 'desc' });
  store.set('kompanie_member_sort', 'quatsch');
  assert.deepEqual(loadMemberOrder(), { sort: 'last-name', dir: 'asc' });
});

test('ohne localStorage gilt der Standard', () => {
  const fail = () => {
    throw new Error('gesperrt');
  };
  Object.assign(globalThis, { localStorage: { getItem: fail, setItem: fail } });
  saveMemberOrder({ sort: 'first-name', dir: 'desc' });
  assert.deepEqual(loadMemberOrder(), { sort: 'last-name', dir: 'asc' });
});

type Row = { kind: 'member' | 'free'; name: string; first_name?: string; last_name?: string; deleted?: boolean };

test('Listen (Issue #121), nach Namen sortieren: Nachname, Vorname, Umlaute wie Grundbuchstaben; leere und gelöschte Zeilen ans Ende', () => {
  const member = (first: string, last: string): Row => ({
    kind: 'member',
    name: `${first} ${last}`.trim(),
    first_name: first,
    last_name: last,
  });
  const rows: Row[] = [
    { kind: 'free', name: '' },
    member('Ada', 'Zander'),
    { kind: 'member', name: '', deleted: true },
    { kind: 'free', name: 'Gast Nachbarverein' },
    member('Bernd', 'Özdemir'),
    member('Kasse', ''),
    member('Anna', 'Müller'),
    member('Zoe', 'Müller'),
  ];
  assert.deepEqual(
    sortRowsByName(rows).map((r) => (r.deleted ? '(gelöscht)' : r.name)),
    ['Kasse', 'Anna Müller', 'Zoe Müller', 'Gast Nachbarverein', 'Bernd Özdemir', 'Ada Zander', '', '(gelöscht)'],
  );
});
