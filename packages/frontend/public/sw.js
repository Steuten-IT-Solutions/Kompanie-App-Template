/* Service Worker der App (Bereich: Adresse der App).
 *
 * - Programmdateien (Hash-Dateinamen, auch die erst bei Bedarf geladenen Teile) und Icons → beim Installieren
 *   vorab gespeichert (Liste setzt der Build ein), danach zuerst aus dem Speicher. Jeder Build hat einen eigenen
 *   Speicher; unveränderte Dateien übernimmt er aus dem der vorigen Version, statt sie neu zu laden. Beim Aktivieren
 *   werden die Speicher der alten Versionen gelöscht.
 * - App-Seite → beim Installieren gespeichert (passt so nach einem Update zu den neuen Programmdateien und ist schon
 *   nach dem ersten Besuch offline da); beim Öffnen zuerst aus dem Netz, aber höchstens NAVIGATION_TIMEOUT_MS warten,
 *   wenn eine gespeicherte Fassung da ist (schlechtes Mobilnetz); ohne Netz die gespeicherte. Nur HTML gilt als
 *   App-Seite (nicht z. B. ein direkt geöffnetes Foto).
 * - API-Antworten → zuerst aus dem Netz; ohne Netz die letzte Version (höchstens DATA_MAX Einträge, die ältesten
 *   fallen zuerst heraus; die App-Seite zählt nicht mit).
 *   Mit dem Header X-Kompanie-Stale-Ok (setzt die App bei GET, Issue #108): Ist eine Version für dieselbe Person
 *   gespeichert (X-Kompanie-User, beim Speichern an der Antwort vermerkt), kommt sie sofort; die Antwort aus dem Netz
 *   holt der Service Worker im Hintergrund. So sieht nach einem Wechsel des Kontos niemand die Daten der Person davor.
 *   Ist die Antwort anders (oder ein Fehler), meldet er das den Fenstern der App ({type: 'kompanie-data', url}), die
 *   die Ansicht dann neu lädt: Dieses Neuladen (bis FRESH_MS danach) kommt aus dem Speicher, ohne zweite Anfrage; nach
 *   einem Fehler geht es direkt ans Netz, damit die App ihn sieht. Jede eigene Änderung (API-Anfrage außer GET; nicht
 *   die Abfrage des Push-Status beim Start) beendet das sofort, und was vor ihr gespeichert wurde, kommt erst wieder
 *   sofort, wenn es danach einmal aus dem Netz kam (Zeitpunkt der Änderung bleibt gespeichert, auch über ein Neuladen
 *   hinweg).
 * - Dokumente (<App>/dokument/<id>?v=<Fassung>, Issue #2) → zuerst aus dem Speicher: Die Adresse wechselt mit
 *   jeder neuen Fassung. Was einmal geöffnet wurde, ist so offline verfügbar; gespeichert wird je Dokument nur die
 *   aktuelle Fassung. Direkt aufgerufen (nicht über fetch) nie als App-Seite speichern.
 * - Abzeichen der Dienstgrade (<App>/abzeichen/<id>?v=<Fassung>&s=<Größe>, Issue #17) → zuerst aus dem Speicher,
 *   ebenso versioniert; je Dienstgrad und Größe nur die aktuelle Fassung. Einmal geladen, sind sie offline da.
 * - Bildschirmfotos des Handbuchs (<Programmdateien>/handbuch/bilder/<name>.jpg?v=<Version>) → zuerst aus dem Speicher;
 *   je Foto nur die Fassung der aktuellen Version. Vorab geladen wird nichts (die App bliebe sonst nicht klein); was
 *   einmal in der Hilfe angesehen wurde, ist offline da.
 * - Rückkehr von Google („Mit Google verbinden“, <App>/google/callback bzw. ?r=/google/callback) → durchreichen: Der
 *   Server tauscht dort den Code ein und leitet weiter; eine gespeicherte App-Seite nach dem Zeitlimit wäre falsch.
 * - Vorschau „App als Rolle ansehen“ (Issue #63): Anfragen mit dem Header X-Kompanie-Preview (API) oder dem Parameter
 *   vorschau (Bilder, Dokumente, Abzeichen) → durchreichen, nie speichern und nie aus dem Speicher beantworten: Sie
 *   gelten nur für diese Ansicht und dürfen sich nicht mit den Daten des Admins mischen.
 * - Verwaltung für Admins (/admin/…, z. B. die Datensicherung) → nie speichern. (Ein no-store der
 *   Server gilt hier nicht: Es soll Zwischenspeicher im Netz verhindern; für den Offline-Betrieb
 *   speichert die App ihre Daten bewusst selbst und löscht sie beim Abmelden.)
 * Wo API und Programmdateien liegen, steht in den Parametern ?api=…&assets=…
 * Beim Abmelden löscht die App alle Speicher.
 */
// Beides setzt der Build ein (packages/frontend/vite.config.ts).
const BUILD = 'dev';
const PRECACHE = [];
const STATIC_CACHE = 'kompanie-static-' + BUILD;
// v2: v1 konnte Antworten von /admin/ enthalten (z. B. eine Datensicherung); beim Aktivieren gelöscht.
const DATA_CACHE = 'kompanie-data-v2';
const DOCS_CACHE = 'kompanie-docs-v1';
const BADGES_CACHE = 'kompanie-badges-v1';
const HELP_IMAGES_CACHE = 'kompanie-hilfebilder-v1';
// Gerüste der Seiten, von der App selbst gespeichert (src/skeleton.tsx); bleiben bei einem Update.
const VIEWS_CACHE = 'kompanie-ansicht-';
const params = new URL(self.location.href).searchParams;
const API = params.get('api') || '';
const ASSETS = params.get('assets') || '';
const NAVIGATION_TIMEOUT_MS = 2500;
const DATA_MAX = 100;
const FRESH_MS = 10000;
/** Gemeldete Adressen (neue Daten oder Fehler im Hintergrund): Adresse → { at, ok }, für das Neuladen danach. */
const announced = new Map();
/**
 * Laufende Abfragen im Hintergrund je Adresse: keine zweite gleichzeitig; wer währenddessen fragt, bekommt danach eine
 * weitere ({ done, again }).
 */
const revalidating = new Map();
const USER_HEADER = 'X-Kompanie-User';
const STORED_AT_HEADER = 'X-Kompanie-Stored-At';
/**
 * Letzte eigene Änderung (API-Anfrage außer GET, z. B. Anmeldung); null: noch nicht aus dem Speicher gelesen (nach
 * einem Neustart des Service Workers). Kurz danach kommt die App-Seite nicht aus dem Speicher.
 */
let changedAt = null;
const CHANGE_WAIT_MS = 60000;
const CHANGED_KEY = self.registration.scope + '?kompanie-changed';

async function lastChange() {
  if (changedAt === null) {
    const marker = await (await caches.open(DATA_CACHE)).match(CHANGED_KEY);
    changedAt = Math.max(changedAt ?? -Infinity, marker ? Number(await marker.text()) || 0 : -Infinity);
  }
  return changedAt;
}
// Gespeichert wird je Adresse genau eine Antwort. Manche Hoster schicken „Vary: accept“ auf jeder Antwort; ohne
// ignoreVary passte ein Eintrag nur zu einer Anfrage mit genau denselben Kopfzeilen (die App fragt mal mit, mal ohne).
const MATCH = { ignoreVary: true };

/**
 * Programmdateien dieses Builds vorab speichern. Dateien mit gleichem Namen (Hash) sind unverändert: aus dem Speicher
 * der vorigen Version übernehmen. Einzelne Fehler verhindern das Update nicht.
 */
async function precache() {
  if (ASSETS === '' || PRECACHE.length === 0) return;
  const cache = await caches.open(STATIC_CACHE);
  await Promise.allSettled(
    PRECACHE.map(async (path) => {
      const url = ASSETS + path;
      if (await cache.match(url)) return;
      const previous = path.startsWith('assets/') ? await caches.match(url) : undefined;
      if (previous) return cache.put(url, previous);
      const res = await fetch(url);
      if (cacheable(res)) await cache.put(url, res);
    }),
  );
}

/** App-Seite in der aktuellen Fassung speichern (mit Anmeldung, wie beim Öffnen). */
async function precachePage() {
  const res = await fetch(self.registration.scope, { credentials: 'same-origin', cache: 'no-store' });
  if (isPage(res)) await store(self.registration.scope, res);
}

/** Daten-Speicher begrenzen: die ältesten Einträge zuerst löschen (Reihenfolge des Speicherns); die App-Seite bleibt. */
async function trimData() {
  const cache = await caches.open(DATA_CACHE);
  const keys = (await cache.keys()).filter((k) => k.url !== self.registration.scope && k.url !== CHANGED_KEY);
  await Promise.all(keys.slice(0, Math.max(0, keys.length - DATA_MAX)).map((k) => cache.delete(k)));
}

self.addEventListener('install', (event) => {
  event.waitUntil(Promise.allSettled([precache(), precachePage()]).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter(
              (k) => ![STATIC_CACHE, DATA_CACHE, DOCS_CACHE, BADGES_CACHE, HELP_IMAGES_CACHE].includes(k) && !k.startsWith(VIEWS_CACHE),
            )
            .map((k) => caches.delete(k)),
        ),
      )
      .then(() => trimData())
      .then(() => self.clients.claim()),
  );
});

function isStatic(url) {
  if (ASSETS === '' || !url.href.startsWith(ASSETS)) return false;
  // Programmdateien mit Hash im Namen; Icons (feste Namen) nur aus der Liste dieses Builds (ein neues Logo ist ein
  // neuer Build mit eigenem Speicher).
  const path = url.href.slice(ASSETS.length);
  return (path.startsWith('assets/') && /\.(js|css|png|svg|woff2?|webp|jpg)$/.test(url.pathname)) || PRECACHE.includes(path);
}

function isApi(url) {
  return API !== '' && url.href.startsWith(API);
}

/** Adresse dekodiert: Pfade auch in der Form index.php?r=/api/v1/… (ggf. kodiert) erkennen. */
function decodedHref(url) {
  try {
    return decodeURIComponent(url.href);
  } catch {
    return url.href; // unvollständig kodiert: wie angegeben prüfen
  }
}

function isAdmin(url) {
  return decodedHref(url).includes('/admin/');
}

/** Rückkehr von Google nach „Mit Google verbinden“: <App>/google/callback oder ?r=/google/callback */
function isGoogleCallback(url) {
  const scope = new URL(self.registration.scope);
  if (url.pathname.startsWith(scope.pathname) && /^google\/callback\/?$/.test(url.pathname.slice(scope.pathname.length))) return true;
  return /^\/?google\/callback$/.test(url.searchParams.get('r') || '');
}

/** Vorschau „App als Rolle ansehen“: Header der App oder Parameter in der Adresse (auch in ?r=… bzw. kodiert). */
function isPreview(request, url) {
  return request.headers.has('X-Kompanie-Preview') || url.searchParams.has('vorschau');
}

/** Kennung eines Dokuments aus seiner Adresse: <App>/dokument/<id>, ?kompanie_app=dokument/<id> oder ?r=/dokument/<id> */
function documentId(url) {
  const scope = new URL(self.registration.scope);
  if (url.pathname.startsWith(scope.pathname)) {
    const m = url.pathname.slice(scope.pathname.length).match(/^dokument\/(\d+)\/?$/);
    if (m) return m[1];
  }
  const param = url.searchParams.get('kompanie_app') || url.searchParams.get('r') || '';
  const m = param.match(/^\/?dokument\/(\d+)$/);
  return m ? m[1] : null;
}

/** Dokumente: gespeicherte Fassung, sonst laden und ältere Fassungen desselben Dokuments ersetzen. */
async function documentFirst(request, id) {
  const cache = await caches.open(DOCS_CACHE);
  const cached = await cache.match(request, MATCH);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const keys = await cache.keys();
    await Promise.all(keys.filter((k) => documentId(new URL(k.url)) === id).map((k) => cache.delete(k)));
    await cache.put(request, res.clone());
  }
  return res;
}

/**
 * Kennung eines Abzeichens aus seiner Adresse (Dienstgrad und Größe): <App>/abzeichen/<id>, ?kompanie_app=abzeichen/<id>
 * oder ?r=/abzeichen/<id>
 */
function badgeKey(url) {
  const scope = new URL(self.registration.scope);
  let id = null;
  if (url.pathname.startsWith(scope.pathname)) {
    const m = url.pathname.slice(scope.pathname.length).match(/^abzeichen\/(\d+)\/?$/);
    if (m) id = m[1];
  }
  if (id === null) {
    const m = (url.searchParams.get('kompanie_app') || url.searchParams.get('r') || '').match(/^\/?abzeichen\/(\d+)$/);
    if (m) id = m[1];
  }
  return id === null ? null : id + ':' + (url.searchParams.get('s') || '');
}

/** Abzeichen: gespeicherte Fassung, sonst laden und ältere Fassungen desselben Abzeichens (gleiche Größe) ersetzen. */
async function badgeFirst(request, key) {
  const cache = await caches.open(BADGES_CACHE);
  const cached = await cache.match(request, MATCH);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const keys = await cache.keys();
    await Promise.all(keys.filter((k) => badgeKey(new URL(k.url)) === key).map((k) => cache.delete(k)));
    await cache.put(request, res.clone());
  }
  return res;
}

function isHelpImage(url) {
  return ASSETS !== '' && url.href.startsWith(ASSETS + 'handbuch/bilder/') && url.pathname.endsWith('.jpg');
}

/** Bildschirmfotos der Hilfe: gespeicherte Fassung, sonst laden und die Fassung einer früheren Version ersetzen. */
async function helpImageFirst(request) {
  const cache = await caches.open(HELP_IMAGES_CACHE);
  const cached = await cache.match(request, MATCH);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const path = new URL(request.url).pathname;
    const keys = await cache.keys();
    await Promise.all(keys.filter((k) => new URL(k.url).pathname === path).map((k) => cache.delete(k)));
    await cache.put(request, res.clone());
  }
  return res;
}

/** Nur erfolgreiche Antworten: Fehler wie 503 im Wartungsmodus überschreiben nie gespeicherte Daten. */
function cacheable(res) {
  return res && res.ok && !res.redirected && res.type === 'basic';
}

/** Als App-Seite speichern nur HTML (eine direkt geöffnete Adresse in der App kann auch ein Bild sein). */
function isPage(res) {
  return cacheable(res) && (res.headers.get('Content-Type') || '').includes('text/html');
}

async function cacheFirst(request) {
  const cached = await caches.match(request, MATCH);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const cache = await caches.open(STATIC_CACHE);
    cache.put(request, res.clone());
  }
  return res;
}

let puts = 0;

async function store(key, res) {
  const cache = await caches.open(DATA_CACHE);
  // Neu einfügen statt ersetzen: Dann steht der Eintrag hinten und trimData löscht ihn zuletzt.
  await cache.delete(key);
  await cache.put(key, res);
  if (++puts % 20 === 0) await trimData();
}

/**
 * Zuerst aus dem Netz. Mit timeoutMs: Ist eine gespeicherte Fassung da und antwortet das Netz nicht
 * rechtzeitig, kommt die gespeicherte; die Antwort aus dem Netz aktualisiert den Speicher danach trotzdem.
 */
/** Antwort mit der Person, für die sie gilt (Header der App), zum Speichern. */
function tagged(res, request) {
  const headers = new Headers(res.headers);
  headers.set(USER_HEADER, request.headers.get(USER_HEADER) || '');
  headers.set(STORED_AT_HEADER, String(Date.now()));
  return new Response(res.body, { status: res.status, statusText: res.statusText, headers });
}

async function networkFirst(request, fallbackUrl, timeoutMs, event) {
  const key = fallbackUrl || request;
  const network = fetch(request).then((res) => {
    if (fallbackUrl ? isPage(res) : cacheable(res)) {
      const saving = store(key, fallbackUrl ? res.clone() : tagged(res.clone(), request));
      try {
        if (event) event.waitUntil(saving);
      } catch {
        // Antwort ist schon raus (Zeitlimit): Speichern läuft trotzdem weiter.
      }
    }
    return res;
  });
  const cached = caches.match(key, MATCH);
  if (timeoutMs) {
    const fallback = await cached;
    if (fallback) {
      const timer = new Promise((resolve) => setTimeout(() => resolve(fallback), timeoutMs));
      return Promise.race([network.catch(() => fallback), timer]);
    }
  }
  try {
    return await network;
  } catch (err) {
    const fallback = await cached;
    if (fallback) return fallback;
    throw err;
  }
}

/**
 * API mit X-Kompanie-Stale-Ok: gespeicherte Version sofort, aus dem Netz im Hintergrund (siehe oben). Ohne gespeicherte
 * Version oder nach einem Fehler eben im Hintergrund wie networkFirst.
 */
async function staleWhileRevalidate(request, event) {
  const key = request.url;
  const user = request.headers.get(USER_HEADER) || '';
  const found = await caches.match(key, MATCH);
  // Nur für dieselbe Person und nur, was nach der letzten eigenen Änderung gespeichert wurde.
  const stored =
    found && user !== '' && found.headers.get(USER_HEADER) === user && Number(found.headers.get(STORED_AT_HEADER)) > (await lastChange())
      ? found
      : undefined;
  const last = announced.get(key);
  const recent = last && Date.now() - last.at < FRESH_MS;
  if (recent) announced.delete(key); // nur für das eine Neuladen nach der Meldung
  if (!stored || (recent && !last.ok)) return networkFirst(request, undefined, 0, event);
  if (recent) return stored;
  let running = revalidating.get(key);
  if (running) {
    // Die laufende Abfrage kann vor einer Änderung (z. B. auf einem anderen Gerät) gestellt worden sein und den Stand
    // davor liefern (Issue #68): danach noch einmal fragen, einmal für alle Anfragen, die inzwischen kamen.
    running.again = request;
  } else {
    running = { again: null };
    running.done = revalidateUntilCurrent(request, key, stored.clone(), running).finally(() => revalidating.delete(key));
    revalidating.set(key, running);
  }
  try {
    event.waitUntil(running.done);
  } catch {
    // Ereignis schon beendet: Die Abfrage läuft trotzdem weiter.
  }
  return stored;
}

/** Im Hintergrund abfragen, bis keine Anfrage mehr wartet, die nach dem Start der letzten Abfrage kam. */
async function revalidateUntilCurrent(request, key, stored, running) {
  const before = await stored.text();
  await revalidate(request, key, before);
  while (running.again) {
    const next = running.again;
    running.again = null;
    const found = await caches.match(key, MATCH);
    await revalidate(next, key, found ? await found.text() : before);
  }
}

async function revalidate(request, key, before) {
  let res;
  try {
    res = await fetch(request);
  } catch {
    return; // ohne Netz: Die gespeicherte Version bleibt.
  }
  const ok = cacheable(res);
  if (ok) {
    const text = await res.clone().text();
    await store(key, tagged(res, request));
    if (text === before) return;
  }
  announced.set(key, { at: Date.now(), ok });
  const windows = await self.clients.matchAll({ type: 'window' });
  for (const w of windows) w.postMessage({ type: 'kompanie-data', url: key });
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  if (request.method !== 'GET') {
    // Eigene Änderung: Nichts gilt mehr als eben frisch geholt.
    // Ausnahme wie in der App (api.ts): die Abfrage des Push-Status beim Start ändert nichts an den Daten.
    if (isApi(url) && !decodedHref(url).includes('/push/status')) {
      announced.clear();
      changedAt = Date.now();
      const saving = caches.open(DATA_CACHE).then((c) => c.put(CHANGED_KEY, new Response(String(changedAt))));
      event.waitUntil(saving.catch(() => undefined));
    }
    return;
  }
  if (url.origin !== self.location.origin) return;
  if (isGoogleCallback(url)) return;
  if (isPreview(request, url)) return;

  const doc = documentId(url);
  const badge = doc === null ? badgeKey(url) : null;
  if (badge !== null) {
    if (request.mode !== 'navigate') event.respondWith(badgeFirst(request, badge));
  } else if (doc !== null) {
    // Direkt aufgerufen (neuer Tab, Download-Link): durchreichen, nicht als App-Seite speichern.
    if (request.mode !== 'navigate') event.respondWith(documentFirst(request, doc));
  } else if (isHelpImage(url)) {
    if (request.mode !== 'navigate') event.respondWith(helpImageFirst(request));
  } else if (isStatic(url)) {
    event.respondWith(cacheFirst(request));
  } else if (request.mode === 'navigate' && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
    // Kurz nach einer Änderung (z. B. Anmeldung) auf das Netz warten: Die gespeicherte Seite enthält evtl. die
    // Konfiguration der Person davor, und mit ihr gäbe es deren gespeicherte Daten.
    const wait = changedAt !== null && Date.now() - changedAt < CHANGE_WAIT_MS ? 0 : NAVIGATION_TIMEOUT_MS;
    event.respondWith(networkFirst(request, self.registration.scope, wait, event));
  } else if (isApi(url) && !isAdmin(url)) {
    event.respondWith(
      request.headers.get('X-Kompanie-Stale-Ok') === '1'
        ? staleWhileRevalidate(request, event)
        : networkFirst(request, undefined, 0, event),
    );
  }
});

/* Benachrichtigungen (Web Push, Issue #19). Der Server schickt verschlüsselt {title, body, route, tag, image?};
 * route ist ein Ziel in der App (z. B. „meins/punkte“), image optional ein Bild (Abzeichen bei Beförderungen, Issue #17). */
self.addEventListener('push', (event) => {
  let msg = {};
  try {
    msg = event.data ? event.data.json() : {};
  } catch {
    msg = { body: event.data ? event.data.text() : '' };
  }
  const title = typeof msg.title === 'string' && msg.title !== '' ? msg.title : 'Neuigkeiten';
  const options = {
    body: typeof msg.body === 'string' ? msg.body : '',
    data: { route: typeof msg.route === 'string' ? msg.route : '' },
  };
  if (ASSETS !== '') options.icon = ASSETS + 'icons/icon-192.png';
  // Mit Bild (Abzeichen): als großes Bild und als Symbol; nur Adressen der eigenen Seite.
  if (typeof msg.image === 'string' && msg.image !== '') {
    try {
      const image = new URL(msg.image, self.registration.scope);
      if (image.origin === self.location.origin) {
        options.image = image.href;
        options.icon = image.href;
      }
    } catch {
      // ungültige Adresse: ohne Bild wie bisher
    }
  }
  // Gleiche Kennung: Die neue Nachricht ersetzt die alte (z. B. die tägliche Erinnerung).
  if (typeof msg.tag === 'string' && msg.tag !== '') {
    options.tag = msg.tag;
    options.renotify = true;
  }
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const route = String((event.notification.data && event.notification.data.route) || '').replace(/^[#/]+/, '');
  const target = self.registration.scope + '#/' + route;
  event.waitUntil(
    // Nur Fenster, die dieser Service Worker steuert: Nur die lassen sich per navigate() umleiten.
    self.clients.matchAll({ type: 'window' }).then((list) => {
      // Ein offenes Fenster der App nach vorn holen, sonst ein neues öffnen.
      const open = list.find((c) => c.url.startsWith(self.registration.scope));
      if (!open) return self.clients.openWindow(target);
      return open
        .focus()
        .then((c) => (c || open).navigate(target))
        .catch(() => self.clients.openWindow(target));
    }),
  );
});
