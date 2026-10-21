// Sortierung der Mitgliederliste (src/memberSort.ts): nach Nachname, Vorname oder Punkten; die Wahl merkt sich das Gerät.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { MemberSummary } from '../src/api.ts';
import { DEFAULT_MEMBER_SORT, loadMemberSort, saveMemberSort, sortMembers, splitName } from '../src/memberSort.ts';

const m = (id: number, name: string, points: number | null = null): MemberSummary => ({
  id,
  name,
  level: 'member',
  member: true,
  points,
  open_cents: null,
});

const list = [m(1, 'Anna Zimmer', 5), m(2, 'Ötzi Becker', 20), m(3, 'Bernd Becker', 20), m(4, 'Carl Müller', null)];
const names = (sorted: MemberSummary[]) => sorted.map((x) => x.name);

test('Nachname ist das letzte Wort', () => {
  assert.deepEqual(splitName('  Hans Peter  Schulz '), { first: 'Hans Peter', last: 'Schulz' });
  assert.deepEqual(splitName('Cher'), { first: '', last: 'Cher' });
});

test('nach Nachname, bei Gleichstand nach Vorname', () => {
  assert.deepEqual(names(sortMembers(list, 'last-name')), ['Bernd Becker', 'Ötzi Becker', 'Carl Müller', 'Anna Zimmer']);
});

test('nach Vorname, Umlaute wie Grundbuchstaben', () => {
  assert.deepEqual(names(sortMembers(list, 'first-name')), ['Anna Zimmer', 'Bernd Becker', 'Carl Müller', 'Ötzi Becker']);
});

test('nach Punkten, meiste zuerst, bei Gleichstand nach Nachname', () => {
  assert.deepEqual(names(sortMembers(list, 'points')), ['Bernd Becker', 'Ötzi Becker', 'Anna Zimmer', 'Carl Müller']);
});

test('ändert die übergebene Liste nicht', () => {
  const copy = [...list];
  sortMembers(list, 'points');
  assert.deepEqual(list, copy);
});

test('Wahl wird gemerkt, Unbekanntes fällt auf den Standard zurück', () => {
  const store = new Map<string, string>();
  Object.assign(globalThis, {
    localStorage: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) },
  });
  assert.equal(loadMemberSort(), DEFAULT_MEMBER_SORT);
  saveMemberSort('points');
  assert.equal(loadMemberSort(), 'points');
  store.set('kompanie_member_sort', 'quatsch');
  assert.equal(loadMemberSort(), DEFAULT_MEMBER_SORT);
});

test('ohne localStorage gilt der Standard', () => {
  Object.assign(globalThis, {
    localStorage: {
      getItem: () => {
        throw new Error('gesperrt');
      },
      setItem: () => {
        throw new Error('gesperrt');
      },
    },
  });
  saveMemberSort('first-name');
  assert.equal(loadMemberSort(), DEFAULT_MEMBER_SORT);
});
