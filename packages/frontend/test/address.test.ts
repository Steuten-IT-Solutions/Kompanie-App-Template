// Postanschriften (src/address.ts): glätten, je Land prüfen, Hinweise, Anzeige – dieselben Regeln wie Domain\Address.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  addressHints,
  addressInput,
  addressProblems,
  EMPTY_ADDRESS,
  formatAddress,
  navigationUrl,
  normalizeAddress,
  normalizePostalCode,
  OTHER_COUNTRY,
  type Address,
} from '../src/address.ts';

const a = (patch: Partial<Address>): Address => ({ ...EMPTY_ADDRESS, ...patch });
const ok = { street: 'Musterstraße', house_number: '1', postal_code: '12345', city: 'Musterstadt' };

test('leer ist erlaubt und heißt keine Adresse', () => {
  assert.deepEqual(addressProblems(EMPTY_ADDRESS), {});
  assert.equal(addressInput(EMPTY_ADDRESS), null);
  assert.equal(addressInput(a({ street: '  ' })), null);
  assert.deepEqual(formatAddress(null), []);
});

test('Pflichtfelder, sobald etwas eingetragen ist', () => {
  assert.deepEqual(Object.keys(addressProblems(a({ street: 'Musterstraße' }))).sort(), ['city', 'postal_code']);
  assert.deepEqual(Object.keys(addressProblems(a({ city: 'Musterstadt' }))).sort(), ['postal_code', 'street']);
  assert.deepEqual(addressProblems(a(ok)), {});
});

test('Postleitzahl je Land', () => {
  const plz = (country: string, postal_code: string) => addressProblems(a({ ...ok, country, postal_code })).postal_code ?? null;
  assert.equal(plz('DE', '01067'), null);
  assert.match(plz('DE', '1234') ?? '', /Deutschland/);
  assert.notEqual(plz('DE', '00123'), null, 'Leitregion 00 gibt es nicht');
  assert.notEqual(plz('DE', '123456'), null);
  assert.equal(plz('AT', '1010'), null);
  assert.notEqual(plz('AT', '0123'), null);
  assert.equal(plz('CH', '8001'), null);
  assert.equal(plz('NL', '1234ab'), null);
  assert.notEqual(plz('NL', '1234'), null);
  assert.equal(plz('PL', '00950'), null);
  assert.equal(plz('CZ', '11000'), null);
  assert.notEqual(plz('CZ', '91000'), null, 'erste Ziffer 1–7');
  assert.equal(plz('ES', '28001'), null);
  assert.notEqual(plz('ES', '53001'), null, 'Provinzen 01–52');
  assert.equal(plz('TR', '06100'), null);
  assert.notEqual(plz('TR', '82000'), null, 'Provinzen 01–81');
  assert.equal(plz('GB', 'sw1a1aa'), null);
  assert.equal(plz('GB', 'M1 1AE'), null);
  assert.notEqual(plz('GB', '12345'), null);
  assert.equal(plz(OTHER_COUNTRY, '10001'), null);
});

test('Postleitzahl vereinheitlichen', () => {
  assert.equal(normalizePostalCode('D-12345', 'DE'), '12345');
  assert.equal(normalizePostalCode(' 123 45 ', 'DE'), '12345');
  assert.equal(normalizePostalCode('L-1234', 'LU'), '1234');
  assert.equal(normalizePostalCode('1234ab', 'NL'), '1234 AB');
  assert.equal(normalizePostalCode('11000', 'CZ'), '110 00');
  assert.equal(normalizePostalCode('00950', 'PL'), '00-950');
  assert.equal(normalizePostalCode('sw1a1aa', 'GB'), 'SW1A 1AA');
});

test('beim Verlassen: Hausnummer und PLZ in ihr Feld', () => {
  const n = normalizeAddress(a({ street: ' Hauptstraße   5 a ', city: '12345  Musterstadt ' }));
  assert.equal(n.street, 'Hauptstraße');
  assert.equal(n.house_number, '5 a');
  assert.equal(n.postal_code, '12345');
  assert.equal(n.city, 'Musterstadt');
  assert.equal(normalizeAddress(a({ street: 'Am Weg 12 - 14' })).house_number, '12-14');
  assert.equal(normalizeAddress(a({ street: 'Straße des 17. Juni' })).house_number, '', 'Zahl mitten im Namen bleibt');
  assert.equal(normalizeAddress(a({ street: 'Hauptstraße 5', house_number: '7' })).street, 'Hauptstraße 5', 'Feld schon gefüllt');
});

test('ungültige Zeichen und Längen', () => {
  assert.ok(addressProblems(a({ ...ok, street: 'Musterstraße <b>' })).street);
  assert.ok(addressProblems(a({ ...ok, street: '123' })).street, 'ohne Buchstaben');
  assert.ok(addressProblems(a({ ...ok, house_number: 'a12' })).house_number);
  assert.ok(addressProblems(a({ ...ok, city: 'M' })).city);
  assert.equal(addressProblems(a({ ...ok, city: 'Frankfurt (Oder)' })).city, undefined);
  assert.equal(addressProblems(a({ ...ok, street: "Rue de l'Église" })).street, undefined);
  assert.ok(addressProblems(a({ ...ok, country: OTHER_COUNTRY, postal_code: '10001' })).country_name, 'anderes Land braucht den Namen');
  assert.deepEqual(addressProblems(a({ ...ok, country: OTHER_COUNTRY, postal_code: '10001', country_name: 'USA' })), {});
});

test('Hinweise ohne Sperre', () => {
  assert.equal(addressHints(a({ ...ok, house_number: '' })).house_number, 'Hausnummer vergessen?');
  assert.ok(addressHints(a({ ...ok, city: 'Paris Cedex 08' })).city);
  assert.deepEqual(addressProblems(a({ ...ok, city: 'Paris Cedex 08' })), {});
});

test('Anzeige wie auf einem Brief', () => {
  assert.deepEqual(formatAddress(a({ ...ok, extra: 'c/o Muster' })), ['Musterstraße 1', 'c/o Muster', '12345 Musterstadt']);
  assert.deepEqual(formatAddress(a({ ...ok, country: 'AT', postal_code: '1010', city: 'Wien' })), [
    'Musterstraße 1',
    '1010 Wien',
    'Österreich',
  ]);
});

test('Route in der Karten-App: ohne Zusatz, mit Land', () => {
  assert.equal(navigationUrl(null, false), null);
  assert.equal(navigationUrl(EMPTY_ADDRESS, true), null);
  const q = encodeURIComponent('Musterstraße 1, 12345 Musterstadt, Deutschland');
  assert.equal(navigationUrl(a({ ...ok, extra: 'c/o Muster' }), false), `https://www.google.com/maps/dir/?api=1&destination=${q}`);
  assert.equal(navigationUrl(a(ok), true), `https://maps.apple.com/?daddr=${q}`);
  assert.equal(
    navigationUrl(a({ ...ok, country: OTHER_COUNTRY, country_name: 'Norwegen', postal_code: '0150', city: 'Oslo' }), true),
    `https://maps.apple.com/?daddr=${encodeURIComponent('Musterstraße 1, 0150 Oslo, Norwegen')}`,
  );
});
