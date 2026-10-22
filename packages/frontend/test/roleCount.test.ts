// Zähler je Rolle (src/format.ts): ab 2 und bei 0 im Plural, eigene Bezeichnungen unverändert.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupCounts, roleCount } from '../src/format.ts';

test('Standardbezeichnungen stehen ab 2 im Plural', () => {
  assert.equal(roleCount('Mitglied', 1), 'Mitglied 1');
  assert.equal(roleCount('Mitglied', 45), 'Mitglieder 45');
  assert.equal(roleCount('Mitglied', 0), 'Mitglieder 0');
  assert.equal(roleCount('Gast', 2), 'Gäste 2');
  assert.equal(roleCount('Anwärter', 5), 'Anwärter 5');
});

test('eigene Bezeichnungen bleiben unverändert', () => {
  assert.equal(roleCount('Jungmariner', 3), 'Jungmariner 3');
});

test('Gruppen in umgekehrter Reihenfolge der Rollen (von unten nach oben), leere fehlen', () => {
  const roles = [
    { key: 'guest', label: 'Gast' },
    { key: 'r2', label: 'Anwärter' },
    { key: 'member', label: 'Mitglied' },
    { key: 'jung', label: 'Jungmariner' },
    { key: 'admin', label: 'Admin' },
  ];
  const counts = new Map([
    ['r2', 5],
    ['jung', 3],
    ['member', 45],
    ['admin', 0],
  ]);
  assert.deepEqual(groupCounts(roles, counts), ['Jungmariner 3', 'Mitglieder 45', 'Anwärter 5']);
  assert.equal(roles[0].key, 'guest', 'Eingabe unverändert');
});
