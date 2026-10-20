// Zwischenspeicher der Ansichten (src/dataCache.ts): eigene Änderungen verwerfen ihn, und eine Ladeanfrage, die vor
// einer Änderung begonnen hat, legt ihre (möglicherweise veralteten) Daten nicht mehr ab.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dataCache } from '../src/dataCache.ts';

test('Daten sind beim nächsten Öffnen da, eine eigene Änderung verwirft sie', () => {
  dataCache.set('termine|[]', ['a']);
  assert.equal(dataCache.has('termine|[]'), true);
  assert.deepEqual(dataCache.get('termine|[]'), ['a']);
  dataCache.clear();
  assert.equal(dataCache.has('termine|[]'), false);
});

test('Antwort einer Anfrage, die vor einer Änderung begann, wird nicht gespeichert', () => {
  const since = dataCache.generation();
  dataCache.clear(); // z. B. POST /points, während die Liste noch lädt
  dataCache.set('me/points|[]', ['alt'], since);
  assert.equal(dataCache.has('me/points|[]'), false);
  dataCache.set('me/points|[]', ['neu'], dataCache.generation());
  assert.deepEqual(dataCache.get('me/points|[]'), ['neu']);
});
