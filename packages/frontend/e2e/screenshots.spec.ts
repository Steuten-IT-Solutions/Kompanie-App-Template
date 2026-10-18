import { expect, test, type Page } from '@playwright/test';

// Bildschirmfotos für die Download-Seite und für Pull Requests einer Kompanie.
// Unabhängig von den Bezeichnungen in kompanie.json: Navigation über die Adressen (#/…).
// Läuft gegen die eigenständige Version mit Beispieldaten (scripts/screenshots.sh).

const DIR = process.env.KOMPANIE_SCREENSHOT_DIR ?? 'screenshots';

async function shot(page: Page, name: string, dark = false) {
  await page.emulateMedia({ colorScheme: dark ? 'dark' : 'light' });
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(250);
  await page.screenshot({ path: `${DIR}/${name}.png` });
}

async function login(page: Page) {
  await page.goto('/app/#/anmelden');
  await page.getByLabel('Benutzername').fill('mitglied1');
  await page.getByLabel('Passwort').fill('test1234');
  await page.locator('form.login button[type=submit]').click();
  await expect(page.locator('.topbar-account')).toHaveText('Konto');
}

test.beforeEach(async ({ page }) => {
  // Den Hinweis „App aufs Handy holen“ ausblenden: Er verdeckt sonst den Inhalt.
  await page.addInitScript(() => localStorage.setItem('kompanie-install-hint-dismissed', '1'));
});

test('Bildschirmfotos', async ({ page }) => {
  await page.goto('/app/');
  // Nur Bereiche zeigen, die diese Kompanie eingeschaltet hat.
  const f = await page.evaluate(() => window.KOMPANIE_CONFIG!.features);
  await expect(page.locator('.topbar')).toBeVisible();
  await shot(page, '0-start-gast');

  await login(page);
  if (f.events || f.calendar) {
    await page.goto('/app/#/termine');
    await expect(page.locator('li.event').first()).toBeVisible();
    await shot(page, '1-termine');
    await shot(page, '1-termine-dunkel', true);
  }
  if (f.points || f.penalties) {
    await page.goto('/app/#/meins');
    await expect(page.locator('.stat').first()).toBeVisible();
    await shot(page, '2-punkte');
  }
  if (f.news) {
    await page.goto('/app/#/news');
    await expect(page.locator('.news-card').first()).toBeVisible();
    await page.locator('.news-card').first().click();
    await expect(page.locator('.news-article')).toBeVisible();
    await shot(page, '3-news');
  }
  if (f.gallery) {
    await page.goto('/app/#/galerie');
    await expect(page.locator('.album-card').first()).toBeVisible();
    await page.waitForLoadState('networkidle');
    await shot(page, '4-galerie');
  }
});
