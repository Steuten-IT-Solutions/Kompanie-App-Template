// CSRF-Token (src/csrfToken.ts): vorab erneuern, wenn das gespeicherte abgelaufen ist; gleichzeitige Erneuerungen teilen
// sich eine Anfrage.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CsrfToken, type FetchResult, type TokenState } from '../src/csrfToken.ts';

const HOUR = 3600;
const NOW = 1_800_000_000_000;

function setup(initial: TokenState, opts: { page?: boolean; online?: boolean; fetchResult?: FetchResult } = {}) {
  const calls = { fetch: 0 };
  let now = NOW;
  const csrf = new CsrfToken({
    initial,
    fetchToken: async () => {
      calls.fetch++;
      await new Promise((resolve) => setTimeout(resolve, 5));
      return opts.fetchResult ?? { ok: true, state: { token: 'neu', expiresAt: now / 1000 + 12 * HOUR } };
    },
    pageExpected: () => opts.page ?? false,
    online: () => opts.online ?? true,
    now: () => now,
    pageWaitMs: 30,
    maxWaitMs: 200,
  });
  return { csrf, calls, advance: (ms: number) => (now += ms) };
}

test('gültiges Token oder eines ohne Ablauf: kein Warten, keine Anfrage', async () => {
  for (const expiresAt of [NOW / 1000 + HOUR, null]) {
    const { csrf, calls } = setup({ token: 'alt', expiresAt });
    assert.equal(csrf.stale(), false);
    await csrf.ready();
    assert.equal(calls.fetch, 0);
    assert.equal(csrf.token(), 'alt');
  }
  // Gäste ohne Token: nichts zu erneuern.
  const guest = setup({ token: '', expiresAt: NOW / 1000 - HOUR });
  assert.equal(guest.csrf.stale(), false);
});

test('abgelaufen: alle gleichzeitigen Anfragen warten auf ein einziges neues Token', async () => {
  const { csrf, calls } = setup({ token: 'alt', expiresAt: NOW / 1000 - 60 });
  assert.equal(csrf.stale(), true);
  await Promise.all([csrf.ready(), csrf.ready(), csrf.ready()]);
  assert.equal(calls.fetch, 1);
  assert.equal(csrf.token(), 'neu');
  assert.equal(csrf.stale(), false);
});

test('kurz vor dem Ablauf schon erneuern', () => {
  const { csrf } = setup({ token: 'alt', expiresAt: NOW / 1000 + 60 });
  assert.equal(csrf.stale(), true);
});

test('vom Service Worker gesteuert: das Token der aktuellen App-Seite reicht, ohne eigene Anfrage', async () => {
  const { csrf, calls } = setup({ token: 'alt', expiresAt: NOW / 1000 - 60 }, { page: true });
  const waiting = csrf.ready();
  setTimeout(() => csrf.take({ token: 'seite', expiresAt: NOW / 1000 + 12 * HOUR }), 5);
  await waiting;
  assert.equal(calls.fetch, 0);
  assert.equal(csrf.token(), 'seite');
});

test('kommt die App-Seite nicht rechtzeitig, holt die App selbst ein Token', async () => {
  const { csrf, calls } = setup({ token: 'alt', expiresAt: NOW / 1000 - 60 }, { page: true });
  await csrf.ready();
  assert.equal(calls.fetch, 1);
  assert.equal(csrf.token(), 'neu');
});

test('ohne Netz nicht warten; scheitert das Holen, geht es mit dem alten Token weiter', async () => {
  const offline = setup({ token: 'alt', expiresAt: NOW / 1000 - 60 }, { online: false });
  await offline.csrf.ready();
  assert.equal(offline.calls.fetch, 0);
  const failing = setup({ token: 'alt', expiresAt: NOW / 1000 - 60 }, { fetchResult: { ok: false, offline: false } });
  await failing.csrf.ready();
  assert.equal(failing.csrf.token(), 'alt');
  assert.equal(failing.calls.fetch, 1);
});

test('falsche Uhr am Gerät: höchstens eine Erneuerung je zehn Minuten', async () => {
  // Der Server liefert ein Token, das nach der Uhr des Geräts schon abgelaufen ist.
  const { csrf, calls, advance } = setup(
    { token: 'alt', expiresAt: NOW / 1000 - 60 },
    { fetchResult: { ok: true, state: { token: 'neu', expiresAt: NOW / 1000 - 30 } } },
  );
  await csrf.ready();
  await csrf.ready();
  assert.equal(calls.fetch, 1);
  advance(11 * 60_000);
  await csrf.ready();
  assert.equal(calls.fetch, 2);
});

test('refresh: gleichzeitige Aufrufe teilen sich eine Anfrage', async () => {
  const { csrf, calls } = setup({ token: 'alt', expiresAt: null });
  const [a, b] = await Promise.all([csrf.refresh(), csrf.refresh()]);
  assert.equal(calls.fetch, 1);
  assert.deepEqual(a, b);
  assert.equal(csrf.token(), 'neu');
  await csrf.refresh();
  assert.equal(calls.fetch, 2, 'danach wieder eine neue Anfrage');
});
