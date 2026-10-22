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

test('Daten vom Gerät erscheinen nach dem Neustart; eigene Änderungen leeren auch den Speicher auf dem Gerät', async () => {
  dataCache.clear();
  const calls: string[] = [];
  const stored = new Map<string, unknown>([
    ['termine|[]', ['vom Gerät']],
    ['me|[]', { alt: true }],
  ]);
  dataCache.set('me|[]', { frisch: true }); // schon frisch geladen, bevor der Speicher da war
  await dataCache.restore('v|1', {
    open: async () => stored,
    put: (key) => calls.push(`put ${key}`),
    clear: () => calls.push('clear'),
  });
  assert.deepEqual(dataCache.get('termine|[]'), ['vom Gerät']);
  assert.deepEqual(dataCache.get('me|[]'), { frisch: true });
  dataCache.set('news|[1]', ['n']);
  dataCache.set('settings|[]', { geheim: 1 }, dataCache.generation(), false); // Verwaltung: nicht auf das Gerät
  dataCache.clear();
  assert.deepEqual(calls, ['put news|[1]', 'clear']);
});
