// Übersetzungen (src/i18n.ts): Deutsch als Schlüssel, Rückfall auf Deutsch, Platzhalter, Mehrzahl, Sprache der Seite.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fill, isLocale, locale, localeTag, setLocale, t, tn, type Catalog } from '../src/i18n.ts';

const en: Catalog = {
  Speichern: 'Save',
  'Hallo, {name}': 'Hello, {name}',
  '{count} Termine': { one: 'one event', other: '{count} events' },
  Leer: '',
};

test('Deutsch ist die Vorgabe, die Seite bekommt lang', () => {
  assert.equal(locale(), 'de');
  assert.equal(localeTag(), 'de-DE');
  const doc = { documentElement: { lang: 'de' } };
  setLocale('en', doc);
  assert.equal(doc.documentElement.lang, 'en');
  assert.equal(localeTag(), 'en-GB');
  setLocale('de', doc);
  assert.equal(doc.documentElement.lang, 'de');
});

test('Deutscher Text ist der Schlüssel, fehlende oder leere Einträge fallen auf Deutsch zurück', () => {
  assert.equal(t('Speichern', undefined, en), 'Save');
  assert.equal(t('Abbrechen', undefined, en), 'Abbrechen');
  assert.equal(t('Leer', undefined, en), 'Leer');
  assert.equal(t('Speichern'), 'Speichern');
});

test('Platzhalter werden ersetzt, unbekannte bleiben stehen', () => {
  assert.equal(t('Hallo, {name}', { name: 'Anna' }, en), 'Hello, Anna');
  assert.equal(fill('{a} und {b}', { a: 1 }), '1 und {b}');
  assert.equal(fill('ohne', undefined), 'ohne');
});

test('Mehrzahl nach den Regeln der Sprache, Zahl im Format der Sprache', () => {
  assert.equal(tn('{count} Termin', '{count} Termine', 1), '1 Termin');
  assert.equal(tn('{count} Termin', '{count} Termine', 0), '0 Termine');
  assert.equal(tn('{count} Termin', '{count} Termine', 1234), '1.234 Termine');
  assert.equal(tn('{count} Termin', '{count} Termine', 1, undefined, en, 'en'), 'one event');
  assert.equal(tn('{count} Termin', '{count} Termine', 1234, undefined, en, 'en'), '1,234 events');
  // Ohne Eintrag: deutsche Formen
  assert.equal(tn('{count} Bild', '{count} Bilder', 2, undefined, en, 'en'), '2 Bilder');
});

test('Nur bekannte Sprachen', () => {
  assert.ok(isLocale('de'));
  assert.ok(isLocale('en'));
  assert.ok(!isLocale('fr'));
  assert.ok(!isLocale(undefined));
});
