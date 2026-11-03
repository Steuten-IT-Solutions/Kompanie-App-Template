// Kopf der Seite einer Person aus den Listen (src/memberPreview.ts), solange ihre eigenen Daten laden.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { FormerMember, MemberSummary } from '../src/api.ts';
import { memberPreview } from '../src/memberPreview.ts';

const member: MemberSummary = {
  id: 7,
  name: 'Erika Muster',
  level: 'member',
  extra_roles: ['board'],
  member: true,
  rank_title: 'Leutnant',
  rank_badge_url: '/abzeichen/3',
  photo_url: '/foto/7',
  points: 120,
  open_cents: 500,
};

const former: FormerMember = {
  id: 9,
  name: 'Max Früher',
  first_name: 'Max',
  last_name: 'Früher',
  photo_url: null,
  points: null,
  open_cents: null,
  reason: 'left',
  joined_on: '1990',
  founder: false,
  left_on: '2020',
  previous_level: 'member',
  personal_deleted: false,
};

test('Mitglied aus der Liste: Name, Abzeichen, Foto, Rollen, Punkte und Offen', () => {
  assert.deepEqual(memberPreview([[member]], 7), {
    id: 7,
    name: 'Erika Muster',
    rank_title: 'Leutnant',
    rank_badge_url: '/abzeichen/3',
    photo_url: '/foto/7',
    points: 120,
    open_cents: 500,
    previous_level: null,
    level: 'member',
    extra_roles: ['board'],
  });
});

test('Ehemalige: Rolle vor dem Ausscheiden, ohne Dienstgrad', () => {
  const p = memberPreview([null, [former]], 9);
  assert.equal(p?.previous_level, 'member');
  assert.equal(p?.level, null);
  assert.equal(p?.rank_title, null);
  assert.equal(p?.points, null);
});

test('Erste Liste mit der Person gewinnt; fehlende Listen werden übersprungen', () => {
  const other = { ...member, name: 'Andere Fassung' };
  assert.equal(memberPreview([undefined, [member], [other]], 7)?.name, 'Erika Muster');
});

test('Nicht in den Listen: null (die Seite zeigt das Gerüst)', () => {
  assert.equal(memberPreview([[member], [former]], 1), null);
  assert.equal(memberPreview([], 7), null);
});

test('Ältere Server ohne Zusatzrollen, Dienstgrad und Foto: leere Werte', () => {
  const { extra_roles: _e, rank_title: _r, rank_badge_url: _b, photo_url: _f, ...old } = member;
  const p = memberPreview([[old as MemberSummary]], 7);
  assert.deepEqual([p?.extra_roles, p?.rank_title, p?.rank_badge_url, p?.photo_url], [[], null, null, null]);
});
