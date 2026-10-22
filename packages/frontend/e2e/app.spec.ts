import { existsSync, readFileSync } from 'node:fs';
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

/** Menü der Verwaltung (Burger-Menü) aufklappen und einen Bereich wählen. */
async function adminMenu(page: Page, name: string) {
  const nav = page.getByRole('navigation', { name: 'Verwaltung' });
  await nav.locator('.admin-menu-toggle').click();
  await nav.locator('.admin-menu-list').getByRole('button', { name, exact: true }).click();
}

/** Bereich unter „Meins“ wählen (Burger-Menü). */
async function meinsMenu(page: Page, name: string) {
  const nav = page.locator('.meins-menu');
  await nav.locator('.admin-menu-toggle').click();
  await nav.locator('.admin-menu-list').getByRole('button', { name, exact: true }).click();
}

/** Seite eines Mitglieds in der Verwaltung wählen (zweites Menü unter dem Namen). */
async function memberMenu(page: Page, name: string) {
  const nav = page.locator('.member-menu');
  await nav.locator('.admin-menu-toggle').click();
  await nav.locator('.admin-menu-list').getByRole('button', { name, exact: true }).click();
}
/** Zählt beantwortete Aufrufe zum Speichern einer Reihenfolge (PUT …/order), um vor dem Neuladen darauf zu warten. */
function countOrderSaves(page: Page, path: string) {
  let n = 0;
  page.on('response', (r) => {
    if (r.request().method() === 'PUT' && decodeURIComponent(r.url()).includes(path)) n++;
  });
  return () => n;
}
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

  await adminMenu(page, 'Punktekatalog');
  await page.getByPlaceholder('z. B. Teilnahme am Sommerfest').fill('Teilnahme Sommerfest');
  await page.locator('.field.narrow input').fill('5');
  await page.getByRole('button', { name: 'Hinzufügen' }).click();
  await expect(page.locator('.toast')).toHaveText('Vorlage angelegt');

  await adminMenu(page, 'Punkte +');
  await page.locator('select').selectOption({ label: 'Teilnahme Sommerfest (+5)' });
  await page.getByLabel('Mitglied1').check();
  await page.getByLabel('Mitglied2').check();
  await shot(page, '6-verwaltung-punkte');
  await page.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toHaveText('+5 Punkte an 2 Mitglieder vergeben');

  await adminMenu(page, 'Kasse +');
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
  // Die Summe aller offenen Einträge steht im Bereich zum Eintragen, nicht in der Mitgliederliste.
  await expect(page.locator('.stat', { hasText: 'Offen gesamt' }).locator('.stat-value')).toContainText('7,50');

  await adminMenu(page, 'Mitglieder');
  await expect(page.locator('.row', { hasText: 'Mitglied1' })).toBeVisible();
  await expect(page.locator('.stat', { hasText: 'Offen gesamt' })).toHaveCount(0);
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await memberMenu(page, 'Kasse');
  await page.locator('.row', { hasText: 'Handy klingelt <10 min' }).getByRole('button', { name: 'Bezahlt' }).click();
  await expect(page.locator('.toast')).toHaveText('Als bezahlt markiert');
  await memberMenu(page, 'Übersicht');
  await expect(page.locator('.stat', { hasText: 'Offen' }).locator('.stat-value')).toContainText('2,50');
  await shot(page, '7-verwaltung-mitglied');
});

test('Mitgliederliste: Zurück aus einem Mitglied steht wieder an derselben Stelle', async ({ page }) => {
  await login(page, 'verwalter');
  // Niedriges Fenster, damit die wenigen Testmitglieder schon scrollen.
  await page.setViewportSize({ width: 390, height: 360 });
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  const row = page.locator('.row', { hasText: 'Mitglied2' });
  await row.scrollIntoViewIfNeeded();
  const y = await page.evaluate(() => window.scrollY);
  expect(y).toBeGreaterThan(0);

  for (const back of [() => page.getByRole('button', { name: '‹ Alle Mitglieder' }).click(), () => page.goBack()]) {
    await row.click();
    await expect(page.locator('.member-name')).toHaveText('Mitglied2');
    await back();
    await expect(page.getByLabel('Mitglied suchen')).toHaveValue('Mitglied');
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(y);
  }

  // Neu geöffnet beginnt die Liste wieder oben.
  await page.locator('.tabbar').getByRole('button', { name: 'Termine' }).click();
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await expect(page.getByLabel('Mitglied suchen')).toHaveValue('');
  expect(await page.evaluate(() => window.scrollY)).toBe(0);
});

/** Waagerecht über den Namen auf der Seite eines Mitglieds wischen (Touch-Ereignisse über CDP, wie ein Finger). */
async function swipe(page: Page, dx: number) {
  const box = (await page.locator('.member-name').boundingBox())!;
  const cdp = await page.context().newCDPSession(page);
  const { width } = page.viewportSize()!;
  const x = width / 2 - dx / 2;
  const y = box.y + box.height / 2;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  for (const f of [0.25, 0.5, 0.75, 1]) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx * f, y }] });
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await cdp.detach();
}

test('Mitglied: Wischen blättert in der Liste und bleibt auf derselben Unterseite', async ({ page }) => {
  await login(page, 'verwalter');
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');
  await expect(page.getByRole('button', { name: 'Vorheriges Mitglied' })).toBeDisabled();
  await memberMenu(page, 'Kasse');

  // Nach links: nächstes Mitglied, weiter auf „Kasse“.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page).toHaveURL(/\/strafen$/);
  await expect(page.locator('.member-menu .admin-menu-current')).toHaveText('Kasse');
  await expect(page.locator('.member-step')).toContainText('2 / 2');
  // Am Ende der Liste geht es nicht weiter.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');

  // Nach rechts bzw. mit dem Pfeil wieder zurück und vor.
  await swipe(page, 200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await page.getByRole('button', { name: 'Nächstes Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-menu .admin-menu-current')).toHaveText('Kasse');

  // Wischen legt keine Einträge im Verlauf an: „Zurück“ nimmt die Wahl der Unterseite zurück, dann geht es zur Liste.
  await page.goBack();
  await expect(page).toHaveURL(/\/mitglied\/\d+$/);
  await page.goBack();
  await expect(page.getByLabel('Mitglied suchen')).toHaveValue('Mitglied');
});

test('Wischen auch bei Ehemaligen und ohne vorher geöffnete Liste (Link, Neustart)', async ({ page }) => {
  const id = Date.now() % 1000000;
  const last = `Altwisch${id}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.evaluate(async (last) => {
    const cfg = window.KOMPANIE_CONFIG!;
    const headers = { [cfg.csrf.header]: cfg.csrf.token, 'Content-Type': 'application/json' };
    const csv = `vorname;nachname;eintritt;ausgeschieden;grund\nAnton;${last};1970;2000;ausgetreten\nBerta;${last};1980;2010;ausgetreten`;
    await fetch(cfg.apiBase + 'members/former/import', {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({ csv, apply: true }),
    });
  }, last);
  // Gemerkte Sortierung aus anderen Tests weg: nach Nachname, dann Vorname
  await page.evaluate(() => localStorage.removeItem('kompanie_member_sort'));
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Ehemalige');
  await page.getByLabel('Ehemalige suchen').fill(last);
  await page.locator('.row', { hasText: 'Anton' }).click();
  await expect(page.locator('.member-name')).toHaveText(`Anton ${last}`);
  await expect(page.locator('.member-step')).toContainText('1 / 2');
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText(`Berta ${last}`);
  await expect(page).toHaveURL(/#\/verwaltung\/ehemalige\/\d+$/);

  // Neu geladen (wie ein Link): ganze Liste der Ehemaligen in der gemerkten Sortierung
  await page.reload();
  await expect(page.locator('.member-name')).toHaveText(`Berta ${last}`);
  await expect(page.locator('.member-step')).toContainText(/\d+ \/ \d+/);
  await page.getByRole('button', { name: 'Vorheriges Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText(`Anton ${last}`);

  // Ebenso bei Mitgliedern, die Unterseite bleibt
  await page.goto('/app/#/verwaltung/mitglieder');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await memberMenu(page, 'Kasse');
  await page.reload();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText(/\d+ \/ \d+/);
  await page.getByRole('button', { name: 'Nächstes Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-menu .admin-menu-current')).toHaveText('Kasse');
});

test('Admin legt ein Mitglied an und setzt sein Passwort zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByLabel('Vorname').fill('Neue');
  await page.getByLabel('Nachname').fill('Testperson');
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByText('Neue Testperson ist angelegt')).toBeVisible();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();
  expect(username).toMatch(/^neue\.testperson\d*$/);
  expect(password).toHaveLength(12);
  await shot(page, '8-mitglied-angelegt');

  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  // Standard-Sortierung nach Nachname: „Nachname, Vorname“.
  await page.locator('.row', { hasText: 'Testperson, Neue' }).first().click();
  await memberMenu(page, 'Konto und Rolle');
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
  await rows.nth(0).getByLabel('Vorname').fill('Ida');
  await rows.nth(0).getByLabel('Nachname').fill(id);
  await rows.nth(1).getByLabel('Vorname').fill('Jan');
  await rows.nth(1).getByLabel('Nachname').fill(id);
  await page.getByRole('button', { name: 'Alle anlegen' }).click();
  await expect(page.getByRole('heading', { name: '2 Konten angelegt' })).toBeVisible();
  await expect(page.locator('.print-sheet')).toHaveCount(2);
  await expect(page.locator('.print-sheet').first()).toContainText('installieren');
  await expect(page.getByRole('button', { name: 'Ausdrucken' })).toBeVisible();

  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  await page.locator('.row', { hasText: `${id}, Ida` }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page.getByRole('button', { name: 'Angaben ändern' }).click();
  await expect(page.locator('.account-edit').getByLabel('Vorname')).toHaveValue('Ida');
  await expect(page.locator('.account-edit').getByLabel('Nachname')).toHaveValue(id);
  await page.locator('.account-edit').getByLabel('Vorname').fill('Ida Neu');
  await page.locator('.account-edit').getByLabel('E-Mail (optional)').fill(`ida-${id}@example.org`);
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.locator('.member-name')).toHaveText(`Ida Neu ${id}`);
  await expect(page.locator('.account-panel')).toContainText(`ida-${id}@example.org`);

  await page.getByRole('button', { name: 'Alle Mitglieder' }).click();
  await page.locator('.row', { hasText: `${id}, Jan` }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page
    .locator('.account-panel')
    .getByRole('button', { name: /Konto löschen|Aus der App entfernen/ })
    .click();
  const confirm = page.getByRole('button', { name: /Endgültig (löschen|entfernen)/ });
  await expect(confirm).toBeDisabled();
  await page.getByLabel('Zur Bestätigung den Namen eintippen').fill(`jan ${id}`);
  await confirm.click();
  await expect(page.locator('.toast')).toContainText(`Jan ${id}`);
  await expect(page.locator('.row', { hasText: `${id}, Jan` })).toHaveCount(0);
});

/** Kleines gültiges PNG (1 × 1 Pixel) zum Hochladen. */
const PNG = {
  name: 'foto.png',
  mimeType: 'image/png',
  buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64'),
};

test('Fotos: beim Anlegen mitgeben (einzeln und mehrere), in Liste und Details, entfernen; Mitglied sieht sein Foto', async ({ page }) => {
  const id = Date.now().toString(36);
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByLabel('Vorname').fill('Fiona');
  await page.getByLabel('Nachname').fill(id);
  await page.locator('.new-member-photo').getByLabel('Foto-Datei').setInputFiles(PNG);
  await expect(page.locator('.new-member-photo .member-avatar img')).toBeVisible();
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  await expect(page.getByText(`Fiona ${id} ist angelegt`)).toBeVisible();
  await expect(page.locator('.print-sheet .form-error')).toHaveCount(0);
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  // Mehrere: Foto nur bei der zweiten Person
  await page.getByRole('button', { name: 'Weiteres Mitglied' }).click();
  await page.getByRole('button', { name: 'Mehrere auf einmal anlegen' }).click();
  const rows = page.locator('.bulk-row');
  await rows.nth(0).getByLabel('Vorname').fill('Gerd');
  await rows.nth(0).getByLabel('Nachname').fill(id);
  await rows.nth(1).getByLabel('Vorname').fill('Hanna');
  await rows.nth(1).getByLabel('Nachname').fill(id);
  await rows.nth(1).getByLabel('Foto-Datei').setInputFiles(PNG);
  await expect(rows.nth(1).locator('.member-avatar img')).toBeVisible();
  await page.getByRole('button', { name: 'Alle anlegen' }).click();
  await expect(page.getByRole('heading', { name: '2 Konten angelegt' })).toBeVisible();
  await expect(page.locator('.print-sheet .form-error')).toHaveCount(0);

  // Liste: Foto bzw. Anfangsbuchstaben; Details: Foto neben dem Dienstgrad, entfernen
  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  await page.getByLabel('Mitglied suchen').fill(id);
  await expect(page.locator('.row', { hasText: `${id}, Hanna` }).locator('.member-avatar img')).toBeVisible();
  await expect(page.locator('.row', { hasText: `${id}, Gerd` }).locator('.member-avatar-initials')).toHaveText(`G${id[0].toUpperCase()}`);
  await page.locator('.row', { hasText: `${id}, Hanna` }).click();
  await expect(page.locator('.member-rank-line .member-avatar img')).toBeVisible();
  // Antippen vergrößert das Foto; bis es geladen ist, dreht sich eine Ladeschnecke. Escape und ✕ schließen die Ansicht.
  let release = () => {};
  const held = new Promise<void>((resolve) => (release = resolve));
  const bigPhoto = /[?&]s=1024(&|$)/;
  await page.route(bigPhoto, async (route) => {
    await held;
    await route.continue();
  });
  await page.getByRole('button', { name: /vergrößern/ }).click();
  const zoom = page.getByRole('dialog', { name: /^Foto von / });
  await expect(zoom.getByRole('status', { name: 'Foto lädt' })).toBeVisible();
  await expect(zoom.locator('img')).toBeHidden();
  release();
  await expect(zoom.locator('img')).toBeVisible();
  await expect(zoom.getByRole('status', { name: 'Foto lädt' })).toHaveCount(0);
  await page.unroute(bigPhoto);
  // Vergrößert die 1024-Pixel-Fassung (scharf auch bei hoher Pixeldichte)
  await expect(zoom.locator('img')).toHaveAttribute('src', /[?&]s=1024(&|$)/);
  await page.keyboard.press('Escape');
  await expect(zoom).toHaveCount(0);
  await page.getByRole('button', { name: /vergrößern/ }).click();
  await zoom.getByRole('button', { name: 'Schließen' }).click();
  await expect(zoom).toHaveCount(0);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Foto entfernen' }).click();
  await expect(page.locator('.toast')).toContainText('Foto entfernt');
  await expect(page.getByRole('button', { name: 'Foto hochladen' })).toBeVisible();
  await expect(page.locator('.member-rank-line .member-avatar-initials')).toBeVisible();

  // Das Mitglied sieht sein Foto unter „Konto“, kann es dort aber nicht ändern.
  await login(page, username, password);
  await page.locator('.topbar-account').click();
  await expect(page.locator('.account-card .member-avatar-large img')).toBeVisible();
  await expect(page.getByRole('button', { name: /Foto/ })).toHaveCount(0);

  // In der Mitgliederliste („Mitglieder“) vergrößert auch ein Mitglied ohne Verwaltungsrechte ein Foto.
  await page.locator('.tabbar').getByRole('button', { name: 'Mitglieder' }).click();
  await page.getByLabel('Mitglied suchen').fill(id);
  await page
    .locator('.directory .member-row', { hasText: 'Fiona' })
    .getByRole('button', { name: /vergrößern/ })
    .click();
  const big = page.getByRole('dialog', { name: `Foto von Fiona ${id}` }).locator('img');
  await expect(big).toBeVisible();
  await expect.poll(() => big.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  await page.keyboard.press('Escape');
  await expect(big).toHaveCount(0);
});

test('Anwärter: sieht den internen Kalender, aber keinen Mitgliederbereich, bis er aufgenommen ist', async ({ page }) => {
  // Eindeutiger Name: Bei WordPress bleiben Konten früherer Läufe bestehen.
  const last = `Anwärterin ${Date.now() % 1000000}`;
  const name = `Anna ${last}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  // Anwärter sind eine eigene Rolle: nur Kalender, für neue Konten, als Mitglied aufzunehmen (bei WordPress ggf. schon da).
  await adminMenu(page, 'Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Rollen', { exact: true }) })
    .click();
  const rollen = page.locator('#einstellungen-rollen');
  await expect(rollen.locator('.row-title').first()).toBeVisible();
  if ((await rollen.locator('.row-title', { hasText: /^Anwärter$/ }).count()) === 0) {
    await rollen.getByRole('button', { name: '+ Neue Rolle' }).click();
    await rollen.getByLabel('Name der Rolle').fill('Anwärter');
    await rollen.getByLabel(/^Mitgliederbereich/).uncheck();
    await rollen.getByLabel(/^Interner Kalender/).check();
    await rollen.getByLabel(/^Beim Anlegen neuer Konten wählbar/).check();
    await rollen.getByLabel(/^Wer Mitglieder anlegen darf, nimmt/).check();
    await rollen.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.toast')).toHaveText('Rolle angelegt');
  }
  await expect(rollen.locator('.row', { hasText: /^Anwärter/ })).toContainText('für neue Konten');
  // Auch feste Rollen lassen sich verschieben: Anwärter am Griff (Pfeil hoch) zwischen Gast und Mitglied.
  const all = async () => (await rollen.locator('.role-item .row-title').allTextContents()).map((t) => t.trim());
  const titles = async () => (await all()).slice(0, 3);
  // exact: Im WordPress-Test gibt es aus dem API-Szenario auch „Kptest Anwärter“.
  const handle = rollen.getByRole('button', { name: 'Anwärter verschieben (ziehen oder Pfeiltasten)', exact: true });
  const saves = countOrderSaves(page, 'roles/order');
  let moves = 0;
  await handle.focus();
  for (let i = (await all()).indexOf('Anwärter'); i > 1; i--, moves++) {
    await page.keyboard.press('ArrowUp');
    await expect.poll(async () => (await all()).indexOf('Anwärter')).toBe(i - 1);
  }
  if ((await all()).indexOf('Anwärter') === 0) {
    await page.keyboard.press('ArrowDown');
    moves++;
  }
  await expect.poll(titles).toEqual(['Gast', 'Anwärter', 'Mitglied']);
  // Erst neu laden, wenn alles gespeichert ist (das Speichern läuft im Hintergrund nacheinander).
  await expect.poll(saves, { timeout: 15_000 }).toBe(moves);
  await page.reload();
  await expect.poll(titles).toEqual(['Gast', 'Anwärter', 'Mitglied']);

  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByLabel('Vorname').fill('Anna');
  await page.getByLabel('Nachname').fill(last);
  await page.getByLabel('Rolle').selectOption({ label: 'Anwärter' });
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  await login(page, username, password);
  await expect(page.getByText('Vereinsabend')).toBeVisible(); // interner Kalender
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  // Zähler über „Mitglied anlegen“: Gruppen wie in der Anwesenheit
  await expect(page.locator('.member-counts')).toContainText(/Anwärter \d+/);
  // Filter und Sortierung sind zunächst eingeklappt.
  await expect(page.getByRole('button', { name: 'Filter und Sortierung' })).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByLabel('Sortierung')).toBeHidden();
  await page.locator('.row', { hasText: `${last}, Anna` }).click();
  await memberMenu(page, 'Konto und Rolle');
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Als Mitglied aufnehmen' }).click();
  await expect(page.locator('.toast')).toHaveText(`${name} ist jetzt Mitglied`);
  await expect(page.getByRole('button', { name: 'Als Mitglied aufnehmen' })).toHaveCount(0);

  await login(page, username, password);
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
});

test('Mitgliederliste: Mitglied sieht alle Mitglieder mit Suche, ohne Punkte oder Rollen', async ({ page }) => {
  await login(page, 'mitglied1');
  await page.locator('.tabbar').getByRole('button', { name: 'Mitglieder' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Mitglieder');
  const list = page.locator('.directory .card-list');
  await expect(list.locator('.member-row', { hasText: 'Mitglied2' })).toBeVisible();
  await expect(list.locator('.member-row', { hasText: 'Verwalter' })).toBeVisible();
  // Zähler je Gruppe wie in der Verwaltung
  await expect(page.locator('.directory .member-counts')).toHaveText(/^Mitglieder? \d+/);
  await expect(list).not.toContainText(/Punkte|offen/);
  await page.getByLabel('Mitglied suchen').fill('mitglied2');
  await expect(list.locator('.member-row')).toHaveCount(1);
  await shot(page, '1-mitgliederliste');

  // Öffentlich (Issue #112): auch ohne Anmeldung, ebenfalls ohne Punkte
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page.locator('.topbar').getByRole('button', { name: 'Anmelden' })).toBeVisible();
  await page.locator('.tabbar').getByRole('button', { name: 'Mitglieder' }).click();
  await expect(list.locator('.member-row', { hasText: 'Mitglied2' })).toBeVisible();
  await expect(list).not.toContainText(/Punkte|offen/);
});

test.describe('ohne Service Worker', () => {
  // page.route sieht Anfragen des Service Workers nicht
  test.use({ serviceWorkers: 'block' });

  test('Ehemalige: auf der Seite einer ehemaligen Person bleibt „Ehemalige“ markiert', async ({ page }) => {
    // Die Liste kommt aus dem Test, damit keine Daten zurückbleiben.
    await page.route(/directory\/former(?![\w/])/, (route) =>
      route.fulfill({
        json: [
          {
            id: 987654,
            name: 'Erwin Ehemalig',
            first_name: 'Erwin',
            last_name: 'Ehemalig',
            photo_url: null,
            founder: true,
            joined_year: '1985',
            left_year: '2010',
          },
        ],
      }),
    );
    await page.goto('/app/#/mitglieder/ehemalige');
    const switcher = page.locator('.members-switch');
    const list = page.locator('.directory .card-list');
    await list.locator('.member-row', { hasText: 'Ehemalig, Erwin' }).locator('.member-row-open').click();
    await expect(page).toHaveURL(/#\/mitglieder\/987654$/);
    await expect(page.locator('.member-page')).toContainText('Ehemalig · Gründungsmitglied · 1985–2010');
    await expect(switcher.getByRole('button', { name: 'Ehemalige' })).toHaveAttribute('aria-pressed', 'true');
    await expect(switcher.getByRole('button', { name: 'Mitglieder' })).toHaveAttribute('aria-pressed', 'false');

    // Bei Mitgliedern bleibt es „Mitglieder“
    await switcher.getByRole('button', { name: 'Mitglieder' }).click();
    await list.locator('.member-row', { hasText: 'Mitglied2' }).locator('.member-row-open').click();
    await expect(page.locator('.member-page').getByRole('heading', { name: 'Mitglied2' })).toBeVisible();
    await expect(switcher.getByRole('button', { name: 'Mitglieder' })).toHaveAttribute('aria-pressed', 'true');
    await expect(switcher.getByRole('button', { name: 'Ehemalige' })).toHaveAttribute('aria-pressed', 'false');
  });
});

test('Ämter: pflegen in der Verwaltung, Zeitstrahl öffentlich im Tab Mitglieder', async ({ page }) => {
  const id = Date.now() % 1000000;
  const group = `Verein ${id}`;
  const office = `Kassenprüfer ${id}`;
  const former = `Erich Ehemalig${id}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  // Ein Ehemaliger mit Konto (Import wie aus einer Chronik), wählbar als Inhaber
  const formerId = await page.evaluate(async (name) => {
    const cfg = window.KOMPANIE_CONFIG!;
    const headers = { [cfg.csrf.header]: cfg.csrf.token, 'Content-Type': 'application/json' };
    const csv = `vorname;nachname;eintritt;ausgeschieden;grund\n${name.split(' ').join(';')};1980;2010;ausgetreten`;
    await fetch(cfg.apiBase + 'members/former/import', {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({ csv, apply: true }),
    });
    const list: { id: number; name: string }[] = await (
      await fetch(cfg.apiBase + 'members/former', { headers, credentials: 'same-origin' })
    ).json();
    return list.find((m) => m.name === name)?.id;
  }, former);
  expect(formerId).toBeTruthy();
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Ämter');
  await page.getByLabel('Neue Gruppe').fill(group);
  await page.getByRole('button', { name: 'Gruppe anlegen' }).click();
  await expect(page.locator('.toast')).toHaveText('Gruppe angelegt');
  const newOffice = page.locator('form', { hasText: 'Neues Amt' });
  await newOffice.getByLabel('Bezeichnung').fill(office);
  await newOffice.getByLabel('Gruppe').selectOption({ label: group });
  await newOffice.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.toast')).toHaveText('Amt angelegt');
  await page.getByRole('button', { name: office, exact: true }).click();

  // Frühere Amtszeit mit einem Namen ohne Konto
  await page.getByRole('button', { name: 'Amtszeit eintragen' }).click();
  let form = page.locator('form', { hasText: 'Neue Amtszeit' });
  await form.getByLabel('Von (Jahr)').fill('1990');
  await form.getByLabel('Bis (leer: bis heute)').fill('2005');
  await form.getByLabel('Name', { exact: true }).fill(`Frieda Früher ${id}`);
  // Zweite Person: ein Ehemaliger mit Konto
  await form.getByRole('button', { name: 'Weitere Person' }).click();
  const formerRow = form.locator('.office-holder-row').nth(1);
  await formerRow.getByRole('combobox', { name: 'Profil' }).selectOption({ label: 'Ehemaliger' });
  await formerRow.getByRole('combobox', { name: 'Ehemaliger', exact: true }).selectOption({ label: former });
  await form.getByLabel(/Zusatz/).fill('Gründungsmitglied');
  await form.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Amtszeit eingetragen');
  // Beim Bearbeiten stehen beide wieder unter ihrem Profil
  await page.locator('.office-term-row', { hasText: '1990' }).getByRole('button', { name: 'Bearbeiten' }).click();
  form = page.locator('form', { hasText: 'Amtszeit bearbeiten' });
  await expect(form.locator('.office-holder-row').nth(0).getByRole('combobox', { name: 'Profil' })).toHaveValue('name');
  await expect(form.locator('.office-holder-row').nth(1).getByRole('combobox', { name: 'Profil' })).toHaveValue('former');
  await expect(form.getByRole('combobox', { name: 'Ehemaliger', exact: true })).toHaveValue(String(formerId));
  await form.getByRole('button', { name: 'Abbrechen' }).click();
  // Laufende Amtszeit mit einem Konto und einem zweiten Namen
  await page.getByRole('button', { name: 'Amtszeit eintragen' }).click();
  form = page.locator('form', { hasText: 'Neue Amtszeit' });
  await form.getByLabel('Von (Jahr)').fill('2020');
  await form.getByRole('combobox', { name: 'Profil' }).first().selectOption({ label: 'Mitglied' });
  await form.getByRole('combobox', { name: 'Mitglied', exact: true }).selectOption({ label: 'Mitglied1' });
  await form.getByRole('button', { name: 'Weitere Person' }).click();
  await form.locator('.office-holder-row').nth(1).getByLabel('Name', { exact: true }).fill(`Paul Partner ${id}`);
  await form.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Amtszeit eingetragen');
  await expect(page.locator('.office-term-row').first()).toContainText('seit 2020');

  // Ohne Anmeldung: Zeitstrahl im Tab Mitglieder, laufende Amtszeit oben
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.locator('.tabbar').getByRole('button', { name: 'Mitglieder' }).click();
  await page.getByRole('button', { name: 'Ämter', exact: true }).click();
  await expect(page).toHaveURL(/#\/mitglieder\/aemter$/);
  const card = page.locator('.office-card', { hasText: office });
  await expect(card.locator('.office-summary-text')).toHaveText(`Mitglied1, Paul Partner ${id} · seit 2020`);
  await expect(card.locator('.office-meta')).toHaveText('Chronik seit 1990 · 2 Einträge');
  await card.locator('.office-head').click();
  // Laufende Amtszeit oben unter „Im Amt“, die frühere in der Chronik
  const spotlight = card.locator('.spotlight');
  await expect(spotlight.locator('.spotlight-person')).toHaveCount(2);
  await expect(spotlight).toContainText('seit 2020');
  const items = card.locator('.tl-item');
  await expect(items).toHaveCount(1);
  await expect(items.nth(0)).toContainText('1990–2005');
  await expect(items.nth(0)).toContainText('Gründungsmitglied');
  await expect(items.nth(0)).toContainText(former);
  await shot(page, '1b-aemter-zeitstrahl');
  if (SHOTS) {
    // Breiter Bildschirm: abwechselnd links und rechts
    await page.setViewportSize({ width: 1000, height: 900 });
    await shot(page, '1c-aemter-zeitstrahl-breit');
  }

  // Schmaler Bildschirm: nichts ragt seitlich heraus
  await page.setViewportSize({ width: 360, height: 740 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  // Personen der Mitgliederliste: Tipp auf den Namen öffnet ihre Seite mit den Ämtern als Zeitstrahl
  await spotlight.getByRole('button', { name: /^Mitglied1/ }).click();
  await expect(page.locator('.member-page').getByRole('heading', { name: 'Mitglied1' })).toBeVisible();
  await expect(page.locator('.member-page .tl-item', { hasText: office })).toContainText('seit 2020');
  // Ebenso aus der Liste: ganze Zeile antippen, ohne eigenen Knopf
  await page.goto('/app/#/mitglieder/liste');
  await expect(page.locator('.member-row', { hasText: 'Mitglied1' }).getByRole('button', { name: 'Ämter' })).toHaveCount(0);
  await page.locator('.member-row', { hasText: 'Mitglied1' }).locator('.member-row-open').click();
  await expect(page).toHaveURL(/#\/mitglieder\/\d+$/);
  await expect(page.locator('.member-page .tl-item', { hasText: office })).toBeVisible();

  // Aufräumen (WordPress behält die Daten zwischen den Läufen)
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/verwaltung/aemter');
  await page.getByRole('button', { name: office, exact: true }).click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Amt löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Amt gelöscht');
  page.once('dialog', (d) => d.accept());
  await page.locator('.row', { hasText: group }).getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Gelöscht');
  const deleted = await page.evaluate(async (formerId) => {
    const cfg = window.KOMPANIE_CONFIG!;
    return (
      await fetch(cfg.apiBase + `members/${formerId}`, {
        method: 'DELETE',
        headers: { [cfg.csrf.header]: cfg.csrf.token },
        credentials: 'same-origin',
      })
    ).status;
  }, formerId);
  expect(deleted).toBeLessThan(300);
});

test('Listen: anlegen, Mitglieder aktualisieren, Spalten und Zeilen, Vorschau, CSV und Druck', async ({ page }) => {
  const title = `Unterschriften ${Date.now() % 1000000}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Listen');
  const create = page.locator('form', { hasText: 'Neue Liste' });
  await create.getByLabel('Titel').fill(title);
  await create.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page).toHaveURL(/#\/verwaltung\/listen\/\d+$/);
  await expect(page.locator('.list-rows')).toHaveCount(0);

  // Mitglieder aktualisieren: erst die Übersicht, dann übernehmen
  await page.getByRole('button', { name: 'Mitglieder aktualisieren' }).click();
  const sync = page.getByRole('dialog', { name: 'Mitglieder aktualisieren' });
  await expect(sync).toContainText('Mitglied1');
  await sync.getByRole('button', { name: 'Übernehmen' }).click();
  await expect(page.locator('.toast')).toHaveText('Mitglieder aktualisiert');
  await expect(page.locator('.list-row', { hasText: 'Mitglied1' })).toHaveCount(1);
  const members = await page.locator('.list-row').count();
  // Nichts mehr zu tun
  await page.getByRole('button', { name: 'Mitglieder aktualisieren' }).click();
  await expect(page.locator('.toast')).toHaveText('Die Liste ist aktuell');
  // Zeile eines Mitglieds von Hand entfernt (noch nicht gespeichert): Aktualisieren speichert erst und bietet sie wieder an
  await page
    .locator('.list-row', { hasText: 'Mitglied1' })
    .getByRole('button', { name: /entfernen$/ })
    .click();
  await page.getByRole('button', { name: 'Mitglieder aktualisieren' }).click();
  await expect(sync).toContainText('1 Zeile kommt dazu: Mitglied1');
  await expect(page.locator('.list-row', { hasText: 'Mitglied1' })).toHaveCount(0);
  await sync.getByRole('button', { name: 'Übernehmen' }).click();
  await expect(page.locator('.list-row', { hasText: 'Mitglied1' })).toHaveCount(1);
  await expect(page.locator('.list-row')).toHaveCount(members);

  // Leere Spalte mit Kästchen, zwei eigene Zeilen (eine mit Namen), speichern
  await page.getByRole('button', { name: '+ Leere Spalte' }).click();
  const column = page.locator('.list-column').last();
  await column.getByLabel('Überschrift').fill('Bezahlt');
  await column.getByLabel('Breite').selectOption('narrow');
  await column.getByLabel('Kästchen zum Abhaken').check();
  await page.getByRole('button', { name: '+ Leere Spalte' }).click();
  const count = page.locator('.list-column').last();
  await count.getByLabel('Überschrift').fill('Anzahl Kinder');
  await count.getByLabel('Breite').selectOption('narrow');
  await page.getByRole('button', { name: '+ Leere Zeile' }).click();
  await page.getByRole('button', { name: '+ Leere Zeile' }).click();
  await page.getByLabel(`Zeile ${members + 1}: Name`).fill('Gast vom Nachbarverein');
  await page.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
  await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);

  // Vorschau wie gedruckt: Kopf mit Titel, Spalten, Kästchen, eigene Zeilen
  const preview = page.locator('.list-preview');
  await expect(preview.locator('.list-sheet-title')).toHaveText(title);
  // Ab der Spalte vor der ersten gedrehten ist der Kopf eine Zelle (sonst schneidet Chrome ihn im Druck ab)
  await expect(preview.locator('thead th:not(.list-th-slanted)')).toHaveText(['Name', 'Vorname']);
  await expect(preview.locator('thead th.list-th-slanted .list-th-straight')).toHaveText(['Unterschrift']);
  // Schmale Spalten mit gedrehter Überschrift (wie in den Tabellen der Kompanie), Nr. nicht
  await expect(preview.locator('thead th.list-th-slanted .list-th-label')).toHaveText(['Bezahlt', 'Anzahl Kinder']);
  // Titel der Seite = Titel der Liste: Den schlägt der Browser als Namen der PDF-Datei vor
  await expect(page).toHaveTitle(title);
  await expect(preview.locator('tbody tr')).toHaveCount(members + 2);
  await expect(preview.locator('tbody tr').nth(members)).toHaveText('NachbarvereinGast vom☐');

  // CSV für Excel: BOM, Semikolon
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'CSV herunterladen' }).click()]);
  expect(download.suggestedFilename()).toBe(`${title}.csv`);
  const csv = readFileSync((await download.path())!, 'utf8');
  expect(csv.startsWith(String.fromCharCode(0xfeff) + 'Name;Vorname;Unterschrift;Bezahlt;Anzahl Kinder\r\n')).toBe(true);
  expect(csv).toContain(';Mitglied1;;;\r\n');
  expect(csv).toContain('\r\nNachbarverein;Gast vom;;;\r\n');

  // Druck: nur die Liste, ohne Kopf- und Tab-Leiste der App
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.list-print-root .list-table')).toBeVisible();
  await expect(page.locator('.tabbar')).toBeHidden();
  await expect(page.locator('.list-editor')).toBeHidden();
  await shot(page, '40b-liste-druck');
  // Auf A4, wie gedruckt (nur mit Bildschirmfotos)
  if (SHOTS) await page.pdf({ path: `${SHOTS}/40c-liste.pdf`, format: 'A4', preferCSSPageSize: true });
  await page.emulateMedia({ media: 'screen' });
  await expect(page.locator('.list-print-root')).toBeHidden();
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/40a-liste-bearbeiten.png`, fullPage: true });

  // Schmaler Bildschirm: nichts ragt seitlich heraus (die Vorschau scrollt in sich)
  await page.setViewportSize({ width: 360, height: 740 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

  // Kopieren und löschen (WordPress behält die Daten zwischen den Läufen)
  await page.getByRole('button', { name: /Alle Listen/ }).click();
  await expect(page).toHaveURL(/#\/verwaltung\/listen$/);
  await expect(page).toHaveTitle('Musterkompanie Beispielstadt');
  await page.locator('.row', { hasText: title }).first().getByRole('button', { name: 'Kopieren' }).click();
  await expect(page.locator('.toast')).toHaveText('Liste kopiert');
  for (const t of [`${title} (Kopie)`, title]) {
    page.once('dialog', (d) => d.accept());
    await page
      .locator('.row', { has: page.locator('.row-title', { hasText: new RegExp(`^${t.replace(/[()]/g, '\\$&')}$`) }) })
      .getByRole('button', { name: 'Löschen' })
      .click();
    await expect(page.locator('.toast')).toHaveText('Gelöscht');
  }
  await expect(page.locator('.row', { hasText: title })).toHaveCount(0);
});

test('Verwalter ohne Admin-Rechte kann weder anlegen noch zurücksetzen', async ({ page }) => {
  await login(page, 'verwalter');
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await expect(page.locator('.row', { hasText: 'Mitglied1' })).toBeVisible();
  await expect(page.getByRole('button', { name: '+ Mitglied anlegen' })).toHaveCount(0);
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await memberMenu(page, 'Konto und Rolle');
  await expect(page.getByRole('button', { name: /Neues Startpasswort/ })).toHaveCount(0);
});

test('Mitglied 1 sieht eigene Punkte und Kasse', async ({ page }) => {
  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Meins' }).click();
  // Übersicht zuerst: Name, Rolle und beide Summen
  const overview = page.locator('.my-overview');
  await expect(overview.locator('.account-name')).toBeVisible();
  await expect(overview.locator('.stat-value')).toHaveText(['5', /2,50/]);
  await shot(page, 'meins-uebersicht');

  await meinsMenu(page, 'Punkte');
  // Erst wenn die Übersicht weg ist, gibt es nur noch eine Kachel.
  await expect(overview).toHaveCount(0);
  await expect(page.locator('.stat-value')).toHaveText('5');
  await expect(page.getByText('Teilnahme Sommerfest')).toBeVisible();
  await shot(page, '2-punkte');

  await meinsMenu(page, 'Kasse');
  await expect(page.locator('.stat-value')).toContainText('2,50');
  await expect(page.getByText('Zu spät gekommen')).toBeVisible();
  await expect(page.getByText('Handy klingelt <10 min')).toBeVisible();
  await shot(page, '3-kasse');
});

test('Mitglied 2 sieht keine fremden Einträge und keine Verwaltungsdaten', async ({ page }) => {
  await login(page, 'mitglied2');
  await page.getByRole('button', { name: 'Meins' }).click();
  await meinsMenu(page, 'Kasse');
  await expect(page.locator('.my-overview')).toHaveCount(0);
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
  await expect(page.locator('.stat-label').first()).toHaveText('Meine Punkte');

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
    // Headless-Chromium verwirft gelegentlich eine angezeigte Benachrichtigung: Das Push-Ereignis kommt an und
    // showNotification() meldet Erfolg, getNotifications() liefert direkt danach aber nichts (Issue #68, etwa jeder
    // zehnte Lauf). Dann noch einmal zustellen; dieselbe Kennung (tag) ersetzt die vorige, es bleibt eine.
    await expect(async () => {
      await cdp.send('ServiceWorker.deliverPushMessage', {
        origin: new URL(page.url()).origin,
        registrationId: regs[0],
        data: JSON.stringify({ title: 'Neuer Termin', body: 'Sommerfest', route: 'termine', tag: 'termin-1' }),
      });
      await expect
        .poll(
          () =>
            page.evaluate(async () =>
              (await (await navigator.serviceWorker.ready).getNotifications()).map((n) => [n.title, n.body, n.tag, n.data.route, n.icon]),
            ),
          { timeout: 3000 },
        )
        .toEqual([['Neuer Termin', 'Sommerfest', 'termin-1', 'termine', expect.stringMatching(/icons\/icon-192\.png$/)]]);
    }).toPass({ timeout: 15_000 });
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

test('Ohne gespeicherte Daten zeigt eine Seite beim Laden ihr Gerüst statt „Lädt …“', async ({ page }) => {
  await page.context().clearCookies();
  const layouts = () =>
    page.evaluate(async () => {
      const cache = await caches.open('kompanie-ansicht-geruest-v1');
      return Promise.all((await cache.keys()).map(async (k) => decodeURIComponent(k.url) + (await (await cache.match(k))!.text())));
    });
  // Erst über die Navigation zu den News, später direkt: Das Gerüst muss trotzdem passen.
  await page.goto('/app/');
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await page.getByRole('button', { name: 'News' }).click();
  await expect(page.locator('.news-card').first()).toBeVisible();
  // Sofort weiter (wie in WordPress, wo die Daten schnell da sind): Das Gerüst ist trotzdem gemerkt.
  await page.getByRole('button', { name: 'Termine' }).click();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect.poll(async () => (await layouts()).some((l) => l.includes('/news|'))).toBe(true);
  // Nur der Aufbau, kein Text
  expect((await layouts()).join('')).not.toContain('Tag der offenen Tür');

  // Keine gespeicherten Daten (auch nicht beim Service Worker), News antworten sehr langsam.
  await page.evaluate(() => caches.delete('kompanie-data-v2'));
  await page.context().route(/news/, async (route) => {
    await new Promise((r) => setTimeout(r, 8000));
    await route.continue().catch(() => undefined);
  });
  try {
    await page.goto('/app/#/news');
    await page.reload();
    await expect(page.locator('.skeleton .news-card').first()).toBeVisible({ timeout: 1500 });
    await expect(page.locator('.loading')).toHaveCount(0);
    await shot(page, 'geruest-news');
    await expect(page.locator('.news-card:not(.skeleton .news-card)').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('.skeleton')).toHaveCount(0);
  } finally {
    await page.context().unrouteAll({ behavior: 'ignoreErrors' });
  }
});

test('Admin stuft ein Mitglied zum Gast herab und zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  if (!STANDALONE) await expect(page.locator('.role-control option', { hasText: 'Admin' })).toHaveCount(0); // WordPress: Admins im Backend
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Gast' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Gast');
  await shot(page, '13-rolle');

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Konto' })).toBeVisible();

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.getByRole('button', { name: 'Filter und Sortierung' }).click();
  await page.getByLabel('Auch Gast-Konten anzeigen').check();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Mitglied');
});

test('Standardrolle „Termine verwalten“: Termine pflegen ja, Punkte und Strafen nein', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Termine verwalten' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Termine verwalten');

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await expect(page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' })).toBeVisible();
  // Die Rechte auf dem Server prüft das API-Szenario (tests/contract) für beide Varianten.

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Mitglied');
});

test('Admin gibt einem Mitglied einzeln ein Recht und nimmt es wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  const rechte = page.locator('.member-rights');
  await expect(rechte.getByLabel(/^Mitgliederbereich/)).toBeChecked();
  await rechte.getByLabel('Öffentliche Termine pflegen').check();
  await expect(rechte.locator('.badge-override')).toHaveText('zusätzlich');
  await rechte.getByRole('button', { name: 'Rechte speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Rechte gespeichert');
  await shot(page, '20b-rechte');

  await login(page, 'mitglied2');
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await expect(page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' })).toBeVisible();

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await expect(page.locator('.member-level')).toContainText('mit Ausnahmen bei den Rechten');
  await memberMenu(page, 'Konto und Rolle');
  page.once('dialog', (d) => d.accept());
  await rechte.getByRole('button', { name: 'Wie die Rolle' }).click();
  await expect(page.locator('.toast')).toHaveText('Rechte wie die Rolle');
  await expect(rechte.getByLabel('Öffentliche Termine pflegen')).not.toBeChecked();

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Öffentliche Termine verwalten' })).toHaveCount(0);
});

test('Admin legt eine eigene Rolle an, vergibt sie und löscht sie wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Rollen', { exact: true }) })
    .click();
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
  await memberMenu(page, 'Konto und Rolle');
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Kassenwart' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Kassenwart');

  await login(page, 'mitglied2');
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Öffentliche Termine verwalten' })).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Rollen', { exact: true }) })
    .click();
  await rollen.getByRole('button', { name: 'Kassenwart bearbeiten' }).click();
  page.once('dialog', (d) => d.accept());
  await rollen.getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Rolle gelöscht');
  await expect(rollen.locator('.row', { hasText: 'Kassenwart' })).toHaveCount(0);
});

test('Zusatzrollen: Mitglied bekommt „Termine verwalten“ dazu, hat die Rechte beider Rollen, Filter findet sie', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  const extra = page.locator('.extra-roles');
  // Feste Rollen sind nur Grundrolle.
  await expect(extra.getByLabel('Mitglied', { exact: true })).toHaveCount(0);
  await extra.getByLabel('Termine verwalten').check();
  await expect(page.locator('.toast')).toHaveText('Zusatzrolle vergeben: Termine verwalten');
  await expect(page.locator('.role-control').getByLabel('Grundrolle')).toHaveValue('member');
  await shot(page, '21-zusatzrollen');

  // Liste zeigt keine Rollen, Filter findet die Person über die Zusatzrolle.
  await page.getByRole('button', { name: '‹ Alle Mitglieder' }).click();
  await expect(page.locator('.row', { hasText: 'Mitglied2' }).locator('.badge-role')).toHaveCount(0);
  await page.getByRole('button', { name: 'Filter und Sortierung' }).click();
  await page.getByLabel('Nach Rolle filtern').selectOption({ label: 'Termine verwalten' });
  await expect(page.locator('.card-list .row', { hasText: 'Mitglied2' })).toBeVisible();
  await expect(page.locator('.card-list .row', { hasText: 'Mitglied1' })).toHaveCount(0);

  // Rechte beider Rollen: Mitgliederbereich und Termine pflegen.
  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toBeVisible();
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await expect(page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' })).toBeVisible();
  await page.getByRole('button', { name: 'Konto' }).click();
  await expect(page.locator('.account-card').first()).toContainText('Rollen: Mitglied, Termine verwalten');

  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Konto und Rolle');
  await page.locator('.extra-roles').getByLabel('Termine verwalten').uncheck();
  await expect(page.locator('.toast')).toHaveText('Zusatzrolle entfernt: Termine verwalten');
});

test('Vorschau: Admin sieht die App als Mitglied und ohne Anmeldung, nur zum Ansehen, danach wieder als Admin', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  const openRoles = async () => {
    await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
    await adminMenu(page, 'Einstellungen');
    await page
      .locator('.settings-link')
      .filter({ has: page.getByText('Rollen', { exact: true }) })
      .click();
  };
  await openRoles();
  const rollen = page.locator('#einstellungen-rollen');
  await expect(rollen.getByRole('button', { name: 'App als Admin ansehen' })).toHaveCount(0);
  await rollen.getByRole('button', { name: 'App als Mitglied ansehen' }).click();

  // Wie ein Mitglied: Navigation ohne Verwaltung und Einstellungen, eigene Daten des Admins, Banner auf jeder Seite.
  const banner = page.locator('.preview-banner');
  await expect(banner).toHaveText(/Vorschau als Mitglied – nur ansehen/);
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await expect(page.getByText('Vereinsabend')).toBeVisible();
  await shot(page, '22-vorschau-mitglied');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.stat-label').first()).toBeVisible();
  await expect(banner).toBeVisible();
  await page.goto('/app/#/einstellungen');
  await expect(page.locator('.topbar-title')).not.toHaveText('Einstellungen');
  await expect(banner).toBeVisible();
  // Neu laden: Die Vorschau bleibt in diesem Tab; ein anderer Tab zeigt die Admin-Ansicht.
  await page.reload();
  await expect(banner).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  const other = await page.context().newPage();
  await other.goto('/app/');
  await expect(other.locator('.tabbar').getByRole('button', { name: MANAGER })).toBeVisible();
  await expect(other.locator('.preview-banner')).toHaveCount(0);
  await other.close();

  // Nur ansehen: Der Server lehnt Änderungen ab, die App zeigt die Meldung.
  await page.getByRole('button', { name: 'Konto' }).click();
  await page.getByLabel('Aktuelles Passwort').fill(ADMIN_PASSWORD);
  await page.getByLabel(/^Neues Passwort \(/).fill('Vorschau-Passwort-1');
  await page.getByLabel('Neues Passwort wiederholen').fill('Vorschau-Passwort-1');
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.form-error')).toHaveText('In der Vorschau kannst du nichts ändern.');
  await expect(banner).toBeVisible();

  // Beenden: zurück in die Einstellungen, wieder mit allen Rechten.
  await banner.getByRole('button', { name: 'Beenden' }).click();
  await expect(rollen).toBeVisible();
  await expect(banner).toHaveCount(0);
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toBeVisible();

  // Ohne Anmeldung: nur Öffentliches, mit „Anmelden“ statt Konto.
  await rollen.getByRole('button', { name: 'App ohne Anmeldung ansehen' }).click();
  await expect(banner).toHaveText(/Vorschau ohne Anmeldung – nur ansehen/);
  await expect(page.locator('.topbar').getByRole('button', { name: 'Anmelden' })).toBeVisible();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect(page.getByText('Vereinsabend')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await banner.getByRole('button', { name: 'Beenden' }).click();
  await expect(rollen).toBeVisible();
  await expect(page.locator('.topbar-account')).toHaveText('Konto');
});

test('Galerie: Gäste sehen öffentliche Bilder, Mitglieder zusätzlich interne', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/#/galerie');
  await expect(page.locator('.album-card', { hasText: 'Sommerfest' })).toBeVisible();
  await expect(page.locator('.album-card', { hasText: 'Vereinsabend' })).toHaveCount(0);
  await page.locator('.album-card', { hasText: 'Sommerfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(2);
  await expect(page.locator('.photo[data-intern]')).toHaveCount(0);
  await page.locator('.photo').first().click();
  await expect(page.locator('.lb-count')).toHaveText('1 / 2');
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.lb-count')).toHaveText('2 / 2');
  // Zwei Finger auseinander: vergrößert; vergrößert verschiebt ein Finger das Bild statt zu blättern.
  const touch = (type: string, points: [number, number][]) =>
    page.locator('.lightbox').evaluate(
      (el, [type, points]) => {
        const touches = (points as [number, number][]).map(
          ([x, y], id) => new Touch({ identifier: id, target: el, clientX: x, clientY: y }),
        );
        el.dispatchEvent(
          new TouchEvent(type as string, {
            bubbles: true,
            touches: type === 'touchend' ? [] : touches,
            changedTouches: touches,
          }),
        );
      },
      [type, points] as const,
    );
  await touch('touchstart', [
    [150, 300],
    [200, 300],
  ]);
  await touch('touchmove', [
    [100, 300],
    [250, 300],
  ]);
  await touch('touchend', [
    [100, 300],
    [250, 300],
  ]);
  await expect(page.locator('.lightbox img')).toHaveClass(/zoomed/);
  await touch('touchstart', [[200, 300]]);
  await touch('touchmove', [[100, 300]]);
  await touch('touchend', [[100, 300]]);
  await expect(page.locator('.lb-count')).toHaveText('2 / 2');
  await page.keyboard.press('ArrowLeft');
  await expect(page.locator('.lightbox img')).not.toHaveClass(/zoomed/);
  await expect(page.locator('.lb-count')).toHaveText('1 / 2');
  await page.keyboard.press('ArrowRight');
  await shot(page, '15-galerie-lightbox');
  await page.keyboard.press('Escape');
  await expect(page.locator('.lightbox')).toHaveCount(0);

  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Galerie' }).click();
  await expect(page.locator('.album-card', { hasText: 'Vereinsabend' })).toBeVisible();
  await shot(page, '16-galerie-alben');
  await page.locator('.album-card', { hasText: 'Sommerfest' }).click();
  await expect(page.locator('.photo')).toHaveCount(3);
  await expect(page.locator('.photo[data-intern]')).toHaveCount(1);
  const internSrc = await page.locator('.photo[data-intern] img').getAttribute('src');
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
  await page.goto('/app/#/einstellungen/bereiche');
  const bereiche = page.locator('.settings-section', { hasText: 'Bereiche' }).first();
  // Der Schalter speichert sofort und zeigt den neuen Stand erst nach der Antwort des Servers.
  await bereiche.getByRole('checkbox', { name: /^Galerie/ }).click();
  await expect(page.locator('.toast')).toHaveText('Galerie eingeschaltet');
  await expect(bereiche.getByRole('checkbox', { name: /^Galerie/ })).toBeChecked();
  await nav.getByRole('button', { name: 'Galerie' }).click();
  await expect(page.locator('.album-card', { hasText: 'Sommerfest' })).toBeVisible();

  // Ausschalten nur nach Rückfrage; Abbrechen lässt den Bereich an.
  await page.goto('/app/#/einstellungen/bereiche');
  page.once('dialog', (d) => {
    expect(d.message()).toContain('Die Daten bleiben erhalten');
    void d.dismiss();
  });
  await bereiche.getByRole('checkbox', { name: /^Galerie/ }).click();
  await expect(bereiche.getByRole('checkbox', { name: /^Galerie/ })).toBeChecked();

  // Wer kein Admin ist, kommt nicht an die Bereiche.
  await login(page, 'verwalter');
  await page.goto('/app/#/einstellungen/bereiche');
  await expect(page.getByRole('heading', { name: 'Bereiche' })).toHaveCount(0);
});

test('Admin sieht Zustand, Updates und lädt eine Sicherung herunter', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Einstellungen');
  await expect(page.locator('.topbar-title')).toHaveText('Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Zustand und Updates', { exact: true }) })
    .click();
  const status = page.locator('.settings-section', { hasText: 'Zustand der App' });
  await expect(status.locator('.status-item').first()).toBeVisible();
  // Die Test-Instanz ist kein Paket aus einem Release: keine Updates über die App.
  await expect(page.getByText(/bekommt keine Updates über die App/)).toBeVisible();
  // Fehlerprotokoll mit den Fehlertexten bleibt auf dem Server (ohne Statusmeldungen sichtbar).
  await expect(page.getByRole('heading', { name: 'Fehlerprotokoll' })).toBeVisible();

  await expect(status.locator('.status-item.status-warn', { hasText: 'Datensicherung' })).toBeVisible();
  await page.getByRole('button', { name: '‹ Einstellungen' }).click();
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Datensicherung', { exact: true }) })
    .click();
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
  await page.goto('/app/#/einstellungen/zustand');
  await expect(status.locator('.status-item.status-ok', { hasText: 'Datensicherung' })).toBeVisible();
  await shot(page, '19-wartung');
});

test('Was ist neu: Admin sieht das Changelog der gebauten Version, Mitglied nicht', async ({ page }) => {
  // So, wie scripts/changelog.php es beim Bauen in die App gelegt hat.
  const changelog = JSON.parse(readFileSync(new URL('../src/generated/changelog.json', import.meta.url), 'utf8')) as {
    versionen: { version: string }[];
    unveroeffentlicht: string | null;
  };
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Was ist neu', { exact: true }) })
    .click();
  await expect(page).toHaveURL(/#\/einstellungen\/neuerungen$/);
  for (const v of changelog.versionen) {
    await expect(page.getByRole('heading', { name: new RegExp(`^Version ${v.version.replace(/\./g, '\\.')}\\b`) })).toBeVisible();
  }
  await expect(page.getByRole('heading', { name: /^Vorabversion/ })).toHaveCount(changelog.unveroeffentlicht ? 1 : 0);
  await expect(page.locator('.changelog-text h4').first()).toBeVisible();
  await shot(page, '19-was-ist-neu');

  await login(page, 'mitglied1');
  await page.goto('/app/#/einstellungen/neuerungen');
  await expect(page.locator('.changelog-text')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: /^Version / })).toHaveCount(0);
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
    // „?“ öffnet die Hilfeseite zur aktuellen Seite.
    await page.getByRole('button', { name: 'Hilfe' }).click();
    await expect(page.locator('.hilfe-seite h2', { hasText: 'Die App aufs Handy holen' })).toBeVisible();
    await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
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
    // Erster Versuch scheitert → einmal neu laden → jetzt klappt es (Hilfe zur Startseite).
    await expect(page.locator('.hilfe-seite')).toBeVisible();
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
  // „?“ öffnet die Hilfe zur aktuellen Seite (Termine), von dort geht es zum Verzeichnis.
  await expect(page.locator('.hilfe-seite')).toBeVisible();
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
  await expect(item('Die App aufs Handy holen')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toHaveCount(0);
  await expect(item('Meine Punkte')).toHaveCount(0);

  // Mitglied: dazu die Seiten für Mitglieder; Bezeichnung „Kasse“ statt „Strafen“
  await login(page, 'mitglied1');
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
  await expect(item('Meine Punkte')).toBeVisible();
  await item('Meine Kasse').click();
  await expect(page.locator('.news-headline')).toHaveText('Meine Kasse');
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toHaveCount(0);
  await expect(item('Punkte vergeben')).toHaveCount(0);

  // Admin: Seiten für Admins, nur die Installation der eigenen Variante; Suche
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();
  await expect(page.getByRole('heading', { name: 'Für Admins' })).toBeVisible();
  await expect(item(STANDALONE ? 'Installation ohne WordPress' : 'Installation mit WordPress')).toBeVisible();
  await expect(item(STANDALONE ? 'Installation mit WordPress' : 'Installation ohne WordPress')).toHaveCount(0);
  await page.getByLabel('Hilfe durchsuchen').fill('Startpasswort');
  await expect(item('Mitglieder anlegen')).toBeVisible();
  await expect(item('Wartungsmodus')).toHaveCount(0);
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

test('Fehler melden: Gast und Mitglied, mit Bildschirmfoto und angezeigten Angaben', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/#/termine');
  // Nur mit Empfänger (reportUrl); die eigenständige Testinstallation hat einen simulierten (tests/standalone/MockHttp.php).
  const available = await page.evaluate(() => !!window.KOMPANIE_CONFIG?.reports);
  test.skip(!available, 'Fehlermeldungen in dieser Testinstallation nicht eingerichtet');
  const details = page.locator('.report-details');
  // 1x1-PNG
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

  // Gast: über „?“ zur Hilfe, dort der Knopf; die Seite, von der aus gemeldet wird, steht in den Angaben.
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await page.getByRole('button', { name: 'Fehler melden' }).click();
  await expect(page.locator('.report-form h2')).toHaveText('Fehler melden');
  await expect(details).toContainText('#/termine');
  await expect(details).toContainText('ohne Anmeldung');
  await expect(details.locator('dd').last()).toHaveText('keine');
  await expect(page.getByText('Meinen Namen mitschicken')).toHaveCount(0);
  await expect(page.locator('input[name="website"]')).not.toBeInViewport();
  await page.getByRole('button', { name: 'Meldung senden' }).click();
  await expect(page.locator('.form-error')).toHaveText('Bitte beschreibe kurz, was nicht klappt.');
  await page.getByLabel('Was klappt nicht? (Pflicht)').fill(`Gast-Test ${Date.now()}`);
  await page.getByRole('button', { name: 'Meldung senden' }).click();
  await expect(page.locator('.form-error')).toContainText('Bitte bestätige');
  await page.getByLabel('Bildschirmfoto').setInputFiles({ name: 'bild.png', mimeType: 'image/png', buffer: png });
  await expect(page.getByAltText('Gewähltes Bildschirmfoto')).toBeVisible();
  await page.getByLabel(/Ich bin einverstanden/).check();
  await expect(page.getByLabel(/Ich bin einverstanden/)).toBeChecked();
  await expect(page.locator('.report-consent')).toContainText('Steuten IT Solutions');
  await expect(page.locator('.report-consent')).toContainText('dem Bildschirmfoto');
  await page.getByRole('button', { name: 'Meldung senden' }).click();
  await expect(page.getByRole('heading', { name: 'Danke, deine Meldung ist angekommen' })).toBeVisible({ timeout: 15_000 });

  // Mitglied: letzte Fehlermeldung der App und auf Wunsch der Name
  await login(page, 'mitglied1');
  await page.goto('/app/#/news/gibt-es-nicht');
  await expect(page.locator('.error')).toBeVisible();
  // Zu Neuigkeiten gibt es keine eigene Hilfeseite: „?“ öffnet das Verzeichnis, auch dort steht der Knopf.
  await page.getByRole('button', { name: 'Hilfe' }).click();
  await page.getByRole('button', { name: 'Fehler melden' }).click();
  await expect(details).toContainText('#/news/gibt-es-nicht');
  await expect(details.locator('dd').last()).not.toHaveText('keine');
  await expect(details).not.toContainText('ohne Anmeldung');
  await expect(details).not.toContainText('Mitglied1');
  await page.getByText('Meinen Namen mitschicken').click();
  await expect(details).toContainText('Mitglied1');
  const text = `Mitglied-Test ${Date.now()}`;
  await page.getByLabel('Was klappt nicht? (Pflicht)').fill(text);
  await page.getByLabel('Was hast du erwartet? (freiwillig)').fill('Eine Neuigkeit');
  await page.getByLabel(/Ich bin einverstanden/).check();
  await page.getByRole('button', { name: 'Meldung senden' }).click();
  await expect(page.getByRole('heading', { name: 'Danke, deine Meldung ist angekommen' })).toBeVisible({ timeout: 15_000 });

  // Eigenständig: Die Hintergrundaufgabe schickt die Meldung an den simulierten Empfänger (mit Namen, signiert). Ohne
  // Cronjob startet die Aufgabe über den Selbstaufruf, höchstens einmal pro Minute: Das kann etwas dauern.
  test.setTimeout(180_000);
  const log = process.env.KOMPANIE_DATA_DIR ? `${process.env.KOMPANIE_DATA_DIR}/mock-reports.jsonl` : '';
  if (STANDALONE && log) {
    await expect
      .poll(
        async () => {
          await page.request.get('/app/');
          return existsSync(log) ? readFileSync(log, 'utf8') : '';
        },
        { timeout: 100_000, intervals: [2000] },
      )
      .toContain(text);
    const sent = readFileSync(log, 'utf8')
      .trim()
      .split('\n')
      .map((l) => JSON.parse(l))
      .find((r) => r.report?.description === text);
    expect(sent.report.name).toBe('Mitglied1');
    expect(sent.report.expected).toBe('Eine Neuigkeit');
    expect(sent.report.page).toBe('#/news/gibt-es-nicht');
  }
});

test('Admin richtet Kalender, Galerie und Neuigkeiten in der App ein', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Einstellungen');
  await expect(page.locator('.topbar-title')).toHaveText('Einstellungen');
  const open = async (title: string) => {
    await page.goto('/app/#/einstellungen');
    await page
      .locator('.settings-link')
      .filter({ has: page.getByText(title, { exact: true }) })
      .click();
  };
  if (!STANDALONE) {
    // WordPress: Neuigkeiten kommen aus den Beiträgen (keine Seite); Kalender und Galerie sind einstellbar.
    // Die Test-Instanz hat keine Attrappen für Google, deshalb nur Speichern und Prüfen der Eingabe.
    await expect(page.locator('.settings-link').filter({ has: page.getByText('Galerie', { exact: true }) })).toBeVisible();
    await expect(page.locator('.settings-link').filter({ has: page.getByText('Neuigkeiten', { exact: true }) })).toHaveCount(0);
    await open('Kalender');
    const cal = page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' });
    await cal.locator('input[type=url]').fill('ftp://falsch');
    await cal.getByRole('button', { name: 'Speichern' }).click();
    await expect(cal.locator('.form-error')).toBeVisible();
    return;
  }
  await open('Kalender');
  await expect(page.getByText('✓ Eingerichtet über die geheime iCal-Adresse.')).toBeVisible();
  await expect(page.getByText('https://kalender.test')).toHaveCount(0); // geheime Adresse wird nicht angezeigt

  await page.getByRole('button', { name: 'Kalender testen' }).click();
  await expect(page.getByText(/3 Termine gefunden/)).toBeVisible();

  const cal = page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' });
  // Speichern ohne Änderung ist kein Fehler.
  await cal.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Nichts geändert – der Kalender ist schon eingerichtet.');
  await cal.getByLabel('Neue iCal-Adresse (ersetzt die bisherige)').fill('ftp://falsch');
  await cal.getByRole('button', { name: 'Speichern' }).click();
  await expect(cal.locator('.form-error')).toBeVisible();

  await open('Galerie');
  const gallery = page.locator('.settings-section', { hasText: 'Galerie aus Google Drive' });
  await expect(gallery.getByText('test@example.iam.gserviceaccount.com')).toBeVisible();
  await gallery.getByRole('button', { name: 'Jetzt abgleichen' }).click();
  await expect(gallery.getByText('Fertig: 2 Alben, 4 Bilder.')).toBeVisible();

  await open('Neuigkeiten');
  const news = page.locator('.settings-section', { hasText: 'Neuigkeiten' });
  await news.getByRole('button', { name: 'Neuigkeiten testen' }).click();
  await expect(news.getByText('2 Beiträge gefunden.')).toBeVisible();
  await page.goto('/app/#/einstellungen');
  await expect(page.locator('.settings-link').first()).toBeVisible();
  await shot(page, '18-einstellungen');
});

test('Admin verbindet die App mit Google (Google simuliert) und trennt wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/einstellungen');
  // In der Übersicht über Kalender und Galerie (die Übersicht erscheint erst nach dem Laden)
  await expect(page.locator('.settings-link', { hasText: 'Google-Zugang' })).toBeVisible();
  const order = await page.locator('.settings-link .row-title').allTextContents();
  expect(order.indexOf('Google-Zugang')).toBeLessThan(order.indexOf('Kalender'));
  expect(order.indexOf('Google-Zugang')).toBeLessThan(order.indexOf('Galerie'));
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Google-Zugang', { exact: true }) })
    .click();
  const google = page.locator('#einstellungen-google');
  await expect(google.getByRole('heading', { name: 'Google-Zugang' })).toBeVisible();
  // Mit dem Dienstkonto als Alternative
  await expect(google.getByText('Alternative: Dienstkonto mit JSON-Schlüssel')).toBeVisible();
  const redirect = await google.getByLabel('Weiterleitungs-URI (im OAuth-Client bei Google eintragen)').inputValue();
  expect(redirect).toMatch(STANDALONE ? /\/google\/callback$/ : /\/wp-admin\/admin-post\.php$/);

  // Google selbst: Anmeldung und Zustimmung überspringen, mit Code zurück an die Weiterleitungs-URI.
  let authorize: URL | null = null;
  await page.route('https://accounts.google.com/**', (route) => {
    authorize = new URL(route.request().url());
    const back = new URL(authorize.searchParams.get('redirect_uri')!);
    back.searchParams.set('state', authorize.searchParams.get('state')!);
    back.searchParams.set('code', 'TESTCODE');
    return route.fulfill({ status: 302, headers: { Location: back.toString() } });
  });
  await google.getByLabel('Client-ID').fill('1-e2e.apps.googleusercontent.com');
  await google.getByLabel('Clientschlüssel (Client-Secret)').fill('GOCSPX-e2e');
  await google.getByRole('button', { name: 'Speichern und mit Google verbinden' }).click();
  await expect(google.getByRole('status')).toContainText('Mit Google verbunden (kompanie@example.org)');
  await expect(page).toHaveURL(/#\/einstellungen\/google$/);
  expect(authorize!.searchParams.get('redirect_uri')).toBe(redirect);
  expect(authorize!.searchParams.get('scope')).toContain('calendar.readonly');
  await expect(google.getByText('Es darf auch den Kalender lesen.')).toBeVisible();
  expect(page.url()).not.toContain('google=1'); // Hinweis nur einmal
  await page.goto('/app/#/einstellungen/galerie');
  await expect(page.locator('.settings-section', { hasText: 'Galerie aus Google Drive' })).toContainText(
    'die Verbindung mit Google (kompanie@example.org)',
  );
  await page.goto('/app/#/einstellungen/google');
  await shot(page, '18b-google-zugang');

  page.once('dialog', (d) => void d.accept());
  await google.getByRole('button', { name: 'Verbindung trennen' }).click();
  await expect(google.getByRole('button', { name: 'Speichern und mit Google verbinden' })).toBeVisible();
  await expect(google.getByLabel('Client-ID')).toHaveValue('1-e2e.apps.googleusercontent.com');
});

test('Dienstgrade: Liste anlegen, mit Punkteschwelle befördern, Mitglied sieht Stand und Verlauf', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Dienstgrade');
  for (const [title, min] of [
    ['Gefreiter', '10'],
    ['Unteroffizier', '1000'],
  ]) {
    await page.getByLabel('Bezeichnung').fill(title);
    await page.getByLabel('Punkteschwelle (optional)').fill(min);
    await page.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.row', { hasText: title })).toBeVisible();
  }
  await page.getByRole('navigation', { name: 'Verwaltung' }).locator('.admin-menu-toggle').click();
  await expect(page.locator('.admin-menu-list').getByRole('button', { name: 'Beförderungen', exact: true })).toBeVisible();
  await page.keyboard.press('Escape');

  // Punkte für mitglied2 buchen, dann befördern.
  await adminMenu(page, 'Mitglieder');
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
  // Aus „Beförderungen“ direkt zum Dienstgrad des Mitglieds, der nächste Dienstgrad ist vorgeschlagen.
  await page.goto('/app/#/verwaltung/befoerderungen');
  await page.locator('.promotions .row', { hasText: 'Mitglied2' }).click();
  await expect(page).toHaveURL(new RegExp(`#/verwaltung/mitglied/${id}/dienstgrad$`));
  await expect(page.getByLabel('Neuer Dienstgrad').locator('option:checked')).toHaveText('Gefreiter (ab 10)');
  await page.getByLabel('Neuer Dienstgrad').selectOption({ label: 'Gefreiter (ab 10)' });
  await page.getByLabel('Begründung (optional)').fill('Gute Arbeit');
  await page.getByRole('button', { name: 'Dienstgrad ändern' }).click();
  await expect(page.locator('.toast')).toHaveText('Dienstgrad geändert');
  await expect(page.getByText('Beförderung: kein Dienstgrad → Gefreiter')).toBeVisible();
  await expect(page.locator('.member-rank strong')).toHaveText('Gefreiter');

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await meinsMenu(page, 'Dienstgrad');
  await expect(page.locator('.rank-card-title')).toHaveText('Gefreiter');
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
  await adminMenu(page, 'Dienstgrade');
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
  await meinsMenu(page, 'Dienstgrad');
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
  await adminMenu(page, 'Anwesenheit');
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
  await meinsMenu(page, 'Anwesenheit');
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

test('Dokumente: Kategorien per Ziehen am Griff umsortieren, bleibt nach dem Neuladen', async ({ page }) => {
  const id = Date.now().toString(36);
  const names = ['A', 'B', 'C'].map((n) => `Ordner ${n} ${id}`);
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: 'Dokumente' }).click();
  await page.getByRole('button', { name: 'Dokumente verwalten' }).click();
  for (const name of names) {
    await page.getByLabel('Neue Kategorie').fill(name);
    await page.getByRole('button', { name: 'Kategorie hinzufügen' }).click();
    await expect(page.locator('.doc-categories .row', { hasText: name })).toBeVisible();
  }
  await expect(page.getByRole('button', { name: /nach oben/ })).toHaveCount(0);
  const order = async () => (await page.locator('.doc-categories .row-title').allTextContents()).filter((t) => t.includes(id));
  expect(await order()).toEqual(names);

  // C am Griff über A ziehen
  const handle = page.getByRole('button', { name: `${names[2]} verschieben (ziehen oder Pfeiltasten)` });
  // Liste knapp unter die Kopfleiste scrollen, damit alle drei Griffe im Bild sind
  await page.evaluate(() => window.scrollBy(0, document.querySelector('.doc-categories')!.getBoundingClientRect().top - 100));
  const from = (await handle.boundingBox())!;
  const to = (await page.locator('.doc-categories .row', { hasText: names[0] }).boundingBox())!;
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2, to.y + 5, { steps: 10 });
  await page.mouse.up();
  await expect(page.locator('.toast')).toHaveText('Reihenfolge gespeichert');
  expect(await order()).toEqual([names[2], names[0], names[1]]);

  await page.reload();
  await expect(page.locator('.doc-categories .row', { hasText: names[1] })).toBeVisible();
  expect(await order()).toEqual([names[2], names[0], names[1]]);
});

test('PDF: Seiten erst zeichnen, wenn sie ins Bild scrollen, als JPEG', async ({ page }) => {
  const id = Date.now().toString(36);
  // Zehn A4-Seiten mit je einer Zeile Text
  const objs = ['<</Type/Catalog/Pages 2 0 R>>', '', '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>'];
  const kids: number[] = [];
  for (let p = 1; p <= 10; p++) {
    const text = `BT /F1 24 Tf 72 720 Td (Seite ${p}) Tj ET`;
    objs.push(`<</Length ${text.length}>>stream\n${text}\nendstream`);
    objs.push(`<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 3 0 R>>>>/Contents ${objs.length} 0 R>>`);
    kids.push(objs.length);
  }
  objs[1] = `<</Type/Pages/Kids[${kids.map((k) => `${k} 0 R`).join(' ')}]/Count 10>>`;
  const pdf = Buffer.from(
    `%PDF-1.4\n${objs.map((o, i) => `${i + 1} 0 obj${o}endobj\n`).join('')}trailer<</Root 1 0 R>>\n%%EOF\n`,
    'latin1',
  );
  // Bildformat der gezeichneten Seiten mitschreiben (Blob-Adressen lassen sich wegen der CSP nicht abrufen)
  await page.addInitScript(() => {
    const types: string[] = [];
    (window as unknown as { pdfImageTypes: string[] }).pdfImageTypes = types;
    const toBlob = HTMLCanvasElement.prototype.toBlob;
    HTMLCanvasElement.prototype.toBlob = function (callback, type, quality) {
      types.push(String(type));
      return toBlob.call(this, callback, type, quality);
    };
  });
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: 'Dokumente' }).click();
  await page.getByRole('button', { name: 'Dokumente verwalten' }).click();
  await page.getByLabel('Datei').setInputFiles({ name: 'lang.pdf', mimeType: 'application/pdf', buffer: pdf });
  await page.getByLabel('Titel').fill(`Langes Dokument ${id}`);
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.toast')).toHaveText(`„Langes Dokument ${id}“ hochgeladen`);
  await page.getByRole('button', { name: '‹ Alle Dokumente' }).click();

  const doc = page.locator('.doc-card', { hasText: `Langes Dokument ${id}` });
  await expect(doc).toBeAttached();
  const toggle = page.locator('.doc-group', { has: doc }).locator('.doc-group-toggle');
  if ((await toggle.count()) > 0 && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click();
  await doc.getByRole('button', { name: 'Öffnen' }).click();
  const first = page.getByAltText(`Langes Dokument ${id}, Seite 1 von 10`);
  await expect(first).toBeVisible();
  // Weit unten: noch nicht gezeichnet, nur der Platzhalter im Seitenverhältnis der Seite
  const last = page.getByRole('img', { name: `Langes Dokument ${id}, Seite 10 von 10 wird aufbereitet` });
  await expect(last).toBeAttached();
  const box = await last.boundingBox();
  expect(box && Math.abs(box.height / box.width - 842 / 595)).toBeLessThan(0.05);
  expect(await page.locator('img.pdf-page').count()).toBeLessThan(10);
  expect(new Set(await page.evaluate(() => (window as unknown as { pdfImageTypes: string[] }).pdfImageTypes))).toEqual(
    new Set(['image/jpeg']),
  );
  await last.scrollIntoViewIfNeeded();
  await expect(page.getByAltText(`Langes Dokument ${id}, Seite 10 von 10`)).toBeVisible();
});

test('Titel: anlegen und vergeben, Mitglied sieht ihn in „Meins“', async ({ page }) => {
  const title = `Ehrenmitglied ${Date.now().toString(36)}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Titel');
  await page.getByLabel('Bezeichnung').fill(title);
  await page.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.title-list .row', { hasText: title })).toContainText('nicht vergeben');

  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Titel');
  await page.locator('.member-titles').getByLabel(title).check();
  await page.getByRole('button', { name: 'Titel speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Titel gespeichert');
  await page.getByRole('button', { name: 'Alle Mitglieder' }).click();
  await expect(page.locator('.row', { hasText: 'Mitglied2' }).locator('.badge-title')).toContainText(title);

  // Vergeben: nicht löschen, nur umbenennen oder deaktivieren.
  await adminMenu(page, 'Titel');
  const row = page.locator('.title-list .row', { hasText: title });
  await expect(row).toContainText('1× vergeben');
  await expect(row.getByRole('button', { name: 'Löschen' })).toHaveCount(0);

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.my-titles')).toContainText(title);
  await shot(page, 'meins-titel');
});

test('Titel: Reihenfolge per Ziehen am Griff und mit Pfeiltasten, bleibt nach dem Neuladen', async ({ page }) => {
  const id = Date.now().toString(36);
  const names = ['A', 'B', 'C'].map((n) => `Reihe ${n} ${id}`);
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Titel');
  for (const name of names) {
    await page.getByLabel('Bezeichnung').fill(name);
    await page.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.title-list .row', { hasText: name })).toBeVisible();
  }
  await expect(page.getByRole('button', { name: /nach oben/ })).toHaveCount(0);
  const order = async () => (await page.locator('.title-list .row-title').allTextContents()).filter((t) => t.includes(id));
  expect(await order()).toEqual(names);

  const saves = countOrderSaves(page, 'titles/order');
  // C am Griff über A ziehen
  const handle = page.getByRole('button', { name: `${names[2]} verschieben (ziehen oder Pfeiltasten)` });
  const from = (await handle.boundingBox())!;
  const to = (await page.locator('.title-list .row', { hasText: names[0] }).boundingBox())!;
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2, to.y + 5, { steps: 10 });
  await page.mouse.up();
  await expect.poll(order).toEqual([names[2], names[0], names[1]]);

  // A mit Pfeil hoch vor C, gleich danach, während das Ziehen noch gespeichert wird; der Griff behält den Fokus
  const handleA = page.getByRole('button', { name: `${names[0]} verschieben (ziehen oder Pfeiltasten)` });
  await handleA.focus();
  await page.keyboard.press('ArrowUp');
  await expect.poll(order).toEqual([names[0], names[2], names[1]]);
  await expect(handleA).toBeFocused();

  // Beide Züge gespeichert (das Speichern läuft im Hintergrund), dann neu laden
  await expect.poll(saves).toBe(2);
  await page.reload();
  await expect(page.locator('.title-list .row', { hasText: names[1] })).toBeVisible();
  expect(await order()).toEqual([names[0], names[2], names[1]]);
});

test('Titel: beim Ziehen an den unteren Rand scrollt die Seite mit', async ({ page }) => {
  const id = Date.now().toString(36);
  const names = Array.from({ length: 8 }, (_, i) => `Lang ${i + 1} ${id}`);
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Titel');
  for (const name of names) {
    await page.getByLabel('Bezeichnung').fill(name);
    await page.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.title-list .row', { hasText: name })).toBeVisible();
  }
  const order = async () => (await page.locator('.title-list .row-title').allTextContents()).filter((t) => t.includes(id));

  // Ersten Titel knapp unter die Kopfleiste holen; der letzte liegt dann weit außerhalb des Bildes
  const first = page.locator('.title-list .row', { hasText: names[0] });
  await first.evaluate((el) => window.scrollBy(0, el.getBoundingClientRect().top - 120));
  const scrolled = await page.evaluate(() => window.scrollY);
  const handle = (await first.getByRole('button', { name: /verschieben/ }).boundingBox())!;
  const tabbar = (await page.locator('.tabbar').boundingBox())!;
  const saves = countOrderSaves(page, 'titles/order');
  await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
  await page.mouse.down();
  // Knapp über der Tab-Leiste halten, bis die Seite unten angekommen ist
  await page.mouse.move(handle.x + handle.width / 2, tabbar.y - 10, { steps: 10 });
  await expect
    .poll(() => page.evaluate(() => Math.ceil(window.scrollY + window.innerHeight) >= document.documentElement.scrollHeight - 2), {
      timeout: 10_000,
    })
    .toBe(true);
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(scrolled);
  await page.mouse.up();
  await expect.poll(order).toEqual([...names.slice(1), names[0]]);

  await expect.poll(saves).toBe(1);
  await page.reload();
  await expect(page.locator('.title-list .row', { hasText: names[0] })).toBeVisible();
  expect(await order()).toEqual([...names.slice(1), names[0]]);
});

test('Schwarzes Brett: Banner auf allen Seiten, ausblenden, „+1 weitere“, Änderung zeigt es wieder, abgelaufen weg', async ({
  page,
  browser,
}) => {
  const id = Date.now().toString(36);
  const first = `Heute kein Training ${id}`;
  const second = `Treffpunkt Schützenhaus ${id}`;
  // Admin schreibt (Recht post_board) über „Verwaltung“ → „Schwarzes Brett“; die Verwaltung bleibt markiert.
  const admin = await browser.newPage();
  admin.on('dialog', (d) => d.accept());
  await login(admin, 'admin', ADMIN_PASSWORD);
  await admin.getByRole('button', { name: 'Konto' }).click();
  await expect(admin.getByRole('button', { name: 'Nachricht schreiben' })).toHaveCount(0);
  await admin.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(admin, 'Schwarzes Brett');
  await expect(admin.locator('.tabbar button.active')).toHaveText(MANAGER);
  await expect(admin.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  const form = admin.locator('.board-form');
  await form.getByLabel('Text').fill(`${first}\nHalle gesperrt.`);
  await expect(form.getByText(`${first.length + 16}/500 Zeichen`)).toBeVisible();
  await expect(form).toContainText('sofort eine Benachrichtigung');
  // Haken „Benachrichtigung senden“: standardmäßig an, abwählbar
  await expect(form.getByLabel('Benachrichtigung senden')).toBeChecked();
  await form.getByLabel('Benachrichtigung senden').uncheck();
  await expect(form).toContainText('Ohne Benachrichtigung');
  await form.getByLabel('Benachrichtigung senden').check();
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
  // Nach dem Neuladen kommt zuerst der gespeicherte Stand (Issue #108), die geänderte Fassung folgt, sobald das Netz
  // antwortet: Dann ist die ausgeblendete Nachricht wieder da („+1 weitere“).
  await page.goto('/app/#/termine');
  await page.reload();
  await expect(banner.getByRole('button', { name: '+1 weitere' })).toBeVisible();
  await expect(banner.locator('.board-entry')).toHaveCount(1);
  await banner.getByRole('button', { name: '+1 weitere' }).click();
  await expect(banner).toContainText(`${first} – fällt aus, Halle gesperrt`);

  // Alle ausgeblendet: Eine schmale Zeile führt weiter zum Brett, auch nach dem Neuladen.
  for (const text of [first, second]) {
    await banner.locator('.board-entry', { hasText: text }).getByRole('button', { name: 'Nachricht ausblenden' }).click();
  }
  await expect(banner.locator('.board-entry')).toHaveCount(0);
  await expect(banner).toContainText('Schwarzes Brett · 2 ausgeblendet');
  await page.reload();
  await expect(banner).toContainText('2 ausgeblendet');
  await banner.getByRole('button', { name: 'Brett öffnen' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  for (const text of [first, second]) await expect(page.locator('.board-card', { hasText: text })).toBeVisible();
  await page.goto('/app/#/termine');

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
  // Admin (Recht manage_polls) legt über „Verwaltung“ → „Umfragen“ an.
  const admin = await browser.newPage();
  admin.on('dialog', (d) => d.accept());
  await login(admin, 'admin', ADMIN_PASSWORD);
  await admin.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(admin, 'Umfragen');
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

test('Seite in der Mitgliederliste: Geburtstag und Eintrittsjahr nur, wem das Mitglied sie zeigt', async ({ page, browser }) => {
  // Die Verwaltung trägt den Eintritt mit Datum ein; die Anzeige wählt nur die Person, auf der Seite steht nur das Jahr.
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberMenu(page, 'Eintritt');
  const adminCard = page.locator('.member-join-year');
  await adminCard.getByLabel('Datum oder Jahr').fill('1.4.2005');
  await adminCard.getByRole('button', { name: 'Eintritt speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Eintritt gespeichert');
  await expect(adminCard).toContainText('Eingetreten am 01.04.2005');
  await expect(adminCard.getByLabel('Datum oder Jahr')).toHaveValue('01.04.2005');
  await expect(adminCard).toContainText('Nicht anzeigen (wählt nur die Person selbst)');
  await expect(adminCard.getByLabel('Auf meiner Seite', { exact: false })).toHaveCount(0);

  // Mitglied2 zeigt den Geburtstag allen (mit Alter) und das Eintrittsjahr nur Mitgliedern.
  const member = await browser.newPage();
  member.on('dialog', (d) => d.accept());
  await login(member, 'mitglied2');
  await member.getByRole('button', { name: 'Konto' }).click();
  const birthday = member.locator('.birthday-card');
  await birthday.getByLabel('Tag').selectOption('12');
  await birthday.getByLabel('Monat').selectOption('3');
  await birthday.getByLabel('Jahr', { exact: true }).fill('1988');
  await birthday.getByLabel(/Alter anzeigen/).check();
  await expect(birthday.getByLabel(/Auf meiner Seite/)).toHaveValue('none');
  await birthday.getByLabel(/Auf meiner Seite/).selectOption('public');
  await expect(birthday).toContainText('Dort steht das ganze Datum mit deinem Alter.');
  await birthday.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  const joined = member.locator('.join-year-card');
  await expect(joined.getByLabel('Datum oder Jahr')).toHaveValue('01.04.2005');
  await joined.getByLabel(/Auf meiner Seite/).selectOption('members');
  await joined.getByRole('button', { name: 'Eintritt speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Eintritt gespeichert');
  await joined.scrollIntoViewIfNeeded();
  await shot(member, '36b-eintritt-konto');

  // Ein anderes Mitglied sieht beides auf der Seite.
  const today = new Date();
  const age = today.getFullYear() - 1988 - (today.getMonth() < 2 || (today.getMonth() === 2 && today.getDate() < 12) ? 1 : 0);
  await login(page, 'mitglied1');
  await page.goto('/app/#/mitglieder/liste');
  await page.locator('.member-row', { hasText: 'Mitglied2' }).locator('.member-row-open').click();
  const facts = page.locator('.member-page .member-page-facts');
  await expect(facts).toHaveText(new RegExp(`^Geburtstag 12\\. März 1988 \\((${age - 1}|${age}|${age + 1}) Jahre\\) · Eintritt 2005$`));
  await shot(page, '1d-mitglied-seite');

  // Ohne Anmeldung nur der Geburtstag.
  await page.context().clearCookies();
  await page.reload();
  await expect(facts).toHaveText(/^Geburtstag 12\. März 1988 \(\d+ Jahre\)$/);

  // Ohne „Alter anzeigen“ nur Tag und Monat; „Nicht anzeigen“ blendet ihn aus.
  await birthday.getByLabel(/Alter anzeigen/).uncheck();
  await birthday.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  await page.reload();
  await expect(facts).toHaveText('Geburtstag 12. März');
  await birthday.getByLabel(/Auf meiner Seite/).selectOption('none');
  await birthday.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  await page.reload();
  await expect(page.locator('.member-page').getByRole('heading', { name: 'Mitglied2' })).toBeVisible();
  await expect(facts).toHaveCount(0);

  // Aufräumen (WordPress behält die Daten zwischen den Läufen)
  await birthday.getByRole('button', { name: 'Entfernen' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag entfernt');
  await joined.getByRole('button', { name: 'Entfernen' }).click();
  await expect(member.locator('.toast')).toHaveText('Eintritt entfernt');
  await member.close();
});

test('Inventar: anlegen, umlagern, Menge mit Grund ändern, Verlauf zeigt alle Schritte mit Namen', async ({ page }) => {
  const title = `Bierbank ${Date.now().toString(36)}`;
  page.on('dialog', (d) => d.accept());
  // Mitglieder ohne das Recht sehen das Inventar nicht.
  await login(page, 'mitglied1');
  await page.goto('/app/#/inventar');
  await expect(page.locator('.topbar-title')).not.toHaveText('Inventar');

  // Admin (hat manage_inventory) öffnet es über „Verwaltung“.
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Inventar');
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
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await adminMenu(page, 'Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Wartungsmodus', { exact: true }) })
    .click();
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
