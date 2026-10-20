/* Service Worker der App (Bereich: Adresse der App).
 *
 * - Programmdateien (Hash-Dateinamen) → beim Installieren vorab gespeichert (Liste setzt der Build
 *   ein), danach zuerst aus dem Speicher. Jeder Build hat einen eigenen Speicher; beim Aktivieren
 *   werden die der alten Versionen gelöscht.
 * - App-Seite → zuerst aus dem Netz, aber höchstens 3 Sekunden warten, wenn eine gespeicherte
 *   Fassung da ist (schlechtes Mobilnetz); ohne Netz die letzte Version.
 * - API-Antworten → zuerst aus dem Netz; ohne Netz die letzte Version (höchstens DATA_MAX Einträge).
 * - Dokumente (<App>/dokument/<id>?v=<Fassung>, Issue #2) → zuerst aus dem Speicher: Die Adresse wechselt mit
 *   jeder neuen Fassung. Was einmal geöffnet wurde, ist so offline verfügbar; gespeichert wird je Dokument nur die
 *   aktuelle Fassung. Direkt aufgerufen (nicht über fetch) nie als App-Seite speichern.
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
const params = new URL(self.location.href).searchParams;
const API = params.get('api') || '';
const ASSETS = params.get('assets') || '';
const NAVIGATION_TIMEOUT_MS = 3000;
const DATA_MAX = 100;

/** Programmdateien dieses Builds vorab speichern; einzelne Fehler verhindern das Update nicht. */
async function precache() {
  if (ASSETS === '' || PRECACHE.length === 0) return;
  const cache = await caches.open(STATIC_CACHE);
  await Promise.allSettled(
    PRECACHE.map(async (path) => {
      const url = ASSETS + path;
      if (await cache.match(url)) return;
      const res = await fetch(url);
      if (cacheable(res)) await cache.put(url, res);
    }),
  );
}

/** Daten-Speicher begrenzen: die ältesten Einträge zuerst löschen (Reihenfolge des Speicherns). */
async function trimData() {
  const cache = await caches.open(DATA_CACHE);
  const keys = await cache.keys();
  await Promise.all(keys.slice(0, Math.max(0, keys.length - DATA_MAX)).map((k) => cache.delete(k)));
}

self.addEventListener('install', (event) => {
  event.waitUntil(precache().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => ![STATIC_CACHE, DATA_CACHE, DOCS_CACHE].includes(k)).map((k) => caches.delete(k))))
      .then(() => trimData())
      .then(() => self.clients.claim()),
  );
});

function isStatic(url) {
  // Nur Programmdateien mit Hash im Namen; Icons (feste Namen) ändern sich mit dem Logo.
  return ASSETS !== '' && url.href.startsWith(ASSETS + 'assets/') && /\.(js|css|png|svg|woff2?|webp|jpg)$/.test(url.pathname);
}

function isApi(url) {
  return API !== '' && url.href.startsWith(API);
}

function isAdmin(url) {
  // Auch in der Form index.php?r=/api/v1/admin/… (Adresse ggf. kodiert)
  let href = url.href;
  try {
    href = decodeURIComponent(href);
  } catch {
    // unvollständig kodiert: wie angegeben prüfen
  }
  return href.includes('/admin/');
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
  const cached = await cache.match(request);
  if (cached) return cached;
  const res = await fetch(request);
  if (cacheable(res)) {
    const keys = await cache.keys();
    await Promise.all(keys.filter((k) => documentId(new URL(k.url)) === id).map((k) => cache.delete(k)));
    await cache.put(request, res.clone());
  }
  return res;
}

function cacheable(res) {
  return res && res.ok && !res.redirected && res.type === 'basic';
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
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
async function networkFirst(request, fallbackUrl, timeoutMs, event) {
  const key = fallbackUrl || request;
  const network = fetch(request).then((res) => {
    if (cacheable(res)) {
      const saving = store(key, res.clone());
      try {
        if (event) event.waitUntil(saving);
      } catch {
        // Antwort ist schon raus (Zeitlimit): Speichern läuft trotzdem weiter.
      }
    }
    return res;
  });
  const cached = caches.match(key);
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

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  const doc = documentId(url);
  if (doc !== null) {
    // Direkt aufgerufen (neuer Tab, Download-Link): durchreichen, nicht als App-Seite speichern.
    if (request.mode !== 'navigate') event.respondWith(documentFirst(request, doc));
  } else if (isStatic(url)) {
    event.respondWith(cacheFirst(request));
  } else if (request.mode === 'navigate' && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
    event.respondWith(networkFirst(request, self.registration.scope, NAVIGATION_TIMEOUT_MS, event));
  } else if (isApi(url) && !isAdmin(url)) {
    event.respondWith(networkFirst(request, undefined, 0, event));
  }
});

/* Benachrichtigungen (Web Push, Issue #19). Der Server schickt verschlüsselt {title, body, route, tag};
 * route ist ein Ziel in der App (z. B. „meins/punkte“). */
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
