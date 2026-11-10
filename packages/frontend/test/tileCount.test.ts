// Zahl im Kreis an den Kacheln der Verwaltung (src/tileCount.ts): Beförderungen, Anfragen und Fehlermeldungen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { tileCount } from '../src/tileCount.ts';

test('keine Zahl ohne offene Einträge oder ohne Recht (null vom Server)', () => {
  for (const id of ['befoerderungen', 'anfragen', 'meldungen']) {
    assert.equal(tileCount(id, {}), null);
    assert.equal(tileCount(id, { promotions: 0, registrations: 0, reports: 0 }), null);
    assert.equal(tileCount(id, { promotions: null, registrations: null, reports: null }), null);
  }
});

test('jede Kachel nimmt ihre eigene Zahl', () => {
  const counts = { promotions: 1, registrations: 2, reports: 3 };
  assert.equal(tileCount('befoerderungen', counts)?.count, 1);
  assert.equal(tileCount('anfragen', counts)?.count, 2);
  assert.equal(tileCount('meldungen', counts)?.count, 3);
  assert.equal(tileCount('mitglieder', counts), null);
});

test('Erklärung in Einzahl und Mehrzahl', () => {
  assert.equal(tileCount('befoerderungen', { promotions: 1 })?.title, '1 Mitglied zur Beförderung bereit');
  assert.equal(tileCount('anfragen', { registrations: 1 })?.title, '1 offene Anfrage auf einen Zugang');
  assert.equal(tileCount('anfragen', { registrations: 4 })?.title, '4 offene Anfragen auf einen Zugang');
  assert.equal(tileCount('meldungen', { reports: 1 })?.title, '1 offene Fehlermeldung');
  assert.equal(tileCount('meldungen', { reports: 2 })?.title, '2 offene Fehlermeldungen');
});

test('ab 100 steht 99+ im Kreis', () => {
  assert.equal(tileCount('meldungen', { reports: 99 })?.text, '99');
  assert.equal(tileCount('meldungen', { reports: 100 })?.text, '99+');
});
