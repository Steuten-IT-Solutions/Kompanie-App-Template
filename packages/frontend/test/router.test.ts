// Schlüssel der Ansichten (src/router.ts): Unterseiten eines Mitglieds und von „Meins“ bauen die Ansicht nicht neu auf.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextEntry, viewKey } from '../src/router.ts';

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

test('neuer Eintrag merkt sich die Seite, von der aus er geöffnet wurde', () => {
  const list = nextEntry(null, null, 'galerie', false, 'a');
  assert.deepEqual(list, { id: 'a', path: 'galerie', prev: null });
  const album = nextEntry(null, list, 'galerie/3', false, 'b');
  assert.deepEqual(album.prev, { id: 'a', path: 'galerie' });
});

test('Weiterleitung ersetzt den Eintrag: zurück geht es dorthin, wo der ersetzte herkam', () => {
  const list = nextEntry(null, null, 'verwaltung/mitglieder', false, 'a');
  const first = nextEntry(null, list, 'verwaltung/mitglied/1', false, 'b');
  const second = nextEntry(null, first, 'verwaltung/mitglied/2', true, 'c');
  assert.deepEqual(second, { id: 'c', path: 'verwaltung/mitglied/2', prev: { id: 'a', path: 'verwaltung/mitglieder' } });
});

test('Zurück im Verlauf: der Eintrag behält Kennung und Herkunft', () => {
  const list = nextEntry(null, null, 'galerie', false, 'a');
  const album = nextEntry(null, list, 'galerie/3', false, 'b');
  assert.deepEqual(nextEntry(list, album, 'galerie', false, 'neu'), list);
});
