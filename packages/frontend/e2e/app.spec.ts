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

test('Konten: mehrere anlegen mit Zugangsdaten, Angaben ändern, Konto löschen', async ({ page }) => {
  const id = Date.now().toString(36);
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByRole('button', { name: 'Mehrere auf einmal anlegen' }).click();
  const rows = page.locator('.bulk-row');
  await rows.nth(0).getByLabel('Name', { exact: true }).fill(`Ida ${id}`);
  await rows.nth(1).getByLabel('Name', { exact: true }).fill(`Jan ${id}`);
  await page.getByRole('button', { name: 'Alle anlegen' }).click();
  await expect(page.getByRole('heading', { name: '2 Konten angelegt' })).toBeVisible();
  await expect(page.locator('.print-sheet')).toHaveCount(2);
  await expect(page.locator('.print-sheet').first()).toContainText('installieren');
  await expect(page.getByRole('button', { name: 'Ausdrucken' })).toBeVisible();

  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  await page.locator('.row', { hasText: `Ida ${id}` }).click();
  await page.getByRole('button', { name: 'Angaben ändern' }).click();
  await page.locator('.account-edit').getByLabel('Name', { exact: true }).fill(`Ida Neu ${id}`);
  await page.locator('.account-edit').getByLabel('E-Mail (optional)').fill(`ida-${id}@example.org`);
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.locator('.member-name')).toHaveText(`Ida Neu ${id}`);
  await expect(page.locator('.account-panel')).toContainText(`ida-${id}@example.org`);

  await page.getByRole('button', { name: 'Alle Mitglieder' }).click();
  await page.locator('.row', { hasText: `Jan ${id}` }).click();
  await page
    .locator('.account-panel')
    .getByRole('button', { name: /Konto löschen|Aus der App entfernen/ })
    .click();
  const confirm = page.getByRole('button', { name: /Endgültig (löschen|entfernen)/ });
  await expect(confirm).toBeDisabled();
  await page.getByLabel('Zur Bestätigung den Namen eintippen').fill(`jan ${id}`);
  await confirm.click();
  await expect(page.locator('.toast')).toContainText(`Jan ${id}`);
  await expect(page.locator('.row', { hasText: `Jan ${id}` })).toHaveCount(0);
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
    await expect(page.getByLabel('Schwarzes Brett: dringende Nachrichten')).toBeChecked();
    await expect(page.getByLabel('Umfragen: neue Umfrage und Erinnerung')).toBeChecked();
    await expect(page.getByLabel('Geburtstage: am Tag selbst')).toBeChecked();
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
    await expect.poll(status).toEqual({
      types: ['board', 'polls', 'birthdays', 'penalties', 'points', 'ranks', 'titles', 'events', 'reminder', 'news'],
      details: true,
    });

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

test('Service Worker: nach einem Update alte Speicher gelöscht, alle Programmteile offline da, langsames Netz', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/');
  const assets = await page.evaluate(() => {
    const src = document.querySelector<HTMLScriptElement>('script[type="module"][src]')!.src;
    return src.slice(0, src.lastIndexOf('/assets/') + 1);
  });
  await page.evaluate(async (assets) => {
    // Stand vor einem Update: Speicher früherer Versionen (alter Name und voriger Build), kein Service Worker,
    // keine gespeicherte App-Seite.
    for (const r of await navigator.serviceWorker.getRegistrations()) await r.unregister();
    await (await caches.open('kompanie-static-v2')).put('/alt.js', new Response('alt'));
    await (await caches.open('kompanie-static-000000000000')).put(assets + 'assets/Alt-0000.js', new Response('alt'));
    await caches.delete('kompanie-data-v2');
  }, assets);
  await page.reload();
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
  });
  const statics = () => page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('kompanie-static-')));
  await expect.poll(statics).toHaveLength(1);
  expect(['kompanie-static-v2', 'kompanie-static-000000000000']).not.toContain((await statics())[0]);
  // Vorab gespeichert: auch die erst bei Bedarf geladenen Ansichten und die Icons.
  const cached = await page.evaluate(async () => {
    const name = (await caches.keys()).find((k) => k.startsWith('kompanie-static-'))!;
    return (await (await caches.open(name)).keys()).map((r) => new URL(r.url).pathname.split('/').slice(-2).join('/'));
  });
  for (const chunk of ['Verwaltung', 'Einstellungen', 'Installieren', 'Galerie', 'Meins', 'maintenance']) {
    expect(
      cached.some((f) => new RegExp(`^assets/${chunk}-.*\\.js$`).test(f)),
      chunk,
    ).toBe(true);
  }
  expect(cached).toContain('icons/icon-192.png');
  // Auch die App-Seite ist schon gespeichert (beim Installieren, ohne weiteres Laden).
  await expect
    .poll(() => page.evaluate(async () => !!(await (await caches.open('kompanie-data-v2')).match(new URL('./', location.href).href))))
    .toBe(true);

  // Offline: App-Seite und erst bei Bedarf geladene Ansichten kommen aus dem Speicher.
  await page.context().setOffline(true);
  try {
    await page.goto('/app/#/installieren');
    await expect(page.getByRole('heading', { name: 'Die App aufs Handy holen' })).toBeVisible();
    await page.getByRole('button', { name: 'Hilfe' }).click();
    await expect(page.locator('.row-title', { hasText: 'Die App aufs Handy holen' })).toBeVisible();
  } finally {
    await page.context().setOffline(false);
  }

  // Langsames Netz: Die gespeicherte App-Seite erscheint nach höchstens 3 Sekunden (der Service Worker wartet 2,5 s).
  await page.goto('/app/');
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await page.context().route(/\/app\/?$/, async (route) => {
    await new Promise((r) => setTimeout(r, 10000));
    await route.continue().catch(() => undefined);
  });
  try {
    const start = Date.now();
    await page.goto('/app/', { waitUntil: 'commit', timeout: 9000 });
    await expect(page.locator('.topbar')).toBeVisible({ timeout: 9000 });
    expect(Date.now() - start).toBeLessThan(3500);
  } finally {
    await page.context().unrouteAll({ behavior: 'ignoreErrors' });
  }
});

test.describe('ohne Service Worker', () => {
  // Damit der Programmteil wirklich aus dem Netz kommt.
  test.use({ serviceWorkers: 'block' });

  test('Fehlt ein Programmteil (nach einem Update), lädt die App einmal neu, ohne Schleife', async ({ page, context }) => {
    let failures = 1;
    await context.route(/\/assets\/Hilfe-[^/]*\.js$/, async (route) => {
      if (failures-- > 0) await route.fulfill({ status: 404, body: '' });
      else await route.continue();
    });
    await context.clearCookies();
    await page.goto('/app/');
    await expect(page.locator('.topbar')).toBeVisible();
    let loads = 0;
    page.on('load', () => loads++);
    await page.getByRole('button', { name: 'Hilfe' }).click();
    // Erster Versuch scheitert → einmal neu laden → jetzt klappt es.
    await expect(page.locator('.row-title', { hasText: 'Die App aufs Handy holen' })).toBeVisible();
    expect(loads).toBe(1);

    // Fehlt der Teil dauerhaft und wurde eben erst neu geladen: keine Schleife, sondern eine Meldung.
    failures = 100;
    const page2 = await context.newPage();
    await page2.goto('/app/');
    await page2.evaluate(() => sessionStorage.setItem('kompanie-chunk-reload', String(Date.now())));
    let loads2 = 0;
    page2.on('load', () => loads2++);
    await page2.getByRole('button', { name: 'Hilfe' }).click();
    await expect(page2.getByText('Diese Ansicht konnte nicht angezeigt werden.')).toBeVisible();
    expect(loads2).toBe(0);
  });
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

test('Abzeichen: PNG und SVG hochladen, Mitglied sieht sie in der Liste und in „Meins“, offline, ohne eigene Fläche im Dunkelmodus', async ({
  page,
}) => {
  // 64 × 32 Pixel, transparenter Hintergrund mit rotem Rechteck
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAEAAAAAgCAYAAACinX6EAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAR0lEQVRo3u3SMQ0AIAxFwYKQ6sD/hA6MgIgODL0T0OS/NAIA6GpUD+zM+3PAOqe0YXb/AAEEEEAAAQQQQAABBBBAAAEAADp5dzgEIAd9ksQAAAAASUVORK5CYII=',
    'base64',
  );
  const svg = Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20" onload="alert(1)"><script>alert(2)</script>' +
      '<path d="M2 18 L20 2 L38 18 Z" fill="#c8a400" stroke="#fff" stroke-width="2"/></svg>',
  );
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrade' }).click();
  for (const [title, file] of [
    ['Gefreiter', { name: 'gefreiter.png', mimeType: 'image/png', buffer: png }],
    ['Unteroffizier', { name: 'uffz.svg', mimeType: 'image/svg+xml', buffer: svg }],
  ] as const) {
    const row = page.locator('.row', { has: page.getByText(title, { exact: true }) });
    const chooser = page.waitForEvent('filechooser');
    await row.getByRole('button', { name: 'Abzeichen hochladen' }).click();
    await (await chooser).setFiles(file);
    await expect(page.locator('.toast')).toHaveText('Abzeichen gespeichert');
    // Vorschau auf heller und dunkler Fläche nebeneinander
    await expect(row.locator('.badge-preview-light img')).toHaveAttribute('alt', title);
    await expect(row.locator('.badge-preview-dark img')).toHaveAttribute('alt', title);
    await expect(row.getByRole('button', { name: 'Ersetzen' })).toBeVisible();
    await expect(row.getByRole('button', { name: 'Entfernen' })).toBeVisible();
  }
  await shot(page, 'abzeichen-verwalten');
  // Das SVG hat im Browser eine PNG-Fassung für Benachrichtigungen bekommen.
  await expect(page.locator('.row', { has: page.getByText('Unteroffizier', { exact: true }) })).not.toContainText(
    'Ohne Bild in Benachrichtigungen',
  );

  await login(page, 'mitglied2');
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Dienstgrad' }).click();
  const loaded = (alt: string, scope: string) =>
    page.evaluate(
      ([a, s]) => {
        const img = document.querySelector<HTMLImageElement>(`${s} img[alt="${a}"]`);
        return !!img && img.complete && img.naturalWidth > 0;
      },
      [alt, scope],
    );
  const hero = page.locator('.rank-badge-hero img');
  await expect(hero).toHaveAttribute('alt', 'Gefreiter');
  await expect.poll(() => loaded('Gefreiter', '.rank-badge-hero')).toBe(true);
  await expect(page.locator('.rank-progress img')).toHaveAttribute('alt', 'Unteroffizier');
  await page.getByText('Alle Dienstgrade').click();
  await expect(page.locator('.rank-list img[alt="Gefreiter"]')).toBeVisible();
  await expect(page.locator('.rank-list img[alt="Unteroffizier"]')).toBeVisible();
  await expect.poll(() => loaded('Unteroffizier', '.rank-list')).toBe(true);
  await shot(page, 'abzeichen-meins');
  // Ausgeliefert wird das bereinigte SVG.
  const svgText = await page.evaluate(async () =>
    (await fetch(document.querySelector<HTMLImageElement>('.rank-list img[alt="Unteroffizier"]')!.src)).text(),
  );
  expect(svgText).toContain('<path');
  expect(svgText).not.toContain('script');
  expect(svgText).not.toContain('onload');

  // Dunkelmodus: keine eigene Fläche, kein Rahmen, kein Filter
  await page.emulateMedia({ colorScheme: 'dark' });
  const look = await hero.evaluate((img) => {
    const s = getComputedStyle(img);
    const p = getComputedStyle(img.parentElement!);
    return [s.backgroundColor, s.backgroundImage, s.borderTopWidth, s.filter, p.backgroundColor];
  });
  expect(look).toEqual(['rgba(0, 0, 0, 0)', 'none', '0px', 'none', 'rgba(0, 0, 0, 0)']);
  await shot(page, 'abzeichen-meins-dunkel');
  await page.emulateMedia({ colorScheme: 'light' });

  // Einmal geladen, auch offline da (versionierte Adressen, zuerst aus dem Speicher).
  await expect
    .poll(() =>
      page.evaluate(async () => {
        if (!(await caches.keys()).includes('kompanie-badges-v1')) return 0;
        return (await (await caches.open('kompanie-badges-v1')).keys()).length;
      }),
    )
    .toBeGreaterThanOrEqual(2);
  await page.context().setOffline(true);
  try {
    await page.reload();
    await expect(page.locator('.offline')).toBeVisible();
    await expect(hero).toHaveAttribute('alt', 'Gefreiter');
    await expect.poll(() => loaded('Gefreiter', '.rank-badge-hero')).toBe(true);
    await page.getByText('Alle Dienstgrade').click();
    await expect.poll(() => loaded('Unteroffizier', '.rank-list')).toBe(true);
  } finally {
    await page.context().setOffline(false);
  }
});

test('Anwesenheit: freie Liste, Status, Punkte aus dem Vorschlag, Mitglied sieht seine Anwesenheit', async ({ page }) => {
  const title = `Übung ${Date.now().toString(36)}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Anwesenheit' }).click();
  await page.locator('.free-event').getByLabel('Titel').fill(title);
  await page.getByRole('button', { name: 'Liste anlegen' }).click();
  await expect(page.locator('.attendance .field').first().locator('input')).toHaveValue(title);

  await page.getByRole('radio', { name: 'Mitglied2: anwesend' }).check();
  await expect(page.locator('.attendance-counts')).toContainText('1 anwesend');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Anwesenheit gespeichert');

  await page.getByRole('button', { name: 'Punkte für 1 Anwesenden' }).click();
  await expect(page.getByLabel('Grund')).toHaveValue(title);
  await page.getByLabel('Punkte', { exact: true }).fill('2');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.attendance-list li', { hasText: 'Mitglied2' })).toContainText('Punkte gebucht');
  await expect(page.getByRole('button', { name: 'Punkte für 0 Anwesende' })).toBeDisabled();

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Anwesenheit' }).click();
  await expect(page.locator('.my-attendance .row', { hasText: title })).toContainText('anwesend');
});

test('Dokumente: hochladen, Kategorien eingeklappt, PDF anzeigen, Sortierung gemerkt, offline erneut öffnen', async ({ page }) => {
  const id = Date.now().toString(36);
  const pdf = Buffer.from(
    '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
      '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n',
  );
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: 'Dokumente' }).click();
  await page.getByRole('button', { name: 'Dokumente verwalten' }).click();
  await page.getByLabel('Neue Kategorie').fill(`Satzungen ${id}`);
  await page.getByRole('button', { name: 'Kategorie hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Kategorie angelegt');
  await page.getByLabel('Datei').setInputFiles({ name: 'satzung.pdf', mimeType: 'application/pdf', buffer: pdf });
  await expect(page.getByLabel('Titel')).toHaveValue('satzung');
  await page.getByLabel('Titel').fill(`Satzung ${id}`);
  await page.getByLabel('Kategorie', { exact: true }).selectOption({ label: `Satzungen ${id}` });
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.toast')).toHaveText(`„Satzung ${id}“ hochgeladen`);

  await login(page, 'mitglied1');
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
  await page.locator('.tabbar').getByRole('button', { name: 'Dokumente' }).click();
  const group = page.locator('.doc-group', { hasText: `Satzungen ${id}` });
  const doc = group.locator('.doc-card', { hasText: `Satzung ${id}` });
  await expect(group.getByRole('button', { name: `Satzungen ${id} (1)` })).toHaveAttribute('aria-expanded', 'false');
  await expect(doc).toBeHidden();
  await group.getByRole('button', { name: `Satzungen ${id} (1)` }).click();
  await doc.getByRole('button', { name: 'Öffnen' }).click();
  await expect(page.getByAltText(`Satzung ${id}, Seite 1 von 1`)).toBeVisible();
  await page.getByRole('button', { name: '‹ Alle Dokumente' }).click();
  await expect(doc).toBeVisible();
  await expect(doc).toContainText('Auch offline verfügbar');

  // Sortierung wird auf dem Gerät gemerkt (Standard: Stand neu → alt).
  await expect(page.getByLabel('Sortierung')).toHaveValue('date-desc');
  await page.getByLabel('Sortierung').selectOption('name-asc');
  await page.reload();
  await expect(page.getByLabel('Sortierung')).toHaveValue('name-asc');

  await page.context().setOffline(true);
  try {
    await group.getByRole('button', { name: `Satzungen ${id} (1)` }).click();
    await doc.getByRole('button', { name: 'Öffnen' }).click();
    await expect(page.getByAltText(`Satzung ${id}, Seite 1 von 1`)).toBeVisible();
  } finally {
    await page.context().setOffline(false);
  }
});

test('Titel: anlegen und vergeben, Mitglied sieht ihn in „Meins“', async ({ page }) => {
  const title = `Ehrenmitglied ${Date.now().toString(36)}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.segmented').getByRole('button', { name: 'Titel' }).click();
  await page.getByLabel('Bezeichnung').fill(title);
  await page.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.title-list .row', { hasText: title })).toContainText('nicht vergeben');

  await page.locator('.segmented').getByRole('button', { name: 'Mitglieder' }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.member-titles').getByLabel(title).check();
  await page.getByRole('button', { name: 'Titel speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Titel gespeichert');
  await page.getByRole('button', { name: 'Alle Mitglieder' }).click();
  await expect(page.locator('.row', { hasText: 'Mitglied2' }).locator('.badge-title')).toContainText(title);

  // Vergeben: nicht löschen, nur umbenennen oder deaktivieren.
  await page.locator('.segmented').getByRole('button', { name: 'Titel' }).click();
  const row = page.locator('.title-list .row', { hasText: title });
  await expect(row).toContainText('1× vergeben');
  await expect(row.getByRole('button', { name: 'Löschen' })).toHaveCount(0);

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.my-titles')).toContainText(title);
});

test('Schwarzes Brett: Banner auf allen Seiten, ausblenden, „+1 weitere“, Änderung zeigt es wieder, abgelaufen weg', async ({
  page,
  browser,
}) => {
  const id = Date.now().toString(36);
  const first = `Heute kein Training ${id}`;
  const second = `Treffpunkt Schützenhaus ${id}`;
  // Admin schreibt (Recht post_board) über „Mein Konto“ → „Nachricht schreiben“.
  const admin = await browser.newPage();
  admin.on('dialog', (d) => d.accept());
  await login(admin, 'admin', ADMIN_PASSWORD);
  await admin.getByRole('button', { name: 'Konto' }).click();
  await admin.getByRole('button', { name: 'Nachricht schreiben' }).click();
  await expect(admin.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  const form = admin.locator('.board-form');
  await form.getByLabel('Text').fill(`${first}\nHalle gesperrt.`);
  await expect(form.getByText(`${first.length + 16}/500 Zeichen`)).toBeVisible();
  await expect(form).toContainText('sofort eine Benachrichtigung');
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(admin.locator('.toast')).toHaveText('Nachricht veröffentlicht');
  await expect(admin.locator('.board-card', { hasText: first })).toContainText('Halle gesperrt.');

  // Mitglied: Banner oben auf jeder Seite.
  await login(page, 'mitglied1');
  const banner = page.locator('.board-banner');
  await expect(banner).toContainText(first);
  await expect(banner).toContainText('Halle gesperrt.');
  await expect(banner).toContainText(/admin ·/i);
  const tabs = page.locator('.tabbar button');
  for (let i = 0; i < (await tabs.count()); i++) {
    await tabs.nth(i).click();
    await expect(banner).toContainText(first);
  }
  await page.getByRole('button', { name: 'Konto' }).click();
  await expect(banner).toContainText(first);
  await expect(page.getByRole('button', { name: 'Nachricht schreiben' })).toHaveCount(0);
  await shot(page, '33-schwarzes-brett');

  // Zweite Nachricht: die neueste oben, „+1 weitere“ klappt die erste auf.
  await admin.locator('.board-form').getByLabel('Text').fill(second);
  await admin.locator('.board-form').getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(admin.locator('.board-card', { hasText: second })).toBeVisible();
  await page.reload();
  await expect(banner.locator('.board-entry')).toHaveCount(1);
  await expect(banner.locator('.board-entry')).toContainText(second);
  await banner.getByRole('button', { name: '+1 weitere' }).click();
  await expect(banner.locator('.board-entry')).toHaveCount(2);
  await expect(banner.locator('.board-entry').nth(1)).toContainText(first);

  // „×“ blendet sie auf diesem Gerät dauerhaft aus.
  await banner.locator('.board-entry', { hasText: first }).getByRole('button', { name: 'Nachricht ausblenden' }).click();
  await expect(banner.locator('.board-entry')).toHaveCount(1);
  await expect(banner.getByRole('button', { name: /weitere/ })).toHaveCount(0);
  await page.reload();
  await expect(banner).toContainText(second);
  await expect(banner).not.toContainText(first);
  // Auf der Seite des Bretts bleibt sie sichtbar.
  await banner.getByRole('button', { name: 'Brett öffnen' }).click();
  await expect(page.locator('.board-card', { hasText: first })).toBeVisible();
  await expect(page.locator('.board-form')).toHaveCount(0);

  // Geändert: Die ausgeblendete Nachricht erscheint wieder (ohne neue Benachrichtigung).
  const card = admin.locator('.board-card', { hasText: first });
  await card.getByRole('button', { name: 'Bearbeiten' }).click();
  await card.getByLabel('Text').fill(`${first} – fällt aus, Halle gesperrt`);
  await card.getByRole('button', { name: 'Speichern' }).click();
  await expect(admin.locator('.toast')).toHaveText('Nachricht geändert');
  await page.goto('/app/#/termine');
  await page.reload();
  await expect(banner.locator('.board-entry')).toHaveCount(1);
  await banner.getByRole('button', { name: '+1 weitere' }).click();
  await expect(banner).toContainText(`${first} – fällt aus, Halle gesperrt`);

  // Nach 24 Stunden blendet die App sie selbst aus (Uhr des Browsers vorgestellt).
  await page.clock.install({ time: new Date(Date.now() + 25 * 3600 * 1000) });
  await page.reload();
  await expect(page.locator('.topbar-title')).toHaveText('Termine');
  await expect(page.getByText('Vereinsabend')).toBeVisible();
  await expect(banner).toHaveCount(0);

  // Aufräumen: vorzeitig löschen.
  for (const text of [second, first]) {
    await admin.locator('.board-card', { hasText: text }).getByRole('button', { name: 'Löschen' }).click();
    await expect(admin.locator('.board-card', { hasText: text })).toHaveCount(0);
  }
  await expect(admin.getByText('Zurzeit gibt es keine Nachrichten.')).toBeVisible();
  await admin.close();
});

test('Umfragen: anlegen, Mitglied sieht sie im Banner, antwortet, sieht das Ergebnis, ändert; beendet keine Änderung mehr', async ({
  page,
  browser,
}) => {
  const title = `Grillfest ${Date.now().toString(36)}: Samstag oder Sonntag?`;
  // Admin (Recht manage_polls) legt über „Mein Konto“ → „Umfrage anlegen“ an.
  const admin = await browser.newPage();
  admin.on('dialog', (d) => d.accept());
  await login(admin, 'admin', ADMIN_PASSWORD);
  await admin.getByRole('button', { name: 'Konto' }).click();
  await admin.getByRole('button', { name: 'Umfrage anlegen' }).click();
  await expect(admin.locator('.topbar-title')).toHaveText('Umfragen');
  const form = admin.locator('.poll-form');
  await form.getByLabel('Frage', { exact: true }).fill(title);
  await form.getByLabel('Möglichkeit 1').fill('Samstag');
  await form.getByLabel('Möglichkeit 2').fill('Sonntag');
  await expect(form.getByLabel('Namentlich')).toBeChecked();
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(admin.locator('.toast')).toHaveText('Umfrage veröffentlicht');
  await expect(admin.locator('.poll-managed', { hasText: title })).toBeVisible();

  // Mitglied: Die Umfrage steht im Banner; „Teilnehmen“ öffnet sie.
  await login(page, 'mitglied1');
  const banner = page.locator('.board-banner');
  const entry = banner.locator('.board-entry-poll', { hasText: title });
  await expect(entry).toBeVisible();
  await entry.getByRole('button', { name: 'Teilnehmen' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Umfragen');
  const card = page.locator('.poll-card');
  await expect(card.locator('.poll-title')).toHaveText(title);
  // Vor dem Antworten: namentlich oder anonym und wann die Ergebnisse sichtbar werden.
  await expect(card.locator('.poll-privacy')).toContainText('Namentlich');
  await expect(card.locator('.poll-privacy')).toContainText('sobald du geantwortet hast');
  await expect(card.locator('.poll-results')).toHaveCount(0);

  await card.getByRole('radio', { name: 'Samstag' }).check();
  await card.getByRole('button', { name: 'Antwort senden' }).click();
  await expect(page.locator('.toast')).toHaveText('Antwort gespeichert');
  const results = card.locator('.poll-results');
  await expect(results.locator('.poll-bar', { hasText: 'Samstag' })).toContainText('1 · 100 %');
  await expect(results.locator('.poll-bar', { hasText: 'Samstag' })).toContainText('Mitglied1');
  await expect(results.locator('.poll-bar', { hasText: 'Sonntag' })).toContainText('0 · 0 %');
  await shot(page, '34-umfrage');

  // Beantwortet: nicht mehr im Banner, am Brett als „beantwortet“.
  await page.goto('/app/#/termine');
  await expect(page.locator('.topbar-title')).toHaveText('Termine');
  await expect(page.locator('.board-entry-poll', { hasText: title })).toHaveCount(0);
  await page.goto('/app/#/brett');
  const onBoard = page.locator('.board-poll-card', { hasText: title });
  await expect(onBoard).toContainText('beantwortet');

  // Antwort ändern: Summen stimmen.
  await onBoard.getByRole('button', { name: 'Antwort ansehen' }).click();
  await card.getByRole('radio', { name: 'Sonntag' }).check();
  await card.getByRole('button', { name: 'Antwort ändern' }).click();
  await expect(page.locator('.toast')).toHaveText('Antwort geändert');
  await expect(results.locator('.poll-bar', { hasText: 'Sonntag' })).toContainText('1 · 100 %');
  await expect(results.locator('.poll-bar', { hasText: 'Samstag' })).toContainText('0 · 0 %');

  // Admin beendet die Umfrage vorzeitig.
  await admin.reload();
  const managed = admin.locator('.poll-managed', { hasText: title });
  await expect(managed.locator('.poll-bar', { hasText: 'Sonntag' })).toContainText('1 · 100 %');
  await managed.getByRole('button', { name: 'Beenden' }).click();
  await expect(admin.locator('.toast')).toHaveText('Umfrage beendet');

  // Danach keine Änderung mehr möglich; das Ergebnis bleibt sichtbar.
  await page.reload();
  await expect(card.locator('.poll-closed-note')).toContainText('Die Umfrage ist beendet');
  await expect(card.getByRole('button', { name: 'Antwort ändern' })).toHaveCount(0);
  await expect(card.getByRole('radio', { name: 'Samstag' })).toBeDisabled();
  await expect(results.locator('.poll-bar', { hasText: 'Sonntag' })).toContainText('1 · 100 %');
  // Beendete Umfragen findet man über das Brett.
  await page.goto('/app/#/brett');
  await expect(page.locator('.board-poll-card', { hasText: title })).toHaveCount(0);
  await page.getByRole('button', { name: 'Beendete Umfragen' }).click();
  await expect(page.locator('.poll-list-item', { hasText: title })).toContainText('beantwortet');

  // Aufräumen
  await admin.locator('.poll-managed', { hasText: title }).getByRole('button', { name: 'Löschen' }).click();
  await expect(admin.locator('.toast')).toHaveText('Umfrage gelöscht');
  await admin.close();
});

test('Geburtstage: Mitglied trägt seinen ein, ein anderes sieht ihn unter „Anstehende Geburtstage“, ausgeblendet verschwindet er', async ({
  page,
  browser,
}) => {
  // In drei Tagen (Zeitzone des Browsers; die der Kompanie verschiebt das höchstens um einen Tag, bleibt also in 7 Tagen).
  const in3 = new Date(Date.now() + 3 * 86400 * 1000);
  const member = await browser.newPage();
  member.on('dialog', (d) => d.accept());
  await login(member, 'mitglied1');
  await member.getByRole('button', { name: 'Konto' }).click();
  const card = member.locator('.birthday-card');
  await card.getByLabel('Tag').selectOption(String(in3.getDate()));
  await card.getByLabel('Monat').selectOption(String(in3.getMonth() + 1));
  await card.getByLabel('Jahr', { exact: true }).fill(String(in3.getFullYear() - 30));
  await expect(card.getByLabel('Anderen Mitgliedern zeigen')).toBeChecked();
  await expect(card.getByLabel(/Alter anzeigen/)).not.toBeChecked();
  await card.getByLabel(/Alter anzeigen/).check();
  await card.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  await card.scrollIntoViewIfNeeded();
  await shot(member, '36-geburtstag-konto');

  // Ein anderes Mitglied sieht ihn oben unter Termine, mit Alter; „Alle Geburtstage“ zeigt die ganze Liste.
  await login(page, 'mitglied2');
  await page.goto('/app/#/termine');
  const upcoming = page.locator('.birthdays-upcoming');
  await expect(upcoming.getByRole('heading', { name: 'Anstehende Geburtstage' })).toBeVisible();
  const entry = upcoming.locator('.birthday-item', { hasText: 'Mitglied1' });
  await expect(entry).toBeVisible();
  await expect(entry).toContainText('wird 30');
  await expect(entry).not.toContainText(String(in3.getFullYear() - 30));
  await shot(page, '35-geburtstage');
  await upcoming.getByRole('button', { name: 'Alle Geburtstage' }).click();
  await expect(page).toHaveURL(/#\/termine\/geburtstage$/);
  await expect(page.locator('.birthdays-page .birthday-item', { hasText: 'Mitglied1' })).toBeVisible();

  // „Anderen Mitgliedern zeigen“ aus: verschwindet.
  await card.getByLabel('Anderen Mitgliedern zeigen').uncheck();
  await card.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  await page.goto('/app/#/termine');
  await page.reload();
  await expect(page.getByText('Vereinsabend')).toBeVisible();
  await expect(page.locator('.birthday-item', { hasText: 'Mitglied1' })).toHaveCount(0);

  // Aufräumen
  await card.getByRole('button', { name: 'Entfernen' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag entfernt');
  await member.close();
});

test('Inventar: anlegen, umlagern, Menge mit Grund ändern, Verlauf zeigt alle Schritte mit Namen', async ({ page }) => {
  const title = `Bierbank ${Date.now().toString(36)}`;
  page.on('dialog', (d) => d.accept());
  // Mitglieder ohne das Recht sehen das Inventar nicht.
  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Konto' }).click();
  await expect(page.locator('.inventory-konto')).toHaveCount(0);
  await page.goto('/app/#/inventar');
  await expect(page.locator('.topbar-title')).not.toHaveText('Inventar');

  // Admin (hat manage_inventory) öffnet es über „Mein Konto“.
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Inventar öffnen' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Inventar');
  await page.getByRole('button', { name: '+ Eintrag anlegen' }).click();
  const form = page.locator('.inventory-form');
  await form.getByLabel('Bezeichnung').fill(title);
  await form.getByLabel('Menge').fill('10');
  await form.getByLabel('Einheit').fill('Stück');
  await form.getByLabel('Ort').fill('Lager');
  await form.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.toast')).toHaveText('Eintrag angelegt');
  const group = page.locator('.inventory-group', { hasText: title });
  await expect(group.locator('.inventory-sum')).toHaveText('10 Stück');

  // Umlagern: 4 in die Halle → zwei Einträge, die Summe bleibt.
  const lager = group.locator('.inventory-entry[data-location="Lager"]');
  await lager.getByRole('button', { name: 'Umlagern' }).click();
  const move = lager.locator('.inventory-move');
  await move.getByLabel('Menge').fill('4');
  await move.getByLabel('Zielort').fill('Halle');
  await move.getByLabel(/Grund/).fill('Sommerfest');
  await move.getByRole('button', { name: 'Umlagern' }).click();
  await expect(page.locator('.toast')).toHaveText('Umgelagert');
  await expect(group.locator('.inventory-group-places')).toHaveText('an 2 Orten');
  await expect(group.locator('.inventory-sum')).toHaveText('10 Stück');
  await expect(group.locator('.inventory-entry[data-location="Halle"] .inventory-qty-value')).toHaveText('4 Stück');
  await expect(lager.locator('.inventory-qty-value')).toHaveText('6 Stück');

  // Menge mit Grund ändern; −/+ ändern um eins.
  await lager.getByRole('button', { name: 'Bearbeiten' }).click();
  const edit = lager.locator('.inventory-form');
  await edit.getByLabel('Menge').fill('5');
  await edit.getByLabel(/Grund/).fill('Inventur');
  await edit.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Eintrag gespeichert');
  await expect(group.locator('.inventory-sum')).toHaveText('9 Stück');
  await group
    .locator('.inventory-entry[data-location="Halle"]')
    .getByRole('button', { name: /eins mehr/ })
    .click();
  await expect(group.locator('.inventory-sum')).toHaveText('10 Stück');
  await shot(page, '37-inventar');

  // Verlauf des Eintrags im Lager: angelegt, umgelagert, geändert – mit Namen und Gründen.
  await lager.getByRole('button', { name: 'Verlauf' }).click();
  await expect(page).toHaveURL(/#\/inventar\/verlauf\/\d+$/);
  const log = page.locator('.inventory-log-entry');
  await expect(log).toHaveCount(3);
  await expect(log.nth(0)).toContainText('Menge: 6 Stück → 5 Stück');
  await expect(log.nth(0)).toContainText('Grund: Inventur');
  await expect(log.nth(1)).toContainText('Umgelagert: 4 Stück von Lager nach Halle');
  await expect(log.nth(1)).toContainText('Grund: Sommerfest');
  await expect(log.nth(2)).toContainText('Angelegt mit 10 Stück in Lager');
  for (let i = 0; i < 3; i++) await expect(log.nth(i).locator('.inventory-log-user')).toHaveText(/admin/i);

  // Gesamter Verlauf: auch der neue Eintrag in der Halle (Umlagern und +1).
  await page.getByRole('button', { name: /Zurück zum Inventar/ }).click();
  await page.getByRole('button', { name: 'Gesamter Verlauf' }).click();
  const all = page.locator('.inventory-log-entry', { hasText: title });
  await expect(all).toHaveCount(5);
  await expect(all.filter({ hasText: 'Halle' }).first()).toContainText('Menge: 4 Stück → 5 Stück');

  // Aufräumen
  await page.getByRole('button', { name: /Zurück zum Inventar/ }).click();
  await group.locator('.inventory-group-head').click();
  for (const place of ['Halle', 'Lager']) {
    await group.locator(`.inventory-entry[data-location="${place}"]`).getByRole('button', { name: 'Löschen' }).click();
    await expect(page.locator('.toast')).toHaveText('Eintrag gelöscht');
    await expect(group.locator(`.inventory-entry[data-location="${place}"]`)).toHaveCount(0);
  }
  await expect(page.locator('.inventory-group', { hasText: title })).toHaveCount(0);
});

test('Wartungsmodus: Admin schaltet mit Begründung ein, Mitglied sieht die Wartungsseite, danach wieder normal', async ({
  page,
  browser,
}) => {
  // Mitglied hat die App offen; seine Termine liegen im Speicher des Service Workers.
  const member = await browser.newPage();
  await login(member, 'mitglied1');
  await expect(member.getByText('Vereinsabend')).toBeVisible();
  const storedApi = () =>
    member.evaluate(async () => {
      const urls: string[] = [];
      for (const name of await caches.keys()) {
        if (name.startsWith('kompanie-data')) for (const req of await (await caches.open(name)).keys()) urls.push(req.url);
      }
      return urls.length;
    });
  await expect.poll(storedApi).toBeGreaterThan(0);
  const before = await storedApi();

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByRole('button', { name: 'Einstellungen öffnen' }).click();
  const card = page.locator('.settings-section', { hasText: 'Wartungsmodus' });
  await card.getByLabel(/Begründung/).fill('Umstellung auf die neue App,\nbis ca. 18 Uhr');
  await expect(card.getByText('43/500 Zeichen')).toBeVisible();
  page.once('dialog', (d) => d.accept());
  await card.getByRole('button', { name: 'Wartungsmodus einschalten' }).click();
  await expect(page.locator('.maintenance-banner')).toContainText('Wartungsmodus aktiv – nur Admins können die App nutzen.');
  await expect(page.locator('.maintenance-banner')).toContainText('bis ca. 18 Uhr');
  await shot(page, '31-wartungsmodus-admin');

  // Das Mitglied öffnet die App erneut: nur die Wartungsseite, die gespeicherten Daten bleiben.
  await member.reload();
  await expect(member.locator('.maintenance-page')).toContainText('Die App wird gerade gewartet. Bitte versuche es später noch einmal.');
  await expect(member.locator('.maintenance-reason')).toHaveText('Umstellung auf die neue App,\nbis ca. 18 Uhr');
  await expect(member.locator('.tabbar')).toHaveCount(0);
  await member.getByRole('button', { name: 'Erneut versuchen' }).click();
  await expect(member.getByText('Die Wartung läuft noch.')).toBeVisible();
  expect(await storedApi()).toBeGreaterThanOrEqual(before);
  await shot(member, '32-wartungsmodus-mitglied');

  // Admin beendet über das Banner; das Mitglied ist nach „Erneut versuchen“ wieder in der App.
  await page.locator('.maintenance-banner').getByRole('button', { name: 'Beenden' }).click();
  await expect(page.locator('.maintenance-banner')).toHaveCount(0);
  await member.getByRole('button', { name: 'Erneut versuchen' }).click();
  await expect(member.getByText('Vereinsabend')).toBeVisible();
  await expect(member.locator('.maintenance-page')).toHaveCount(0);
  await member.close();
});
