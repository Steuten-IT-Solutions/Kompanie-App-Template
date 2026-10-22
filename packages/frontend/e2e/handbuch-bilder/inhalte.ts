import { expect, type Locator } from '@playwright/test';
import type { Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „inhalte“ (siehe gruppen.ts): Dokumente, Schwarzes Brett, Umfragen.
// Beispieldaten: tests/standalone/handbuch-daten/40-inhalte.php. Formulare werden nur ausgefüllt, nicht abgeschickt.

/** Kleine PDF-Datei für das Formular „Dokument hochladen“ (wird nicht hochgeladen). */
const PDF = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n');

const gruppe: Gruppe = async (f) => {
  const { page } = f;
  /** Ausschnitt ohne die feste Kopf- und Tab-Leiste, die sonst über dem gescrollten Ausschnitt liegen. */
  const ausschnitt = async (name: string, teil: Locator) => {
    const style = await page.addStyleTag({ content: '.topbar, .tabbar { visibility: hidden !important; }' });
    await f.foto(name, teil);
    await style.evaluate((el) => el.remove());
  };

  // Dokumente lesen (Mitglied)
  await f.anmelden('mitglied1');
  await f.oeffnen('dokumente');
  await f.foto('dokumente');
  await page.locator('.doc-group-toggle').filter({ hasText: 'Protokolle' }).click();
  await f.foto('dokumente-kategorie');
  await page.locator('.doc-card').filter({ hasText: 'Protokoll Jahreshauptversammlung' }).getByRole('button', { name: 'Öffnen' }).click();
  await expect(page.locator('img.pdf-page')).toBeVisible({ timeout: 15_000 });
  await f.foto('dokument-ansehen');

  // Dokumente pflegen (Admin)
  await f.anmelden('admin');
  await f.oeffnen('dokumente/verwalten');
  await f.foto('dokumente-pflegen');
  const upload = page.locator('form.card').filter({ has: page.locator('input[type=file]') });
  await upload
    .locator('input[type=file]')
    .setInputFiles({ name: 'Protokoll Vorstandssitzung Juni.pdf', mimeType: 'application/pdf', buffer: PDF });
  await upload.getByLabel('Kategorie').selectOption({ label: 'Protokolle' });
  await ausschnitt('dokumente-pflegen-hochladen', upload);
  await page.locator('.doc-group-toggle').filter({ hasText: 'Protokolle' }).click();
  const kasse = page.locator('.card').filter({ hasText: 'Kassenbericht' });
  await kasse.getByRole('button', { name: 'Bearbeiten' }).click();
  const bearbeiten = page.locator('form.doc-edit');
  await bearbeiten.scrollIntoViewIfNeeded();
  await ausschnitt('dokumente-pflegen-bearbeiten', bearbeiten);
  await bearbeiten.getByRole('button', { name: 'Abbrechen' }).click();
  const kategorien = page.locator('.doc-categories');
  await kategorien.scrollIntoViewIfNeeded();
  await ausschnitt('dokumente-pflegen-kategorien', kategorien);

  // Schwarzes Brett lesen: mitglied2 hat eine laufende Umfrage noch nicht beantwortet (Banner mit „+1 weitere“).
  await f.anmelden('mitglied2');
  await f.oeffnen('termine');
  const banner = page.locator('.board-banner');
  await expect(banner.locator('.board-more')).toBeVisible();
  await ausschnitt('schwarzes-brett-banner', banner);
  await banner.locator('.board-more').click();
  await ausschnitt('schwarzes-brett-banner-mehr', banner);
  await f.oeffnen('brett');
  await f.foto('schwarzes-brett');
  await f.oeffnen('termine');
  for (let i = 0; i < 2; i++) await banner.locator('.board-dismiss').first().click();
  await expect(page.locator('.board-banner-collapsed')).toBeVisible();
  await ausschnitt('schwarzes-brett-ausgeblendet', page.locator('.board-banner-collapsed'));

  // Umfragen (mitglied2)
  await f.oeffnen('umfragen');
  await f.foto('umfragen');
  await page.locator('.poll-list-item').filter({ hasText: 'Ausflugsziele' }).click();
  await expect(page.locator('.poll-answer')).toBeVisible();
  await f.foto('umfrage-beantworten');
  await page.locator('.poll-option').filter({ hasText: 'Schifffahrt' }).click();
  await page.locator('.poll-option').filter({ hasText: 'Wanderung' }).click();
  await ausschnitt('umfrage-beantworten-auswahl', page.locator('.poll-answer'));
  await f.oeffnen('umfragen');
  await page.locator('.poll-list-item').filter({ hasText: 'Grillfest' }).click();
  await expect(page.locator('.poll-results')).toBeVisible();
  await ausschnitt('umfrage-beantworten-ergebnis', page.locator('.poll-card'));

  // Ans Brett schreiben (Admin)
  await f.anmelden('admin');
  await f.oeffnen('brett');
  await f.foto('schwarzes-brett-schreiben');
  const neu = page.locator('.board-form');
  await neu.locator('textarea').fill('Heute kein Training – die Halle ist gesperrt.');
  await ausschnitt('schwarzes-brett-schreiben-neu', neu);
  await neu.locator('textarea').fill('');
  const eintrag = page.locator('.board-card').filter({ hasText: 'Treffen am Samstag' });
  await eintrag.getByRole('button', { name: 'Bearbeiten' }).click();
  await ausschnitt('schwarzes-brett-schreiben-bearbeiten', eintrag);
  await eintrag.getByRole('button', { name: 'Abbrechen' }).click();

  // Umfragen verwalten (Admin)
  await f.oeffnen('umfragen/verwalten');
  await f.foto('umfragen-verwalten');
  const form = page.locator('.poll-form');
  await form.getByLabel('Frage', { exact: true }).fill('Sollen wir ein Vereinsfoto machen lassen?');
  await form.getByRole('textbox', { name: 'Möglichkeit 1', exact: true }).fill('Ja');
  await form.getByRole('textbox', { name: 'Möglichkeit 2', exact: true }).fill('Nein');
  await form.locator('.poll-add-option').click();
  await form.getByRole('textbox', { name: 'Möglichkeit 3', exact: true }).fill('Egal');
  await ausschnitt('umfragen-verwalten-neu', form);
  const grill = page.locator('.poll-managed').filter({ hasText: 'Grillfest' });
  await grill.scrollIntoViewIfNeeded();
  await ausschnitt('umfragen-verwalten-umfrage', grill);
};
export default gruppe;
