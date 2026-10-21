import { defineConfig, devices } from '@playwright/test';

// E2E-Tests gegen eine laufende Test-Instanz (scripts/setup-test-wp.sh).
// Erwartet die Testbenutzer verwalter, mitglied1, mitglied2 (Passwort test1234) und admin/admin.
export default defineConfig({
  testDir: './e2e',
  // Bildschirmfotos nur auf Wunsch (scripts/screenshots.sh), sonst die Funktionstests.
  testMatch: process.env.KOMPANIE_SCREENSHOTS ? 'screenshots.spec.ts' : 'app.spec.ts',
  globalSetup: './e2e/global-setup.ts',
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: process.env.KOMPANIE_BASE_URL ?? 'http://localhost:8888',
    ...devices['iPhone 13'],
    browserName: 'chromium',
    // Voller Chromium im neuen Headless-Modus statt der abgespeckten Headless-Shell: Nur er zeigt Benachrichtigungen
    // des Service Workers an (E2E-Test „Benachrichtigungen“).
    channel: process.env.PW_CHROMIUM_PATH ? undefined : 'chromium',
    launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
    // Bei einem Fehlschlag Ablauf mit Netzwerk und DOM behalten (CI lädt test-results/ hoch), Issue #68.
    trace: 'retain-on-failure',
  },
});
