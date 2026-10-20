// Service Worker (public/sw.js) in einer nachgebauten Umgebung: Vorab-Speicher, Aufräumen nach einem Update,
// Begrenzung des Daten-Speichers und was als App-Seite gespeichert wird.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../public/sw.js', import.meta.url), 'utf8');
const ORIGIN = 'https://kompanie.example';
const SCOPE = `${ORIGIN}/app/`;
const ASSETS = `${ORIGIN}/wp-content/plugins/kompanie-app/app/`;
const API = `${ORIGIN}/wp-json/kompanie-app/v1/`;

/** Speicher wie im Browser (CacheStorage), Einträge in der Reihenfolge des Speicherns. */
class FakeCache {
  constructor() {
    this.entries = new Map();
  }
  async match(req) {
    const r = this.entries.get(url(req));
    return r ? r.clone() : undefined;
  }
  async put(req, res) {
    this.entries.set(url(req), res.clone());
  }
  async delete(req) {
    return this.entries.delete(url(req));
  }
  async keys() {
    return [...this.entries.keys()].map((u) => new Request(u));
  }
}

class FakeCacheStorage {
  constructor() {
    this.caches = new Map();
  }
  async open(name) {
    if (!this.caches.has(name)) this.caches.set(name, new FakeCache());
    return this.caches.get(name);
  }
  async keys() {
    return [...this.caches.keys()];
  }
  async delete(name) {
    return this.caches.delete(name);
  }
  async match(req) {
    for (const c of this.caches.values()) {
      const r = await c.match(req);
      if (r) return r;
    }
    return undefined;
  }
}

const url = (req) => (typeof req === 'string' ? req : req.url);

/** Antwort wie von fetch(): type 'basic' (gleiche Herkunft). */
function response(body, type = 'application/javascript', status = 200) {
  const res = new Response(body, { status, headers: { 'Content-Type': type } });
  Object.defineProperty(res, 'type', { value: 'basic' });
  return res;
}

/**
 * Lädt sw.js mit eingesetzter Liste (wie der Build) und gibt Hilfen zum Auslösen der Ereignisse zurück.
 * `net` beantwortet fetch(): Adresse → Antwort; fehlt sie, gibt es einen Netzfehler.
 */
function load({ build = 'b2', precache = [], net = {}, storage = new FakeCacheStorage() } = {}) {
  const listeners = {};
  const fetched = [];
  const source = SOURCE.replace("const BUILD = 'dev';", `const BUILD = '${build}';`).replace(
    'const PRECACHE = [];',
    `const PRECACHE = ${JSON.stringify(precache)};`,
  );
  assert.notEqual(source, SOURCE, 'Platzhalter in sw.js gefunden');
  const self = {
    location: { href: `${ORIGIN}/app/sw.js?api=${encodeURIComponent(API)}&assets=${encodeURIComponent(ASSETS)}`, origin: ORIGIN },
    registration: { scope: SCOPE },
    clients: { claim: async () => undefined },
    skipWaiting: async () => undefined,
    addEventListener: (type, fn) => (listeners[type] = fn),
  };
  const context = {
    self,
    caches: storage,
    URL,
    Request,
    Response,
    Promise,
    setTimeout,
    decodeURIComponent,
    fetch: async (req) => {
      const u = url(req);
      fetched.push(u);
      const make = net[u];
      if (!make) throw new TypeError('Netzfehler');
      return make();
    },
  };
  vm.runInNewContext(source, context);

  /** Ereignis mit waitUntil (install, activate); wartet, bis alles erledigt ist. */
  async function lifecycle(type) {
    const waits = [];
    listeners[type]({ waitUntil: (p) => waits.push(p) });
    await Promise.all(waits);
  }

  /** fetch-Ereignis; gibt die Antwort des Service Workers zurück (undefined: durchgereicht). */
  async function request(u, mode = 'cors') {
    let answer;
    const waits = [];
    listeners.fetch({
      request: { url: u, method: 'GET', mode },
      respondWith: (p) => (answer = p),
      waitUntil: (p) => waits.push(p),
    });
    const res = await answer;
    await Promise.all(waits);
    return res;
  }

  return { storage, fetched, lifecycle, request };
}

test('Installieren: Programmdateien, Icons und App-Seite vorab gespeichert, unveränderte Dateien übernommen', async () => {
  const storage = new FakeCacheStorage();
  // Speicher des vorigen Builds: Viewer-a.js ist unverändert (gleicher Hash), Alt-x.js gibt es nicht mehr.
  await (await storage.open('kompanie-static-b1')).put(ASSETS + 'assets/Viewer-a.js', response('viewer'));
  await (await storage.open('kompanie-static-b1')).put(ASSETS + 'assets/Alt-x.js', response('alt'));
  const precache = ['assets/index-n.js', 'assets/Verwaltung-n.js', 'assets/Viewer-a.js', 'icons/icon-192.png'];
  const net = {
    [ASSETS + 'assets/index-n.js']: () => response('index'),
    [ASSETS + 'assets/Verwaltung-n.js']: () => response('verwaltung'),
    [ASSETS + 'icons/icon-192.png']: () => response('png', 'image/png'),
    [SCOPE]: () => response('<html>neu</html>', 'text/html; charset=utf-8'),
  };
  const sw = load({ build: 'b2', precache, net, storage });
  await sw.lifecycle('install');

  const files = (await (await storage.open('kompanie-static-b2')).keys()).map((r) => r.url.slice(ASSETS.length)).sort();
  assert.deepEqual(files, [...precache].sort());
  assert.ok(!sw.fetched.includes(ASSETS + 'assets/Viewer-a.js'), 'unveränderte Datei nicht erneut geladen');
  const page = await (await storage.open('kompanie-data-v2')).match(SCOPE);
  assert.equal(await page.text(), '<html>neu</html>', 'App-Seite der neuen Version gespeichert');

  // Aktivieren: nur die Speicher dieses Builds und die Daten bleiben.
  await storage.open('kompanie-static-v2');
  await storage.open('kompanie-docs-v1');
  await storage.open('kompanie-badges-v1');
  await storage.open('kompanie-data-v1');
  await sw.lifecycle('activate');
  assert.deepEqual((await storage.keys()).sort(), ['kompanie-badges-v1', 'kompanie-data-v2', 'kompanie-docs-v1', 'kompanie-static-b2']);
});

test('Installieren ohne Netz für einzelne Dateien bricht nicht ab', async () => {
  const sw = load({ precache: ['assets/index-n.js', 'assets/Fehlt-n.js'], net: { [ASSETS + 'assets/index-n.js']: () => response('i') } });
  await sw.lifecycle('install');
  const files = (await (await sw.storage.open('kompanie-static-b2')).keys()).map((r) => r.url);
  assert.deepEqual(files, [ASSETS + 'assets/index-n.js']);
});

test('Programmdateien und Icons der Liste kommen aus dem Speicher, auch offline', async () => {
  const sw = load({
    precache: ['assets/index-n.js', 'icons/icon-192.png'],
    net: { [ASSETS + 'assets/index-n.js']: () => response('index'), [ASSETS + 'icons/icon-192.png']: () => response('png', 'image/png') },
  });
  await sw.lifecycle('install');
  const before = sw.fetched.length;
  assert.equal(await (await sw.request(ASSETS + 'assets/index-n.js')).text(), 'index');
  assert.equal(await (await sw.request(ASSETS + 'icons/icon-192.png')).text(), 'png');
  assert.equal(sw.fetched.length, before, 'ohne Netz beantwortet');
  // Icons außerhalb der Liste gehen nicht über den Programmspeicher.
  assert.equal(await sw.request(ASSETS + 'icons/icon-512.png'), undefined);
});

test('Als App-Seite wird nur HTML gespeichert', async () => {
  const sw = load({
    net: {
      [`${SCOPE}foto/3/large`]: () => response('jpeg', 'image/jpeg'),
      [`${SCOPE}installieren/`]: () => response('<html>app</html>', 'text/html'),
    },
  });
  await sw.request(`${SCOPE}foto/3/large`, 'navigate');
  assert.equal(await (await sw.storage.open('kompanie-data-v2')).match(SCOPE), undefined, 'Foto ist keine App-Seite');
  await sw.request(`${SCOPE}installieren/`, 'navigate');
  assert.equal(await (await (await sw.storage.open('kompanie-data-v2')).match(SCOPE)).text(), '<html>app</html>');
});

test('App-Seite ohne Netz aus dem Speicher; Daten-Speicher begrenzt, die App-Seite bleibt', async () => {
  const net = { [SCOPE]: () => response('<html>app</html>', 'text/html') };
  for (let i = 0; i < 130; i++) net[`${API}events?i=${i}`] = () => response('[]', 'application/json');
  const sw = load({ net });
  await sw.request(SCOPE, 'navigate');
  for (let i = 0; i < 130; i++) await sw.request(`${API}events?i=${i}`);
  await sw.lifecycle('activate'); // räumt ebenfalls auf
  const keys = (await (await sw.storage.open('kompanie-data-v2')).keys()).map((r) => r.url);
  assert.ok(keys.includes(SCOPE), 'App-Seite bleibt');
  assert.equal(keys.length, 101, 'höchstens 100 Daten-Einträge und die App-Seite');
  assert.ok(keys.includes(`${API}events?i=129`) && !keys.includes(`${API}events?i=0`), 'die ältesten fallen heraus');

  // Ohne Netz: gespeicherte App-Seite und Daten.
  delete net[SCOPE];
  delete net[`${API}events?i=129`];
  assert.equal(await (await sw.request(`${SCOPE}`, 'navigate')).text(), '<html>app</html>');
  assert.equal(await (await sw.request(`${API}events?i=129`)).text(), '[]');
});

test('Verwaltung (/admin/) wird nie gespeichert', async () => {
  const sw = load({ net: { [`${API}admin/backup`]: () => response('{"geheim":1}', 'application/json') } });
  assert.equal(await sw.request(`${API}admin/backup`), undefined);
  assert.equal((await (await sw.storage.open('kompanie-data-v2')).keys()).length, 0);
});

test('Rückkehr von Google wird nie aus dem Speicher beantwortet', async () => {
  const net = { [SCOPE]: () => response('<html>app</html>', 'text/html') };
  const sw = load({ net });
  await sw.request(SCOPE, 'navigate'); // App-Seite gespeichert
  assert.equal(await sw.request(`${SCOPE}google/callback?state=s&code=c`, 'navigate'), undefined);
  assert.equal(await sw.request(`${SCOPE}index.php?r=/google/callback&state=s&code=c`, 'navigate'), undefined);
  assert.ok(await sw.request(`${SCOPE}google?x=1`, 'navigate'), 'andere Adressen wie bisher');
});
