// Handynummern (src/phone.ts): Ländervorwahl aus der Auswahl, einheitlich international speichern, prüfen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { composePhone, normalizePhone, OTHER, phoneHint, phoneProblem, splitPhone, tidyPhone } from '../src/phone.ts';

test('beim Tippen: nur Zeichen einer Telefonnummer', () => {
  assert.equal(tidyPhone('0171.123 abc 4567'), '0171123  4567');
  assert.equal(tidyPhone('+49 (0) 171/123-45'), '+49 (0) 171/123-45');
});

test('zusammensetzen: Auswahl + Nummer', () => {
  assert.equal(composePhone('49', '0171 1234567'), '+49 171 1234567');
  assert.equal(composePhone('49', '0171/123 45 67'), '+49 171 1234567');
  assert.equal(composePhone('49', '171 1234567'), '+49 171 1234567');
  assert.equal(composePhone('49', '01512 3456789'), '+49 1512 3456789');
  assert.equal(composePhone('49', '0160 12345678'), '+49 160 12345678');
  assert.equal(composePhone('49', '02841 12345'), '+49 284112345');
  assert.equal(composePhone('43', '0664 1234567'), '+43 6641234567');
  assert.equal(composePhone('31', '06 12345678'), '+31 612345678');
  // Italien: Die 0 gehört zur Nummer.
  assert.equal(composePhone('39', '06 1234567'), '+39 061234567');
  assert.equal(composePhone('48', '512 345 678'), '+48 512345678');
  assert.equal(composePhone('49', '  '), '');
});

test('internationale Nummer im Feld gilt statt der Auswahl', () => {
  assert.equal(composePhone('49', '+31 6 12345678'), '+31 612345678');
  assert.equal(composePhone('49', '0031 6 12345678'), '+31 612345678');
  assert.equal(composePhone('49', '+49 (0) 171 1234567'), '+49 171 1234567');
  assert.equal(composePhone(OTHER, '+1 212 555 0100'), '+12125550100');
  assert.equal(composePhone(OTHER, '212 555'), '212 555');
});

test('zerlegen für die Anzeige', () => {
  assert.deepEqual(splitPhone('+49 171 1234567'), { code: '49', national: '171 1234567' });
  assert.deepEqual(splitPhone('+352 621123456'), { code: '352', national: '621123456' });
  assert.deepEqual(splitPhone('0031 6 12345678'), { code: '31', national: '612345678' });
  assert.deepEqual(splitPhone('+1 212 555 0100'), { code: OTHER, national: '+12125550100' });
  // Ältere Einträge ohne Ländervorwahl
  assert.deepEqual(splitPhone('0171 1234567'), { code: '49', national: '0171 1234567' });
  assert.deepEqual(splitPhone(''), { code: '49', national: '' });
});

test('prüfen', () => {
  assert.equal(phoneProblem(''), null);
  assert.equal(phoneProblem('+49 171 1234567'), null);
  assert.match(phoneProblem('212 555') ?? '', /Ländervorwahl/);
  assert.equal(phoneProblem(composePhone('49', '0171')), 'Die Nummer ist zu kurz.');
  assert.equal(phoneProblem(composePhone('49', '0171 1234567890123')), 'Die Nummer hat zu viele Ziffern.');
});

test('Hinweis bei deutschen Handynummern mit falscher Länge', () => {
  assert.equal(phoneHint('+49 171 1234567'), null);
  assert.equal(phoneHint('+49 1512 3456789'), null);
  assert.match(phoneHint(composePhone('49', '0171 123456')) ?? '', /10 oder 11 Ziffern/);
  assert.match(phoneHint(composePhone('49', '0171 123456789')) ?? '', /10 oder 11 Ziffern/);
  assert.equal(phoneHint('+49 284112345'), null);
});

test('einheitlich schreiben, auch ältere Einträge', () => {
  assert.equal(normalizePhone('0171 1234567'), '+49 171 1234567');
  assert.equal(normalizePhone('+49 171 1234567'), '+49 171 1234567');
  assert.equal(normalizePhone('0031 6 12345678'), '+31 612345678');
  assert.equal(normalizePhone('+1 212 555 0100'), '+12125550100');
  assert.equal(normalizePhone(''), '');
});
