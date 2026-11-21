// Übersetzungen (src/i18n.ts): Deutsch als Schlüssel, Rückfall auf Deutsch, Platzhalter, Mehrzahl, Sprache der Seite.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  chooseLocale,
  deviceLocale,
  fill,
  isLocale,
  locale,
  localeTag,
  saveDeviceLocale,
  setLocale,
  t,
  tc,
  tn,
  type Catalog,
} from '../src/i18n.ts';

const en: Catalog = {
  Speichern: 'Save',
  'Hallo, {name}': 'Hello, {name}',
  '{count} Termine': { one: 'one event', other: '{count} events' },
  Leer: '',
  Zusagen: 'Accept',
  'Anzahl|Zusagen': 'Accepted',
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

test('Zusammenhang trennt gleiche deutsche Texte, ohne Eintrag bleibt Deutsch', () => {
  assert.equal(t('Zusagen', undefined, en), 'Accept');
  assert.equal(tc('Anzahl', 'Zusagen', undefined, en), 'Accepted');
  assert.equal(tc('Knopf', 'Zusagen', undefined, en), 'Zusagen');
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

test('Sprache beim Start: Konto, Gerät, Browser, Deutsch – nur eingeschaltete', () => {
  const enabled = ['de', 'en'];
  assert.equal(chooseLocale({ enabled: ['de'], account: 'en', device: 'en', browser: ['en-US'] }), 'de', 'nur Deutsch an');
  assert.equal(chooseLocale({ enabled, account: 'en', device: 'de', browser: ['de-DE'] }), 'en', 'Konto zuerst');
  assert.equal(chooseLocale({ enabled, account: null, device: 'en', browser: ['de-DE'] }), 'en', 'dann Gerät');
  assert.equal(
    chooseLocale({ enabled, account: 'fr', device: 'xx', browser: ['fr-FR', 'en-GB', 'de'] }),
    'en',
    'dann Browser, erste passende',
  );
  assert.equal(chooseLocale({ enabled, browser: ['fr'] }), 'de', 'sonst Deutsch');
  assert.equal(chooseLocale({}), 'de', 'ältere Server ohne languages');
});

test('Wahl am Gerät merken und lesen, ohne Speicher kein Fehler', () => {
  const data = new Map<string, string>();
  const storage = {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    removeItem: (k: string) => void data.delete(k),
  };
  assert.equal(deviceLocale(storage), null);
  saveDeviceLocale('en', storage);
  assert.equal(deviceLocale(storage), 'en');
  saveDeviceLocale(null, storage);
  assert.equal(deviceLocale(storage), null);
  data.set('kompanie-locale', 'fr');
  assert.equal(deviceLocale(storage), null, 'Unbekanntes zählt nicht');
  const broken = {
    getItem: () => {
      throw new Error('gesperrt');
    },
    setItem: () => {
      throw new Error('gesperrt');
    },
    removeItem: () => undefined,
  };
  assert.equal(deviceLocale(broken), null);
  saveDeviceLocale('en', broken);
});
