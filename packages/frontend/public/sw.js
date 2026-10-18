/* Service Worker der App (Bereich: Adresse der App).
 *
 * - Programmdateien (Hash-Dateinamen) → zuerst aus dem Speicher.
 * - App-Seite und API-Antworten → zuerst aus dem Netz; ohne Netz die letzte Version.
 * Wo API und Programmdateien liegen, steht in den Parametern ?api=…&assets=…
 * Beim Abmelden löscht die App alle Speicher.
 */
const STATIC_CACHE = 'kompanie-static-v2';
const DATA_CACHE = 'kompanie-data-v1';
const params = new URL(self.location.href).searchParams;
const API = params.get('api') || '';
const ASSETS = params.get('assets') || '';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => ![STATIC_CACHE, DATA_CACHE].includes(k)).map((k) => caches.delete(k))))
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

async function networkFirst(request, fallbackUrl) {
  try {
    const res = await fetch(request);
    if (cacheable(res)) {
      const cache = await caches.open(DATA_CACHE);
      cache.put(fallbackUrl || request, res.clone());
    }
    return res;
  } catch (err) {
    const cached = await caches.match(fallbackUrl || request);
    if (cached) return cached;
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
    event.respondWith(networkFirst(request, self.registration.scope));
  } else if (isApi(url)) {
    event.respondWith(networkFirst(request));
  }
});
