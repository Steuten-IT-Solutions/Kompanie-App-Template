// Zahl im Kreis an den Kacheln der Verwaltung (src/tileCount.ts): Beförderungen, Aufnahmen, Anfragen und Fehlermeldungen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { tileCount, tileSoonCount, tileTitle } from '../src/tileCount.ts';

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

test('Aufnahmen: Anwärter, die die Voraussetzung erfüllen', () => {
  assert.equal(tileCount('aufnahmen', { admissions: null }), null);
  assert.equal(tileCount('aufnahmen', { admissions: 0 }), null);
  assert.equal(tileCount('aufnahmen', { admissions: 1 })?.title, '1 Anwärter erfüllt die Voraussetzung für die Aufnahme');
  assert.equal(tileCount('aufnahmen', { admissions: 3 })?.title, '3 Anwärter erfüllen die Voraussetzung für die Aufnahme');
});

test('Aufnahmen: zweiter Kreis für Anwärter kurz vor der Aufnahme', () => {
  assert.equal(tileSoonCount('aufnahmen', { admissionsSoon: null }), null);
  assert.equal(tileSoonCount('aufnahmen', { admissionsSoon: 0 }), null);
  assert.equal(tileSoonCount('befoerderungen', { admissionsSoon: 2 }), null, 'nur an der Kachel Aufnahmen');
  assert.equal(tileSoonCount('aufnahmen', { admissionsSoon: 1 })?.title, '1 Anwärter steht kurz vor der Aufnahme');
  assert.equal(tileSoonCount('aufnahmen', { admissionsSoon: 2 })?.text, '2');
  assert.equal(tileCount('aufnahmen', { admissions: 0, admissionsSoon: 2 }), null, 'der rote Kreis zählt nur Erfüllte');
});

test('Erklärung beider Zahlen in einem Satz', () => {
  const counts = { admissions: 1, admissionsSoon: 2 };
  assert.equal(
    tileTitle(tileCount('aufnahmen', counts), tileSoonCount('aufnahmen', counts)),
    '1 Anwärter erfüllt die Voraussetzung für die Aufnahme, 2 Anwärter stehen kurz vor der Aufnahme',
  );
  assert.equal(tileTitle(null, tileSoonCount('aufnahmen', counts)), '2 Anwärter stehen kurz vor der Aufnahme');
  assert.equal(tileTitle(null, null), undefined);
});
