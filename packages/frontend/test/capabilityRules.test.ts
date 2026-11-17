// Regeln der Rechte (src/capabilityRules.ts): Voraussetzungen, Haken je Gruppe, feste Rollen – wie im Kern.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Capability } from '../src/api.ts';
import { groupState, lockedFor, orderedCaps, requires, toggleCap, toggleForRole, toggleMany } from '../src/capabilityRules.ts';

const set = (...caps: Capability[]) => new Set<Capability>(caps);

test('eigene Daten und Verwalten setzen die Mitgliedschaft voraus, Sehen nicht', () => {
  assert.equal(requires('view_own_points'), 'member');
  assert.equal(requires('manage_events'), 'member');
  assert.equal(requires('view_accounts'), 'member');
  assert.equal(requires('manage_former'), 'member');
  assert.equal(requires('view_ranks'), null);
  assert.equal(requires('calendar'), null);
  assert.equal(requires('member'), null);
});

test('zu- und absagen setzt den internen Kalender voraus, nicht die Mitgliedschaft', () => {
  assert.equal(requires('respond_calendar'), 'calendar');
  assert.deepEqual(orderedCaps(toggleCap(set(), 'respond_calendar', true)), ['calendar', 'respond_calendar']);
  assert.deepEqual(orderedCaps(toggleCap(set('calendar', 'respond_calendar', 'view_ranks'), 'calendar', false)), ['view_ranks']);
  assert.equal(lockedFor('guest', true, 'respond_calendar'), false, 'auch für Gast');
});

test('Anhaken bringt die Voraussetzung mit, Abhaken nimmt was sie voraussetzt', () => {
  assert.deepEqual(orderedCaps(toggleCap(set(), 'manage_events', true)), ['member', 'manage_events']);
  const before = set('member', 'view_own_points', 'manage_events', 'view_ranks');
  assert.deepEqual(orderedCaps(toggleCap(before, 'member', false)), ['view_ranks'], 'Sehen bleibt');
  assert.deepEqual(orderedCaps(before), ['member', 'view_own_points', 'view_ranks', 'manage_events'], 'unverändert');
});

test('persönliche Angaben: pflegen setzt sehen voraus, über mehrere Stufen', () => {
  assert.equal(requires('manage_personal'), 'view_personal');
  assert.equal(requires('view_personal'), 'member');
  assert.deepEqual(orderedCaps(toggleCap(set(), 'manage_personal', true)), ['member', 'view_personal', 'manage_personal']);
  const all = set('member', 'view_personal', 'manage_personal', 'view_ranks');
  assert.deepEqual(orderedCaps(toggleCap(all, 'view_personal', false)), ['member', 'view_ranks']);
  assert.deepEqual(orderedCaps(toggleCap(all, 'member', false)), ['view_ranks'], 'ohne Mitgliedschaft fällt beides weg');
});

test('Schreiben setzt das passende Lesen voraus, Lesen die Mitgliedschaft (wie Capability::READ_OF)', () => {
  const pairs: [Capability, Capability][] = [
    ['manage_points', 'view_points'],
    ['manage_penalties', 'view_penalties'],
    ['manage_ranks', 'view_promotions'],
    ['manage_titles', 'view_titles'],
    ['manage_attendance', 'view_attendance'],
    ['create_users', 'view_accounts'],
    ['promote_users', 'view_accounts'],
  ];
  for (const [write, read] of pairs) {
    assert.equal(requires(write), read, write);
    assert.equal(requires(read), 'member', read);
  }
  assert.deepEqual(orderedCaps(toggleCap(set(), 'manage_points', true)), ['member', 'view_points', 'manage_points']);
  assert.deepEqual(
    orderedCaps(toggleCap(set('member', 'view_points', 'manage_points'), 'view_points', false)),
    ['member'],
    'ohne Lesen kein Schreiben',
  );
  assert.deepEqual(orderedCaps(toggleCap(set('member', 'view_points', 'manage_points'), 'manage_points', false)), [
    'member',
    'view_points',
  ]);
});

test('Haken einer Gruppe: alle, einige, keine', () => {
  const keys: Capability[] = ['view_directory', 'view_offices', 'view_former'];
  const on = set('view_directory');
  assert.equal(
    groupState((c) => on.has(c), keys),
    'some',
  );
  assert.equal(
    groupState((c) => toggleMany(on, keys, true).has(c), keys),
    'all',
  );
  assert.equal(
    groupState((c) => toggleMany(on, keys, false).has(c), keys),
    'none',
  );
});

test('Gast: jedes Recht außer Mitglied, ohne die Voraussetzung Mitglied', () => {
  assert.equal(lockedFor('guest', true, 'view_documents'), false);
  assert.equal(lockedFor('guest', true, 'member'), true);
  assert.equal(lockedFor('guest', true, 'view_own_points'), false);
  assert.equal(lockedFor('guest', true, 'manage_events'), false);
  assert.equal(lockedFor('guest', true, 'submit_gallery'), false);
  // Einreichen brächte member mit: Das kommt bei Gast nicht dazu, das Recht bleibt.
  assert.deepEqual(orderedCaps(toggleForRole('guest', true, set(), ['submit_gallery', 'view_news'], true)), [
    'view_news',
    'submit_gallery',
  ]);
});

test('Mitglied gehört immer zur Kompanie und kann jedes Recht bekommen; Admin alles gesperrt', () => {
  assert.equal(lockedFor('member', true, 'member'), true);
  assert.equal(lockedFor('member', true, 'view_own_points'), false);
  assert.equal(lockedFor('member', true, 'manage_points'), false);
  assert.equal(lockedFor('member', true, 'submit_gallery'), false);
  assert.deepEqual(orderedCaps(toggleForRole('member', true, set('member'), ['submit_gallery'], true)), ['member', 'submit_gallery']);
  assert.equal(lockedFor('admin', true, 'view_news'), true);
  assert.equal(lockedFor('r1', false, 'manage_points'), false, 'eigene Rollen: alles');
  assert.deepEqual(orderedCaps(toggleForRole('member', true, set('member', 'view_catalog'), ['view_catalog'], false)), ['member']);
});

test('Kompanien und Sponsoren: Rechte zum Sehen, auch für Gast, und beim Speichern einer Rolle erhalten (Issue #278)', () => {
  for (const c of ['view_companies', 'view_sponsors'] as Capability[]) {
    assert.equal(requires(c), null, c);
    assert.equal(lockedFor('guest', true, c), false, c);
    assert.equal(lockedFor('member', true, c), false, c);
  }
  // Ohne Haken in der Liste (die Bereiche kommen später) dürfen sie beim Speichern nicht verloren gehen.
  assert.deepEqual(orderedCaps(set('member', 'view_sponsors', 'view_news', 'view_companies')), [
    'view_news',
    'view_companies',
    'view_sponsors',
    'member',
  ]);
});
