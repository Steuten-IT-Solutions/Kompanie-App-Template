// Zahl im Kreis an den Kacheln der Verwaltung (src/tileCount.ts): Beförderungen, Anfragen und Fehlermeldungen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { awaitingResponses, tileCount } from '../src/tileCount.ts';

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

test('Kalender: Termine ohne eigene Rückmeldung, deren Frist in 30 Tagen endet', () => {
  const now = Date.parse('2026-11-15T12:00:00+01:00');
  const ev = (mine: 'yes' | 'no' | null, until: string, more: { open?: boolean; can_respond?: boolean } = {}) => ({
    responses: { mine, note: '', yes: 0, no: 0, until, open: true, can_respond: true, can_list: false, ...more },
  });
  const events = [
    ev(null, '2026-11-20T19:30:00+01:00'),
    ev('yes', '2026-11-21T19:30:00+01:00'),
    ev(null, '2026-11-22T00:00:00+01:00', { open: false }),
    ev(null, '2026-11-23T00:00:00+01:00', { can_respond: false }),
    ev(null, '2027-01-10T00:00:00+01:00'),
    {},
  ];
  assert.equal(awaitingResponses(events, now), 1);
  assert.equal(tileCount('termine', { responses: 1 })?.title, '1 Termin ohne deine Zu- oder Absage');
  assert.equal(tileCount('termine', { responses: 0 }), null);
});
