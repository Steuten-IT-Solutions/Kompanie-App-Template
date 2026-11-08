import { expect, type Page } from '@playwright/test';

/*
 * Rückfragen der App (src/ask.tsx, Issue #342) sind eigene Fenster statt window.confirm/prompt/alert:
 * Die Tests beantworten sie über die Knöpfe im Fenster.
 */

/** Offene Rückfrage mit „OK“ (bzw. der eigenen Beschriftung) bestätigen; bei einer Texteingabe vorher `value` eintragen. */
export async function askOk(page: Page, value?: string) {
  const dialog = page.locator('.ask-dialog');
  if (value !== undefined) await dialog.locator('.ask-input').fill(value);
  await dialog.locator('button[type=submit]').click();
  await expect(dialog).toHaveCount(0);
}

/** Offene Rückfrage mit „Abbrechen“ schließen. */
export async function askCancel(page: Page) {
  const dialog = page.locator('.ask-dialog');
  await dialog.getByRole('button', { name: 'Abbrechen' }).click();
  await expect(dialog).toHaveCount(0);
}

/**
 * Jede Rückfrage auf der Seite im Hintergrund bestätigen (wie früher page.on('dialog', …)); den Text jeweils in
 * `messages` sammeln. Endet mit der Seite.
 */
export function autoAsk(page: Page, messages?: string[]) {
  const dialog = page.locator('.ask-dialog');
  void (async () => {
    for (;;) {
      await dialog.waitFor({ timeout: 0 });
      messages?.push((await dialog.locator('.ask-message').textContent()) ?? '');
      await dialog.locator('button[type=submit]').click();
      await dialog.waitFor({ state: 'detached', timeout: 0 });
    }
  })().catch(() => undefined);
}
