// Geburtstage der Passiven (src/passiveAges.ts): Alter aus dem Eingabefeld lesen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseAges } from '../src/passiveAges.ts';

test('Komma, Semikolon und Leerzeichen trennen; aufsteigend, doppelte einmal', () => {
  assert.deepEqual(parseAges('80, 50;60  70,50'), [50, 60, 70, 80]);
  assert.deepEqual(parseAges(' 75 '), [75]);
});

test('leer heißt keine Alter', () => {
  assert.deepEqual(parseAges(''), []);
  assert.deepEqual(parseAges(' , '), []);
});

test('nur ganze Zahlen von 1 bis 120, höchstens 30', () => {
  for (const bad of ['0', '121', 'fünfzig', '-5', '7.5']) {
    assert.equal(typeof parseAges(bad), 'string', bad);
  }
  assert.deepEqual(parseAges('1, 120'), [1, 120]);
  assert.equal(typeof parseAges(Array.from({ length: 31 }, (_, i) => i + 1).join(',')), 'string');
});
