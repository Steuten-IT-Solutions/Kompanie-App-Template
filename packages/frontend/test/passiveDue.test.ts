// Beitrag der Passiven erst ab dem Eintritt (src/passiveDue.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { joinedBy, joinYear } from '../src/passiveDue.ts';

test('Eintrittsjahr aus Jahr, Datum oder Text', () => {
  assert.equal(joinYear('2027'), 2027);
  assert.equal(joinYear('01.03.2027'), 2027);
  assert.equal(joinYear('2027-03-01'), 2027);
  assert.equal(joinYear('ab 2027'), 2027);
  assert.equal(joinYear(' seit 1998 '), 1998);
});

test('ohne Jahr oder „vor …“ keine Grenze', () => {
  for (const t of ['', '   ', 'vor 2014', 'Vor 2014', 'unbekannt', '12345', undefined, null]) {
    assert.equal(joinYear(t), null, String(t));
  }
});

test('dabei ab dem Eintrittsjahr', () => {
  assert.equal(joinedBy('2027', 2026), false);
  assert.equal(joinedBy('2027', 2027), true);
  assert.equal(joinedBy('2027', 2028), true);
  assert.equal(joinedBy('vor 2014', 2010), true);
  assert.equal(joinedBy('', 2026), true);
});
