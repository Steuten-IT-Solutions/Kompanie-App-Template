// Eigene Namen je weiterer Sprache (src/names.ts): Übersetzung, sonst der deutsche Name.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setBaseLocale } from '../src/i18n.ts';
import { nameIn, setNameTranslations, translatedName } from '../src/names.ts';

test('Übersetzung, sonst der deutsche Name; Deutsch bleibt Deutsch', () => {
  setNameTranslations({ en: { nav: { termine: { label: 'Dates' } }, role: { member: { label: 'Fellow' } } } });
  assert.equal(nameIn('nav', 'termine', 'Feste', 'label', 'en'), 'Dates');
  assert.equal(nameIn('nav', 'galerie', 'Fotos', 'label', 'en'), 'Fotos');
  assert.equal(nameIn('role', 'member', 'Mitglied', 'label', 'de'), 'Mitglied');
  assert.equal(translatedName('role', 'member', 'en'), 'Fellow');
  assert.equal(translatedName('role', 'member', 'de'), '');
  // Dienstgrade: Kennung ist die ID
  setNameTranslations({ en: { rank: { '4': { label: 'Sergeant' } } } });
  assert.equal(nameIn('rank', '4', 'Feldwebel', 'label', 'en'), 'Sergeant');
  assert.equal(nameIn('rank', '5', 'Leutnant', 'label', 'en'), 'Leutnant');
  assert.equal(nameIn('rank', '4', 'Feldwebel', 'label', 'de'), 'Feldwebel');
  // Leer kommt vom Server als []
  setNameTranslations([]);
  assert.equal(nameIn('nav', 'termine', 'Feste', 'label', 'en'), 'Feste');
});

test('Basissprache Englisch: Übersetzungen nur für Deutsch', () => {
  setBaseLocale('en');
  setNameTranslations({ de: { role: { member: { label: 'Kamerad' } } }, en: { role: { member: { label: 'Fellow' } } } });
  assert.equal(nameIn('role', 'member', 'Member', 'label', 'de'), 'Kamerad');
  assert.equal(nameIn('role', 'member', 'Member', 'label', 'en'), 'Member', 'Basissprache: der Name, wie er ist');
  setBaseLocale('de');
  setNameTranslations([]);
});
