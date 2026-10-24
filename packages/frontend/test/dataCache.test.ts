// Zwischenspeicher der Ansichten (src/dataCache.ts): eigene Änderungen verwerfen ihn, und eine Ladeanfrage, die vor
// einer Änderung begonnen hat, legt ihre (möglicherweise veralteten) Daten nicht mehr ab.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cacheKey, dataCache, MAX_ENTRIES, prefetch } from '../src/dataCache.ts';

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

test('Höchstens MAX_ENTRIES Einträge: der am längsten nicht gespeicherte fällt heraus', () => {
  dataCache.clear();
  for (let i = 0; i < MAX_ENTRIES; i++) dataCache.set(`member|[${i}]`, i);
  dataCache.set('member|[0]', 'neu'); // erneut gespeichert: rückt nach hinten
  dataCache.set('documents|[]', []);
  assert.equal(dataCache.has('member|[0]'), true);
  assert.equal(dataCache.get('member|[0]'), 'neu');
  assert.equal(dataCache.has('member|[1]'), false);
  assert.equal(dataCache.has(`member|[${MAX_ENTRIES - 1}]`), true);
  assert.equal(dataCache.has('documents|[]'), true);
});

test('Vorladen legt die Daten unter dem Schlüssel von useAsync ab, nur einmal', async () => {
  dataCache.clear();
  let calls = 0;
  const load = () => {
    calls++;
    return Promise.resolve({ id: 7 });
  };
  await Promise.all([prefetch('member', [7], load), prefetch('member', [7], load)]);
  assert.equal(calls, 1);
  assert.equal(cacheKey('member', [7]), 'member|[7]');
  assert.deepEqual(dataCache.get('member|[7]'), { id: 7 });
  await prefetch('member', [7], load);
  assert.equal(calls, 1);
});

test('Vorladen: nach einer eigenen Änderung nichts Veraltetes ablegen, Fehler bleiben still', async () => {
  dataCache.clear();
  let resolve!: (d: unknown) => void;
  const done = prefetch('member', [8], () => new Promise((r) => (resolve = r)));
  dataCache.clear();
  resolve({ id: 8 });
  await done;
  assert.equal(dataCache.has('member|[8]'), false);
  await prefetch('member', [9], () => Promise.reject(new Error('offline')));
  assert.equal(dataCache.has('member|[9]'), false);
});
