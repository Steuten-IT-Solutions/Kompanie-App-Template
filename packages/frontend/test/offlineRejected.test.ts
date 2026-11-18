// Abgelehnte offline vorgemerkte Änderungen (src/offlineRejected.ts): bleiben sichtbar, neueste zuerst, begrenzt.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { REJECTED_MAX, rejectedText, sortedRejected, validRejected, withRejected } from '../src/offlineRejected.ts';

test('dazunehmen, neueste zuerst, höchstens REJECTED_MAX', () => {
  let items = withRejected({}, [{ kind: 'response', label: 'Fest', message: 'Frist vorbei' }], 1000);
  items = withRejected(
    items,
    [
      { kind: 'attendance', label: 'Probe', message: 'Liste gelöscht' },
      { kind: 'booking', label: '3 Punkte: Arbeitsdienst', message: 'Kein Recht' },
    ],
    2000,
  );
  assert.equal(Object.keys(items).length, 3);
  assert.deepEqual(
    sortedRejected(items).map(([, r]) => r.label),
    ['3 Punkte: Arbeitsdienst', 'Probe', 'Fest'],
  );
  // Gleiche Zeit, gleicher Schlüssel: nichts überschrieben
  items = withRejected(items, [{ kind: 'response', label: 'Fest', message: 'nochmal' }], 1000);
  assert.equal(Object.keys(items).length, 4);
  const many = Array.from({ length: REJECTED_MAX + 5 }, (_, i) => ({ kind: 'booking' as const, label: `B${i}`, message: 'x' }));
  assert.equal(Object.keys(withRejected(items, many, 3000)).length, REJECTED_MAX);
});

test('nur gültige Einträge aus dem Speicher', () => {
  assert.deepEqual(validRejected({ kind: 'response', label: 'a', message: 'b', at: 1 }), {
    kind: 'response',
    label: 'a',
    message: 'b',
    at: 1,
  });
  assert.equal(validRejected({ kind: 'anderes', label: 'a', message: 'b', at: 1 }), null);
  assert.equal(validRejected({ kind: 'response', label: 'a', at: 1 }), null);
  assert.equal(validRejected(null), null);
});

test('Text für den Hinweis', () => {
  assert.equal(
    rejectedText({ kind: 'response', label: 'Fest', message: 'Für diesen Termin kannst du nicht mehr zu- oder absagen.', at: 1 }),
    'Zu- oder Absage zu „Fest“: Für diesen Termin kannst du nicht mehr zu- oder absagen.',
  );
  assert.equal(rejectedText({ kind: 'attendance', label: 'Probe', message: 'weg', at: 1 }), 'Anwesenheit „Probe“: weg');
  assert.equal(rejectedText({ kind: 'booking', label: '', message: 'weg', at: 1 }), 'Buchung: weg');
});
