import { defineConfig, devices } from '@playwright/test';

// E2E-Tests gegen eine laufende Test-Instanz (scripts/setup-test-wp.sh).
// Erwartet die Testbenutzer verwalter, mitglied1, mitglied2 (Passwort test1234) und admin/admin.
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  workers: 1,
  timeout: 60_000,
  use: {
    baseURL: process.env.KOMPANIE_BASE_URL ?? 'http://localhost:8888',
    ...devices['iPhone 13'],
    browserName: 'chromium',
    launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
    locale: 'de-DE',
    timezoneId: 'Europe/Berlin',
  },
});
