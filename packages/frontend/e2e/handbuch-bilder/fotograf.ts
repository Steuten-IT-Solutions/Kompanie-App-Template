import { expect, type Locator, type Page } from '@playwright/test';

// Werkzeug für die Bildschirmfotos des Handbuchs (handbuch-bilder.spec.ts, scripts/handbuch-bilder.sh).
// Navigation über die Adressen (#/…) und Rollen, nicht über Beschriftungen: Die heißen je Kompanie anders.

export const DIR = process.env.KOMPANIE_SCREENSHOT_DIR ?? 'handbuch-bilder';

/** Testbenutzer (tests/standalone/e2e-setup.php): admin hat alle Rechte, verwalter die Rolle „Spieß“ (Punkte, Strafen,
 * Termine), mitglied1 und mitglied2 sind einfache Mitglieder. */
export type Benutzer = 'admin' | 'verwalter' | 'mitglied1' | 'mitglied2';
const PASSWORT: Record<Benutzer, string> = { admin: 'admin', verwalter: 'test1234', mitglied1: 'test1234', mitglied2: 'test1234' };

export interface Fotograf {
  page: Page;
  /** Foto unter docs/handbuch: ![…](bild:<name>). Ohne Ausschnitt der ganze Bildschirm. */
  foto(name: string, ausschnitt?: Locator): Promise<void>;
  /** Abmelden und als diese Person anmelden; ohne Angabe: Gast (nicht angemeldet). */
  anmelden(benutzer?: Benutzer): Promise<void>;
  /** Seite der App öffnen, z. B. „verwaltung/katalog“, und warten, bis sie geladen ist. */
  oeffnen(route: string): Promise<void>;
}

/** Fotos eines Teils des Handbuchs, nacheinander. */
export type Gruppe = (f: Fotograf) => Promise<void>;

const fertig = new Set<string>();

export function fotograf(page: Page): Fotograf {
  const geladen = async () => {
    await expect(page.locator('.topbar')).toBeVisible();
    // Ladeanzeigen und Gerüste (src/skeleton.tsx) abwarten
    await expect(page.locator('[data-loading]')).toHaveCount(0, { timeout: 15_000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);
  };
  return {
    page,
    async foto(name, ausschnitt) {
      if (!/^[a-z0-9-]+$/.test(name)) throw new Error(`Ungültiger Name für ein Foto: ${name}`);
      if (fertig.has(name)) throw new Error(`Foto ${name} doppelt`);
      fertig.add(name);
      await page.emulateMedia({ colorScheme: 'light' });
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(300);
      const opts = { path: `${DIR}/${name}.jpg`, type: 'jpeg' as const, quality: 70, animations: 'disabled' as const };
      if (ausschnitt) await ausschnitt.screenshot(opts);
      else await page.screenshot(opts);
    },
    async anmelden(benutzer) {
      await page.context().clearCookies();
      await page.goto('/app/');
      await page.evaluate(() => localStorage.clear()).catch(() => undefined);
      await page.evaluate(() => localStorage.setItem('kompanie-install-hint-dismissed', '1'));
      if (!benutzer) {
        await page.goto('/app/');
        await geladen();
        return;
      }
      await page.goto('/app/#/anmelden');
      await page.getByLabel('Benutzername').fill(benutzer);
      await page.getByLabel('Passwort').fill(PASSWORT[benutzer]);
      await page.locator('form.login button[type=submit]').click();
      await expect(page).not.toHaveURL(/#\/anmelden/);
      await expect(page.locator('.tabbar')).toBeVisible();
      await geladen();
    },
    async oeffnen(route) {
      await page.goto(`/app/#/${route}`);
      await geladen();
    },
  };
}
