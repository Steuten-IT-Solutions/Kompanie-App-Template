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
function load({ build = 'b2', precache = [], net = {}, storage = new FakeCacheStorage(), now = () => 0 } = {}) {
  const listeners = {};
  const fetched = [];
  const messages = [];
  const source = SOURCE.replace("const BUILD = 'dev';", `const BUILD = '${build}';`).replace(
    'const PRECACHE = [];',
    `const PRECACHE = ${JSON.stringify(precache)};`,
  );
  assert.notEqual(source, SOURCE, 'Platzhalter in sw.js gefunden');
  const self = {
    location: { href: `${ORIGIN}/app/sw.js?api=${encodeURIComponent(API)}&assets=${encodeURIComponent(ASSETS)}`, origin: ORIGIN },
    registration: { scope: SCOPE },
    clients: { claim: async () => undefined, matchAll: async () => [{ postMessage: (m) => messages.push(m) }] },
    skipWaiting: async () => undefined,
    addEventListener: (type, fn) => (listeners[type] = fn),
  };
  const context = {
    self,
    caches: storage,
    URL,
    Request,
    Response,
    Headers,
    Promise,
    setTimeout,
    // Uhr zum Vorstellen (FRESH_MS); die übrigen Teile von Date wie gewohnt.
    Date: Object.assign(
      function (...a) {
        return new Date(...a);
      },
      { now: () => now() },
    ),
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
  async function request(u, mode = 'cors', headers = {}, method = 'GET') {
    let answer;
    const waits = [];
    listeners.fetch({
      request: { url: u, method, mode, headers: new Headers(headers) },
      respondWith: (p) => (answer = p),
      waitUntil: (p) => waits.push(p),
    });
    const res = await answer;
    await Promise.all(waits);
    return res;
  }

  return { storage, fetched, messages, lifecycle, request };
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

test('Dateianhänge werden immer durchgereicht, auch als Navigation (nie als App-Seite gespeichert)', async () => {
  const uid = 'a'.repeat(32);
  const net = { [SCOPE]: () => response('<html>app</html>', 'text/html') };
  const sw = load({ net });
  await sw.request(SCOPE, 'navigate'); // App-Seite gespeichert
  assert.equal(await sw.request(`${SCOPE}anhang/${uid}`, 'navigate'), undefined);
  assert.equal(await sw.request(`${SCOPE}anhang/${uid}`), undefined);
  assert.equal(await sw.request(`${ORIGIN}/index.php?r=/anhang/${uid}`, 'navigate'), undefined);
  assert.equal(await sw.request(`${ORIGIN}/?kompanie_app=anhang/${uid}`, 'navigate'), undefined);
  const pages = (await sw.storage.keys()).length;
  assert.ok(pages >= 1);
  assert.ok(await sw.request(`${SCOPE}anhang/kein-anhang`, 'navigate'), 'andere Adressen wie bisher');
});

test('Vorschau „App als Rolle ansehen“ (Issue #63): weder gespeichert noch aus dem Speicher', async () => {
  const doc = `${SCOPE}dokument/7?v=abc`;
  const badge = `${SCOPE}abzeichen/2?v=x&s=128`;
  const net = {
    [`${API}events`]: () => response('[1]', 'application/json'),
    [`${doc}&vorschau=member`]: () => response('pdf', 'application/pdf'),
    [`${badge}&vorschau=member`]: () => response('png', 'image/png'),
    [doc]: () => response('pdf-admin', 'application/pdf'),
  };
  const sw = load({ net });
  // Admin-Ansicht gespeichert …
  await sw.request(`${API}events`);
  await sw.request(doc);
  // … in der Vorschau nicht verwendet und nichts dazugelegt.
  assert.equal(await sw.request(`${API}events`, 'cors', { 'X-Kompanie-Preview': 'member' }), undefined, 'API mit Header: durchgereicht');
  assert.equal(await sw.request(`${doc}&vorschau=member`), undefined, 'Dokument mit Parameter: durchgereicht');
  assert.equal(await sw.request(`${badge}&vorschau=member`), undefined, 'Abzeichen mit Parameter: durchgereicht');
  assert.equal(await sw.request(`${ORIGIN}/index.php?r=/foto/3/thumb&vorschau=%40public`), undefined);
  const docs = (await (await sw.storage.open('kompanie-docs-v1')).keys()).map((r) => r.url);
  assert.deepEqual(docs, [doc], 'gespeichertes Dokument des Admins bleibt, keine Vorschau-Fassung');
  assert.equal((await (await sw.storage.open('kompanie-badges-v1')).keys()).length, 0);
});

const STALE_OK = { 'X-Kompanie-Stale-Ok': '1', 'X-Kompanie-User': '5' };
const json =
  (body, status = 200) =>
  () =>
    response(body, 'application/json', status);

test('Mit X-Kompanie-Stale-Ok (Issue #108): gespeicherte Antwort sofort, aus dem Netz im Hintergrund, Meldung bei Änderung', async () => {
  let t = 0;
  const net = { [`${API}members`]: json('[1]') };
  const sw = load({ net, now: () => t });
  // Noch nichts gespeichert: aus dem Netz (und gespeichert).
  assert.equal(await (await sw.request(`${API}members`, 'cors', STALE_OK)).text(), '[1]');
  assert.equal(sw.fetched.length, 1);

  // Neue Daten auf dem Server; nach FRESH_MS: zuerst der gespeicherte Stand, im Hintergrund der neue, dann die Meldung.
  net[`${API}members`] = json('[1,2]');
  t = 20_000;
  assert.equal(await (await sw.request(`${API}members`, 'cors', STALE_OK)).text(), '[1]');
  assert.equal(sw.fetched.length, 2, 'im Hintergrund aus dem Netz');
  assert.equal(JSON.stringify(sw.messages), JSON.stringify([{ type: 'kompanie-data', url: `${API}members` }]));

  // Neu laden nach der Meldung: frisch aus dem Speicher, ohne zweite Anfrage.
  assert.equal(await (await sw.request(`${API}members`, 'cors', STALE_OK)).text(), '[1,2]');
  assert.equal(sw.fetched.length, 2);

  // Unverändert: keine weitere Meldung.
  t = 40_000;
  await sw.request(`${API}members`, 'cors', STALE_OK);
  assert.equal(sw.fetched.length, 3);
  assert.equal(sw.messages.length, 1);
});

test('Mit X-Kompanie-Stale-Ok: Läuft die Abfrage im Hintergrund schon, fragt er danach noch einmal (Issue #68)', async () => {
  let t = 0;
  const net = { [`${API}board`]: json('["alt"]') };
  const sw = load({ net, now: () => t });
  await sw.request(`${API}board`, 'cors', STALE_OK);

  // Die Abfrage im Hintergrund ist schon unterwegs (Server antwortet noch mit dem alten Stand), da ändert jemand die
  // Nachricht auf einem anderen Gerät, und die App fragt erneut (z. B. nach dem Neuladen).
  let release;
  net[`${API}board`] = () => new Promise((resolve) => (release = () => resolve(response('["alt"]', 'application/json'))));
  t = 20_000;
  const first = sw.request(`${API}board`, 'cors', STALE_OK);
  await new Promise((r) => setTimeout(r, 0));
  assert.equal(sw.fetched.length, 2, 'im Hintergrund unterwegs');
  const second = sw.request(`${API}board`, 'cors', STALE_OK);
  await new Promise((r) => setTimeout(r, 0));
  assert.equal(sw.fetched.length, 2, 'keine zweite Abfrage gleichzeitig');
  net[`${API}board`] = json('["neu"]');
  release();
  assert.equal(await (await first).text(), '["alt"]');
  assert.equal(await (await second).text(), '["alt"]');

  // Die laufende Abfrage kam mit dem alten Stand zurück; die zweite Anfrage bekommt trotzdem den neuen, samt Meldung.
  assert.equal(sw.fetched.length, 3, 'danach noch einmal aus dem Netz');
  assert.equal(JSON.stringify(sw.messages), JSON.stringify([{ type: 'kompanie-data', url: `${API}board` }]));
  assert.equal(await (await sw.request(`${API}board`, 'cors', STALE_OK)).text(), '["neu"]');
});

test('Mit X-Kompanie-Stale-Ok: Fehler im Hintergrund wird gemeldet, dann aus dem Netz; ohne Netz bleibt der Stand', async () => {
  let t = 0;
  const net = { [`${API}events`]: json('["a"]') };
  const sw = load({ net, now: () => t });
  await sw.request(`${API}events`, 'cors', STALE_OK);

  // Wartungsmodus: gespeicherter Stand sofort, die Meldung führt zur Anfrage ans Netz, die den Fehler zeigt.
  net[`${API}events`] = json('{"code":"maintenance"}', 503);
  t = 20_000;
  assert.equal(await (await sw.request(`${API}events`, 'cors', STALE_OK)).text(), '["a"]');
  assert.equal(sw.messages.length, 1);
  assert.equal((await sw.request(`${API}events`, 'cors', STALE_OK)).status, 503);
  const stored = await (await sw.storage.open('kompanie-data-v2')).match(`${API}events`);
  assert.equal(await stored.text(), '["a"]', 'Fehler überschreibt den Speicher nicht');

  // Ohne Netz: gespeicherter Stand, keine Meldung.
  delete net[`${API}events`];
  t = 40_000;
  assert.equal(await (await sw.request(`${API}events`, 'cors', STALE_OK)).text(), '["a"]');
  assert.equal(sw.messages.length, 1);
});

test('Ohne X-Kompanie-Stale-Ok wie bisher zuerst aus dem Netz (z. B. nach einer eigenen Änderung, CSRF-Token)', async () => {
  const net = { [`${API}members`]: json('[1]') };
  const sw = load({ net });
  await sw.request(`${API}members`);
  net[`${API}members`] = json('[1,2]');
  assert.equal(await (await sw.request(`${API}members`)).text(), '[1,2]');
  assert.equal(sw.messages.length, 0);
  // Verwaltung auch mit dem Header nie aus dem Speicher.
  assert.equal(await sw.request(`${API}admin/settings`, 'cors', STALE_OK), undefined);
});

test('Mit X-Kompanie-Stale-Ok: nur für dieselbe Person aus dem Speicher', async () => {
  const net = { [`${API}members`]: json('[1]') };
  const sw = load({ net, now: () => 1 });
  await sw.request(`${API}members`, 'cors', STALE_OK);

  // Andere Person (Konto gewechselt): nicht die gespeicherten Daten der Person davor, sondern aus dem Netz.
  net[`${API}members`] = json('[2]');
  assert.equal(await (await sw.request(`${API}members`, 'cors', { ...STALE_OK, 'X-Kompanie-User': '6' })).text(), '[2]');
  // Ohne Angabe der Person (direkter Aufruf, z. B. CSRF-Token) ebenso.
  net[`${API}members`] = json('[3]');
  assert.equal(await (await sw.request(`${API}members`, 'cors', { 'X-Kompanie-Stale-Ok': '1' })).text(), '[3]');
});

test('Nach einer eigenen Änderung: vorher Gespeichertes erst aus dem Netz, auch nach einem Neustart des Service Workers', async () => {
  let t = 1000;
  const storage = new FakeCacheStorage();
  const net = { [`${API}titles`]: json('["A","B"]'), [`${API}members`]: json('[1]') };
  const sw = load({ net, storage, now: () => t });
  await sw.request(`${API}titles`, 'cors', STALE_OK);
  await sw.request(`${API}members`, 'cors', STALE_OK);

  // Reihenfolge geändert (PUT). Der Push-Status beim Start zählt nicht als Änderung.
  t = 2000;
  await sw.request(`${API}push/status`, 'cors', {}, 'POST');
  t = 3000;
  assert.equal(await sw.request(`${API}titles/order`, 'cors', {}, 'PUT'), undefined, 'Änderung durchgereicht');
  net[`${API}titles`] = json('["B","A"]');

  // Neustart (z. B. Seite neu geladen, Service Worker beendet): Der Zeitpunkt der Änderung ist gespeichert.
  t = 30_000;
  const again = load({ net, storage, now: () => t });
  const before = again.fetched.length;
  assert.equal(await (await again.request(`${API}titles`, 'cors', STALE_OK)).text(), '["B","A"]', 'nicht der Stand vor der Änderung');
  assert.equal(again.fetched.length, before + 1);
  assert.equal(again.messages.length, 0);

  // Danach frisch gespeichert: wieder sofort aus dem Speicher (im Hintergrund aufgefrischt).
  t = 60_000;
  assert.equal(await (await again.request(`${API}titles`, 'cors', STALE_OK)).text(), '["B","A"]');
  assert.equal(again.fetched.length, before + 2, 'im Hintergrund aus dem Netz');
});

test('Kurz nach einer Änderung (z. B. Anmeldung) kommt die App-Seite aus dem Netz, nicht nach dem Zeitlimit aus dem Speicher', async () => {
  let release;
  const net = { [SCOPE]: () => response('<html>alt</html>', 'text/html') };
  const sw = load({ net });
  await sw.request(SCOPE, 'navigate');
  net[SCOPE] = () => new Promise((resolve) => (release = () => resolve(response('<html>neu</html>', 'text/html'))));
  // Ohne Änderung: nach dem Zeitlimit die gespeicherte Seite (schlechtes Netz).
  assert.equal(await (await sw.request(SCOPE, 'navigate')).text(), '<html>alt</html>');
  await sw.request(`${API}auth/login`, 'cors', {}, 'POST');
  const page = sw.request(SCOPE, 'navigate');
  await new Promise((r) => setTimeout(r, 2700)); // länger als NAVIGATION_TIMEOUT_MS
  release();
  assert.equal(await (await page).text(), '<html>neu</html>');
});
