// Vorabladen der wahrscheinlich nächsten Ansicht (src/prefetch.ts, Issue #104): eins nach dem anderen, Antippen vor dem
// Leerlauf, nichts in Vorschau/Wartung bzw. im Leerlauf bei saveData oder 2G, Fehler ohne Eintrag unter „Fehler melden“.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cacheKey, dataCache, pendingPrefetch, prefetch, takeFresh } from '../src/dataCache.ts';
import { forgetError, lastError, noteError, resetLastError } from '../src/lastError.ts';
import { createPrefetcher as create, idleNetworkOk, type PrefetcherOptions, type PrefetchSource } from '../src/prefetch.ts';

/** wie viewData.ts, mit Speicher und „Fehler melden“ der App */
const createPrefetcher = (o: Pick<PrefetcherOptions, 'blocked' | 'idleOk' | 'whenIdle'>) => create({ ...o, prefetch, forgetError });

function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => ((resolve = res), (reject = rej)));
  return { promise, resolve, reject };
}

/** Quelle, die mitschreibt, wann sie startet, und erst auf Zuruf antwortet. */
function source(key: string, log: string[]) {
  const d = deferred<unknown>();
  const s: PrefetchSource = {
    key,
    deps: [],
    load: () => {
      log.push(key);
      return d.promise;
    },
  };
  return { s, d };
}

const tick = () => new Promise((r) => setTimeout(r, 0));

test('Leerlauf: nicht offline, nicht bei saveData, nicht bei 2G', () => {
  assert.equal(idleNetworkOk({ onLine: true }), true, 'ohne navigator.connection (Safari)');
  assert.equal(idleNetworkOk({ onLine: true, connection: { effectiveType: '4g' } }), true);
  assert.equal(idleNetworkOk({ onLine: false }), false);
  assert.equal(idleNetworkOk({ onLine: true, connection: { saveData: true, effectiveType: '4g' } }), false);
  assert.equal(idleNetworkOk({ onLine: true, connection: { effectiveType: '2g' } }), false);
  assert.equal(idleNetworkOk({ onLine: true, connection: { effectiveType: 'slow-2g' } }), false);
});

test('Höchstens eine Vorab-Anfrage gleichzeitig, Antippen kommt vor dem Leerlauf', async () => {
  dataCache.clear();
  const log: string[] = [];
  const a = source('a', log);
  const b = source('b', log);
  const c = source('c', log);
  const p = createPrefetcher({ blocked: () => false, idleOk: () => true, whenIdle: () => Promise.resolve() });
  const done = p.idle([a.s, b.s]);
  await tick();
  assert.deepEqual(log, ['a'], 'b wartet, bis a fertig ist');
  void p.tap([c.s]);
  a.d.resolve(['A']);
  await tick();
  assert.deepEqual(log, ['a', 'c'], 'angetippt: vor b');
  c.d.resolve(['C']);
  await tick();
  assert.deepEqual(log, ['a', 'c', 'b']);
  b.d.resolve(['B']);
  await done;
  assert.deepEqual(dataCache.get(cacheKey('a', [])), ['A']);
  assert.deepEqual(dataCache.get(cacheKey('b', [])), ['B']);
  assert.deepEqual(dataCache.get(cacheKey('c', [])), ['C']);
});

test('Vorschau/Wartung: gar nicht; saveData/2G: im Leerlauf nicht, beim Antippen schon', async () => {
  dataCache.clear();
  const log: string[] = [];
  const blocked = createPrefetcher({ blocked: () => true, idleOk: () => true, whenIdle: () => Promise.resolve() });
  await blocked.idle([source('x', log).s]);
  await blocked.tap([source('y', log).s]);
  assert.deepEqual(log, []);

  const slow = createPrefetcher({ blocked: () => false, idleOk: () => false, whenIdle: () => Promise.resolve() });
  await slow.idle([source('z', log).s]);
  assert.deepEqual(log, []);
  const t = source('t', log);
  const done = slow.tap([t.s]);
  t.d.resolve(1);
  await done;
  assert.deepEqual(log, ['t']);
});

test('Fehler beim Vorabladen: kein Eintrag unter „Fehler melden“, eine frühere Meldung bleibt', async () => {
  dataCache.clear();
  resetLastError();
  noteError('Früher');
  const s: PrefetchSource = {
    key: 'documents',
    deps: [],
    // wie api.ts: Fehler vermerken und am ApiError festhalten
    load: () => Promise.reject(Object.assign(new Error('Keine Verbindung'), { noted: noteError('Keine Verbindung zum Server') })),
  };
  const p = createPrefetcher({ blocked: () => false, idleOk: () => true, whenIdle: () => Promise.resolve() });
  await p.tap([s]);
  assert.equal(lastError()?.message, 'Früher');
  assert.equal(dataCache.has(cacheKey('documents', [])), false);
});

test('Die Ansicht wartet auf eine laufende Vorab-Anfrage; gerade geladen gilt einmal als frisch', async () => {
  dataCache.clear();
  const log: string[] = [];
  const a = source('news', log);
  const p = createPrefetcher({ blocked: () => false, idleOk: () => true, whenIdle: () => Promise.resolve() });
  const done = p.tap([a.s]);
  await tick();
  const k = cacheKey('news', []);
  assert.ok(pendingPrefetch(k), 'läuft');
  a.d.resolve({ items: [] });
  await done;
  assert.equal(pendingPrefetch(k), undefined);
  assert.equal(takeFresh(k), true);
  assert.equal(takeFresh(k), false, 'nur einmal');
});

test('Eine eigene Änderung während des Vorabladens: nichts ablegen, nicht frisch, nicht abwarten', async () => {
  dataCache.clear();
  const log: string[] = [];
  const a = source('me', log);
  const p = createPrefetcher({ blocked: () => false, idleOk: () => true, whenIdle: () => Promise.resolve() });
  const done = p.tap([a.s]);
  await tick();
  const k = cacheKey('me', []);
  dataCache.clear(); // z. B. POST /points
  assert.equal(pendingPrefetch(k), undefined, 'die Ansicht fragt selbst');
  a.d.resolve({ id: 1 });
  await done;
  assert.equal(dataCache.has(k), false);
  assert.equal(takeFresh(k), false);
});

test('Frisch nur bis FRESH_MS nach dem Vorabladen', async () => {
  dataCache.clear();
  const p = createPrefetcher({ blocked: () => false, idleOk: () => true, whenIdle: () => Promise.resolve() });
  await p.tap([{ key: 'galerie', deps: [], load: () => Promise.resolve({ years: [] }) }]);
  assert.equal(takeFresh(cacheKey('galerie', []), Date.now() + 60_000), false);
});
