/* Service Worker der App (Bereich: Adresse der App).
 *
 * - Programmdateien (Hash-Dateinamen) → zuerst aus dem Speicher.
 * - App-Seite und API-Antworten → zuerst aus dem Netz; ohne Netz die letzte Version.
 * - Verwaltung für Admins (/admin/…, z. B. die Datensicherung) → nie speichern. (Ein no-store der
 *   Server gilt hier nicht: Es soll Zwischenspeicher im Netz verhindern; für den Offline-Betrieb
 *   speichert die App ihre Daten bewusst selbst und löscht sie beim Abmelden.)
 * Wo API und Programmdateien liegen, steht in den Parametern ?api=…&assets=…
 * Beim Abmelden löscht die App alle Speicher.
 */
const STATIC_CACHE = 'kompanie-static-v2';
// v2: v1 konnte Antworten von /admin/ enthalten (z. B. eine Datensicherung); beim Aktivieren gelöscht.
const DATA_CACHE = 'kompanie-data-v2';
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
  } else if (isApi(url) && !isAdmin(url)) {
    event.respondWith(networkFirst(request));
  }
});
