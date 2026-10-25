import { existsSync, readFileSync } from 'node:fs';
import { devices, expect, test, type Locator, type Page, type Request } from '@playwright/test';
import { strToU8, zipSync } from 'fflate';

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

/** Bereiche, die unter „Einrichtungen“ stehen, sobald es davon mindestens zwei gibt. */
const SETUP = ['Punktekatalog', 'Dienstgrade', 'Titel', 'Ämter'];

/** Startseite der Verwaltung (Tab unten) öffnen und dort den Knopf eines Bereichs wählen (ggf. über „Einrichtungen“). */
async function adminMenu(page: Page, name: string) {
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  const tiles = page.locator('.admin-tiles');
  const setup = tiles.getByRole('button', { name: 'Einrichtungen', exact: true });
  await tiles.waitFor();
  if (SETUP.includes(name) && (await setup.count()) > 0) await setup.click();
  await tiles.getByRole('button', { name, exact: true }).click();
}

/** „Intern“ (Tab unten) öffnen und dort den Knopf eines Bereichs wählen (Mitglieder oder Dokumente). */
async function internMenu(page: Page, name: string) {
  await page.locator('.tabbar').getByRole('button', { name: 'Intern' }).click();
  await page.getByRole('navigation', { name: 'Intern' }).getByRole('button', { name, exact: true }).click();
}

/** Bereich unter „Meins“ wählen (Burger-Menü). */
async function meinsMenu(page: Page, name: string) {
  const nav = page.locator('.meins-menu');
  await nav.locator('.admin-menu-toggle').click();
  await nav.locator('.admin-menu-list').getByRole('button', { name, exact: true }).click();
}

/** Konto öffnen: „Meins“ und dort „Konto“ (ohne eigene Daten unter „Meins“ steht das Konto gleich da). */
async function openKonto(page: Page) {
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Meins');
  const menu = page.locator('.meins-menu');
  const logout = page.getByRole('button', { name: 'Abmelden' });
  await expect(menu.or(logout)).toBeVisible();
  if (await menu.count()) await meinsMenu(page, 'Konto');
  await expect(logout).toBeVisible();
}

/** Auf der Seite eines Mitglieds in der Verwaltung „Konto und Rolle“ aufklappen (ganz unten). */
async function memberAccount(page: Page) {
  const toggle = page.locator('.member-account .fold-toggle');
  if ((await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click();
}
/** Auf der Seite eines Mitglieds die Kachel „Offen“ antippen: Fenster mit Formular und Einträgen der Kasse. */
async function memberPenalties(page: Page) {
  await page.locator('.member-stats .stat-button', { hasText: 'Offen' }).click();
  await expect(page.getByRole('dialog', { name: 'Kasse' })).toBeVisible();
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
  await page.locator('.topbar').getByRole('button', { name: 'Login' }).click();
  await fillLogin(page, user, password);
  await expect(page).toHaveURL(/\/app\/?(#.*)?$/);
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
}

async function shot(page: Page, name: string) {
  if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}.png` });
}

/** Senkrechter Abstand zwischen der Unterkante von a und der Oberkante von b (Pixel). */
async function gapBetween(a: Locator, b: Locator): Promise<number> {
  const [ra, rb] = [await a.boundingBox(), await b.boundingBox()];
  return Math.round(rb!.y - (ra!.y + ra!.height));
}

/** Bildschirmfotos schmal (360 px) und breit, hell und dunkel; danach wieder wie vorher. */
async function shotsNarrowAndWide(page: Page, name: string) {
  if (!SHOTS) return;
  const before = page.viewportSize()!;
  for (const [width, suffix] of [
    [360, 'schmal'],
    [1000, 'breit'],
  ] as const) {
    await page.setViewportSize({ width, height: 900 });
    for (const scheme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: scheme });
      await page.screenshot({ path: `${SHOTS}/${name}-${suffix}${scheme === 'dark' ? '-dunkel' : ''}.png`, fullPage: true });
    }
  }
  await page.emulateMedia({ colorScheme: 'light' });
  await page.setViewportSize(before);
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
  await adminMenu(page, 'Mitglieder');
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
  // Antippen der Kachel: Formular nur für diese Person und ihre Einträge
  await memberPenalties(page);
  const sheet = page.getByRole('dialog', { name: 'Kasse' });
  await expect(sheet.getByLabel('Mitglied1')).toHaveCount(0);
  await sheet.locator('.row', { hasText: 'Handy klingelt <10 min' }).getByRole('button', { name: 'Bezahlt' }).click();
  await expect(page.locator('.toast')).toHaveText('Als bezahlt markiert');
  await page.keyboard.press('Escape');
  await expect(sheet).toHaveCount(0);
  await expect(page).toHaveURL(/\/mitglied\/\d+$/);
  await expect(page.locator('.member-stats .stat', { hasText: 'Offen' }).locator('.stat-value')).toContainText('2,50');
  await shot(page, '7-verwaltung-mitglied');
});

// Nach dem Test davor: Mitglied1 hat dann Punkte.
test('Benachrichtigung führt zum Eintrag: Termin und Punkte (Issue #190)', async ({ page }) => {
  await login(page, 'mitglied1');
  // Niedriges Fenster: Der letzte Termin steht unterhalb, die Liste muss dorthin scrollen.
  await page.setViewportSize({ width: 390, height: 420 });
  const last = page.locator('li.event', { hasText: 'Sommerfest' });
  await expect(last).toBeAttached();
  await expect(last).not.toBeInViewport();
  const id = await last.getAttribute('data-focus-id');
  expect(id).toMatch(/^[0-9a-f]{32}$/);

  // Offene App (der Service Worker ändert nur die Adresse) und Kaltstart (neues Fenster mit dieser Adresse)
  for (const open of ['offen', 'neu'] as const) {
    if (open === 'offen') await page.goto(`/app/#/termine/termin/${id}`);
    else await page.reload();
    await expect(last).toBeInViewport();
    await expect(last).toHaveClass(/\bopen\b/);
    await expect(last).toHaveClass(/\bfocused\b/);
    await expect(last.getByRole('button', { name: 'In meinen Kalender' })).toBeVisible();
    await expect(last).not.toHaveClass(/\bfocused\b/, { timeout: 5000 }); // Hervorhebung nur kurz
  }

  // Unbekannter Termin (gelöscht oder vorbei): einfach die Liste
  await page.goto('/app/#/termine/termin/gibtesnicht');
  await expect(page.locator('li.event').first()).toBeVisible();
  await expect(page.locator('li.event.focused, li.event.open')).toHaveCount(0);

  // Punkte: meins/punkte/<id>
  await page.setViewportSize(devices['iPhone 13'].viewport);
  await page.goto('/app/#/meins/punkte');
  const row = page.locator('.card-list > li.row').last();
  await expect(row).toBeVisible();
  const pointId = await row.getAttribute('data-focus-id');
  expect(pointId).toMatch(/^\d+$/);
  await page.goto(`/app/#/meins/punkte/${pointId}`);
  await expect(row).toHaveClass(/\bfocused\b/);
  await expect(row).toBeInViewport();
});

test('Mitglied: Punkte-Kachel vergibt Punkte nur an diese Person, Einträge im selben Fenster', async ({ page }) => {
  await login(page, 'verwalter');
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  // Keine Knöpfe mehr unter den Kacheln und kein Menü unter dem Namen
  await expect(page.getByRole('button', { name: 'Punkte vergeben' })).toHaveCount(0);
  await expect(page.locator('.member-menu')).toHaveCount(0);
  const before = Number(
    (await page.locator('.member-stats .stat', { hasText: 'Punkte' }).locator('.stat-value').textContent())!.replace(/\D/g, ''),
  );
  await page.locator('.member-stats .stat-button', { hasText: 'Punkte' }).click();
  const sheet = page.getByRole('dialog', { name: 'Punkte' });
  await expect(page).toHaveURL(/\/mitglied\/\d+\/punkte$/);
  await expect(sheet.getByLabel('Mitglied2')).toHaveCount(0);
  await sheet.getByLabel('Punkte', { exact: true }).fill('3');
  await sheet.getByLabel('Grund').fill('E2E Kachel');
  await sheet.getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toHaveText('+3 Punkte an 1 Mitglied vergeben');
  await expect(sheet.locator('.row', { hasText: 'E2E Kachel' })).toBeVisible();
  await expect(sheet.getByLabel('Grund')).toHaveValue('');
  await expect(page.locator('.member-stats .stat', { hasText: 'Punkte' }).locator('.stat-value')).toHaveText(String(before + 3));
  // Wieder stornieren, damit die Summen anderer Tests stimmen
  page.once('dialog', (d) => d.accept());
  await sheet.locator('.row', { hasText: 'E2E Kachel' }).getByRole('button', { name: 'Stornieren' }).click();
  await expect(page.locator('.toast')).toHaveText('Storniert');
  await sheet.getByRole('button', { name: 'Schließen' }).click();
  await expect(sheet).toHaveCount(0);
  await expect(page.locator('.member-stats .stat', { hasText: 'Punkte' }).locator('.stat-value')).toHaveText(String(before));
});

test('Mitgliederliste: Zurück aus einem Mitglied steht wieder an derselben Stelle', async ({ page }) => {
  await login(page, 'verwalter');
  // Niedriges Fenster, damit die wenigen Testmitglieder schon scrollen.
  await page.setViewportSize({ width: 390, height: 360 });
  await adminMenu(page, 'Mitglieder');
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
  await adminMenu(page, 'Mitglieder');
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

test('Mitglied: Wischen blättert in der Liste, nicht aber in einem offenen Fenster', async ({ page }) => {
  await login(page, 'verwalter');
  await adminMenu(page, 'Mitglieder');
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');
  await expect(page.getByRole('button', { name: 'Vorheriges Mitglied' })).toBeEnabled();

  // Nach links: nächstes Mitglied.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-step')).toContainText('2 / 2');
  // Karussell: Nach dem letzten kommt wieder das erste.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');

  // Nach rechts vom ersten zum letzten; mit den Pfeilen ebenso im Kreis.
  await swipe(page, 200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await page.getByRole('button', { name: 'Nächstes Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await page.getByRole('button', { name: 'Vorheriges Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');

  // Im offenen Fenster blättert Wischen nicht; „Zurück“ schließt erst das Fenster, dann geht es zur Liste (Wischen legt
  // keine Einträge im Verlauf an).
  await memberPenalties(page);
  await expect(page).toHaveURL(/\/strafen$/);
  // Nach links: Nach rechts ginge Chromium hier manchmal selbst im Verlauf zurück (ohne Aufruf aus der App, trotz
  // overscroll-behavior-x im Fenster), und der Test wäre zufällig rot. Nach links gibt es keinen Eintrag, zu dem er vorgehen
  // könnte; blätterte die App, stünde hier Mitglied1.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.getByRole('dialog', { name: 'Kasse' })).toBeVisible();
  await expect(page).toHaveURL(/\/strafen$/);
  await page.goBack();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page).toHaveURL(/\/mitglied\/\d+$/);
  await page.goBack();
  await expect(page.getByLabel('Mitglied suchen')).toHaveValue('Mitglied');
});

test('Mitglied: Beim Wischen steht der Nachbar schon daneben, gewechselt wird ohne Laden', async ({ page }) => {
  await login(page, 'verwalter');
  await adminMenu(page, 'Mitglieder');
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  // Erst die eigene Seite fertig laden lassen (z. B. die Ämter), sonst zählt deren „Lädt …“ als Laden beim Wechsel.
  await expect(page.locator('[data-loading]')).toHaveCount(0);
  // Merken, ob irgendwann „Lädt …“ bzw. das Gerüst erscheint.
  await page.evaluate(() => {
    const w = window as unknown as { sawLoading: boolean };
    w.sawLoading = false;
    new MutationObserver(() => {
      if (document.querySelector('[data-loading]')) w.sawLoading = true;
    }).observe(document.body, { subtree: true, childList: true });
  });

  // Finger noch auf dem Bildschirm: Die Karte ist verschoben, rechts daneben steht schon das nächste Mitglied.
  const box = (await page.locator('.member-name').boundingBox())!;
  const x = page.viewportSize()!.width / 2 + 60;
  const y = box.y + box.height / 2;
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  for (const dx of [-20, -60, -120]) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y }] });
  }
  await expect(page.locator('.member-peek-next .member-peek-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-peek-next .member-step')).toContainText('2 / 2');
  // Touch-Bewegungen kommen erst mit dem nächsten Bild an, daher warten statt sofort lesen.
  await expect.poll(() => page.locator('.member-track').evaluate((el) => el.style.transform)).toBe('translateX(-120px)');
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await cdp.detach();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-peek')).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as { sawLoading: boolean }).sawLoading)).toBe(false);

  // Nur kurz gezogen: Die Karte schnappt zurück, es bleibt beim selben Mitglied.
  await swipe(page, 30);
  await expect(page.locator('.member-peek')).toHaveCount(0);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  expect(await page.locator('.member-track').evaluate((el) => el.style.transform)).toBe('');
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
  await adminMenu(page, 'Ehemalige');
  // Filter und Sortierung sind wie in der Mitgliederliste zunächst eingeklappt; ein gesetzter Filter zeigt „aktiv“.
  const filters = page.getByRole('button', { name: 'Filter und Sortierung' });
  await expect(filters).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByLabel('Nach Grund filtern')).toBeHidden();
  await filters.click();
  await expect(filters).toHaveAttribute('aria-expanded', 'true');
  await page.getByLabel('Nach Grund filtern').selectOption('left');
  await filters.click();
  await expect(page.getByLabel('Nach Grund filtern')).toBeHidden();
  await expect(filters).toContainText('aktiv');
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

  // Eintritt und Austritt unten auf der Seite; Antippen öffnet gleich das Formular zum Ändern.
  const dates = page.locator('.member-dates');
  await expect(dates.getByRole('button', { name: /^Eintritt/ })).toContainText('1970');
  await expect(dates.getByRole('button', { name: /^Austritt/ })).toContainText('Ausgetreten 2000');
  await dates.getByRole('button', { name: /^Austritt/ }).click();
  const sheet = page.getByRole('dialog', { name: 'Austritt' });
  await sheet.getByLabel('Ausgeschieden am (optional)').fill('2001');
  await sheet.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
  await sheet.getByRole('button', { name: 'Schließen' }).click();
  await expect(dates.getByRole('button', { name: /^Austritt/ })).toContainText('Ausgetreten 2001');

  // Ebenso bei Mitgliedern
  await page.goto('/app/#/verwaltung/mitglieder');
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await page.reload();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText(/\d+ \/ \d+/);
  await page.getByRole('button', { name: 'Nächstes Mitglied' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
});

/** Anfragen nach den Daten einer Person (members/<id>), nicht nach Unterseiten wie members/<id>/points. */
const MEMBER_DETAIL = /members\/(\d+)(?![\w/])/;

test('Mitglied: Alle der Liste werden nacheinander vorgeladen, nicht nur die Nachbarn', async ({ page }) => {
  await login(page, 'verwalter');
  const requested = new Set<string>();
  page.on('request', (r) => {
    const m = MEMBER_DETAIL.exec(r.url());
    if (m && r.method() === 'GET') requested.add(m[1]);
  });
  await adminMenu(page, 'Mitglieder');
  await page.locator('.member-row').first().click();
  const step = (await page.locator('.member-step .muted').textContent()) ?? '';
  const total = Number(/\/ (\d+)/.exec(step)?.[1]);
  expect(total).toBeGreaterThan(2);
  // Die Person selbst und alle anderen der Liste, eine nach der anderen
  await expect.poll(() => requested.size, { timeout: 20_000 }).toBe(total);
});

test.describe('ohne Service Worker, Daten zurückgehalten', () => {
  // page.route sieht Anfragen des Service Workers nicht
  test.use({ serviceWorkers: 'block' });

  test('Mitglied: Lädt die Seite noch, steht schon der Name da, und Wischen geht weiter', async ({ page }) => {
    await login(page, 'verwalter');
    await adminMenu(page, 'Mitglieder');
    await page.getByLabel('Mitglied suchen').fill('Mitglied');
    await expect(page.locator('.member-row', { hasText: 'Mitglied2' })).toBeVisible();
    // Daten der Personen zurückhalten, bis der Test sie freigibt
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route(MEMBER_DETAIL, async (route) => {
      await gate;
      await route.continue();
    });
    await page.locator('.row', { hasText: 'Mitglied1' }).click();
    await expect(page.locator('[data-loading]')).not.toHaveCount(0);
    await expect(page.locator('.member-name')).toHaveText('Mitglied1');
    await expect(page.locator('.member-step')).toContainText('1 / 2');
    await swipe(page, -200);
    await expect(page.locator('.member-name')).toHaveText('Mitglied2');
    await expect(page.locator('.member-step')).toContainText('2 / 2');
    await expect(page.locator('[data-loading]')).not.toHaveCount(0);
    release();
    await expect(page.locator('[data-loading]')).toHaveCount(0);
    await expect(page.locator('.member-name')).toHaveText('Mitglied2');
    await expect(page.locator('.member-level').first()).toContainText('Rolle');
  });

  test('Mitglied: Lädt die Seite noch, wischt auch die leere Fläche unter dem Gerüst', async ({ page }) => {
    await login(page, 'verwalter');
    await adminMenu(page, 'Mitglieder');
    await page.getByLabel('Mitglied suchen').fill('Mitglied');
    await expect(page.locator('.member-row', { hasText: 'Mitglied2' })).toBeVisible();
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route(MEMBER_DETAIL, async (route) => {
      await gate;
      await route.continue();
    });
    await page.locator('.row', { hasText: 'Mitglied1' }).click();
    await expect(page.locator('[data-loading]')).not.toHaveCount(0);
    // Unten über der Tab-Leiste: dort steht beim Laden nichts mehr von der Seite, nur die leere Fläche darunter.
    const { width, height } = page.viewportSize()!;
    const y = height - 120;
    const card = (await page.locator('.member-detail').boundingBox())!;
    expect(card.y + card.height).toBeLessThan(y);
    const cdp = await page.context().newCDPSession(page);
    const x = width / 2 + 100;
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    for (const dx of [-50, -100, -150, -200]) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y }] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
    await expect(page.locator('.member-name')).toHaveText('Mitglied2');
    await expect(page.locator('[data-loading]')).not.toHaveCount(0);
    release();
    await expect(page.locator('[data-loading]')).toHaveCount(0);
  });

  test('Mitglied: Kommen die Daten mitten im Wischen an, blättert die Geste trotzdem', async ({ page }) => {
    await login(page, 'verwalter');
    await adminMenu(page, 'Mitglieder');
    await page.getByLabel('Mitglied suchen').fill('Mitglied');
    await expect(page.locator('.member-row', { hasText: 'Mitglied2' })).toBeVisible();
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route(MEMBER_DETAIL, async (route) => {
      await gate;
      await route.continue();
    });
    await page.locator('.row', { hasText: 'Mitglied1' }).click();
    await expect(page.locator('[data-loading]')).not.toHaveCount(0);
    // Finger auf den Namen im Gerüst, ein Stück ziehen; dann kommen die Daten, die Seite baut sich auf.
    const box = (await page.locator('.member-name').boundingBox())!;
    const x = page.viewportSize()!.width / 2 + 60;
    const y = box.y + box.height / 2;
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x - 40, y }] });
    release();
    await expect(page.locator('[data-loading]')).toHaveCount(0);
    for (const dx of [-120, -200]) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y }] });
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await cdp.detach();
    await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  });
});

test('Mitglieder (für alle): Liegt der Finger schon auf, während die Karte noch gleitet, blättert er danach weiter', async ({ page }) => {
  await login(page, 'mitglied1');
  await page.evaluate(() => localStorage.removeItem('kompanie_member_sort'));
  await internMenu(page, 'Mitglieder');
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  await page
    .locator('.directory .member-row', { hasText: 'Mitglied1' })
    .getByRole('button', { name: /Mitglied1/ })
    .click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  const box = (await page.locator('.member-name').boundingBox())!;
  const x = page.viewportSize()!.width / 2 + 80;
  const y = box.y + box.height / 2;
  const cdp = await page.context().newCDPSession(page);
  const move = async (dx: number) => cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y }] });
  // Erster Wisch: Die Karte gleitet zur nächsten Person.
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  for (const dx of [-50, -100, -160]) await move(dx);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  // Gleich wieder aufsetzen, noch während sie gleitet; die Seite der nächsten Person ersetzt die Ansicht unter dem Finger.
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-step')).toContainText('2 / 2');
  // Derselbe Finger zieht weiter: Die Karte folgt ihm und blättert (im Kreis) wieder zur ersten.
  for (const dx of [-20, -60, -120, -180]) await move(dx);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await cdp.detach();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');
});

test('Mitglieder (für alle): Wischen und Pfeile blättern in der Liste, „Zurück“ führt zur Liste', async ({ page }) => {
  await login(page, 'mitglied1');
  await page.evaluate(() => localStorage.removeItem('kompanie_member_sort'));
  await internMenu(page, 'Mitglieder');
  await page.getByLabel('Mitglied suchen').fill('Mitglied');
  await page
    .locator('.directory .member-row', { hasText: 'Mitglied1' })
    .getByRole('button', { name: /Mitglied1/ })
    .click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');
  await expect(page.getByRole('button', { name: 'Vorherige Person' })).toBeEnabled();

  // Nach links: nächste Person; nach der letzten wieder die erste (Karussell), nach rechts zurück zur letzten.
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');
  await expect(page.locator('.member-step')).toContainText('2 / 2');
  await expect(page).toHaveURL(/#\/mitglieder\/\d+$/);
  await swipe(page, -200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText('1 / 2');
  await swipe(page, 200);
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');

  // Beim Ziehen steht die Nachbarin daneben, mit Namen aus der Liste.
  const box = (await page.locator('.member-name').boundingBox())!;
  const x = page.viewportSize()!.width / 2 - 60;
  const y = box.y + box.height / 2;
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
  for (const dx of [20, 60, 120]) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x + dx, y }] });
  }
  await expect(page.locator('.member-peek-prev .member-peek-name')).toHaveText('Mitglied1');
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await cdp.detach();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await page.getByRole('button', { name: 'Nächste Person' }).click();
  await expect(page.locator('.member-name')).toHaveText('Mitglied2');

  // Blättern legt keine Einträge im Verlauf an: „Zurück“ führt gleich zur Liste.
  await page.getByRole('button', { name: '‹ Zurück' }).click();
  await expect(page.locator('.directory .card-list')).toBeVisible();

  // Neu geladen (wie ein Link): die ganze Liste in der gemerkten Sortierung
  await page.goto('/app/#/mitglieder/liste');
  await page
    .locator('.directory .member-row', { hasText: 'Mitglied1' })
    .getByRole('button', { name: /Mitglied1/ })
    .click();
  await page.reload();
  await expect(page.locator('.member-name')).toHaveText('Mitglied1');
  await expect(page.locator('.member-step')).toContainText(/\d+ \/ \d+/);
});

test('Admin legt ein Mitglied an und setzt sein Passwort zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
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
  await memberAccount(page);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: /Neues Startpasswort erzeugen/ }).click();
  const fresh = (await page.getByTestId('reset-password').textContent())!.trim();
  expect(fresh).toHaveLength(12);
  expect(fresh).not.toBe(password);

  await login(page, username, fresh);
  await expect(page.locator('.topbar-sub')).toHaveText('Hallo, Neue Testperson');
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
});

test('Konten: aus einer Excel-Datei importieren, mit Ehemaligen', async ({ page }) => {
  const id = Date.now().toString(36);
  // Kleinste .xlsx-Datei wie aus Excel: Texte inline, Geburtstag als Datumszahl mit Datumsformat.
  const cell = (ref: string, v: string) => `<c r="${ref}" t="inlineStr"><is><t>${v}</t></is></c>`;
  const rows = [
    ['Vorname', 'Nachname', 'Status', 'Geburtstag', 'Ausgeschieden', 'Grund'],
    ['Karla', id, 'Mitglied', '', '', ''],
    ['Lutz', id, 'ehemalig', '', '2001', 'verstorben'],
  ];
  const sheet =
    '<worksheet><sheetData>' +
    rows
      .map(
        (r, i) =>
          `<row r="${i + 1}">${r.map((v, j) => (v ? cell(`${'ABCDEF'[j]}${i + 1}`, v) : '')).join('')}${i === 1 ? '<c r="D2" s="1"><v>29254</v></c>' : ''}</row>`,
      )
      .join('') +
    '</sheetData></worksheet>';
  const xlsx = zipSync({
    'xl/workbook.xml': strToU8('<workbook><sheets><sheet name="A" sheetId="1"/></sheets></workbook>'),
    'xl/styles.xml': strToU8('<styleSheet><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>'),
    'xl/worksheets/sheet1.xml': strToU8(sheet),
  });
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByRole('button', { name: 'Aus Datei importieren (Excel oder CSV)' }).click();
  await page.getByLabel('Datei wählen (Excel .xlsx oder CSV)').setInputFiles({
    name: 'mitglieder.xlsx',
    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    buffer: Buffer.from(xlsx),
  });
  await expect(page.getByLabel('Inhalt von mitglieder.xlsx')).toHaveValue(new RegExp(`Karla\t${id}\tMitglied\t03\.02\.1980`));
  await page.getByRole('button', { name: 'Vorschau' }).click();
  await expect(page.getByRole('status')).toContainText('Neu: 1 Konto mit Anmeldung · 1 ehemalig');
  await page.getByRole('button', { name: 'Übernehmen' }).click();
  await expect(page.getByRole('heading', { name: 'Import übernommen' })).toBeVisible();
  await expect(page.locator('.print-sheet')).toHaveCount(1);
  await expect(page.locator('.print-sheet')).toContainText(`Karla ${id}`);
  await page.getByRole('button', { name: '‹ Zur Mitgliederliste' }).click();
  await expect(page.locator('.row', { hasText: `${id}, Karla` })).toBeVisible();
  await expect(page.locator('.row', { hasText: `${id}, Lutz` })).toHaveCount(0);
  await adminMenu(page, 'Ehemalige');
  await expect(page.locator('.row', { hasText: `${id}, Lutz` })).toBeVisible();
});

test('Konten: mehrere anlegen mit Zugangsdaten, Angaben ändern, Konto löschen', async ({ page }) => {
  const id = Date.now().toString(36);
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
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
  await memberAccount(page);
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
  await memberAccount(page);
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

test('Konten: das eigene Konto in der Verwaltung ändern, ohne Löschen', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.evaluate(() => (location.hash = `#/verwaltung/mitglied/${window.KOMPANIE_CONFIG!.user!.id}`));
  await memberAccount(page);
  const panel = page.locator('.account-panel');
  await expect(panel.getByRole('button', { name: 'Angaben ändern' })).toBeVisible();
  await expect(panel.getByRole('button', { name: /Konto löschen|Aus der App entfernen/ })).toHaveCount(0);

  // Handynummer ohne Passwort.
  await panel.getByRole('button', { name: 'Angaben ändern' }).click();
  const form = page.locator('.account-edit');
  await form.getByLabel('Handynummer (optional)').fill('0171 7654321');
  await expect(form.getByLabel(/Aktuelles Passwort/)).toHaveCount(0);
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(panel).toContainText('+49 171 7654321');

  // Neue E-Mail-Adresse nur mit dem aktuellen Passwort; das Feld erscheint erst dann.
  const email = await page.evaluate(async () => {
    const cfg = window.KOMPANIE_CONFIG!;
    const res = await fetch(`${cfg.apiBase}members/${cfg.user!.id}/account`, { credentials: 'same-origin' });
    return ((await res.json()) as { email: string }).email;
  });
  await panel.getByRole('button', { name: 'Angaben ändern' }).click();
  await form.getByLabel('E-Mail (optional)').fill('chef-neu@example.org');
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(form.locator('.form-error')).toHaveText('Bitte zur Bestätigung das aktuelle Passwort eingeben.');
  await form.getByLabel(/Aktuelles Passwort/).fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(panel).toContainText('chef-neu@example.org');

  // Wieder wie vorher, damit andere Tests die Angaben des Admins unverändert vorfinden.
  await panel.getByRole('button', { name: 'Angaben ändern' }).click();
  await form.getByLabel('E-Mail (optional)').fill(email);
  await form.getByLabel('Handynummer (optional)').fill('');
  if (email !== 'chef-neu@example.org') await form.getByLabel(/Aktuelles Passwort/).fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(form).toHaveCount(0);
  await expect(panel).not.toContainText('+49 171 7654321');
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
  await adminMenu(page, 'Mitglieder');
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
  // Antippen vergrößert das Foto; bis es geladen ist, dreht sich eine Ladeschnecke. Erst die 1024-Pixel-Fassung, dann
  // ersetzt sie die nachgeladene Originalauflösung (s=4096). Escape und ✕ schließen die Ansicht.
  const hold = () => {
    let release = () => {};
    const held = new Promise<void>((resolve) => (release = resolve));
    return { held, release };
  };
  const heldBig = hold();
  const heldOriginal = hold();
  const bigPhoto = /[?&]s=1024(&|$)/;
  const originalPhoto = /[?&]s=4096(&|$)/;
  await page.route(bigPhoto, async (route) => {
    await heldBig.held;
    await route.continue();
  });
  await page.route(originalPhoto, async (route) => {
    await heldOriginal.held;
    await route.continue();
  });
  await page.getByRole('button', { name: /vergrößern/ }).click();
  const zoom = page.getByRole('dialog', { name: /^Foto von / });
  await expect(zoom.getByRole('status', { name: 'Foto lädt' })).toBeVisible();
  await expect(zoom.locator('img')).toBeHidden();
  heldBig.release();
  await expect(zoom.locator('img')).toBeVisible();
  await expect(zoom.getByRole('status', { name: 'Foto lädt' })).toHaveCount(0);
  await expect(zoom.locator('img')).toHaveAttribute('src', bigPhoto);
  heldOriginal.release();
  await expect(zoom.locator('img')).toHaveAttribute('src', originalPhoto);
  await page.unroute(bigPhoto);
  await page.unroute(originalPhoto);
  await page.keyboard.press('Escape');
  await expect(zoom).toHaveCount(0);
  await page.getByRole('button', { name: /vergrößern/ }).click();
  await zoom.getByRole('button', { name: 'Schließen' }).click();
  await expect(zoom).toHaveCount(0);
  // Ersetzen und entfernen nur in der vergrößerten Ansicht, nicht mehr auf der Seite
  await expect(page.getByRole('button', { name: 'Foto entfernen' })).toHaveCount(0);
  await page.getByRole('button', { name: /vergrößern/ }).click();
  await expect(zoom.getByRole('button', { name: 'Foto ersetzen' })).toBeVisible();
  page.once('dialog', (d) => d.accept());
  await zoom.getByRole('button', { name: 'Foto entfernen' }).click();
  await expect(page.locator('.toast')).toContainText('Foto entfernt');
  await expect(zoom).toHaveCount(0);
  await expect(page.locator('.member-rank-line .member-avatar-initials')).toBeVisible();
  // Ohne Foto öffnen die Anfangsbuchstaben ein Fenster zum Hochladen.
  await page.getByRole('button', { name: /hochladen$/ }).click();
  const upload = page.getByRole('dialog', { name: 'Foto' });
  await upload.getByLabel('Foto-Datei').setInputFiles(PNG);
  await expect(page.locator('.toast')).toContainText('Foto gespeichert');
  await expect(upload).toHaveCount(0);
  await expect(page.locator('.member-rank-line .member-avatar img')).toBeVisible();

  // Das Mitglied sieht sein Foto unter „Konto“, kann es dort aber nicht ändern.
  await login(page, username, password);
  await openKonto(page);
  await expect(page.locator('.account-card .member-avatar-large img')).toBeVisible();
  await expect(page.getByRole('button', { name: /Foto/ })).toHaveCount(0);

  // In der Mitgliederliste („Mitglieder“) vergrößert auch ein Mitglied ohne Verwaltungsrechte ein Foto.
  await internMenu(page, 'Mitglieder');
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

  await adminMenu(page, 'Mitglieder');
  await page.getByRole('button', { name: '+ Mitglied anlegen' }).click();
  await page.getByLabel('Vorname').fill('Anna');
  await page.getByLabel('Nachname').fill(last);
  await page.getByLabel('Rolle').selectOption({ label: 'Anwärter' });
  await page.getByRole('button', { name: 'Mitglied anlegen' }).click();
  const username = (await page.getByTestId('new-username').textContent())!.trim();
  const password = (await page.getByTestId('new-password').textContent())!.trim();

  await login(page, username, password);
  await expect(page.getByText('Vereinsabend')).toBeVisible(); // interner Kalender
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  // Unter „Meins“ nur das Konto, keine Daten aus dem Mitgliederbereich
  await openKonto(page);
  await expect(page.locator('.meins-menu')).toHaveCount(0);

  // Öffentliche Liste (ohne Anmeldung): Die Rolle steht wie ein Titel neben dem Namen.
  await page.context().clearCookies();
  await page.goto('/app/');
  await page.locator('.tabbar').getByRole('button', { name: 'Mitglieder' }).click();
  await expect(page.locator('.directory .member-row', { hasText: last }).locator('.badge-candidate')).toHaveText('Anwärter');

  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  // Zähler über „Mitglied anlegen“: Gruppen wie in der Anwesenheit
  await expect(page.locator('.member-counts')).toContainText(/Anwärter \d+/);
  // Filter und Sortierung sind zunächst eingeklappt.
  await expect(page.getByRole('button', { name: 'Filter und Sortierung' })).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByLabel('Sortierung')).toBeHidden();
  // Die Rolle steht wie ein Titel neben dem Namen, solange die Person noch aufzunehmen ist.
  await expect(page.locator('.row', { hasText: `${last}, Anna` }).locator('.badge-candidate')).toHaveText('Anwärter');
  await page.locator('.row', { hasText: `${last}, Anna` }).click();
  await memberAccount(page);
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Als Mitglied aufnehmen' }).click();
  await expect(page.locator('.toast')).toHaveText(`${name} ist jetzt Mitglied`);
  await expect(page.getByRole('button', { name: 'Als Mitglied aufnehmen' })).toHaveCount(0);

  await login(page, username, password);
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(page.locator('.meins-menu')).toBeVisible();
});

test('Intern: Knöpfe für Mitglieder, Dokumente, Brett, Dienstgrade und Punktekatalog, „‹ Intern“ führt zurück; ohne Anmeldung Mitglieder als eigener Tab', async ({
  page,
}) => {
  await login(page, 'mitglied1');
  const tabbar = page.locator('.tabbar');
  await expect(tabbar.getByRole('button', { name: 'Mitglieder' })).toHaveCount(0);
  await expect(tabbar.getByRole('button', { name: 'Dokumente' })).toHaveCount(0);
  await tabbar.getByRole('button', { name: 'Intern' }).click();
  await expect(page).toHaveURL(/#\/intern$/);
  await expect(page.locator('.topbar-title')).toHaveText('Intern');
  const tiles = page.getByRole('navigation', { name: 'Intern' });
  await expect(tiles.getByRole('button')).toHaveText(['Mitglieder', 'Dokumente', 'Schwarzes Brett', 'Dienstgrade', 'Punktekatalog']);

  await tiles.getByRole('button', { name: 'Dokumente', exact: true }).click();
  await expect(page).toHaveURL(/#\/dokumente$/);
  await expect(tabbar.getByRole('button', { name: 'Intern' })).toHaveAttribute('aria-current', 'page');
  await page.getByRole('button', { name: '‹ Intern' }).click();
  await tiles.getByRole('button', { name: 'Mitglieder', exact: true }).click();
  await expect(page).toHaveURL(/#\/mitglieder$/);
  await expect(page.locator('.directory .member-row').first()).toBeVisible();
  await expect(tabbar.getByRole('button', { name: 'Intern' })).toHaveAttribute('aria-current', 'page');
  await page.getByRole('button', { name: '‹ Intern' }).click();
  await expect(page).toHaveURL(/#\/intern$/);

  // Schwarzes Brett zum Lesen: gehört zu „Intern“ (wer schreibt, hat es in der Verwaltung; Test „Schwarzes Brett: Banner …“)
  await tiles.getByRole('button', { name: 'Schwarzes Brett', exact: true }).click();
  await expect(page).toHaveURL(/#\/brett$/);
  await expect(page.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  await expect(tabbar.getByRole('button', { name: 'Intern' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('button', { name: 'Nachricht schreiben' })).toHaveCount(0);
  await page.getByRole('button', { name: '‹ Intern' }).click();

  // Nachschlagen: Dienstgrade und Punktekatalog (nur aktive Vorlagen, mit Punktzahl)
  await tiles.getByRole('button', { name: 'Dienstgrade', exact: true }).click();
  await expect(page).toHaveURL(/#\/dienstgrade$/);
  await expect(page.locator('.topbar-title')).toHaveText('Dienstgrade');
  await expect(tabbar.getByRole('button', { name: 'Intern' })).toHaveAttribute('aria-current', 'page');
  await page.getByRole('button', { name: '‹ Intern' }).click();
  await tiles.getByRole('button', { name: 'Punktekatalog', exact: true }).click();
  await expect(page).toHaveURL(/#\/punktekatalog$/);
  await expect(page.locator('.topbar-title')).toHaveText('Punktekatalog');
  await expect(page.locator('.card.row', { hasText: 'Teilnahme Sommerfest' }).locator('.row-value')).toHaveText('+5');
  await page.getByRole('button', { name: '‹ Intern' }).click();
  await expect(page).toHaveURL(/#\/intern$/);

  // Ohne Anmeldung nur die Mitglieder: kein „Intern“, die Mitglieder stehen unten als eigener Tab.
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(tabbar.getByRole('button', { name: 'Intern' })).toHaveCount(0);
  await tabbar.getByRole('button', { name: 'Mitglieder' }).click();
  await expect(page).toHaveURL(/#\/mitglieder$/);
  await expect(page.locator('.directory .member-row').first()).toBeVisible();
  await expect(tabbar.getByRole('button', { name: 'Mitglieder' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('button', { name: '‹ Intern' })).toHaveCount(0);
  // Auch ein Link auf #/intern führt Gäste nicht auf eine leere Seite.
  await page.goto('/app/#/intern');
  await expect(page.locator('.admin-tiles')).toHaveCount(0);
  // Dienstgrade und Punktekatalog sind nur für Mitglieder.
  await page.goto('/app/#/punktekatalog');
  await expect(page.locator('.card.row', { hasText: 'Teilnahme Sommerfest' })).toHaveCount(0);
  await expect(tabbar.getByRole('button', { name: 'Dienstgrade' })).toHaveCount(0);
});

test('Mitgliederliste: Mitglied sieht alle Mitglieder mit Suche, ohne Punkte oder Rollen', async ({ page }) => {
  await login(page, 'mitglied1');
  await internMenu(page, 'Mitglieder');
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
  await expect(page.locator('.topbar').getByRole('button', { name: 'Login' })).toBeVisible();
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
            joined_on: '1985-04-01',
            left_on: '2010',
          },
        ],
      }),
    );
    await page.goto('/app/#/mitglieder/ehemalige');
    const switcher = page.locator('.members-switch');
    const list = page.locator('.directory .card-list');
    await list.locator('.member-row', { hasText: 'Ehemalig, Erwin' }).locator('.member-row-open').click();
    await expect(page).toHaveURL(/#\/mitglieder\/987654$/);
    await expect(page.locator('.member-page')).toContainText('Ehemalig · Gründungsmitglied · 01.04.1985 – 2010');
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
  // Abstände um „Amtszeit eintragen“ wie zwischen Karten, auch mit offenem Formular (Issue #179)
  const addTerm = page.getByRole('button', { name: 'Amtszeit eintragen' });
  expect(await gapBetween(page.locator('.office-term-row').last(), addTerm)).toBe(16);
  expect(await gapBetween(addTerm, page.locator('.btn-block + .card'))).toBe(16);
  await shotsNarrowAndWide(page, '1a-amt-bearbeiten');
  await addTerm.click();
  form = page.locator('form', { hasText: 'Neue Amtszeit' });
  expect(await gapBetween(page.locator('.office-term-row').last(), form)).toBe(16);
  expect(await gapBetween(form, page.locator('form.card:has-text("Neue Amtszeit") + .card'))).toBe(16);
  await shotsNarrowAndWide(page, '1a-amt-bearbeiten-neue-amtszeit');
  await form.getByRole('button', { name: 'Abbrechen' }).click();

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

test('Ämter: Haken „Ehrung“ an der Amtszeit, einzelnes Jahr ohne Ehrung (Issue #180)', async ({ page }) => {
  const office = `Ehrentest ${Date.now() % 1000000}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Ämter');
  const newOffice = page.locator('form', { hasText: 'Neues Amt' });
  await newOffice.getByLabel('Bezeichnung').fill(office);
  await newOffice.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.toast')).toHaveText('Amt angelegt');
  await page.getByRole('button', { name: office, exact: true }).click();

  const add = async (kind: 'period' | 'point', from: string, to: string, name: string, honor: boolean) => {
    await page.getByRole('button', { name: 'Amtszeit eintragen' }).click();
    const form = page.locator('form', { hasText: 'Neue Amtszeit' });
    await form.getByRole('combobox', { name: /^Art/ }).selectOption(kind);
    await form.getByLabel(kind === 'point' ? 'Jahr' : 'Von (Jahr)', { exact: true }).fill(from);
    if (to) await form.getByLabel('Bis (leer: bis heute)').fill(to);
    await form.getByLabel('Name', { exact: true }).fill(name);
    if (honor) await form.getByLabel(/^Ehrung/).check();
    await form.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.locator('.toast')).toHaveText('Amtszeit eingetragen');
  };
  await expect(page.getByRole('button', { name: 'Amtszeit eintragen' })).toBeVisible();
  await page.getByRole('button', { name: 'Amtszeit eintragen' }).click();
  await expect(page.locator('form', { hasText: 'Neue Amtszeit' }).getByRole('combobox', { name: /^Art/ }).locator('option')).toHaveText([
    'Zeitraum (von–bis oder bis heute)',
    'Einzelnes Jahr',
  ]);
  await page.locator('form', { hasText: 'Neue Amtszeit' }).getByRole('button', { name: 'Abbrechen' }).click();
  await add('point', '2019', '', 'Karl König', false);
  await add('point', '2008', '', 'Gustav Gans', true);
  await add('period', '2000', '2004', 'Ida Igel', true);
  // Der Haken steht beim Bearbeiten wieder da
  await page.locator('.office-term-row', { hasText: '2008' }).getByRole('button', { name: 'Bearbeiten' }).click();
  const edit = page.locator('form', { hasText: 'Amtszeit bearbeiten' });
  await expect(edit.getByLabel(/^Ehrung/)).toBeChecked();
  await edit.getByRole('button', { name: 'Abbrechen' }).click();
  await page.locator('.office-term-row', { hasText: '2019' }).getByRole('button', { name: 'Bearbeiten' }).click();
  await expect(page.locator('form', { hasText: 'Amtszeit bearbeiten' }).getByLabel(/^Ehrung/)).not.toBeChecked();
  await page.locator('form', { hasText: 'Amtszeit bearbeiten' }).getByRole('button', { name: 'Abbrechen' }).click();

  // Öffentlicher Zeitstrahl: Stern und Abzeichen nur mit dem Haken, bei jeder Art
  await page.goto('/app/#/mitglieder/aemter');
  const card = page.locator('.office-card', { hasText: office });
  await card.locator('.office-head').click();
  const item = (text: string) => card.locator('.tl-item', { hasText: text });
  await expect(item('Karl König')).not.toHaveClass(/\bhonor\b/);
  await expect(item('Karl König').locator('.timeline-honor')).toHaveCount(0);
  await expect(item('Karl König').locator('.tl-dot')).toHaveText('');
  await expect(item('Gustav Gans')).toHaveClass(/\bhonor\b/);
  await expect(item('Gustav Gans').locator('.timeline-honor')).toHaveText('Ehrung');
  await expect(item('Gustav Gans').locator('.tl-dot')).toHaveText('★');
  await expect(item('Ida Igel').locator('.timeline-honor')).toHaveText('Ehrung');
  await expect(item('Ida Igel')).toContainText('4 Jahre');

  await page.goto('/app/#/verwaltung/aemter');
  await page.getByRole('button', { name: office, exact: true }).click();
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Amt löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Amt gelöscht');
});

test('Ehemalig eintragen: Vorschau der endenden Ämter, danach enden sie mit dem Austrittsjahr (Issue #181)', async ({ page }) => {
  const id = Date.now() % 1000000;
  const flag = `Fahne ${id}`;
  const ministers = `Minister ${id}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  const ids = await page.evaluate(
    async ({ id, flag, ministers }) => {
      const cfg = window.KOMPANIE_CONFIG!;
      const post = async (path: string, body: unknown) =>
        (
          await fetch(cfg.apiBase + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', [cfg.csrf.header]: cfg.csrf.token },
            body: JSON.stringify(body),
          })
        ).json();
      const user = await post('users', { first_name: 'Erna', last_name: `Austritt${id}` });
      const a = await post('offices', { title: flag });
      const b = await post('offices', { title: ministers });
      await post(`offices/${a.id}/terms`, { start_year: 2006, holders: [{ user_id: user.id }] });
      await post(`offices/${b.id}/terms`, { start_year: 2020, holders: [{ user_id: user.id }, { name: `Paul Partner ${id}` }] });
      return { user: user.id as number, offices: [a.id as number, b.id as number] };
    },
    { id, flag, ministers },
  );
  expect(ids.user).toBeTruthy();

  await page.goto(`/app/#/verwaltung/mitglied/${ids.user}`);
  await page.getByRole('button', { name: /^Austritt/ }).click();
  // Das Fenster „Austritt“ zeigt gleich das Formular
  const sheet = page.getByRole('dialog');
  const hint = sheet.locator('.ending-offices');
  const year = new Date().getFullYear();
  // Ohne Datum: dieses Jahr; mit Datum: dessen Jahr
  await expect(hint).toContainText(`Mit dem Ausscheiden enden: ${flag} (seit 2006), ${ministers} (seit 2020) – Ende ${year}.`);
  await expect(hint).toContainText('nur dieser Eintrag endet');
  await sheet.getByLabel(/^Ausgeschieden am/).fill('15.03.2024');
  await expect(hint).toContainText(`– Ende 2024.`);
  await sheet.getByLabel(/^Ausgeschieden am/).fill('2010');
  await expect(hint).toContainText(`Der Austritt liegt vor dem Beginn von ${ministers}`);
  await sheet.getByLabel(/^Ausgeschieden am/).fill('15.03.2024');
  await expect(hint).toContainText(`– Ende 2024.`);
  await sheet.getByRole('button', { name: 'Als ehemalig eintragen', exact: true }).click();
  await expect(page.locator('.toast')).toContainText('ist jetzt als ehemalig eingetragen');
  await expect(page.locator('.toast')).toContainText('beendet: 2');

  // Öffentlicher Zeitstrahl: beendet statt „seit“; der Partner bleibt im Amt
  await page.goto('/app/#/mitglieder/aemter');
  const flagCard = page.locator('.office-card', { hasText: flag });
  await expect(flagCard.locator('.office-summary-text')).not.toContainText('seit');
  await flagCard.locator('.office-head').click();
  await expect(flagCard.locator('.tl-item').first()).toContainText('2006–2024');
  const ministerCard = page.locator('.office-card', { hasText: ministers });
  await expect(ministerCard.locator('.office-summary-text')).toHaveText(`Paul Partner ${id} · seit 2020`);

  // Aufräumen (WordPress behält die Daten zwischen den Läufen)
  const status = await page.evaluate(async (ids) => {
    const cfg = window.KOMPANIE_CONFIG!;
    const del = (path: string) =>
      fetch(cfg.apiBase + path, { method: 'DELETE', credentials: 'same-origin', headers: { [cfg.csrf.header]: cfg.csrf.token } }).then(
        (r) => r.status,
      );
    return [await del(`offices/${ids.offices[0]}`), await del(`offices/${ids.offices[1]}`), await del(`members/${ids.user}`)];
  }, ids);
  expect(status.every((s) => s < 300)).toBe(true);
});

test('Ämter: Einzahl auf der Seite einer Person, Hinweis mit Link bei Titeln, die ein Amt vergibt (Issue #112)', async ({ page }) => {
  const id = Date.now() % 1000000;
  const office = `Wartleute ${id}`;
  const title = `Wartlauf ${id}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  const ids = await page.evaluate(
    async ({ id, office, title }) => {
      const cfg = window.KOMPANIE_CONFIG!;
      const send = async (method: string, path: string, body: unknown) =>
        (
          await fetch(cfg.apiBase + path, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', [cfg.csrf.header]: cfg.csrf.token },
            body: JSON.stringify(body),
          })
        ).json();
      const user = await send('POST', 'users', { first_name: 'Theo', last_name: `Titelprobe${id}` });
      const t = await send('POST', 'titles', { title });
      const o = await send('POST', 'offices', { title: office, singular: `Wartmann ${id}` });
      await send('POST', `offices/${o.id}/terms`, { start_year: 2020, holders: [{ user_id: user.id }] });
      await send('PATCH', `offices/${o.id}`, { title_id: t.id });
      return { user: user.id as number, title: t.id as number, office: o.id as number };
    },
    { id, office, title },
  );

  // Titel verwalten: Hinweis mit Link zum Amt
  await adminMenu(page, 'Titel');
  const row = page.locator('.title-list .row', { hasText: title });
  await expect(row).toContainText(`Wird über das Amt „${office}“ vergeben`);
  await row.getByRole('button', { name: `das Amt „${office}“` }).click();
  await expect(page).toHaveURL(new RegExp(`#/verwaltung/aemter/${ids.office}$`));
  await expect(page.getByLabel(/^Einzahl/)).toHaveValue(`Wartmann ${id}`);

  // Mitgliedsdetails: Häkchen gesperrt, Hinweis darunter
  await page.goto(`/app/#/verwaltung/mitglied/${ids.user}`);
  await page.locator('.member-titles-tile').click();
  const box = page.locator('.member-titles').getByLabel(title);
  await expect(box).toBeChecked();
  await expect(box).toBeDisabled();
  await expect(page.locator('.member-titles .title-office-note')).toContainText(`Wird über das Amt „${office}“ vergeben`);
  await page.getByRole('dialog').getByRole('button', { name: 'Schließen' }).click();

  // Seite der Person: die Einzahl statt der Bezeichnung des Amts
  await page.goto(`/app/#/mitglieder/${ids.user}`);
  await expect(page.locator('.member-page .tl-item', { hasText: `Wartmann ${id}` })).toContainText('seit 2020');
  await expect(page.locator('.member-page .tl-item', { hasText: office })).toHaveCount(0);

  const status = await page.evaluate(async (ids) => {
    const cfg = window.KOMPANIE_CONFIG!;
    const del = (path: string) =>
      fetch(cfg.apiBase + path, { method: 'DELETE', credentials: 'same-origin', headers: { [cfg.csrf.header]: cfg.csrf.token } }).then(
        (r) => r.status,
      );
    return [await del(`offices/${ids.office}`), await del(`members/${ids.user}`), await del(`titles/${ids.title}`)];
  }, ids);
  expect(status.every((s) => s < 300)).toBe(true);
});

test('Ämter: Jahresübersicht auf breiten Bildschirmen, eine Spalte je Amt, gemerkt (Issue #112)', async ({ page }) => {
  const id = Date.now() % 1000000;
  const office = `Fähnriche ${id}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  const officeId = await page.evaluate(
    async ({ id, office }) => {
      const cfg = window.KOMPANIE_CONFIG!;
      const post = async (path: string, body: unknown) =>
        (
          await fetch(cfg.apiBase + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', [cfg.csrf.header]: cfg.csrf.token },
            body: JSON.stringify(body),
          })
        ).json();
      const o = await post('offices', { title: office });
      // Zwei gleichzeitig (zwei Spuren), ein Vorgänger, eine Ehrung als einzelnes Jahr
      await post(`offices/${o.id}/terms`, { start_year: 2006, holders: [{ name: `Anna Alt ${id}` }] });
      await post(`offices/${o.id}/terms`, { start_year: 2010, holders: [{ name: `Bert Bunt ${id}` }] });
      await post(`offices/${o.id}/terms`, { start_year: 1990, end_year: 2006, holders: [{ name: `Carl Clau ${id}` }] });
      await post(`offices/${o.id}/terms`, { kind: 'point', start_year: 2000, honor: true, holders: [{ name: `Dora Dank ${id}` }] });
      return o.id as number;
    },
    { id, office },
  );

  // Schmal: keine Umschaltung
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/app/#/mitglieder/aemter');
  await expect(page.locator('.office-card', { hasText: office })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Ansicht' })).toHaveCount(0);

  // Breit: „Jahre“ zeigt das Raster; die Seite selbst scrollt nicht seitlich
  await page.setViewportSize({ width: 1200, height: 900 });
  await page.getByRole('group', { name: 'Ansicht' }).getByRole('button', { name: 'Jahre' }).click();
  const grid = page.locator('.year-grid');
  await expect(grid).toBeVisible();
  await expect(grid.locator('.yg-office', { hasText: office })).toBeVisible();
  const bar = (name: string) => grid.locator('.yg-bar', { hasText: name });
  await expect(bar(`Anna Alt ${id}`)).toHaveClass(/current/);
  await expect(bar(`Bert Bunt ${id}`)).toHaveClass(/current/);
  await expect(bar(`Dora Dank ${id}`)).toHaveClass(/honor/);
  await expect(bar(`Dora Dank ${id}`)).toContainText('★');
  await expect(bar(`Carl Clau ${id}`)).toHaveAttribute('title', `${office}: 1990–2006 · Carl Clau ${id}`);
  // Gleichzeitige Amtszeiten nebeneinander, die Nachfolge in derselben Spalte
  const x = async (name: string) => (await bar(name).boundingBox())!.x;
  expect(await x(`Anna Alt ${id}`)).toBe(await x(`Carl Clau ${id}`));
  expect(await x(`Bert Bunt ${id}`)).not.toBe(await x(`Anna Alt ${id}`));
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await shot(page, '1d-aemter-jahre');

  // Gemerkt: nach dem Neuladen wieder die Jahre
  await page.reload();
  await expect(page.locator('.year-grid')).toBeVisible();
  await page.getByRole('group', { name: 'Ansicht' }).getByRole('button', { name: 'Chronik' }).click();
  await expect(page.locator('.year-grid')).toHaveCount(0);

  const status = await page.evaluate(async (officeId) => {
    const cfg = window.KOMPANIE_CONFIG!;
    return (
      await fetch(cfg.apiBase + `offices/${officeId}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { [cfg.csrf.header]: cfg.csrf.token },
      })
    ).status;
  }, officeId);
  expect(status).toBeLessThan(300);
});

test('Listen: anlegen, Mitglieder aktualisieren, Spalten und Zeilen, Vorschau, CSV und Druck', async ({ page }) => {
  const title = `Unterschriften ${Date.now() % 1000000}`;
  await login(page, 'admin', ADMIN_PASSWORD);
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

  // Auch im dunklen Modus schwarz auf weiß; Zeilen werden nicht geteilt, der Kopf steht auf jeder Seite (Issue #121).
  // Mit vielen Zeilen über mehrere Seiten (nicht gespeichert, die Druckansicht zeigt den Entwurf).
  for (let i = 0; i < 8; i++) await page.getByRole('button', { name: '+ 5 leere Zeilen' }).click();
  await page.emulateMedia({ media: 'print', colorScheme: 'dark' });
  const printed = await page.evaluate(() => {
    const css = (sel: string) => getComputedStyle(document.querySelector(sel)!);
    const rows = [...document.querySelectorAll('.list-print-root .list-table tbody tr')];
    return {
      html: getComputedStyle(document.documentElement).backgroundColor,
      body: css('body').backgroundColor,
      sheet: [css('.list-print-root .list-sheet').color, css('.list-print-root .list-sheet').backgroundColor],
      cell: css('.list-print-root .list-table tbody td').color,
      title: css('.list-print-root .list-sheet-title').color,
      head: css('.list-print-root .list-table thead').display,
      rows: rows.length,
      avoid: rows.every((r) => getComputedStyle(r).breakInside === 'avoid'),
    };
  });
  expect(printed).toEqual({
    html: 'rgb(255, 255, 255)',
    body: 'rgb(255, 255, 255)',
    sheet: ['rgb(0, 0, 0)', 'rgb(255, 255, 255)'],
    cell: 'rgb(0, 0, 0)',
    title: 'rgb(255, 255, 255)',
    head: 'table-header-group',
    rows: members + 42,
    avoid: true,
  });
  if (SHOTS) await page.pdf({ path: `${SHOTS}/40e-liste-dunkel-mehrseitig.pdf`, format: 'A4', preferCSSPageSize: true });
  await page.emulateMedia({ media: 'screen', colorScheme: 'light' });

  // Kopieren und löschen (WordPress behält die Daten zwischen den Läufen); die leeren Zeilen von eben verwerfen
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: /Alle Listen/ }).click();
  await expect(page).toHaveURL(/#\/verwaltung\/listen$/);
  await expect(page).toHaveTitle('Musterkompanie Beispielstadt');
  await page.locator('.row', { hasText: title }).first().getByRole('button', { name: 'Kopieren' }).click();
  await expect(page.locator('.toast')).toHaveText('Liste kopiert');
  // Abstand zwischen der letzten Liste und „Neue Liste“ (Issue #179), erst wenn die Kopie in der Liste steht
  await expect(page.locator('.row', { hasText: `${title} (Kopie)` }).first()).toBeVisible();
  await expect.poll(() => gapBetween(page.locator('.card-list'), page.locator('form.card', { hasText: 'Neue Liste' }))).toBe(16);
  await shotsNarrowAndWide(page, '40d-listen-uebersicht');
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
  await adminMenu(page, 'Mitglieder');
  await expect(page.locator('.row', { hasText: 'Mitglied1' })).toBeVisible();
  await expect(page.getByRole('button', { name: '+ Mitglied anlegen' })).toHaveCount(0);
  await page.locator('.row', { hasText: 'Mitglied1' }).click();
  await memberAccount(page);
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
  await openKonto(page);

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
  await expect(page.locator('.stat-label').first()).toHaveText('Punkte');

  await login(page, 'mitglied2', NEW);
  await openKonto(page);
  await page.getByLabel('Aktuelles Passwort').fill(NEW);
  await page.getByLabel(/^Neues Passwort \(/).fill(PASSWORD);
  await page.getByLabel('Neues Passwort wiederholen').fill(PASSWORD);
  await page.getByRole('button', { name: 'Passwort ändern' }).click();
  await expect(page.locator('.toast')).toContainText('Passwort geändert');
});

test('Mitglied wählt die Darstellung hell oder dunkel unabhängig vom Gerät', async ({ page }) => {
  const bg = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  await page.emulateMedia({ colorScheme: 'light' });
  await login(page, 'mitglied2');
  await openKonto(page);
  const choice = page.getByRole('navigation', { name: 'Darstellung' });
  await expect(choice.getByRole('button', { name: 'Wie Gerät' })).toHaveAttribute('aria-pressed', 'true');
  const light = await bg();

  await choice.getByRole('button', { name: 'Dunkel' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  const dark = await bg();
  expect(dark).not.toBe(light);
  // Wie der Dunkelmodus des Geräts
  await choice.getByRole('button', { name: 'Wie Gerät' }).click();
  await page.emulateMedia({ colorScheme: 'dark' });
  expect(await bg()).toBe(dark);

  // Hell gewinnt gegen den Dunkelmodus des Geräts und bleibt nach dem Neuladen.
  await choice.getByRole('button', { name: 'Hell' }).click();
  expect(await bg()).toBe(light);
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  expect(await bg()).toBe(light);
  await expect(page.getByRole('navigation', { name: 'Darstellung' }).getByRole('button', { name: 'Hell' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );

  await page.getByRole('navigation', { name: 'Darstellung' }).getByRole('button', { name: 'Wie Gerät' }).click();
  await expect(page.locator('html')).not.toHaveAttribute('data-theme');
  expect(await bg()).toBe(dark);
  await page.emulateMedia({ colorScheme: 'light' });
});

test('Knopf in der Kopfzeile wechselt hell und dunkel, auch ohne Anmeldung', async ({ page }) => {
  const bg = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  await page.emulateMedia({ colorScheme: 'light' });
  await page.goto('/app/');
  const light = await bg();
  // Ohne Anmeldung: Kopfzeile mit Hilfe, Darstellung und „Login“
  await expect(page.locator('.topbar').getByRole('button', { name: 'Login' })).toBeVisible();
  await page.locator('.topbar').getByRole('button', { name: 'Dunkle Darstellung' }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  const dark = await bg();
  expect(dark).not.toBe(light);
  // Zurück zur Darstellung des Geräts: folgt wieder dem Gerät.
  await page.locator('.topbar').getByRole('button', { name: 'Helle Darstellung' }).click();
  await expect(page.locator('html')).not.toHaveAttribute('data-theme');
  expect(await bg()).toBe(light);
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(page.locator('.topbar').getByRole('button', { name: 'Helle Darstellung' })).toBeVisible();
  expect(await bg()).toBe(dark);

  // Angemeldet: kein „Konto“ mehr oben; Knopf und Auswahl unter Meins → Konto zeigen dasselbe.
  await login(page, 'mitglied2');
  await expect(page.locator('.topbar').getByRole('button', { name: 'Konto' })).toHaveCount(0);
  await openKonto(page);
  const choice = page.getByRole('navigation', { name: 'Darstellung' });
  await page.locator('.topbar').getByRole('button', { name: 'Helle Darstellung' }).click();
  await expect(choice.getByRole('button', { name: 'Hell' })).toHaveAttribute('aria-pressed', 'true');
  expect(await bg()).toBe(light);
  await page.locator('.topbar').getByRole('button', { name: 'Dunkle Darstellung' }).click();
  await expect(choice.getByRole('button', { name: 'Wie Gerät' })).toHaveAttribute('aria-pressed', 'true');
  await page.emulateMedia({ colorScheme: 'light' });
  // Alte Adresse #/konto führt zum Konto unter „Meins“.
  await page.goto('/app/#/konto');
  await expect(page.locator('.topbar-title')).toHaveText('Meins');
  await expect(page.getByRole('button', { name: 'Abmelden' })).toBeVisible();
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
  await expect(page.locator('.topbar').getByRole('button', { name: 'Login' })).toBeVisible();
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

test('Geteilter Link: Gast meldet sich an und landet auf der Seite; Teilen-Knopf liefert die Adresse', async ({ page }) => {
  await page.context().clearCookies();
  // Teilen-Menü des Geräts nachbilden: Die App gibt ihm die Adresse der Seite.
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'share', {
      value: async (data: ShareData) => {
        (window as unknown as { shared: ShareData }).shared = data;
      },
    });
  });
  await page.goto('/app/#/meins/punkte');
  const hint = page.locator('.login-first');
  await expect(hint).toBeVisible();
  await hint.getByRole('button', { name: 'Anmelden' }).click();
  await fillLogin(page, 'mitglied1', PASSWORD);
  await expect(page).toHaveURL(/\/app\/#\/meins\/punkte$/);
  await expect(page.locator('.topbar-title')).toHaveText('Meins');
  await expect(page.locator('.login-first')).toHaveCount(0);

  await page.getByRole('button', { name: 'Link zu dieser Seite teilen' }).click();
  const shared = await page.evaluate(() => (window as unknown as { shared?: ShareData }).shared);
  expect(shared?.url).toMatch(/\/app\/#\/meins\/punkte$/);
  expect(shared?.title).toContain('Meins');
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
    await openKonto(page);
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
    await openKonto(page);
    await page.getByRole('button', { name: 'Abmelden' }).click();
    await expect(page.locator('.topbar').getByRole('button', { name: 'Login' })).toBeVisible();
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
  // Laufende Abrufe der News, auch die des Service Workers (die sieht nur der Kontext, nicht die Seite).
  const running = new Set<Request>();
  page.context().on('request', (r) => /news/.test(r.url()) && running.add(r));
  page.context().on('requestfinished', (r) => running.delete(r));
  page.context().on('requestfailed', (r) => running.delete(r));
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

  // Keine gespeicherten Daten (auch nicht beim Service Worker), News antworten sehr langsam. Erst verlangsamen, dann
  // leeren: Ein Abruf des Service Workers, der vorher begann, speichert seine Antwort sonst nach dem Löschen wieder, und
  // die News kämen beim Neuladen sofort, ohne Gerüst. Also warten, bis er fertig ist, und prüfen, dass es leer bleibt.
  await page.context().route(/news/, async (route) => {
    await new Promise((r) => setTimeout(r, 8000));
    await route.continue().catch(() => undefined);
  });
  try {
    const before = [...running];
    await expect.poll(() => before.filter((r) => running.has(r)).length).toBe(0);
    await expect
      .poll(() =>
        page.evaluate(async () => {
          await caches.delete('kompanie-data-v2');
          await new Promise((r) => setTimeout(r, 300));
          const cache = await caches.open('kompanie-data-v2');
          return (await cache.keys()).some((k) => k.url.includes('news'));
        }),
      )
      .toBe(false);
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

test.describe('Vorabladen (Issue #104)', () => {
  // page.route und page.on('request') sehen Anfragen, die der Service Worker selbst stellt, nicht.
  test.use({ serviceWorkers: 'block' });

  /** Pfad einer API-Anfrage: eigenständig …/api/v1/…, WordPress …/kompanie-app/v1/… (auch ?rest_route=…) */
  const API_PATH = /\/(?:api|kompanie-app)\/v1(\/[^?&]*)/;

  /** Anfragen an die API mitschreiben (Pfad, z. B. „/news“), dazu Antworten mit 403. preview: nur die der Vorschau. */
  function watchApi(page: Page, preview = false) {
    const paths: string[] = [];
    const forbidden: string[] = [];
    page.on('request', (r) => {
      const m = decodeURIComponent(r.url()).match(API_PATH);
      if (m && r.method() === 'GET' && (!preview || r.headers()['x-kompanie-preview'])) paths.push(m[1]);
    });
    page.on('response', (r) => {
      if (r.status() === 403) forbidden.push(r.url());
    });
    return { paths, forbidden, count: (p: string) => paths.filter((x) => x === p).length };
  }

  /** Termine sind geladen (mit oder ohne öffentliche Termine, je nachdem, welche Tests vorher liefen). */
  const startPageLoaded = (page: Page) => expect(page.getByText(/Tag der offenen Tür|Keine anstehenden Termine/)).toBeVisible();

  /** Datensparmodus (navigator.connection.saveData) wie in Chrome auf Android */
  const saveData = (page: Page) =>
    page.addInitScript(() => Object.defineProperty(navigator, 'connection', { value: { saveData: true, effectiveType: '4g' } }));

  /** API ab jetzt sehr langsam: Was nicht schon vorab da ist, zeigt „Lädt …“ bzw. das Gerüst. */
  const slowApi = (page: Page, re: RegExp) =>
    page.route(re, async (route) => {
      await new Promise((r) => setTimeout(r, 8000));
      await route.continue().catch(() => undefined);
    });

  test('Leerlauf: Die übrigen Tabs sind beim ersten Öffnen sofort da, ohne 403', async ({ page }) => {
    const api = watchApi(page);
    await login(page, 'mitglied1');
    await expect(page.getByText('Vereinsabend')).toBeVisible();
    // Nach dem Start im Leerlauf, eins nach dem anderen (Reihenfolge der Tab-Leiste)
    await expect.poll(() => api.count('/me')).toBeGreaterThan(0);
    for (const p of ['/news', '/gallery', '/documents', '/directory']) expect(api.count(p), p).toBeGreaterThan(0);
    // Nichts aus der Verwaltung (Mitglied hat keine), keine abgelehnten Anfragen
    expect(api.paths.filter((p) => p.startsWith('/admin'))).toEqual([]);
    expect(api.forbidden).toEqual([]);

    await slowApi(page, /news|gallery|documents/);
    try {
      const tabbar = page.locator('.tabbar');
      await tabbar.getByRole('button', { name: 'News' }).click();
      await expect(page.locator('.news-card').first()).toBeVisible({ timeout: 1500 });
      await tabbar.getByRole('button', { name: 'Galerie' }).click();
      await expect(page.locator('.album-card').first()).toBeVisible({ timeout: 1500 });
      await internMenu(page, 'Dokumente');
      await expect(page.locator('.doc-group, .empty').first()).toBeVisible({ timeout: 1500 });
      await expect(page.locator('.loading, .skeleton')).toHaveCount(0);
    } finally {
      await page.unrouteAll({ behavior: 'ignoreErrors' });
    }
  });

  test('Sitzung endet, während im Hintergrund vorab geladen wird: keine Weiterleitung zur Anmeldung', async ({ page }) => {
    const api = watchApi(page);
    await login(page, 'mitglied1');
    await expect(page.getByText('Vereinsabend')).toBeVisible();
    // Abgemeldet (z. B. Sitzung abgelaufen), bevor die App im Leerlauf vorab lädt: /me antwortet dann mit 401.
    await page.context().clearCookies();
    await expect.poll(() => api.count('/me')).toBeGreaterThan(0);
    await page.waitForTimeout(500);
    await expect(page).not.toHaveURL(/#\/anmelden|wp-login\.php/);
    await expect(page.getByText('Vereinsabend')).toBeVisible();
  });

  test('Antippen: Die Anfrage startet bei pointerdown, vor dem Wechsel der Ansicht; ebenso ein Album', async ({ page }) => {
    // Im Datensparmodus lädt die App im Leerlauf nichts vorab: So zählt hier nur das Antippen.
    await saveData(page);
    const api = watchApi(page);
    await page.context().clearCookies();
    await page.goto('/app/');
    await startPageLoaded(page);
    const news = page.locator('.tabbar').getByRole('button', { name: 'News' });
    await news.dispatchEvent('pointerdown');
    await expect.poll(() => api.count('/news')).toBe(1);
    await expect(page).not.toHaveURL(/#\/news/);
    await news.click();
    await expect(page.locator('.news-card').first()).toBeVisible();
    // Die Ansicht wartet auf die schon laufende Anfrage bzw. nimmt ihre Antwort, statt noch einmal zu fragen.
    expect(api.count('/news')).toBe(1);

    await page.locator('.tabbar').getByRole('button', { name: 'Galerie' }).click();
    const album = page.locator('.album-card', { hasText: 'Sommerfest' });
    await expect(album).toBeVisible();
    const before = api.paths.filter((p) => /^\/gallery\/\d+$/.test(p)).length;
    await album.dispatchEvent('pointerdown');
    await expect.poll(() => api.paths.filter((p) => /^\/gallery\/\d+$/.test(p)).length).toBe(before + 1);
    await album.click();
    await expect(page.locator('.photo')).toHaveCount(2);
    expect(api.paths.filter((p) => /^\/gallery\/\d+$/.test(p)).length).toBe(before + 1);
  });

  test('Datensparmodus: im Leerlauf nichts vorab', async ({ page }) => {
    await saveData(page);
    const api = watchApi(page);
    await page.context().clearCookies();
    await page.goto('/app/');
    await startPageLoaded(page);
    // Ohne Datensparmodus käme die erste Vorab-Anfrage nach etwa einer Sekunde (Test oben).
    await page.waitForTimeout(3000);
    expect(api.count('/news')).toBe(0);
    expect(api.count('/gallery')).toBe(0);
  });

  test('Vorschau „App als Rolle ansehen“: kein Vorabladen, auch nicht beim Antippen', async ({ page }) => {
    await login(page, 'admin', ADMIN_PASSWORD);
    await page.evaluate(() => sessionStorage.setItem('kompanie-preview', '@public'));
    // Nur Anfragen der Vorschau: Die Admin-Ansicht davor lädt im Leerlauf noch vorab.
    const api = watchApi(page, true);
    await page.goto('/app/#/termine');
    await page.reload();
    await expect(page.locator('.preview-banner')).toHaveText(/Vorschau ohne Anmeldung/);
    await startPageLoaded(page);
    expect(api.paths.length).toBeGreaterThan(0); // Die Vorschau lädt ihre Startseite mit dem Header.
    await page.waitForTimeout(3000);
    await page.locator('.tabbar').getByRole('button', { name: 'News' }).dispatchEvent('pointerdown');
    await page.waitForTimeout(500);
    expect(api.count('/news')).toBe(0);
    expect(api.count('/gallery')).toBe(0);
    await page.locator('.preview-banner').getByRole('button', { name: 'Beenden' }).click();
    await expect(page.locator('.preview-banner')).toHaveCount(0);
  });
});

test('Admin stuft ein Mitglied zum Gast herab und zurück', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  if (!STANDALONE) await expect(page.locator('.role-control option', { hasText: 'Admin' })).toHaveCount(0); // WordPress: Admins im Backend
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Gast' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Gast');
  await shot(page, '13-rolle');

  await login(page, 'mitglied2');
  // Als Gast keine eigenen Daten: „Meins“ zeigt nur das Konto, ohne Menü.
  await openKonto(page);
  await expect(page.locator('.meins-menu')).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.getByRole('button', { name: 'Filter und Sortierung' }).click();
  await page.getByLabel('Auch Gast-Konten anzeigen').check();
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Mitglied');
});

test('Standardrolle „Termine verwalten“: Termine pflegen ja, Punkte und Strafen nein', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Termine verwalten' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Termine verwalten');

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Meins' })).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toHaveCount(0);
  await page.getByRole('button', { name: 'Öffentliche Termine verwalten' }).click();
  await expect(page.getByRole('button', { name: '+ Öffentlichen Termin anlegen' })).toBeVisible();
  // Die Rechte auf dem Server prüft das API-Szenario (tests/contract) für beide Varianten.

  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Mitglied' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Mitglied');
});

test('Admin gibt einem Mitglied einzeln ein Recht und nimmt es wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
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
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await expect(page.locator('.member-level')).toContainText('mit Ausnahmen bei den Rechten');
  await memberAccount(page);
  page.once('dialog', (d) => d.accept());
  await rechte.getByRole('button', { name: 'Wie die Rolle' }).click();
  await expect(page.locator('.toast')).toHaveText('Rechte wie die Rolle');
  await expect(rechte.getByLabel('Öffentliche Termine pflegen')).not.toBeChecked();

  await login(page, 'mitglied2');
  await expect(page.getByRole('button', { name: 'Öffentliche Termine verwalten' })).toHaveCount(0);
});

test('Admin legt eine eigene Rolle an, vergibt sie und löscht sie wieder', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
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

  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  await page.locator('.role-control').getByLabel('Grundrolle').selectOption({ label: 'Kassenwart' });
  await expect(page.locator('.toast')).toHaveText('Grundrolle geändert: Kassenwart');

  await login(page, 'mitglied2');
  await expect(page.locator('.tabbar').getByRole('button', { name: MANAGER })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Öffentliche Termine verwalten' })).toHaveCount(0);

  await login(page, 'admin', ADMIN_PASSWORD);
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
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
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
  await openKonto(page);
  await expect(page.locator('.account-card').first()).toContainText('Rollen: Mitglied, Termine verwalten');

  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await memberAccount(page);
  await page.locator('.extra-roles').getByLabel('Termine verwalten').uncheck();
  await expect(page.locator('.toast')).toHaveText('Zusatzrolle entfernt: Termine verwalten');
});

test('Vorschau: Admin sieht die App als Mitglied und ohne Anmeldung, nur zum Ansehen, danach wieder als Admin', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  const openRoles = async () => {
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
  await openKonto(page);
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

  // Ohne Anmeldung: nur Öffentliches, mit „Login“ oben.
  await rollen.getByRole('button', { name: 'App ohne Anmeldung ansehen' }).click();
  await expect(banner).toHaveText(/Vorschau ohne Anmeldung – nur ansehen/);
  await expect(page.locator('.topbar').getByRole('button', { name: 'Login' })).toBeVisible();
  await expect(page.getByText('Tag der offenen Tür')).toBeVisible();
  await expect(page.getByText('Vereinsabend')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Meins' })).toHaveCount(0);
  await banner.getByRole('button', { name: 'Beenden' }).click();
  await expect(rollen).toBeVisible();
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
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

test('Galerie: Video mit ▶ im Album, spielt in der Großansicht aus Drive (Range)', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto('/app/#/galerie');
  const card = page.locator('.album-card', { hasText: 'Umzug' });
  await expect(card).toContainText('1 Bild · 1 Video');
  await card.click();
  await expect(page.locator('.photo')).toHaveCount(2);
  const tile = page.locator('.photo-video');
  await expect(tile).toHaveCount(1);
  await expect(tile.locator('.photo-play')).toBeVisible();
  await expect(tile.locator('.photo-duration')).toHaveText('0:02');
  // Der Browser fragt das Video mit Range ab; die App reicht den Ausschnitt aus Drive durch.
  const videoResponse = page.waitForResponse((r) => /\/foto\/\d+\/video/.test(r.url()));
  await tile.click();
  const video = page.locator('.lightbox video');
  await expect(video).toHaveAttribute('src', /\/foto\/\d+\/video/);
  const res = await videoResponse;
  expect(res.status()).toBe(206);
  expect(res.headers()['content-type']).toBe('video/webm');
  expect(res.headers()['content-range']).toMatch(/^bytes 0-\d+\/\d+$/);
  await expect.poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState)).toBeGreaterThanOrEqual(1);
  expect(await video.evaluate((v: HTMLVideoElement) => Math.round(v.duration))).toBe(2);
  // Blättern zum Bild: das Video verschwindet.
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.lightbox video')).toHaveCount(0);
  await expect(page.locator('.lightbox img')).toBeVisible();
  await page.keyboard.press('Escape');
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
  await openKonto(page);
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
  await page.locator('.topbar').getByRole('button', { name: 'Login' }).click();
  await expect(STANDALONE ? page.getByLabel('Angemeldet bleiben') : page.locator('#rememberme')).toBeChecked();
  await fillLogin(page, 'mitglied1', PASSWORD);
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
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
  let importStep = false;
  while ((await page.getByRole('heading', { name: 'Fast fertig' }).count()) === 0) {
    importStep ||= (await page.getByRole('heading', { name: 'Mitglieder übernehmen' }).count()) > 0;
    await expect(page.getByRole('heading', { name: 'Galerie aus Google Drive' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Weiter', exact: true }).click();
  }
  expect(importStep).toBe(true);
  await page.getByRole('button', { name: 'Einrichtung abschließen' }).click();
  await expect(page).toHaveURL(/#\/verwaltung$/);
  await expect(hint).toHaveCount(0);
  const nav = page.getByRole('navigation', { name: 'Hauptnavigation' });
  await expect(nav.getByRole('button', { name: 'Galerie' })).toHaveCount(0);
  await page.reload();
  await expect(page.locator('.tabbar').getByRole('button', { name: 'Meins' })).toBeVisible();
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
  // Der Server schreibt die Datei Stück für Stück direkt in den Download (eigene Route statt API-Antwort).
  expect(decodeURIComponent(download.url())).toMatch(/sicherung\/?$/);
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
  expect((await cachedUrls()).filter((u) => u.includes('/admin/') || /[/=]sicherung\/?$/.test(u))).toEqual([]);
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
  // Lizenzen (Issue #173): für alle, mit Lizenz der App, Quellcode und den Bausteinen aus dem Build samt Lizenztext
  await page.getByRole('link', { name: 'Lizenzen' }).click();
  await expect(page.locator('.news-headline')).toHaveText('Lizenzen');
  await expect(page.locator('.hilfe-text')).toContainText('GPL-2.0-or-later');
  await expect(page.locator('.hilfe-text a[href="https://github.com/Steuten-IT-Solutions/Kompanie-App-Template"]')).toBeVisible();
  await page.locator('.lizenz-liste summary', { hasText: 'react-dom' }).click();
  await expect(page.locator('.lizenz-liste details[open] .lizenz-text')).toContainText('Permission is hereby granted');
  await expect(page.locator('.lizenz-liste summary', { hasText: /^sharp / })).toHaveCount(0);
  await page.getByRole('button', { name: '‹ Alle Hilfeseiten' }).click();

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
  await expect(item('Mitglied anlegen')).toBeVisible();
  await expect(item('Wartungsmodus')).toHaveCount(0);
  await page.getByLabel('Hilfe durchsuchen').fill('');
  await page.locator('.row-title', { hasText: /^Rollen$/ }).click();
  await page.locator('.hilfe-text a', { hasText: 'Konto und Rolle' }).first().click(); // Verweis auf eine andere Seite
  await expect(page.locator('.news-headline')).toHaveText('Konto und Rolle');
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
    await page.goto('/app/#/hilfe/lizenzen');
    await expect(page.locator('.lizenz-liste summary', { hasText: 'react' }).first()).toBeVisible();
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
  await adminMenu(page, 'Einstellungen');
  await expect(page.locator('.topbar-title')).toHaveText('Einstellungen');
  const open = async (title: string) => {
    await page.goto('/app/#/einstellungen');
    await page
      .locator('.settings-link')
      .filter({ has: page.getByText(title, { exact: true }) })
      .click();
  };
  // Zwischenspeicher-Dauer (Issue #177): 1441 lehnt schon das Feld ab; eine reine Änderung der Minuten wird gespeichert.
  const cacheMinutes = async () => {
    const cal = page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' });
    const field = cal.getByLabel('Aktualisieren alle … Minuten');
    await expect(field).toHaveValue('15');
    await field.fill('1441');
    await cal.getByRole('button', { name: 'Speichern' }).click();
    expect(await field.evaluate((el: HTMLInputElement) => el.validity.rangeOverflow && el.validationMessage !== '')).toBe(true);
    await expect(page.locator('.toast')).toHaveCount(0);
    await field.fill('30');
    await cal.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.locator('.toast')).toHaveText('Gespeichert');
    await page.reload();
    await expect(
      page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' }).getByLabel('Aktualisieren alle … Minuten'),
    ).toHaveValue('30');
    await page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' }).getByLabel('Aktualisieren alle … Minuten').fill('15');
    await page.locator('.settings-section', { hasText: 'Kalender für Mitglieder' }).getByRole('button', { name: 'Speichern' }).click();
    await expect(page.locator('.toast')).toHaveText('Gespeichert');
    await page.locator('.toast').waitFor({ state: 'detached' });
  };
  if (!STANDALONE) {
    // WordPress: Neuigkeiten kommen aus den Beiträgen (keine Seite); Kalender und Galerie sind einstellbar.
    // Die Test-Instanz hat keine Attrappen für Google, deshalb nur Speichern und Prüfen der Eingabe.
    await expect(page.locator('.settings-link').filter({ has: page.getByText('Galerie', { exact: true }) })).toBeVisible();
    await expect(page.locator('.settings-link').filter({ has: page.getByText('Neuigkeiten', { exact: true }) })).toHaveCount(0);
    await open('Kalender');
    await cacheMinutes();
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
  await cacheMinutes();

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
  await expect(gallery.getByText('Fertig: 3 Alben, 6 Bilder und Videos.')).toBeVisible();

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
  // „‹ Einrichtungen“ und „‹ Verwaltung“ führen zurück zur Startseite; mit Punkten gibt es dort auch „Beförderungen“,
  // die Dienstgrade stehen dort nur unter „Einrichtungen“ (vor „Einstellungen“).
  await page.getByRole('button', { name: '‹ Einrichtungen' }).click();
  await expect(page).toHaveURL(/#\/verwaltung\/einrichtungen$/);
  await expect(page.locator('.admin-tiles').getByRole('button', { name: 'Punktekatalog', exact: true })).toBeVisible();
  await page.getByRole('button', { name: '‹ Verwaltung' }).click();
  await expect(page).toHaveURL(/#\/verwaltung$/);
  const tiles = page.locator('.admin-tiles');
  await expect(tiles.getByRole('button', { name: 'Beförderungen', exact: true })).toBeVisible();
  await expect(tiles.getByRole('button', { name: 'Dienstgrade', exact: true })).toHaveCount(0);
  await expect(tiles.locator('li').nth(-2)).toHaveText('Einrichtungen');
  await expect(tiles.locator('li').last()).toHaveText('Einstellungen');
  // Zahl im Kreis an der Kachel „Beförderungen“: wie viele schon zur Beförderung bereit sind (Testdaten).
  const dueNow = () =>
    page.evaluate(async () => {
      const c = window.KOMPANIE_CONFIG!;
      const list = await (
        await fetch(c.apiBase + 'promotions', { credentials: 'same-origin', headers: { [c.csrf.header]: c.csrf.token } })
      ).json();
      return list.filter(
        (p: { next_rank: unknown; points_to_next: number | null }) => p.next_rank && p.points_to_next !== null && p.points_to_next <= 0,
      ).length as number;
    });
  const badge = tiles.getByRole('button', { name: 'Beförderungen', exact: true }).locator('.count-badge');
  const dueBefore = await dueNow();
  if (dueBefore) await expect(badge).toHaveText(String(dueBefore));
  else await expect(badge).toHaveCount(0);

  // Punkte für mitglied2 im Formular vergeben: Hinweis, dass mitglied2 die Schwelle neu erreicht hat; die Zahl an der
  // Kachel steigt um eins.
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  const id = Number(page.url().split('/').pop());
  await page.goto(`/app/#/verwaltung/punkte/${id}`);
  await page.getByLabel('Punkte', { exact: true }).fill('12');
  await page.getByLabel('Grund').fill('E2E Dienstgrad');
  await page.locator('form').getByRole('button', { name: 'Punkte vergeben' }).click();
  await expect(page.locator('.toast')).toContainText('vergeben');
  const notice = page.locator('.notice-card');
  await expect(notice).toContainText('Neue Beförderungsschwelle erreicht');
  await expect(notice).toContainText('Mitglied2: Schwelle für „Gefreiter“ erreicht');
  await page.getByRole('button', { name: '‹ Verwaltung' }).click();
  await expect(notice).toBeVisible();
  await expect(badge).toHaveText(String(dueBefore + 1));
  await shot(page, 'befoerderung-schwelle-erreicht');
  await badge.scrollIntoViewIfNeeded();
  await shot(page, 'befoerderung-kachel');

  // Aus dem Hinweis zu „Beförderungen“, von dort direkt zum Dienstgrad des Mitglieds, der nächste Dienstgrad ist vorgeschlagen.
  await notice.getByRole('button', { name: 'Zu den Beförderungen' }).click();
  await expect(page).toHaveURL(/#\/verwaltung\/befoerderungen$/);
  await expect(notice).toHaveCount(0);
  await page.locator('.promotions .row', { hasText: 'Mitglied2' }).click();
  await expect(page).toHaveURL(new RegExp(`#/verwaltung/mitglied/${id}/dienstgrad$`));
  await expect(page.getByLabel('Neuer Dienstgrad').locator('option:checked')).toHaveText('Gefreiter (ab 10)');
  await page.getByLabel('Neuer Dienstgrad').selectOption({ label: 'Gefreiter (ab 10)' });
  await page.getByLabel('Begründung (optional)').fill('Gute Arbeit');
  await page.getByRole('button', { name: 'Dienstgrad ändern' }).click();
  await expect(page.locator('.toast')).toHaveText('Dienstgrad geändert');
  await expect(page.getByText('Beförderung: kein Dienstgrad → Gefreiter')).toBeVisible();
  await expect(page.locator('.member-rank strong')).toHaveText('Gefreiter');
  // Befördert: an der Kachel wieder einer weniger.
  expect(await dueNow()).toBe(dueBefore);
  // Über einen Link geöffnet: Schließen bleibt auf der Seite des Mitglieds; das Abzeichen öffnet das Fenster wieder.
  await page.getByRole('dialog').getByRole('button', { name: 'Schließen' }).click();
  await expect(page).toHaveURL(new RegExp(`#/verwaltung/mitglied/${id}$`));
  await page.locator('.member-rank-open').click();
  await expect(page.getByRole('dialog', { name: 'Dienstgrad' }).locator('.member-rank strong')).toHaveText('Gefreiter');

  // Unter die Schwelle des eigenen Dienstgrads (Issue #178): eigener Abschnitt ganz oben, ohne Schalter.
  const book = (points: number) =>
    page.evaluate(
      async ([userId, n]) => {
        const c = window.KOMPANIE_CONFIG!;
        const r = await fetch(c.apiBase + 'points', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', [c.csrf.header]: c.csrf.token },
          body: JSON.stringify({ user_ids: [userId], points: n, reason: 'E2E Schwelle' }),
        });
        return r.status;
      },
      [id, points],
    );
  // Auf 9 Punkte, eins unter der Schwelle des Gefreiten (andere Tests haben schon Punkte vergeben)
  const current = await page.evaluate(async (userId) => {
    const c = window.KOMPANIE_CONFIG!;
    const list = await (
      await fetch(c.apiBase + 'promotions', { credentials: 'same-origin', headers: { [c.csrf.header]: c.csrf.token } })
    ).json();
    return (list as { user_id: number; points: number }[]).find((p) => p.user_id === userId)!.points;
  }, id);
  const minus = 9 - current;
  expect(await book(minus)).toBeLessThan(300);
  await page.goto('/app/#/verwaltung/befoerderungen');
  const promotions = page.locator('.promotions');
  await expect(promotions.locator('li').first()).toHaveText('Unter der Schwelle');
  await expect(promotions.locator('li').nth(1)).toContainText('Mitglied2');
  await expect(promotions.locator('li').nth(1)).toHaveClass(/\bbelow\b/);
  await expect(promotions.locator('.row', { hasText: 'Mitglied2' })).toHaveCount(1);
  await expect(page.locator('label.toggle')).toHaveCount(0);
  await shot(page, '24c-befoerderungen-unter-der-schwelle');
  expect(await book(-minus)).toBeLessThan(300);
  await page.reload();
  await expect(promotions.locator('.list-divider', { hasText: 'Unter der Schwelle' })).toHaveCount(0);
  await expect(promotions.locator('.list-divider').first()).toHaveText(/Schwelle erreicht|Noch nicht erreicht/);

  await login(page, 'mitglied2');
  await page.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await meinsMenu(page, 'Dienstgrad');
  await expect(page.locator('.rank-card-title')).toHaveText('Gefreiter');
  await expect(page.locator('.rank-progress')).toContainText('bis „Unteroffizier“');
  await expect(page.getByText('Gute Arbeit')).toBeVisible();
});

test('Punktekatalog und Dienstgrade per Ziehen am Griff umsortieren, Schwellen werden geprüft', async ({ page }) => {
  const id = Date.now().toString(36);
  await login(page, 'admin', ADMIN_PASSWORD);

  /** Griff von „from“ mit der Maus auf die Zeile „onto“ ziehen. */
  const drag = async (from: string, onto: Locator) => {
    const handle = page.getByRole('button', { name: `${from} verschieben (ziehen oder Pfeiltasten)` });
    await handle.scrollIntoViewIfNeeded();
    const a = (await handle.boundingBox())!;
    const b = (await onto.boundingBox())!;
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(a.x + a.width / 2, b.y + 5, { steps: 10 });
    await page.mouse.up();
  };
  const saved = (path: string) => page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes(path));

  // Punktekatalog (Issue #176): ziehen und Pfeiltasten, gilt auch für die Vorlagen beim Vergeben
  const items = ['A', 'B', 'C'].map((n) => `Vorlage ${n} ${id}`);
  await adminMenu(page, 'Punktekatalog');
  for (const title of items) {
    // Das Formular leert sich erst nach der Meldung
    await expect(page.getByPlaceholder('z. B. Teilnahme am Sommerfest')).toHaveValue('');
    await page.getByPlaceholder('z. B. Teilnahme am Sommerfest').fill(title);
    await page.locator('.field.narrow input').fill('2');
    await page.getByRole('button', { name: 'Hinzufügen' }).click();
    await expect(page.locator('.toast')).toHaveText('Vorlage angelegt');
  }
  const catalogOrder = async () => (await page.locator('.card-list .row-title').allTextContents()).filter((t) => t.includes(id));
  await expect.poll(catalogOrder).toEqual(items); // neue Einträge ans Ende
  let done = saved('catalog');
  await drag(items[2], page.locator('.card-list .row', { hasText: items[0] }));
  expect((await done).status()).toBe(200);
  expect(await catalogOrder()).toEqual([items[2], items[0], items[1]]);
  done = saved('catalog');
  await page.getByRole('button', { name: `${items[1]} verschieben (ziehen oder Pfeiltasten)` }).press('ArrowUp');
  expect((await done).status()).toBe(200);
  await page.reload();
  await expect.poll(catalogOrder).toEqual([items[2], items[1], items[0]]);
  await adminMenu(page, 'Punkte +');
  const options = async () =>
    (await page.getByLabel('Vorlage aus dem Katalog').locator('option').allTextContents()).filter((t) => t.includes(id));
  await expect.poll(options).toEqual([items[2], items[1], items[0]].map((t) => `${t} (+2)`));
  // Aufräumen: deaktivieren (Katalogeinträge lassen sich nicht löschen)
  await adminMenu(page, 'Punktekatalog');
  for (const title of items) {
    await page.locator('.card-list .row', { hasText: title }).getByRole('button', { name: 'Deaktivieren' }).click();
    await expect(page.locator('.toast')).toHaveText('Deaktiviert');
  }

  // Dienstgrade: keine Knöpfe „niedriger“/„höher“ mehr; ein Zug gegen die Schwellen springt mit Meldung zurück
  const ranks = [`Rang A ${id}`, `Rang B ${id}`, `Rang C ${id}`];
  await adminMenu(page, 'Dienstgrade');
  for (const [title, min] of [
    [ranks[0], '5000'],
    [ranks[1], '6000'],
    [ranks[2], ''],
  ]) {
    await expect(page.getByLabel('Bezeichnung')).toHaveValue('');
    await page.getByLabel('Bezeichnung').fill(title);
    await page.getByLabel('Punkteschwelle (optional)').fill(min);
    await page.getByRole('button', { name: 'Anlegen' }).click();
    await expect(page.locator('.row', { hasText: title })).toBeVisible();
  }
  await expect(page.getByRole('button', { name: /niedriger|höher/ })).toHaveCount(0);
  const rankOrder = async () => (await page.locator('.card-list .row-title').allTextContents()).filter((t) => t.includes(id));
  await expect.poll(rankOrder).toEqual(ranks);
  // C (ohne Schwelle) per Pfeiltaste vor B: erlaubt
  done = saved('ranks');
  await page.getByRole('button', { name: `${ranks[2]} verschieben (ziehen oder Pfeiltasten)` }).press('ArrowUp');
  expect((await done).status()).toBe(200);
  await expect.poll(rankOrder).toEqual([ranks[0], ranks[2], ranks[1]]);
  // B (6000) vor A (5000): Die Schwellen fallen, der Server lehnt ab, B springt zurück
  done = saved('ranks');
  await drag(ranks[1], page.locator('.card-list .row', { hasText: ranks[0] }));
  expect((await done).status()).toBe(400);
  await expect(page.locator('.form-error')).toBeVisible();
  await expect.poll(rankOrder).toEqual([ranks[0], ranks[2], ranks[1]]);
  await page.reload();
  await expect.poll(rankOrder).toEqual([ranks[0], ranks[2], ranks[1]]);
  // Während ein Dienstgrad bearbeitet wird, ist Ziehen gesperrt
  await page.locator('.card-list .row', { hasText: ranks[0] }).getByRole('button', { name: 'Ändern' }).click();
  await page.getByRole('button', { name: `${ranks[1]} verschieben (ziehen oder Pfeiltasten)` }).press('ArrowUp');
  await page.getByRole('button', { name: 'Abbrechen' }).click();
  await expect.poll(rankOrder).toEqual([ranks[0], ranks[2], ranks[1]]);
  // Aufräumen
  for (const title of ranks) {
    page.once('dialog', (d) => void d.accept());
    await page.locator('.card-list .row', { hasText: title }).getByRole('button', { name: 'Löschen' }).click();
    await expect(page.locator('.toast')).toHaveText('Gelöscht');
  }
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
  await internMenu(page, 'Dokumente');
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
  await internMenu(page, 'Dokumente');
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
  await internMenu(page, 'Dokumente');
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
  await internMenu(page, 'Dokumente');
  await page.getByRole('button', { name: 'Dokumente verwalten' }).click();
  await page.getByLabel('Datei').setInputFiles({ name: 'lang.pdf', mimeType: 'application/pdf', buffer: pdf });
  await page.getByLabel('Titel').fill(`Langes Dokument ${id}`);
  await page.getByRole('button', { name: 'Hochladen' }).click();
  await expect(page.locator('.toast')).toHaveText(`„Langes Dokument ${id}“ hochgeladen`);
  await page.getByRole('button', { name: '‹ Alle Dokumente' }).click();

  const doc = page.locator('.doc-card', { hasText: `Langes Dokument ${id}` });
  await expect(doc).toBeAttached();
  const toggle = page.locator('.doc-group', { has: doc }).locator('.fold-toggle');
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
  await adminMenu(page, 'Titel');
  await page.getByLabel('Bezeichnung').fill(title);
  await page.getByRole('button', { name: 'Anlegen' }).click();
  await expect(page.locator('.title-list .row', { hasText: title })).toContainText('nicht vergeben');

  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page.locator('.member-titles-tile').click();
  await page.locator('.member-titles').getByLabel(title).check();
  await page.getByRole('button', { name: 'Titel speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Titel gespeichert');
  await page.getByRole('dialog').getByRole('button', { name: 'Schließen' }).click();
  await expect(page.locator('.member-titles-tile .badge-title')).toContainText(title);
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

/** Karte einer Nachricht über ihre Kennung (beim Bearbeiten steht der Titel in einem Feld, nicht mehr als Text). */
async function boardCard(page: Page, title: string): Promise<Locator> {
  const id = await page.locator('.board-card', { hasText: title }).first().getAttribute('data-focus-id');
  return page.locator(`.board-card[data-focus-id="${id}"]`);
}

/** Neue Nachricht am Brett schreiben (Titel, Text im Editor) und veröffentlichen; der Bestätigungsdialog wird angenommen. */
async function postBoard(page: Page, title: string, text: string) {
  const form = page.locator('.board-form');
  await form.getByLabel('Titel').fill(title);
  await form.getByLabel('Text der Nachricht').fill(text);
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(page.locator('.board-card', { hasText: title })).toBeVisible();
}

test('Schwarzes Brett: Banner mit Titel auf allen Seiten, ausblenden, „+1 weitere“, Änderung zeigt es wieder, abgelaufen weg', async ({
  page,
  browser,
}) => {
  const id = Date.now().toString(36);
  const first = `Heute kein Training ${id}`;
  const second = `Treffpunkt Schützenhaus ${id}`;
  // Admin schreibt (Recht post_board) über „Verwaltung“ → „Schwarzes Brett“; die Verwaltung bleibt markiert.
  const admin = await browser.newPage();
  const dialogs: string[] = [];
  admin.on('dialog', (d) => {
    dialogs.push(d.message());
    void d.accept();
  });
  await login(admin, 'admin', ADMIN_PASSWORD);
  await openKonto(admin);
  await expect(admin.getByRole('button', { name: 'Nachricht schreiben' })).toHaveCount(0);
  await adminMenu(admin, 'Schwarzes Brett');
  await expect(admin.locator('.tabbar button.active')).toHaveText(MANAGER);
  await expect(admin.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  const form = admin.locator('.board-form');
  await form.getByLabel('Titel').fill(first);
  // Formatierter Text: „Halle gesperrt.“ fett über die Werkzeugleiste
  const editor = form.getByLabel('Text der Nachricht');
  await editor.click();
  await admin.keyboard.type('Die ');
  await form.getByRole('button', { name: 'Fett' }).click();
  await admin.keyboard.type('Halle ist gesperrt.');
  await expect(form.getByRole('button', { name: 'Fett' })).toHaveAttribute('aria-pressed', 'true');
  await expect(form).toContainText('sofort eine Benachrichtigung mit dem Titel');
  // Sichtbar bis: vorbelegt mit jetzt + 24 Stunden (Ortszeit)
  await expect(form.getByLabel('Sichtbar bis')).toHaveValue(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
  // Haken „Benachrichtigung senden“: standardmäßig an, abwählbar; „E-Mail senden“ ohne eingerichteten Mailversand aus
  await expect(form.getByLabel('Benachrichtigung senden')).toBeChecked();
  await form.getByLabel('Benachrichtigung senden').uncheck();
  await expect(form).toContainText('Ohne Benachrichtigung');
  await form.getByLabel('Benachrichtigung senden').check();
  await expect(form.getByLabel(/E-Mail senden/)).toBeDisabled();
  await expect(form).toContainText('Einstellungen → E-Mail');
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(admin.locator('.toast')).toHaveText('Nachricht veröffentlicht');
  // Die Bestätigung nennt Titel, Ende und Versandwege.
  expect(dialogs.at(-1)).toContain(`„${first}“`);
  expect(dialogs.at(-1)).toMatch(/Sichtbar bis: .+ Uhr/);
  expect(dialogs.at(-1)).toContain('E-Mail: keine');
  const card = admin.locator('.board-card', { hasText: first });
  await expect(card.locator('.board-text strong, .board-text b')).toHaveText('Halle ist gesperrt.');

  // Mitglied: Banner oben auf jeder Seite, nur mit dem Titel.
  await login(page, 'mitglied1');
  const banner = page.locator('.board-banner');
  await expect(banner).toContainText(first);
  await expect(banner).not.toContainText('Halle ist gesperrt.');
  await expect(banner).toContainText(/admin ·/i);
  const tabs = page.locator('.tabbar button');
  for (let i = 0; i < (await tabs.count()); i++) {
    await tabs.nth(i).click();
    await expect(banner).toContainText(first);
  }
  await openKonto(page);
  await expect(banner).toContainText(first);
  await expect(page.getByRole('button', { name: 'Nachricht schreiben' })).toHaveCount(0);
  await shot(page, '33-schwarzes-brett');

  // Zweite Nachricht: die neueste oben, „+1 weitere“ klappt die erste auf.
  await postBoard(admin, second, 'Wir treffen uns am Schützenhaus.');
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
  // Ein Klick auf den Titel öffnet die vollständige Nachricht am Brett; dort bleibt die ausgeblendete sichtbar.
  await banner.getByRole('button', { name: second }).click();
  await expect(page.locator('.topbar-title')).toHaveText('Schwarzes Brett');
  await expect(page.locator('.board-card', { hasText: second })).toContainText('Wir treffen uns am Schützenhaus.');
  await expect(page.locator('.board-card', { hasText: first })).toContainText('Halle ist gesperrt.');
  await expect(page.locator('.board-form')).toHaveCount(0);
  // Mitglieder sehen weder Verlauf noch, wer geändert hat.
  await expect(page.getByRole('button', { name: 'Verlauf' })).toHaveCount(0);

  // Nur das Enddatum geändert: ausgeblendet bleibt ausgeblendet.
  const firstCard = await boardCard(admin, first);
  await firstCard.getByRole('button', { name: 'Bearbeiten' }).click();
  const until = await firstCard.getByLabel('Sichtbar bis').inputValue();
  const later = new Date(new Date(until).getTime() + 2 * 3600 * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  await firstCard
    .getByLabel('Sichtbar bis')
    .fill(
      `${later.getFullYear()}-${pad(later.getMonth() + 1)}-${pad(later.getDate())}T${pad(later.getHours())}:${pad(later.getMinutes())}`,
    );
  await firstCard.getByRole('button', { name: 'Speichern' }).click();
  await expect(admin.locator('.toast')).toHaveText('Nachricht geändert');
  await expect(admin.locator('.board-card', { hasText: first }).locator('.board-last-edit')).toContainText(/Zuletzt geändert von admin/i);
  await page.goto('/app/#/termine');
  await page.reload();
  await expect(banner).toContainText(second);
  await expect(banner.getByRole('button', { name: /weitere/ })).toHaveCount(0);

  // Titel geändert: Die ausgeblendete Nachricht erscheint wieder (ohne neue Benachrichtigung).
  const card2 = await boardCard(admin, first);
  await card2.getByRole('button', { name: 'Bearbeiten' }).click();
  await card2.getByLabel('Titel').fill(`${first} – fällt aus`);
  await card2.getByRole('button', { name: 'Speichern' }).click();
  await expect(admin.locator('.toast')).toHaveText('Nachricht geändert');
  // Nach dem Neuladen kommt zuerst der gespeicherte Stand (Issue #108), die geänderte Fassung folgt, sobald das Netz
  // antwortet: Dann ist die ausgeblendete Nachricht wieder da („+1 weitere“).
  await page.goto('/app/#/termine');
  await page.reload();
  await expect(banner.getByRole('button', { name: '+1 weitere' })).toBeVisible();
  await expect(banner.locator('.board-entry')).toHaveCount(1);
  await banner.getByRole('button', { name: '+1 weitere' }).click();
  await expect(banner).toContainText(`${first} – fällt aus`);

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

  // Nach dem Ende blendet die App sie selbst aus (Uhr des Browsers vorgestellt).
  await page.clock.install({ time: new Date(Date.now() + 27 * 3600 * 1000) });
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

test('Schwarzes Brett: Verlauf mit vorher/nachher, gelöschte Nachrichten mit Konto und Zeitpunkt', async ({ page }) => {
  page.on('dialog', (d) => d.accept());
  const title = `Verlauf ${Date.now().toString(36)}`;
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/brett');
  await postBoard(page, title, 'Erste Fassung');
  const card = await boardCard(page, title);
  await card.getByRole('button', { name: 'Bearbeiten' }).click();
  await card.getByLabel('Text der Nachricht').fill('Zweite Fassung');
  await card.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Nachricht geändert');
  const edited = page.locator('.board-card', { hasText: title });
  // Nach dem Speichern lädt die App das Brett neu und baut die Karte neu auf (neues updated_at): erst danach klicken.
  await expect(edited.locator('.board-text')).toContainText('Zweite Fassung');
  await edited.getByRole('button', { name: 'Verlauf' }).click();
  const history = edited.locator('.board-history');
  await expect(history.locator('.board-revision')).toHaveCount(2);
  await expect(history.locator('.board-revision').nth(0)).toContainText(/admin: erstellt/i);
  await expect(history.locator('.board-revision').nth(1)).toContainText(/admin: Inhalt geändert/i);
  await expect(history.locator('.board-before')).toContainText('Erste Fassung');
  await expect(history.locator('.board-after')).toContainText('Zweite Fassung');
  await shot(page, '33b-brett-verlauf');

  await edited.getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.board-card', { hasText: title })).toHaveCount(0);
  await page.getByRole('button', { name: /Verlauf: abgelaufene und gelöschte Nachrichten/ }).click();
  const ended = page.locator('.board-ended-card', { hasText: title });
  await expect(ended).toContainText(/gelöscht von admin/i);
  await ended.getByRole('button', { name: 'Verlauf' }).click();
  await expect(ended.locator('.board-revision').last()).toContainText(/admin: gelöscht/i);
});

test('Schwarzes Brett: Signatur einrichten (Sprung ins Konto, Entwurf bleibt), einfügen, Änderung wirkt nicht auf die Nachricht', async ({
  page,
}) => {
  page.on('dialog', (d) => d.accept());
  const id = Date.now().toString(36);
  await login(page, 'admin', ADMIN_PASSWORD);
  // Ausgangslage: keine Signatur
  await openKonto(page);
  const signatureCard = page.locator('.signature-card');
  if (await signatureCard.getByRole('button', { name: 'Löschen' }).count()) {
    await signatureCard.getByRole('button', { name: 'Löschen' }).click();
    await expect(signatureCard.getByRole('button', { name: 'Signatur einrichten' })).toBeVisible();
  }
  await page.goto('/app/#/brett');
  const form = page.locator('.board-form');
  await form.getByLabel('Titel').fill(`Mit Signatur ${id}`);
  await form.getByLabel('Text der Nachricht').fill('Bitte pünktlich sein.');
  // Ohne Signatur führt der Knopf direkt zur Einrichtung im Konto.
  await form.getByRole('button', { name: 'Noch keine Signatur – jetzt einrichten' }).click();
  await expect(page.locator('.signature-card')).toBeVisible();
  await page.locator('.signature-card').getByLabel('Signatur').fill(`Gruß, der Vorstand ${id}`);
  // Bild in der Signatur: hochgeladen über den Bildspeicher der App, im Editor gleich sichtbar
  const uploaded = page.waitForResponse((r) => r.request().method() === 'POST' && decodeURIComponent(r.url()).includes('/rich-images'));
  await page.locator('.signature-card input[type=file]').setInputFiles(PNG);
  expect((await uploaded).status()).toBe(201);
  await expect(page.locator('.signature-card .rich-editor-content img[data-image]')).toHaveCount(1);
  await page.locator('.signature-card').getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Signatur gespeichert');
  await expect(page.locator('.signature-preview')).toContainText(`Gruß, der Vorstand ${id}`);
  const previewImage = page.locator('.signature-preview img');
  await expect(previewImage).toHaveCount(1);
  await expect.poll(() => previewImage.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  const signatureImage = await previewImage.getAttribute('data-image');
  await shot(page, '33c-signatur');
  // Zurück zum Brett: Der Entwurf ist noch da.
  await page
    .locator('.signature-card')
    .getByRole('button', { name: /Zurück zur Nachricht/ })
    .click();
  await expect(form.getByLabel('Titel')).toHaveValue(`Mit Signatur ${id}`);
  await expect(form.getByLabel('Text der Nachricht')).toContainText('Bitte pünktlich sein.');
  await form.getByRole('button', { name: 'Signatur einfügen' }).click();
  await expect(form.getByLabel('Text der Nachricht')).toContainText(`Gruß, der Vorstand ${id}`);
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  const card = page.locator('.board-card', { hasText: `Mit Signatur ${id}` });
  await expect(card).toContainText(`Gruß, der Vorstand ${id}`);
  // Das Bild der Signatur wurde für die Nachricht kopiert (eigene Kennung) und lädt.
  const messageImage = card.locator('.board-text img');
  await expect.poll(() => messageImage.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
  expect(await messageImage.getAttribute('data-image')).not.toBe(signatureImage);

  // Signatur ändern: die Nachricht bleibt, wie sie ist.
  await openKonto(page);
  await page.locator('.signature-card').getByRole('button', { name: 'Ändern' }).click();
  await page.locator('.signature-card').getByLabel('Signatur').fill('Ganz andere Signatur');
  await page.locator('.signature-card').getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.signature-preview')).toContainText('Ganz andere Signatur');
  await page.goto('/app/#/brett');
  await expect(page.locator('.board-card', { hasText: `Mit Signatur ${id}` })).toContainText(`Gruß, der Vorstand ${id}`);
  await page
    .locator('.board-card', { hasText: `Mit Signatur ${id}` })
    .getByRole('button', { name: 'Löschen' })
    .click();
});

test('Einstellungen → E-Mail: Kompanieadresse und Mailserver speichern, Passwort nie angezeigt', async ({ page }) => {
  await login(page, 'admin', ADMIN_PASSWORD);
  await page.goto('/app/#/einstellungen');
  await page.getByRole('button', { name: /^E-Mail/ }).click();
  const section = page.locator('#einstellungen-email');
  await section.getByLabel('Kompanieadresse (Absender)').fill('info@musterkompanie.example');
  await section.getByLabel('Mailserver (SMTP)').fill('smtp.musterkompanie.example');
  await section.getByRole('spinbutton', { name: 'Port', exact: true }).fill('587');
  await section.getByLabel(/^Passwort/).fill('geheimes-smtp-passwort');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
  await expect(section.getByLabel(/^Passwort/)).toHaveValue('');
  await expect(section).toContainText('gespeichert – leer lassen, um es zu behalten');
  await expect(section.getByRole('button', { name: 'Verbindung prüfen' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText('geheimes-smtp-passwort');
  await shot(page, '33d-einstellungen-email');
  // Aufräumen: Mailversand wieder aus (andere Tests erwarten den Haken „E-Mail senden“ deaktiviert)
  await section.getByLabel('Mailserver (SMTP)').fill('');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
});

test('E-Mails: an Rolle, Mitglied und freie Adressen (An, Cc, Bcc) mit dem Editor des Bretts schreiben, Bestätigung, Verlauf', async ({
  page,
}) => {
  const id = Date.now().toString(36);
  const dialogs: string[] = [];
  page.on('dialog', (d) => {
    dialogs.push(d.message());
    void d.accept();
  });
  await login(page, 'admin', ADMIN_PASSWORD);
  // Ohne Mailversand gibt es den Bereich noch nicht (wie Kalender oder Galerie ohne Einrichtung)
  await page.locator('.tabbar').getByRole('button', { name: MANAGER }).click();
  await expect(page.locator('.admin-tiles').getByRole('button', { name: 'Listen', exact: true })).toBeVisible();
  await expect(page.locator('.admin-tiles').getByRole('button', { name: 'E-Mails', exact: true })).toHaveCount(0);
  // Mailversand einrichten (Server nicht erreichbar: Die Mails bleiben im Hintergrund ausstehend)
  await page.goto('/app/#/einstellungen/email');
  const section = page.locator('#einstellungen-email');
  await section.getByLabel('Kompanieadresse (Absender)').fill('info@musterkompanie.example');
  await section.getByLabel('Mailserver (SMTP)').fill('127.0.0.1');
  await section.getByRole('spinbutton', { name: 'Port', exact: true }).fill('9');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');

  await page.reload();
  await adminMenu(page, 'E-Mails');
  await expect(page.locator('.topbar-title')).toHaveText('Verwaltung');
  const form = page.locator('.mail-form');
  await expect(form.locator('.mail-from')).toContainText('info@musterkompanie.example');
  // An: Rolle per Vorschlag, Mitglied ohne Adresse (markiert)
  const to = form.locator('.mail-to-input');
  await to.fill('Adm');
  await form
    .locator('.mail-suggestions')
    .getByRole('button', { name: /Admin.*Rolle/ })
    .click();
  await to.fill('Mitglied1');
  await to.press('Enter');
  // Testaufbau: In der eigenständigen Version hat Mitglied1 keine Mailadresse (markiert), in WordPress schon.
  if (STANDALONE) await expect(form.locator('.mail-chip-missing')).toContainText('ohne Adresse');
  else await expect(form.locator('.mail-chip', { hasText: 'Mitglied1' })).not.toHaveClass(/mail-chip-missing/);
  // Cc und Bcc: freie Adressen (eine ungültige bleibt mit Hinweis stehen)
  await form.getByRole('button', { name: 'Cc und Bcc hinzufügen' }).click();
  await form.locator('.mail-cc-input').fill('extern@example.net');
  await form.locator('.mail-cc-input').press('Enter');
  await form.locator('.mail-bcc-input').fill('keine-adresse@');
  await form.locator('.mail-bcc-input').press('Enter');
  await expect(form).toContainText('Keine gültige Adresse: keine-adresse@');
  await form.locator('.mail-bcc-input').fill(`geheim-${id}@example.net`);
  await form.locator('.mail-bcc-input').press('Enter');
  await expect(form.locator('.mail-chip-email')).toHaveCount(2);
  await form.getByLabel('Betreff').fill(`Einladung ${id}`);
  // Derselbe Editor wie am Brett: Werkzeugleiste und „Signatur einfügen“
  const editor = form.getByLabel('Text der E-Mail');
  await editor.click();
  await page.keyboard.type('Liebe ');
  await form.getByRole('button', { name: 'Fett' }).click();
  await page.keyboard.type('Kameraden');
  await expect(form.getByRole('button', { name: /Signatur/ })).toBeVisible();
  await shot(page, '33e-emails-schreiben');
  // Entwurf bleibt beim Neuladen
  await page.reload();
  await expect(page.locator('.mail-form').getByLabel('Betreff')).toHaveValue(`Einladung ${id}`);
  await page.locator('.mail-form').getByRole('button', { name: 'Senden' }).click();
  await expect(page.locator('.toast')).toHaveText('E-Mail wird versendet');
  expect(dialogs.at(-1)).toContain(`„Einladung ${id}“ jetzt an ${STANDALONE ? 3 : 4} Empfänger senden?`);
  expect(dialogs.at(-1)).toContain(`An: ${STANDALONE ? 1 : 2} · Cc: 1 · Bcc: 1`);
  if (STANDALONE) expect(dialogs.at(-1)).toContain('Ohne Mailadresse (bekommen nichts): Mitglied1');
  else expect(dialogs.at(-1)).not.toContain('Ohne Mailadresse');
  await expect(page.locator('.mail-form').getByLabel('Betreff')).toHaveValue('');
  // Verlauf und Versand je Empfänger
  await page.locator('.mail-history-item', { hasText: `Einladung ${id}` }).click();
  const detail = page.locator('.mail-detail');
  await expect(detail.locator('.board-text strong, .board-text b')).toHaveText('Kameraden');
  await expect(detail.locator('.mail-selection')).toContainText('Admin (Rolle), Mitglied1');
  await expect(detail.locator('.mail-selection')).toContainText(`geheim-${id}@example.net`);
  await detail.getByText('Versand je Empfänger').click();
  await expect(detail.locator('.board-mail-log li')).toHaveCount(STANDALONE ? 3 : 4);
  await shot(page, '33f-email-verlauf');
  await page.getByRole('button', { name: '‹ E-Mails' }).click();
  await expect(page.locator('.mail-form')).toBeVisible();

  // Aufräumen: Mailversand wieder aus (andere Tests erwarten den Haken „E-Mail senden“ deaktiviert)
  await page.goto('/app/#/einstellungen/email');
  await section.getByLabel('Mailserver (SMTP)').fill('');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
});

test('Anhänge: am Brett anhängen, Mitglied lädt herunter, beim Bearbeiten ändern (Verlauf); an einer E-Mail', async ({ page, browser }) => {
  const id = Date.now().toString(36);
  page.on('dialog', (d) => void d.accept());
  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Schwarzes Brett');
  const form = page.locator('.board-form');
  await form.getByLabel('Titel').fill(`Mit Anhang ${id}`);
  await form.getByLabel('Text der Nachricht').click();
  await page.keyboard.type('Siehe Anhang.');
  await form.locator('.attachments-field input[type=file]').setInputFiles([
    { name: `Einladung ${id}.pdf`, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 Einladung') },
    { name: 'Werkzeug.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('MZ') },
  ]);
  await expect(form.locator('.attachment-list-edit li')).toHaveCount(2);
  await form.getByRole('button', { name: 'Werkzeug.exe entfernen' }).click();
  await expect(form.locator('.attachment-list-edit li')).toHaveCount(1);
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  await expect(page.locator('.toast')).toHaveText('Nachricht veröffentlicht');
  const card = page.locator('.board-card', { hasText: `Mit Anhang ${id}` });
  await expect(card.locator('.attachment-link')).toHaveText(new RegExp(`Einladung ${id}\\.pdf`));
  await shot(page, '33g-brett-anhang');

  // Mitglied sieht den Anhang und lädt ihn herunter
  const member = await browser.newPage();
  await login(member, 'mitglied1');
  await member.locator('.board-banner').getByText(`Mit Anhang ${id}`).click();
  const memberCard = member.locator('.board-card', { hasText: `Mit Anhang ${id}` });
  const [download] = await Promise.all([member.waitForEvent('download'), memberCard.locator('.attachment-link').click()]);
  expect(download.suggestedFilename()).toBe(`Einladung ${id}.pdf`);
  await member.close();

  // Bearbeiten: zweiten Anhang dazu, ersten entfernen; im Verlauf (im Bearbeiten steht der Titel in einem Feld)
  const editCard = page.locator(`.board-card[data-focus-id="${await card.getAttribute('data-focus-id')}"]`);
  await editCard.getByRole('button', { name: 'Bearbeiten' }).click();
  await editCard
    .locator('.attachments-field input[type=file]')
    .setInputFiles({ name: 'Plan.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('PK') });
  await expect(editCard.locator('.attachment-list-edit li')).toHaveCount(2);
  await editCard.getByRole('button', { name: `Einladung ${id}.pdf entfernen` }).click();
  await editCard.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Nachricht geändert');
  await expect(editCard.locator('.attachment-link')).toHaveText(/Plan\.xlsx/);
  await editCard.getByRole('button', { name: 'Verlauf' }).click();
  await expect(editCard.locator('.board-revision', { hasText: 'Anhänge geändert' })).toContainText(`Einladung ${id}.pdf`);
  await editCard.getByRole('button', { name: 'Löschen' }).click();
  await expect(page.locator('.toast')).toHaveText('Nachricht gelöscht');

  // E-Mail mit Anhang (Mailversand eingerichtet, Server nicht erreichbar)
  await page.goto('/app/#/einstellungen/email');
  const section = page.locator('#einstellungen-email');
  await section.getByLabel('Kompanieadresse (Absender)').fill('info@musterkompanie.example');
  await section.getByLabel('Mailserver (SMTP)').fill('127.0.0.1');
  await section.getByRole('spinbutton', { name: 'Port', exact: true }).fill('9');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
  await page.reload();
  await adminMenu(page, 'E-Mails');
  const mail = page.locator('.mail-form');
  await mail.locator('.mail-to-input').fill(`anhang-${id}@example.net`);
  await mail.locator('.mail-to-input').press('Enter');
  await mail.getByLabel('Betreff').fill(`Satzung ${id}`);
  await mail.getByLabel('Text der E-Mail').click();
  await page.keyboard.type('Anbei die Satzung.');
  await mail
    .locator('.attachments-field input[type=file]')
    .setInputFiles({ name: 'Satzung.docx', mimeType: 'application/octet-stream', buffer: Buffer.from('PK') });
  await expect(mail.locator('.attachment-list-edit li')).toHaveCount(1);
  await mail.getByRole('button', { name: 'Senden' }).click();
  await expect(page.locator('.toast')).toHaveText('E-Mail wird versendet');
  await page.locator('.mail-history-item', { hasText: `Satzung ${id}` }).click();
  await expect(page.locator('.mail-detail .attachment-link')).toHaveText(/Satzung\.docx/);

  await page.goto('/app/#/einstellungen/email');
  await section.getByLabel('Mailserver (SMTP)').fill('');
  await section.getByRole('button', { name: 'Speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Gespeichert');
});

test('Schwarzes Brett: Enddatum in der Ortszeit eines Geräts in New York, gespeichert in UTC, in Berlin richtig angezeigt', async ({
  browser,
  page,
}) => {
  const context = await browser.newContext({ ...devices['iPhone 13'], locale: 'de-DE', timezoneId: 'America/New_York' });
  const ny = await context.newPage();
  ny.on('dialog', (d) => d.accept());
  const title = `Zeitzone ${Date.now().toString(36)}`;
  await login(ny, 'admin', ADMIN_PASSWORD);
  await ny.goto('/app/#/brett');
  const form = ny.locator('.board-form');
  await form.getByLabel('Titel').fill(title);
  await form.getByLabel('Text der Nachricht').fill('Gilt bis Mitte Januar.');
  // 15. Januar 2031, 10:00 Uhr in New York (EST, UTC−5) = 15:00 UTC = 16:00 Uhr in Berlin (MEZ)
  await form.getByLabel('Sichtbar bis').fill('2031-01-15T10:00');
  const posted = ny.waitForResponse((r) => r.request().method() === 'POST' && decodeURIComponent(r.url()).includes('/board'));
  await form.getByRole('button', { name: 'Veröffentlichen' }).click();
  const body = (await (await posted).json()) as { expires_at: string; id: number };
  expect(body.expires_at).toBe('2031-01-15T15:00:00+00:00');
  await expect(ny.locator('.board-card', { hasText: title }).locator('.board-until')).toContainText('15. Jan. 2031, 10:00 Uhr');

  await login(page, 'admin', ADMIN_PASSWORD);
  page.on('dialog', (d) => d.accept());
  await page.goto('/app/#/brett');
  const card = page.locator('.board-card', { hasText: title });
  await expect(card.locator('.board-until')).toContainText('15. Jan. 2031, 16:00 Uhr');
  await card.getByRole('button', { name: 'Löschen' }).click();
  await context.close();
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
  await openKonto(member);
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
  await adminMenu(page, 'Mitglieder');
  await page.locator('.row', { hasText: 'Mitglied2' }).click();
  await page
    .locator('.member-dates')
    .getByRole('button', { name: /^Eintritt/ })
    .click();
  const adminCard = page.locator('.member-join-year');
  await adminCard.getByLabel('Datum oder Jahr').fill('1.4.2005');
  await adminCard.getByRole('button', { name: 'Eintritt speichern' }).click();
  await expect(page.locator('.toast')).toHaveText('Eintritt gespeichert');
  await expect(adminCard).toContainText('Eingetreten am 01.04.2005');
  // Neben der Beschriftung „Eintritt“ nur das Datum, ohne „am“ (Issue #174)
  await expect(page.locator('.member-dates .member-date', { hasText: 'Eintritt' }).locator('.member-date-value')).toHaveText('01.04.2005');
  await expect(adminCard.getByLabel('Datum oder Jahr')).toHaveValue('01.04.2005');
  await expect(adminCard).toContainText('Mitgliedern anzeigen (wählt nur die Person selbst)');
  await expect(adminCard.getByLabel('Auf meiner Seite', { exact: false })).toHaveCount(0);

  // Mitglied2 zeigt den Geburtstag allen (mit Alter) und das Eintrittsjahr nur Mitgliedern.
  const member = await browser.newPage();
  member.on('dialog', (d) => d.accept());
  await login(member, 'mitglied2');
  await openKonto(member);
  const birthday = member.locator('.birthday-card');
  await birthday.getByLabel('Tag').selectOption('12');
  await birthday.getByLabel('Monat').selectOption('3');
  await birthday.getByLabel('Jahr', { exact: true }).fill('1988');
  await birthday.getByLabel(/Alter anzeigen/).check();
  await expect(birthday.getByLabel(/Auf meiner Seite/)).toHaveValue('members');
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
  await member.locator('.tabbar').getByRole('button', { name: 'Meins' }).click();
  await expect(member.locator('.member-dates .member-date', { hasText: 'Eintritt' }).locator('.member-date-value')).toHaveText(
    '01.04.2005',
  );
  await openKonto(member);

  // Ein anderes Mitglied sieht beides auf der Seite.
  const today = new Date();
  const age = today.getFullYear() - 1988 - (today.getMonth() < 2 || (today.getMonth() === 2 && today.getDate() < 12) ? 1 : 0);
  await login(page, 'mitglied1');
  await page.goto('/app/#/mitglieder/liste');
  await page.locator('.member-row', { hasText: 'Mitglied2' }).locator('.member-row-open').click();
  const facts = page.locator('.member-page .member-page-facts');
  await expect(facts).toHaveText(
    new RegExp(`^Geburtstag 12\\.03\\.1988 \\((${age - 1}|${age}|${age + 1}) Jahre\\) · Eintritt 01\\.04\\.2005$`),
  );
  await shot(page, '1d-mitglied-seite');

  // Ohne Anmeldung nur der Geburtstag.
  await page.context().clearCookies();
  await page.reload();
  await expect(facts).toHaveText(/^Geburtstag 12\.03\.1988 \(\d+ Jahre\)$/);

  // Ohne „Alter anzeigen“ nur Tag und Monat; „Nicht anzeigen“ blendet ihn aus.
  await birthday.getByLabel(/Alter anzeigen/).uncheck();
  await birthday.getByRole('button', { name: 'Geburtstag speichern' }).click();
  await expect(member.locator('.toast')).toHaveText('Geburtstag gespeichert');
  await page.reload();
  await expect(facts).toHaveText('Geburtstag 12.03.');
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
  // Im Wartungsmodus lädt die App nichts vorab (Issue #104), auch für Admins nicht: nur die geöffnete Ansicht.
  await page.reload();
  await expect(page.locator('.maintenance-banner')).toBeVisible();
  await page.waitForTimeout(3000);
  const prefetched = await page.evaluate(() =>
    performance
      .getEntriesByType('resource')
      .map((e) => decodeURIComponent(e.name))
      .filter((u) => /\/(?:api|kompanie-app)\/v1\/(news|gallery|documents|directory)\b/.test(u)),
  );
  expect(prefetched).toEqual([]);

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

test('Nutzung: Admin sieht aktive Konten, Nutzung je Bereich und den Verlauf einer Person, schaltet die Erfassung je Person aus und an', async ({
  page,
  browser,
}) => {
  // Ein Mitglied nutzt die App (Termine, eigene Punkte).
  const member = await browser.newPage();
  await login(member, 'mitglied1');
  await expect(member.getByText('Vereinsabend')).toBeVisible();
  await member.close();

  await login(page, 'admin', ADMIN_PASSWORD);
  await adminMenu(page, 'Einstellungen');
  await page
    .locator('.settings-link')
    .filter({ has: page.getByText('Nutzung', { exact: true }) })
    .click();
  await expect(page.getByRole('heading', { name: 'Aktive Personen je Tag' })).toBeVisible();
  const aktiv = page.locator('.stat', { hasText: 'Aktiv (24 Std.)' }).locator('.stat-value');
  await expect.poll(async () => Number(await aktiv.textContent())).toBeGreaterThanOrEqual(2);
  await expect(page.locator('.usage-bars li', { hasText: 'Allgemein' }).first()).toBeVisible();
  await shot(page, '33-nutzung');

  // Verlauf einer Person: nur Bereich, Aktion und Zeit
  const members = page.locator('.settings-section', { has: page.getByRole('heading', { name: 'Mitglieder', exact: true }) });
  await members.locator('.row', { hasText: 'zuletzt aktiv' }).first().click();
  await expect(page.locator('.usage-history li').first()).toContainText(/angesehen|angelegt|geändert|gelöscht|abgestimmt|angemeldet/);
  await page.keyboard.press('Escape');

  // Ausschalten löscht die Einträge je Person; die Liste der Mitglieder verschwindet, gezählt wird weiter.
  const schalter = page.getByLabel('Nutzung je Person erfassen (wer, wann, welcher Bereich)');
  page.once('dialog', (d) => d.accept());
  // Der Schalter folgt dem Server (erst nach der Antwort umgelegt): klicken, dann auf den neuen Stand warten.
  await schalter.click();
  await expect(schalter).not.toBeChecked();
  await expect(page.getByText('Ausgeschaltet: Die App zählt nur noch')).toBeVisible();
  await expect(members).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Als CSV-Datei herunterladen' })).toHaveCount(0);
  await schalter.click();
  await expect(schalter).toBeChecked();
  await expect(members).toBeVisible();
});
