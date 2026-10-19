/* Service Worker der App (Bereich: Adresse der App).
 *
 * - Programmdateien (Hash-Dateinamen) → beim Installieren vorab gespeichert (Liste setzt der Build
 *   ein), danach zuerst aus dem Speicher. Jeder Build hat einen eigenen Speicher; beim Aktivieren
 *   werden die der alten Versionen gelöscht.
 * - App-Seite → zuerst aus dem Netz, aber höchstens 3 Sekunden warten, wenn eine gespeicherte
 *   Fassung da ist (schlechtes Mobilnetz); ohne Netz die letzte Version.
 * - API-Antworten → zuerst aus dem Netz; ohne Netz die letzte Version (höchstens DATA_MAX Einträge).
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
      .then((keys) => Promise.all(keys.filter((k) => ![STATIC_CACHE, DATA_CACHE].includes(k)).map((k) => caches.delete(k))))
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

  if (isStatic(url)) {
    event.respondWith(cacheFirst(request));
  } else if (request.mode === 'navigate' && url.pathname.startsWith(new URL(self.registration.scope).pathname)) {
    event.respondWith(networkFirst(request, self.registration.scope, NAVIGATION_TIMEOUT_MS, event));
  } else if (isApi(url) && !isAdmin(url)) {
    event.respondWith(networkFirst(request, undefined, 0, event));
  }
});
