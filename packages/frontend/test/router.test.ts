// Schlüssel der Ansichten (src/router.ts): Unterseiten eines Mitglieds und von „Meins“ bauen die Ansicht nicht neu auf.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { viewKey } from '../src/router.ts';

test('Seiten eines Mitglieds teilen sich den Schlüssel', () => {
  assert.equal(viewKey(['verwaltung', 'mitglied', '12']), viewKey(['verwaltung', 'mitglied', '12', 'punkte']));
  assert.equal(viewKey(['verwaltung', 'mitglied', '12', 'konto']), viewKey(['verwaltung', 'mitglied', '12', 'titel']));
});

test('Seiten einer ehemaligen Person teilen sich den Schlüssel, nicht aber der Import', () => {
  assert.equal(viewKey(['verwaltung', 'ehemalige', '12']), viewKey(['verwaltung', 'ehemalige', '12', 'punkte']));
  assert.notEqual(viewKey(['verwaltung', 'ehemalige', '12']), viewKey(['verwaltung', 'mitglied', '12']));
  assert.notEqual(viewKey(['verwaltung', 'ehemalige']), viewKey(['verwaltung', 'ehemalige', 'import']));
});

test('anderes Mitglied, andere Ansicht', () => {
  assert.notEqual(viewKey(['verwaltung', 'mitglied', '12']), viewKey(['verwaltung', 'mitglied', '13']));
  assert.notEqual(viewKey(['verwaltung', 'mitglied', '12']), viewKey(['verwaltung', 'mitglieder']));
});

test('Seiten von „Meins“ teilen sich den Schlüssel', () => {
  assert.equal(viewKey(['meins']), viewKey(['meins', 'strafen']));
  assert.equal(viewKey(['meins', 'punkte']), viewKey(['meins', 'dienstgrad']));
});

test('sonst baut jede Adresse die Ansicht neu auf', () => {
  assert.notEqual(viewKey(['verwaltung', 'punkte']), viewKey(['verwaltung', 'punkte', '12']));
  assert.notEqual(viewKey(['galerie']), viewKey(['galerie', '3']));
});
