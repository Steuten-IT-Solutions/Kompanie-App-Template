// Speicher der Ansichten auf dem Gerät (src/deviceStore.ts): nur Einträge des eigenen Bereichs (Version und Benutzer),
// Begrenzung der Anzahl.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deviceStore } from '../src/deviceStore.ts';

/** Speicher wie im Browser (CacheStorage), Einträge in der Reihenfolge des Speicherns. */
class FakeCache {
  entries = new Map<string, Response>();
  async match(req: RequestInfo) {
    return this.entries.get(String(req instanceof Request ? req.url : req))?.clone();
  }
  async put(req: RequestInfo, res: Response) {
    this.entries.set(String(req instanceof Request ? req.url : req), res);
  }
  async delete(req: RequestInfo) {
    return this.entries.delete(String(req instanceof Request ? req.url : req));
  }
  async keys() {
    return [...this.entries.keys()].map((u) => new Request(u));
  }
}

function fakeStorage() {
  const caches = new Map<string, FakeCache>();
  const storage = {
    async open(name: string) {
      if (!caches.has(name)) caches.set(name, new FakeCache());
      return caches.get(name)!;
    },
  } as unknown as CacheStorage;
  return { storage, caches };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 10));

test('Nach dem Neustart sind die Einträge des Bereichs da, die anderer Konten werden gelöscht', async () => {
  const { storage, caches } = fakeStorage();
  const a = deviceStore('t', 10, storage);
  await a.open('1.0|7');
  a.put('termine|[]', ['x']);
  await settle();
  const b = deviceStore('t', 10, storage);
  await b.open('1.0|8'); // anderes Konto
  b.put('me|[]', { id: 8 });
  await settle();
  assert.equal(caches.get('t')!.entries.size, 1);

  const c = deviceStore('t', 10, storage);
  assert.deepEqual([...(await c.open('1.0|8'))], [['me|[]', { id: 8 }]]);
  const d = deviceStore('t', 10, storage);
  assert.equal((await d.open('1.1|8')).size, 0, 'neue Version: altes Format nicht zeigen');
});

test('Ohne open() wird nichts gespeichert, und es bleiben höchstens max Einträge (die ältesten fallen heraus)', async () => {
  const { storage, caches } = fakeStorage();
  const s = deviceStore('t', 5, storage);
  s.put('vorher', 1);
  await s.open('v|1');
  for (let i = 0; i < 10; i++) s.put(`k${i}`, i);
  s.put('k0', 'neu'); // neu eingefügt: jetzt der jüngste
  await settle();
  const found = await deviceStore('t', 5, storage).open('v|1');
  assert.equal(caches.get('t')!.entries.size <= 6, true);
  assert.equal(found.has('vorher'), false);
  assert.equal(found.get('k0'), 'neu');
  assert.equal(found.get('k9'), 9);
  assert.equal(found.has('k1'), false);
});

test('Ohne Cache Storage (z. B. ohne HTTPS) läuft alles, nur ohne Speicher', async () => {
  const s = deviceStore('t', 5, undefined);
  assert.equal((await s.open('v|1')).size, 0);
  s.put('a', 1);
});
