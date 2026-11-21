// Eigene Namen je weiterer Sprache (src/names.ts): Übersetzung, sonst der deutsche Name.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nameIn, setNameTranslations, translatedName } from '../src/names.ts';

test('Übersetzung, sonst der deutsche Name; Deutsch bleibt Deutsch', () => {
  setNameTranslations({ en: { nav: { termine: { label: 'Dates' } }, role: { member: { label: 'Fellow' } } } });
  assert.equal(nameIn('nav', 'termine', 'Feste', 'label', 'en'), 'Dates');
  assert.equal(nameIn('nav', 'galerie', 'Fotos', 'label', 'en'), 'Fotos');
  assert.equal(nameIn('role', 'member', 'Mitglied', 'label', 'de'), 'Mitglied');
  assert.equal(translatedName('role', 'member', 'en'), 'Fellow');
  assert.equal(translatedName('role', 'member', 'de'), '');
  // Leer kommt vom Server als []
  setNameTranslations([]);
  assert.equal(nameIn('nav', 'termine', 'Feste', 'label', 'en'), 'Feste');
});
