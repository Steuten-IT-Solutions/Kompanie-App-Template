// Wartungsabo unter Einstellungen → Zustand und Updates (src/wartungsabo.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dayText, maintenanceText } from '../src/wartungsabo.ts';

test('ohne Angabe nichts anzeigen', () => {
  assert.equal(maintenanceText(null, false), null);
  assert.equal(maintenanceText(undefined, undefined), null);
});

test('laufende Wartung nennt das Datum', () => {
  assert.equal(maintenanceText('2027-06-30', false), 'Wartung bis 30.06.2027. Bis dahin bekommt die App neue Versionen.');
});

test('nach dem Ende: weiterlaufen, keine Sicherheitsupdates', () => {
  const t = maintenanceText('2026-11-20', true) ?? '';
  assert.match(t, /am 20\.11\.2026 abgelaufen/);
  assert.match(t, /auch Sicherheitsupdates/);
});

test('unbefristet und unbekannte Angaben', () => {
  assert.equal(maintenanceText('unbefristet', false), 'Wartung: unbefristet.');
  assert.equal(dayText('morgen'), 'morgen');
});
