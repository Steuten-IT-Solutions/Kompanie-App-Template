import { devices, expect, test, type Page } from '@playwright/test';

// E2E-Tests gegen eine Test-Instanz mit der Beispielkonfiguration (config/kompanie.example.json):
// Der Bereich Strafen heißt dort „Kasse“. Die Testdaten legen die eigene Rolle „Spieß“ an (Punkte, Kasse, Termine). Die App liegt unter /app/.
// - WordPress-Plugin (Standard): scripts/setup-test-wp.sh, Anmeldung über wp-login.php
// - eigenständige Version (KOMPANIE_VARIANT=standalone): tests/standalone/e2e-router.php,
//   Anmeldung über das Formular in der App

const PASSWORD = process.env.KOMPANIE_TEST_PASSWORD ?? 'test1234';
const ADMIN_PASSWORD = process.env.KOMPANIE_ADMIN_PASSWORD ?? 'admin';
const SHOTS = process.env.KOMPANIE_SCREENSHOT_DIR;
/** Tab für Punkte und Strafen */
const MANAGER = 'Verwaltung';
/** Rolle der Testperson „verwalter“ (tests/standalone/e2e-setup.php, scripts/setup-test-wp.sh) */
const SPIESS = 'Spieß';
const STANDALONE = process.env.KOMPANIE_VARIANT === 'standalone';

/** Anmeldeformular: wp-login.php bzw. das Formular der App. */
async function fillLogin(page: Page, user: string, password: string) {
  if (STANDALONE) {
    await expect(page).toHaveURL(/#\/anmelden$/);
    await page.getByLabel('Benutzername').fill(user);
    await page.getByLabel('Passwort').fill(password);
    await page.locator('form.login').getByRole('button', { name: 'Anmelden' }).click();
  } else {
    await expect(page).toHaveURL(/wp-login\.php/);
    // wp-login.php setzt kurz nach dem Laden den Fokus ins Benutzerfeld; danach prüfen, ob beide Werte stimmen.
    await expect(async () => {
      await page.fill('#user_login', user);
      await page.fill('#user_pass', password);
      await expect(page.locator('#user_login')).toHaveValue(user, { timeout: 500 });
      await expect(page.locator('#user_pass')).toHaveValue(password, { timeout: 500 });
    }).toPass();
    await page.click('#wp-submit');
  }
}

async function login(page: Page, user: string, password = PASSWORD) {
  await page.context().clearCookies();
  await page.goto('/app/');
  // Gäste sehen die öffentliche Ansicht; angemeldet wird über den Knopf in der Kopfleiste.
  await page.locator('.topbar').getByRole('button', { name: 'Anmelden' }).click();
  await fillLogin(page, user, password);
  await expect(page).toHaveURL(/\/app\/?(#.*)?$/);
  await expect(page.locator('.topbar-account')).toHaveText('Konto');
}

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}

test.describe.configure({ mode: 'serial' });

test('Gestaltung kommt aus kompanie.json, ohne fremde Motive', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page).toHaveTitle('Musterkompanie Beispielstadt');
  await expect(page.locator('.topbar-sub')).toHaveText('Musterkompanie Beispielstadt');
  const primary = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--primary').trim());
  expect(primary).toBe('#2e6b3f');
  const manifest = await (await page.request.get('/app/manifest.webmanifest')).json();
  expect(manifest.short_name).toBe('Musterkompanie');
  expect(manifest.theme_color).toBe('#2e6b3f');
  const html = await page.content();
  expect(html).not.toMatch(/marine|anker|ahoi|wappen/i);
  await shot(page, '0-start-gast');
});

test('Mitglied sieht Termine, aber keine Verwaltung', async ({ page }) => {
  await login(page, 'mitglied1');
  await expect(page.locator('.topbar-sub')).toHaveText('Hallo, Mitglied1');
  await expect(page.getByText('Vereinsabend')).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await page.getByText('Vereinsabend').click();
  await expect(page.getByText('Bitte pünktlich sein!')).toBeVisible();
  await expect(page.getByRole('button', { name: 'In meinen Kalender' })).toBeVisible();
  await page.waitForTimeout(300);
  await shot(page, '1-termine');
  if (SHOTS) {
    await page.emulateMedia({ colorScheme: 'dark' });
    await shot(page, '1-termine-dunkel');
    await page.emulateMedia({ colorScheme: 'light' });
  }
  // Direkter Aufruf der Verwaltung fällt auf die erste Ansicht zurück.
  await page.goto('/app/#/verwaltung');
  await expect(page.locator('.topbar-title')).toHaveText('Termine');
});

test('Verwalter legt Katalog an, vergibt Punkte und Kassen-Einträge', async ({ page }) => {
  await login(page, 'verwalter');
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await shot(page, '5-verwaltung-mitglieder');

  await page.getByRole('button', { name: 'Katalog' }).click();
  await page.getByPlaceholder('z. B. Teilnahme am Sommerfest').fill('Teilnahme Sommerfest');
  await page.locator('.field.narrow input').fill('5');
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Vorlage angelegt');

  await page.getByRole('button', { name: 'Punkte +' }).click();
  await page.locator('select').selectOption({ label: 'Teilnahme Sommerfest (+5)' });
  await page.getByLabel('Mitglied1').check();
  await page.getByLabel('Mitglied2').check();
  await shot(page, '6-verwaltung-punkte');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toHaveText('+5 Punkte an 2 Mitglieder vergeben');

  await page.getByRole('button', { name: 'Kasse +' }).click();
  await page.getByPlaceholder('2,50').fill('2,50');
  await page.getByPlaceholder('z. B. Zu spät gekommen').fill('Zu spät gekommen');
  await page.getByLabel('Mitglied1').check();
  await page.getByRole('button', { name: 'Eintragen' }).click();
  await expect(page.locator('.toast')).toContainText('2,50');

  await page.getByPlaceholder('2,50').fill('5');
  await page.getByPlaceholder('z. B. Zu spät gekommen').fill('Handy klingelt <10 min');
  await page.getByLabel('Mitglied1').check();
  await page.getByRole('button', { name: 'Eintragen' }).click();
  await expect(page.locator('.toast')).toContainText('5,00');

  await page.getByRole('button', { name: 'Mitglieder' }).click();
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await page.locator('.row', { hasText: 'Handy klingelt <10 min' }).getByRole('button', { name: 'Bezahlt' }).click();
  await expect(page.locator('.toast')).toHaveText('Als bezahlt markiert');
  await expect(page.locator('.stat', { hasText: 'Offen' }).locator('.stat-value')).toContainText('2,50');
  await shot(page, '7-verwaltung-mitglied');
});

test('Admin legt ein Mitglied an und setzt sein Passwort zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill('Neue Testperson');
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByText('Neue Testperson ist angelegt')).toBeVisible();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();
  expect(username).toMatch(/^neue\.testperson\d*$/);
  expect(password).toHaveLength(12);
  await shot(page, '8-mitglied-angelegt');

  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  await page.locator('.row', { hasText: 'Neue Testperson' }).first().click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: /Neues Startpasswort erzeugen/ }).click();
  const fresh = (await page.getByTestId('reset-password').textContent())!.trim();
  expect(fresh).toHaveLength(12);
  expect(fresh).not.toBe(password);

  await login(page, username, fresh);
  await expect(page.locator('.topbar-sub')).toHaveText('Hallo, Neue Testperson');
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
});

test('Anwärter: sieht den internen Kalender, aber keinen Mitgliederbereich, bis er aufgenommen ist', async ({ page }) => {
  // Eindeutiger Name: Bei WordPress bleiben Konten früherer Läufe bestehen.
  const name = `Anna Anwärterin ${Date.now() % 1000000}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByPlaceholder('Vorname Nachname').fill(name);
  await page.getByLabel('Rolle').selectOption('candidate');
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  await login(page, username, password);
  await expect(page.getByText('Vereinsabend')).toBeVisible(); // interner Kalender
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await expect(page.getByLabel('Auch Anwärter anzeigen')).toBeChecked();
  await page.locator('.row', { hasText: name }).click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Als Mitglied aufnehmen' }).click();
  await expect(page.locator('.toast')).toHaveText(`${name} ist jetzt Mitglied`);
  await expect(page.getByRole('button', { name: 'Als Mitglied aufnehmen' })).toHaveCount(0);

  await login(page, username, password);
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
});

test('Verwalter ohne Admin-Rechte kann weder anlegen noch zurücksetzen', async ({ page }) => {
  await login(page, 'verwalter');
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await expect(page.locator('.stat').first()).toBeVisible();
  await expect(page.getByRole('button', { name: '+ Mitglied anlegen' })).toHaveCount(0);
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.getByRole('button', { name: /Neues Startpasswort/ })).toHaveCount(0);
});

test('Mitglied 1 sieht eigene Punkte und Kasse', async ({ page }) => {
  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-value')).toHaveText('5');
  await expect(page.getByText('Teilnahme Sommerfest')).toBeVisible();
  await shot(page, '2-punkte');

  await page.locator('.segmented').getByRole('button', { name: 'Kasse' }).click();
  await expect(page.locator('.stat-value')).toContainText('2,50');
  await expect(page.getByText('Zu spät gekommen')).toBeVisible();
  await expect(page.getByText('Handy klingelt <10 min')).toBeVisible();
  await shot(page, '3-kasse');
});

test('Mitglied 2 sieht keine fremden Einträge und keine Verwaltungsdaten', async ({ page }) => {
  await login(page, 'mitglied2');
  await page.getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Kasse' }).click();
  await expect(page.locator('.stat-value')).toContainText('0,00');
  await expect(page.getByText('Zu spät gekommen')).toHaveCount(0);

  const status = await page.evaluate(async () => {
    const cfg = window.KOMPANIE_CONFIG!;
    const res = await fetch(cfg.apiBase + 'members', { headers: { [cfg.csrf.header]: cfg.csrf.token }, credentials: 'same-origin' });
    return res.status;
  });
  expect(status).toBe(403);
});

test('Abgelaufenes Token wird erneuert, ohne dass die App auffällt', async ({ page }) => {
  await login(page, 'mitglied1');
  // WordPress prüft das Token bei jeder Anfrage, die eigenständige Version nur bei Änderungen.
  // Geprüft wird eine Änderung, die nach der Token-Prüfung mit 400 abgelehnt wird (leeres Passwort).
  const result = await page.evaluate(async (standalone) => {
    const cfg = window.KOMPANIE_CONFIG!;
    const send = (token: string) =>
      standalone
        ? fetch(cfg.apiBase + 'me/password', {
            method: 'POST',
            headers: { [cfg.csrf.header]: token, 'Content-Type': 'application/json' },
            body: '{}',
            credentials: 'same-origin',
          })
        : fetch(cfg.apiBase + 'me', { headers: { [cfg.csrf.header]: token }, credentials: 'same-origin' });
    const bad = await send('ungueltig');
    const body = await bad.json();
    const fresh = await (await fetch(cfg.csrf.refreshUrl, { credentials: 'same-origin' })).json();
    const ok = await send(fresh.token);
    return { badStatus: bad.status, code: body.code, okStatus: ok.status };
  }, STANDALONE);
  expect(result).toEqual(
    STANDALONE
      ? { badStatus: 403, code: 'csrf_invalid', okStatus: 400 }
      : { badStatus: 403, code: 'rest_cookie_invalid_nonce', okStatus: 200 },
  );
});

test('Mitglied ändert sein Passwort und bleibt angemeldet', async ({ page }) => {
  const NEW = 'Neues-Testpasswort-1';
  await login(page, 'mitglied2');
  await page.getByRole('button', { name: 'Konto' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Mein Konto');

  await page.getByLabel('Aktuelles Passwort').fill('falsch');
  await page.getByLabel(/^Neues Passwort \(/).fill(NEW);
  await page.getByLabel('Neues Passwort wiederholen').fill(NEW);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.form-error')).toHaveText('Das aktuelle Passwort ist falsch.');

  await page.getByLabel('Aktuelles Passwort').fill(PASSWORD);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.toast')).toContainText('Passwort geändert');
  await shot(page, '9-konto');

  // Sitzung bleibt gültig: Daten laden weiterhin (neues Token wurde geholt).
  await page.getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-label')).toHaveText('Meine Punkte');

  await login(page, 'mitglied2', NEW);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByLabel('Aktuelles Passwort').fill(NEW);
  await page.getByLabel(/^Neues Passwort \(/).fill(PASSWORD);
  await page.getByLabel('Neues Passwort wiederholen').fill(PASSWORD);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.toast')).toContainText('Passwort geändert');
});

test('Admin pflegt öffentliche Termine', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' }).click();
  const d = new Date(Date.now() + 10 * 86400000);
  const ymd = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  await page.getByLabel('Titel').fill('Tag der offenen Tür');
  await page.getByLabel('Datum').fill(ymd);
  await page.getByLabel('Beginn (optional)').fill('14:00');
  await page.getByLabel('Ort').fill('Vereinsheim');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Termin angelegt');
  await expect(page.locator('.row', { hasText: 'Tag der offenen Tür' })).toBeVisible();
  await shot(page, '10-termine-verwalten');
});

test('Gast ohne Anmeldung sieht öffentliche Termine und News', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page.locator('.topbar').getByRole('button', { name: 'Anmelden' })).toBeVisible();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect(page.getByText('Vereinsabend')).toHaveCount(0); // nur im Kalender für Mitglieder
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await shot(page, '11-gast-termine');

  await page.getByRole('button', { name: 'News' }).click();
  await expect(page.locator('.news-card').first()).toBeVisible();
  await page.locator('.news-card').first().click();
  await expect(page.getByRole('link', { name: 'Auf der Webseite öffnen' })).toBeVisible();
  await shot(page, '12-gast-news');

  const status = await page.evaluate(
    async () => (await fetch(window.KOMPANIE_CONFIG!.apiBase + 'me/points', { credentials: 'same-origin' })).status,
  );
  expect(status).toBe(401);
});

/**
 * Headless-Chromium hat keinen Push-Dienst: Erlaubnis und PushManager nachbilden. Das Abo hat einen echten
 * P-256-Schlüssel (der Server prüft ihn) und liegt im localStorage, damit es ein Neuladen übersteht.
 */
async function fakePush(page: Page) {
  await page.addInitScript(() => {
    Object.defineProperty(Notification, 'permission', { get: () => localStorage.getItem('fake-push-perm') ?? 'default' });
    Notification.requestPermission = async () => {
      localStorage.setItem('fake-push-perm', 'granted');
      return 'granted';
    };
    const b64u = (buf: ArrayBuffer) =>
      btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
    const make = (json: { endpoint: string; keys: { p256dh: string; auth: string } }, key: ArrayBuffer) =>
      ({
        endpoint: json.endpoint,
        options: { applicationServerKey: key, userVisibleOnly: true },
        toJSON: () => json,
        unsubscribe: async () => {
          localStorage.removeItem('fake-push-sub');
          return true;
        },
      }) as unknown as PushSubscription;
    PushManager.prototype.getSubscription = async () => {
      const saved = localStorage.getItem('fake-push-sub');
      if (!saved) return null;
      const { json, key } = JSON.parse(saved);
      return make(json, Uint8Array.from(atob(key), (c) => c.charCodeAt(0)).buffer);
    };
    PushManager.prototype.subscribe = async (options?: PushSubscriptionOptionsInit) => {
      const pair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
      const raw = await crypto.subtle.exportKey('raw', pair.publicKey);
      const json = {
        endpoint: 'https://fcm.googleapis.com/fcm/send/e2e-' + crypto.randomUUID(),
        keys: { p256dh: b64u(raw), auth: b64u(crypto.getRandomValues(new Uint8Array(16)).buffer) },
      };
      const key = new Uint8Array(options!.applicationServerKey as ArrayBuffer).slice().buffer;
      localStorage.setItem('fake-push-sub', JSON.stringify({ json, key: btoa(String.fromCharCode(...new Uint8Array(key))) }));
      return make(json, key);
    };
  });
}

test('Benachrichtigungen auf dem iPhone: erst die App installieren', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.getByRole('button', { name: 'Bei neuen Terminen benachrichtigen' }).click();
  await expect(page.getByText('nur in der installierten App')).toBeVisible();
  await page.getByRole('button', { name: 'App installieren' }).click();
  await expect(page).toHaveURL(/#\/installieren$/);
});

test.describe('Android', () => {
  const { defaultBrowserType: _browser, ...pixel } = devices['Pixel 7'];
  test.use(pixel);

  test('Benachrichtigungen: Gast ohne Konto und Mitglied schalten sie ein und aus', async ({ page }) => {
    await fakePush(page);
    await page.context().clearCookies();
    await page.goto('/app/');
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.getByRole('button', { name: 'Bei neuen Terminen benachrichtigen' }).click();
    await expect(page.getByRole('heading', { name: 'Benachrichtigungen' }).last()).toBeVisible();
    // Gäste: nur Öffentliches
    await expect(page.getByLabel('Termine: neue und geänderte')).toBeChecked();
    await expect(page.getByText('Kasse: neue Einträge')).toHaveCount(0);
    await page.getByLabel('News: neue Beiträge').uncheck();
    await page.getByRole('button', { name: 'Benachrichtigungen einschalten' }).click();
    await expect(page.getByRole('button', { name: 'Testnachricht senden' })).toBeVisible();
    await shot(page, '13-benachrichtigungen-gast');
    // Der Service Worker zeigt eine Nachricht an (über das DevTools-Protokoll zugestellt, ohne Push-Dienst).
    await page.context().grantPermissions(['notifications']);
    const cdp = await page.context().newCDPSession(page);
    const regs: string[] = [];
    cdp.on('ServiceWorker.workerRegistrationUpdated', (e) => regs.push(...e.registrations.map((r) => r.registrationId)));
    await cdp.send('ServiceWorker.enable');
    await expect.poll(() => regs.length).toBeGreaterThan(0);
    await cdp.send('ServiceWorker.deliverPushMessage', {
      origin: new URL(page.url()).origin,
      registrationId: regs[0],
      data: JSON.stringify({ title: 'Neuer Termin', body: 'Sommerfest', route: 'termine', tag: 'termin-1' }),
    });
    await expect
      .poll(() =>
        page.evaluate(async () =>
          (await (await navigator.serviceWorker.ready).getNotifications()).map((n) => [n.title, n.body, n.tag, n.data.route, n.icon]),
        ),
      )
      .toEqual([['Neuer Termin', 'Sommerfest', 'termin-1', 'termine', expect.stringMatching(/icons\/icon-192\.png$/)]]);
    // Der Server kennt das Abo samt Auswahl (auch nach dem Neuladen).
    await page.reload();
    await expect(page.getByRole('button', { name: 'Testnachricht senden' })).toBeVisible();
    await expect(page.getByLabel('News: neue Beiträge')).not.toBeChecked();
    await page.getByRole('button', { name: 'Benachrichtigungen ausschalten' }).click();
    await expect(page.getByRole('button', { name: 'Benachrichtigungen einschalten' })).toBeVisible();

    // Mitglied: eigene Punkte und Kasse, Schalter für Beträge
    await login(page, 'mitglied1');
    await page.getByRole('button', { name: 'Konto' }).click();
    await page.getByRole('button', { name: 'Benachrichtigungen einstellen' }).click();
    await expect(page.getByLabel('Punkte: neue Einträge')).toBeChecked();
    await expect(page.getByLabel('Kasse: neue Einträge')).toBeChecked();
    await expect(page.getByLabel('Kasse: Betrag und Grund in der Nachricht zeigen')).not.toBeChecked();
    await page.getByRole('button', { name: 'Benachrichtigungen einschalten' }).click();
    await expect(page.getByRole('button', { name: 'Testnachricht senden' })).toBeVisible();
    await page.getByLabel('Kasse: Betrag und Grund in der Nachricht zeigen').check();
    const device = await page.evaluate(() => JSON.parse(localStorage.getItem('fake-push-sub') ?? 'null').json);
    const status = async () =>
      page.evaluate(async (d) => {
        const c = window.KOMPANIE_CONFIG!;
        const res = await fetch(c.apiBase + 'me/push/status', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', [c.csrf.header]: c.csrf.token },
          body: JSON.stringify({ endpoint: d.endpoint }),
        });
        return (await res.json()).subscription;
      }, device);
    await expect.poll(status).toEqual({ types: ['penalties', 'points', 'ranks', 'events', 'reminder', 'news'], details: true });

    // Abmelden meldet das Gerät beim Server ab.
    await page.getByRole('button', { name: 'Konto' }).click();
    await page.getByRole('button', { name: 'Abmelden' }).click();
    await expect(page.locator('.topbar').getByRole('button', { name: 'Anmelden' })).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('fake-push-sub'))).toBeNull();
    await login(page, 'mitglied1');
    expect(await status()).toBeNull();
  });
});

test('Tab-Wechsel zeigt sofort die zuletzt geladenen Daten', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  // Ab jetzt antwortet die API für Termine sehr langsam: Die Liste muss trotzdem sofort da sein.
  await page.context().route(/events/, async (route) => {
    await new Promise((r) => setTimeout(r, 8000));
    await route.continue().catch(() => undefined);
  });
  try {
    await page.getByRole('button', { name: 'News' }).click();
    await expect(page.locator('.news-card').first()).toBeVisible();
    await page.getByRole('button', { name: 'Termine' }).click();
    await expect(page.getByText('Tag der offenen Tür')).toBeVisible({ timeout: 1500 });
  } finally {
    await page.context().unrouteAll({ behavior: 'ignoreErrors' });
  }
});

test('Admin stuft ein Mitglied zum Gast herab und zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  if (!STANDALONE) await expect(page.locator('.role-control option', { hasText: 'Admin' })).toHaveCount(0); // WordPress: Admins im Backend
  await page.locator('.role-control').getByLabel('Rolle in der App').selectOption({ label: 'Gast' });
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Gast');
  await shot(page, '13-rolle');

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Konto' })).toBeVisible();

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByLabel('Auch Gast-Konten anzeigen').check();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.role-control').getByLabel('Rolle in der App').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Mitglied');
});

test('Standardrolle „Termine verwalten“: Termine pflegen ja, Punkte und Strafen nein', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.role-control').getByLabel('Rolle in der App').selectOption({ label: 'Termine verwalten' });
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Termine verwalten');

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await expect(page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' })).toBeVisible();
  // Die Rechte auf dem Server prüft das API-Szenario (tests/contract) für beide Varianten.

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.role-control').getByLabel('Rolle in der App').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Mitglied');
});

test('Admin legt eine eigene Rolle an, vergibt sie und löscht sie wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Einstellungen öffnen' }).click();
  const rollen = page.locator('#einstellungen-rollen');
  await expect(rollen.locator('.row-title', { hasText: SPIESS })).toBeVisible();
  await expect(rollen.locator('.row-title', { hasText: 'Kasse verwalten' })).toBeVisible(); // Standardrolle des Bereichs
  await rollen.getByRole('button', { name: '+ Neue Rolle' }).click();
  await rollen.getByLabel('Name der Rolle').fill('Kassenwart');
  await rollen.getByLabel(/^Kasse vergeben/).check();
  await rollen.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle angelegt');
  await expect(rollen.locator('.row', { hasText: 'Kassenwart' })).toContainText('0 Personen');
  await shot(page, '20-rollen');

  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.role-control').getByLabel('Rolle in der App').selectOption({ label: 'Kassenwart' });
  await expect(page.locator('.toast')).toHaveText('Rolle geändert: Kassenwart');

  await login(page, 'mitglied2');
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Öffentliche Termine verwalten' })).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Einstellungen öffnen' }).click();
  await rollen.getByRole('button', { name: 'Kassenwart bearbeiten' }).click();
  page.once('dialog', (d) => d.accept());
  await rollen.getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle gelöscht');
  await expect(rollen.locator('.row', { hasText: 'Kassenwart' })).toHaveCount(0);
});

test('Galerie: Gäste sehen öffentliche Bilder, Mitglieder zusätzlich interne', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/#/galerie');
  await expect(page.locator('.album-card', { hasText: 'Sommerfest' })).toBeVisible();
  await expect(page.locator('.album-card', { hasText: 'Vereinsabend' })).toHaveCount(0);
  await page.locator('.album-card', { hasText: 'Sommerfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(2);
  await expect(page.locator('.photo-lock')).toHaveCount(0);
  await page.locator('.photo').first().click();
  await expect(page.locator('.lb-count')).toHaveText('1 / 2');
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.lb-count')).toHaveText('2 / 2');
  await shot(page, '15-galerie-lightbox');
  await page.keyboard.press('Escape');
  await expect(page.locator('.lightbox')).toHaveCount(0);

  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Galerie' }).click();
  await expect(page.locator('.album-card', { hasText: 'Vereinsabend' })).toBeVisible();
  await shot(page, '16-galerie-alben');
  await page.locator('.album-card', { hasText: 'Sommerfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(3);
  await expect(page.locator('.photo-lock')).toHaveCount(1);
  const internSrc = await page
    .locator('.photo', { has: page.locator('.photo-lock') })
    .locator('img')
    .getAttribute('src');
  expect(internSrc).toContain('/app/foto/');
  const asMember = await page.request.get(internSrc!);
  expect(asMember.status()).toBe(200);
  expect(asMember.headers()['content-type']).toBe('image/jpeg');
  await shot(page, '17-galerie-album');

  await page.context().clearCookies();
  const asGuest = await page.request.get(internSrc!);
  expect(asGuest.status()).toBe(401);
});

test('iPhone: Banner führt zur bebilderten Installationsanleitung', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.evaluate(() => localStorage.removeItem('kompanie-install-hint-dismissed'));
  await page.reload();
  const banner = page.locator('.install-hint');
  await expect(banner).toContainText('Die App aufs Handy holen');
  await shot(page, '12-install-banner');
  await banner.getByRole('button', { name: "So geht's" }).click();
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
  await expect(page.locator('.install-step')).toHaveCount(4);
  await expect(page.locator('.install-pic text', { hasText: new URL(page.url()).host }).first()).toBeAttached();
  await expect(page.getByRole('button', { name: 'iPhone / iPad' })).toHaveAttribute('aria-pressed', 'true');
  await shot(page, '13-install-ios');
  await page.getByRole('button', { name: 'Android' }).click();
  await expect(page.locator('.install-step')).toHaveCount(3);

  // Eigene Adresse zum Weitergeben öffnet direkt die Anleitung.
  await page.goto('/app/installieren/');
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
  await expect(page).toHaveURL(/\/app\/#\/installieren$/);
  await expect(page.locator('.install-share code')).toHaveText(/\/app\/installieren\/$/);

  // Ausblenden bleibt gespeichert.
  await page.goto('/app/#/termine');
  await page.getByRole('button', { name: 'Hinweis schließen' }).click();
  await page.reload();
  await expect(page.locator('.topbar')).toBeVisible();
  await expect(page.locator('.install-hint')).toHaveCount(0);

  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'App installieren' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('App installieren');
});

test.describe('Android', () => {
  const { defaultBrowserType: _ignored, ...pixel } = devices['Pixel 7'];
  test.use(pixel);

  test('Installieren-Knopf öffnet den Installationsdialog des Browsers', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/app/');
    await expect(page.locator('.topbar')).toBeVisible();
    await page.evaluate(() => {
      const w = window as unknown as { __prompted: number };
      w.__prompted = 0;
      const ev = new Event('beforeinstallprompt', { cancelable: true }) as Event & Record<string, unknown>;
      ev.prompt = () => {
        w.__prompted++;
        return Promise.resolve();
      };
      ev.userChoice = Promise.resolve({ outcome: 'accepted' });
      window.dispatchEvent(ev);
    });
    const button = page.locator('.install-hint').getByRole('button', { name: 'Installieren' });
    await expect(button).toBeVisible();
    await shot(page, '14-install-android');
    await button.click();
    await expect(page.locator('.toast')).toContainText('Die App wird installiert');
    expect(await page.evaluate(() => (window as unknown as { __prompted: number }).__prompted)).toBe(1);
    await expect(page.locator('.install-hint').getByRole('button', { name: "So geht's" })).toBeVisible();
  });
});

test('Angemeldet bleiben ist vorausgewählt und hält rund 400 Tage', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.locator('.topbar').getByRole('button', { name: 'Anmelden' }).click();
  await expect(STANDALONE ? page.getByLabel('Angemeldet bleiben') : page.locator('#rememberme')).toBeChecked();
  await fillLogin(page, 'mitglied1', PASSWORD);
  await expect(page.locator('.topbar-account')).toHaveText('Konto');
  const cookie = (await page.context().cookies()).find((c) =>
    STANDALONE ? c.name === 'kompanie_session' : c.name.startsWith('wordpress_logged_in_'),
  );
  expect(cookie).toBeDefined();
  const days = (cookie!.expires - Date.now() / 1000) / 86400;
  expect(days).toBeGreaterThan(390);
});

test('Admin wird durch die Einrichtung geführt', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  const hint = page.locator('.setup-hint');
  await expect(hint).toBeVisible();
  await hint.getByRole('button', { name: 'Einrichtung starten' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Einrichtung');
  // Erster Schritt: Bereiche wählen. Ohne Galerie entfällt deren Schritt.
  await expect(page.getByRole('heading', { name: 'Willkommen!' })).toBeVisible();
  await page.getByRole('checkbox', { name: /^Galerie/ }).uncheck();
  await page.getByRole('button', { name: 'Weiter', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Kalender für Mitglieder' })).toBeVisible();
  // Kalender (und bei der eigenständigen Version Neuigkeiten) lassen sich überspringen.
  while ((await page.getByRole('heading', { name: 'Fast fertig' }).count()) === 0) {
    await expect(page.getByRole('heading', { name: 'Galerie aus Google Drive' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
  }
  await page.getByRole('button', { name: 'Einrichtung abschließen' }).click();
  await expect(page).toHaveURL(/#\/verwaltung$/);
  await expect(hint).toHaveCount(0);
  const nav = page.getByRole('navigation', { name: 'Hauptnavigation' });
  await expect(nav.getByRole('button', { name: 'Galerie' })).toHaveCount(0);
  await page.reload();
  await expect(page.locator('.topbar-account')).toHaveText('Konto');
  await expect(hint).toHaveCount(0);
  await expect(nav.getByRole('button', { name: 'Galerie' })).toHaveCount(0);
  // Ausgeschaltet heißt auch auf dem Server gesperrt.
  await page.context().clearCookies();
  const blocked = await page.request.get(STANDALONE ? '/app/api/v1/gallery' : '/?rest_route=/kompanie-app/v1/gallery');
  expect(blocked.status()).toBe(403);
  expect((await blocked.json()).code).toBe('feature_disabled');

  // Einstellungen → Bereiche: Galerie wieder einschalten, die Alben sind unverändert da.
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/einstellungen');
  const bereiche = page.locator('.settings-section', { hasText: 'Bereiche' }).first();
  // Der Schalter speichert sofort und zeigt den neuen Stand erst nach der Antwort des Servers.
  await bereiche.getByRole('checkbox', { name: /^Galerie/ }).click();
  await expect(page.locator('.toast')).toHaveText('Galerie eingeschaltet');
  await expect(bereiche.getByRole('checkbox', { name: /^Galerie/ })).toBeChecked();
  await nav.getByRole('button', { name: 'Galerie' }).click();
  await expect(page.locator('.album-card', { hasText: 'Sommerfest' })).toBeVisible();

  // Ausschalten nur nach Rückfrage; Abbrechen lässt den Bereich an.
  await page.goto('/app/#/einstellungen');
  page.once('dialog', (d) => {
    expect(d.message()).toContain('Die Daten bleiben erhalten');
    void d.dismiss();
  });
  await bereiche.getByRole('checkbox', { name: /^Galerie/ }).click();
  await expect(bereiche.getByRole('checkbox', { name: /^Galerie/ })).toBeChecked();

  // Wer kein Admin ist, kommt nicht an die Bereiche.
  await login(page, 'verwalter');
  await page.goto('/app/#/einstellungen');
  await expect(page.getByRole('heading', { name: 'Bereiche' })).toHaveCount(0);
});

test('Admin sieht Zustand, Updates und lädt eine Sicherung herunter', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Einstellungen öffnen' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Einstellungen');
  const status = page.locator('.settings-section', { hasText: 'Zustand der App' });
  await expect(status.locator('.status-item').first()).toBeVisible();
  // Die Test-Instanz ist kein Paket aus einem Release: keine Updates über die App.
  await expect(page.getByText(/bekommt keine Updates über die App/)).toBeVisible();

  await expect(status.locator('.status-item.status-warn', { hasText: 'Datensicherung' })).toBeVisible();
  const backup = page.locator('.settings-section', { hasText: 'Datensicherung' });
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    backup.getByRole('button', { name: 'Sicherung herunterladen' }).click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/^sicherung-\d{4}-\d{2}-\d{2}\.json$/);
  const data = JSON.parse(await (await import('node:fs/promises')).readFile((await download.path())!, 'utf8'));
  expect(data.format).toBe('kompanie-app-backup');
  expect(data.variant).toBe(STANDALONE ? 'standalone' : 'wordpress');
  expect(Object.keys(data.tables)).toContain('events');
  // Die Sicherung (und alles unter /admin/) landet nie im Speicher des Service Workers. Gegenprobe: Der
  // Service Worker steuert die Seite und hat andere API-Antworten gespeichert (sonst wäre die Prüfung leer).
  const cachedUrls = () =>
    page.evaluate(async () => {
      const urls: string[] = [];
      for (const name of await caches.keys()) {
        for (const req of await (await caches.open(name)).keys()) urls.push(decodeURIComponent(req.url));
      }
      return urls;
    });
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  const apiBase = await page.evaluate(() => new URL(window.KOMPANIE_CONFIG!.apiBase, location.href).href);
  await expect.poll(async () => (await cachedUrls()).some((u) => u.startsWith(decodeURIComponent(apiBase)))).toBe(true);
  expect((await cachedUrls()).filter((u) => u.includes('/admin/'))).toEqual([]);
  // Vorher gelb (noch nie gesichert), nach dem Herunterladen grün.
  await expect(status.locator('.status-item.status-ok', { hasText: 'Datensicherung' })).toBeVisible();
  await shot(page, '19-wartung');
});

test('Offline: App und zuletzt geladene Termine kommen aus dem Speicher', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  // Erst ab dem nächsten Laden steuert der Service Worker die Seite und speichert Antworten.
  await page.reload();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  // Gegenprobe: Die Termine liegen wirklich im Speicher des Service Workers.
  await expect
    .poll(() =>
      page.evaluate(async () => {
        for (const name of await caches.keys()) {
          for (const req of await (await caches.open(name)).keys()) if (decodeURIComponent(req.url).includes('events')) return true;
        }
        return false;
      }),
    )
    .toBe(true);
  await page.context().setOffline(true);
  try {
    await page.reload();
    await expect(page.locator('.offline')).toBeVisible();
    await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  } finally {
    await page.context().setOffline(false);
  }
});

test('Service Worker: Programmdateien vorab gespeichert, alte Speicher gelöscht, langsames Netz', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.evaluate(async () => {
    // Stand vor einem Update: alter Speicher einer früheren Version, Service Worker noch nicht da.
    for (const r of await navigator.serviceWorker.getRegistrations()) await r.unregister();
    await (await caches.open('kompanie-static-v2')).put('/alt.js', new Response('alt'));
  });
  await page.reload();
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  const statics = () => page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('kompanie-static-')));
  await expect.poll(statics).toHaveLength(1);
  expect((await statics())[0]).not.toBe('kompanie-static-v2');
  // Auch die erst bei Bedarf geladenen Ansichten liegen schon im Speicher (offline verfügbar).
  const cached = await page.evaluate(async () => {
    const name = (await caches.keys()).find((k) => k.startsWith('kompanie-static-'))!;
    return (await (await caches.open(name)).keys()).map((r) => new URL(r.url).pathname.split('/').pop());
  });
  expect(cached.some((f) => /^Verwaltung-.*\.js$/.test(f ?? ''))).toBe(true);
  expect(cached.some((f) => /^Einstellungen-.*\.js$/.test(f ?? ''))).toBe(true);

  // Langsames Netz: Die gespeicherte App-Seite erscheint nach höchstens 3 Sekunden.
  await page.reload();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await page.context().route(/\/app\/?$/, async (route) => {
    await new Promise((r) => setTimeout(r, 10000));
    await route.continue().catch(() => undefined);
  });
  try {
    const start = Date.now();
    await page.goto('/app/', { waitUntil: 'commit', timeout: 9000 });
    await expect(page.locator('.topbar')).toBeVisible({ timeout: 9000 });
    expect(Date.now() - start).toBeLessThan(6000);
  } finally {
    await page.context().unrouteAll({ behavior: 'ignoreErrors' });
  }
});

test('Hilfe: Seiten passend zu Rechten, Bereichen und Variante, mit eigenen Bezeichnungen, auch offline', async ({ page }) => {
  // Einträge der Seitenliste (Titel stehen teils auch in Hinweisen der App)
  const item = (title: string) => page.locator('.row-title', { hasText: title });
  // Gast: nur Seiten für alle
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Hilfe');
  await expect(item('Die App aufs Handy holen')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toHaveCount(0);
  await expect(item('Meine Punkte')).toHaveCount(0);

  // Mitglied: dazu die Seiten für Mitglieder; Bezeichnung „Kasse“ statt „Strafen“
  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await expect(item('Meine Punkte')).toBeVisible();
  await item('Meine Kasse').click();
  await expect(page.locator('.news-headline')).toHaveText('Meine Kasse');
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toHaveCount(0);
  await expect(item('Punkte vergeben')).toHaveCount(0);

  // Admin: Seiten für Admins, nur die Installation der eigenen Variante; Suche
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toBeVisible();
  await expect(item(STANDALONE ? 'Installation ohne WordPress' : 'Installation mit WordPress')).toBeVisible();
  await expect(item(STANDALONE ? 'Installation mit WordPress' : 'Installation ohne WordPress')).toHaveCount(0);
  await page.getByLabel('Hilfe durchsuchen').fill('Startpasswort');
  await expect(item('Mitglieder anlegen')).toBeVisible();
  await expect(item('Häufige Probleme')).toHaveCount(0);
  await page.getByLabel('Hilfe durchsuchen').fill('');
  await item('Rollen und Mitglieder').click();
  await page.locator('.hilfe-text a', { hasText: 'Rollen vergeben' }).click(); // Verweis auf eine andere Seite
  await expect(page.locator('.news-headline')).toHaveText('Rollen vergeben');
  await shot(page, '21-hilfe');

  // Offline: Hilfe kommt aus dem Speicher des Service Workers
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  await page.reload();
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  await page.context().setOffline(true);
  try {
    await page.goto('/app/#/hilfe/app-installieren');
    await expect(page.locator('.news-headline')).toHaveText('Die App aufs Handy holen');
  } finally {
    await page.context().setOffline(false);
  }
});

test('Admin richtet Kalender, Galerie und Neuigkeiten in der App ein', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Einstellungen öffnen' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Einstellungen');
  if (!STANDALONE) {
    // WordPress: Neuigkeiten kommen aus den Beiträgen (kein Abschnitt); Kalender und Galerie sind einstellbar.
    // Die Test-Instanz hat keine Attrappen für Google, deshalb nur Speichern und Prüfen der Eingabe.
    await expect(page.locator('.settings-section', { hasText: 'Galerie aus Google Drive' })).toBeVisible();
    await expect(page.locator('.settings-section h3', { hasText: /^Neuigkeiten$/ })).toHaveCount(0);
    const cal = page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' });
    await cal.locator('input[type=url]').fill('ftp://falsch');
    await cal.getByRole('button', { name: 'Speichern' }).click();
    await expect(cal.locator('.form-error')).toBeVisible();
    return;
  }
  await expect(page.getByText('✓ Eingerichtet über die geheime iCal-Adresse.')).toBeVisible();
  await expect(page.getByText('https://kalender.test')).toHaveCount(0); // geheime Adresse wird nicht angezeigt

  await page.getByRole('button', { name: 'Kalender testen' }).click();
  await expect(page.getByText(/3 Termine gefunden/)).toBeVisible();

  const cal = page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' });
  await cal.getByLabel('Neue iCal-Adresse (ersetzt die bisherige Einrichtung)').fill('ftp://falsch');
  await cal.getByRole('button', { name: 'Speichern' }).click();
  await expect(cal.locator('.form-error')).toBeVisible();

  const gallery = page.locator('.settings-section', { hasText: 'Galerie aus Google Drive' });
  await expect(gallery.getByText('test@example.iam.gserviceaccount.com')).toBeVisible();
  await gallery.getByRole('button', { name: 'Jetzt abgleichen' }).click();
  await expect(gallery.getByText('Fertig: 2 Alben, 4 Bilder.')).toBeVisible();

  const news = page.locator('.settings-section', { hasText: 'Neuigkeiten' });
  await news.getByRole('button', { name: 'Neuigkeiten testen' }).click();
  await expect(news.getByText('2 Beiträge gefunden.')).toBeVisible();
  await shot(page, '18-einstellungen');
});

test('Dienstgrade: Liste anlegen, mit Punkteschwelle befördern, Mitglied sieht Stand und Verlauf', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrade' }).click();
  for (const [title, min] of [
    ['Gefreiter', '10'],
    ['Unteroffizier', '1000'],
  ]) {
    await page.getByLabel('Bezeichnung').fill(title);
    await page.getByLabel('Punkteschwelle (optional)').fill(min);
    await page.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.row', { hasText: title })).toBeVisible();
  }
  await expect(page.locator('.segmented').getByRole('button', { name: 'Beförderungen' })).toBeVisible();

  // Punkte für mitglied2 buchen, dann befördern.
  await page.locator('.segmented').getByRole('button', { name: 'Mitglieder' }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  const id = Number(page.url().split('/').pop());
  await page.evaluate(async (userId) => {
    const c = window.KOMPANIE_CONFIG!;
    await fetch(c.apiBase + 'points', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', [c.csrf.header]: c.csrf.token },
      body: JSON.stringify({ user_ids: [userId], points: 12, reason: 'E2E Dienstgrad' }),
    });
  }, id);
  await page.reload();
  await page.getByLabel('Neuer Dienstgrad').selectOption({ label: 'Gefreiter (ab 10)' });
  await page.getByLabel('Begründung (optional)').fill('Gute Arbeit');
  await page.getByRole('button', { name: 'Dienstgrad ändern' }).click();
  await expect(page.locator('.toast')).toHaveText('Dienstgrad geändert');
  await expect(page.getByText('Beförderung: kein Dienstgrad → Gefreiter')).toBeVisible();
  await expect(page.locator('.member-rank strong')).toHaveText('Gefreiter');

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrad' }).click();
  await expect(page.locator('.stat-value')).toHaveText('Gefreiter');
  await expect(page.locator('.rank-progress')).toContainText('bis „Unteroffizier“');
  await expect(page.getByText('Gute Arbeit')).toBeVisible();
});
