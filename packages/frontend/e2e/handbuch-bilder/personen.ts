import { expect } from '@playwright/test';
import type { Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „personen“ (siehe gruppen.ts): Verwaltung und Mitgliederliste, Seite eines Mitglieds,
// Anlegen und Import, Ehemalige, öffentliche Mitgliederliste und Ämter. Beispieldaten: tests/standalone/handbuch-daten/30-personen.php.
// Am Ende wird ein Mitglied angelegt (Zugangsdaten); alles andere bleibt, wie es war.
const gruppe: Gruppe = async (f) => {
  const page = f.page;
  // Element knapp unter die Kopfzeile scrollen (Fotos des ganzen Bildschirms; Ausschnitte würden von Kopf- und
  // Fußleiste überdeckt).
  const oben = (selector: string) =>
    page
      .locator(selector)
      .first()
      .evaluate((el) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - 170));

  // Öffentliche Mitgliederliste, Seite einer Person, Ehemalige und Ämter (als Mitglied)
  await f.anmelden('mitglied1');
  await f.oeffnen('mitglieder/liste');
  await f.foto('mitglieder');
  await page.locator('.member-row-open', { hasText: 'Hoffmann' }).click();
  await expect(page.locator('.member-page h2')).toBeVisible();
  await f.foto('mitglieder-person');
  await f.oeffnen('mitglieder/ehemalige');
  await f.foto('mitglieder-ehemalige');
  await f.oeffnen('mitglieder/aemter');
  await f.foto('aemter');
  await page.locator('.office-head').first().click();
  await expect(page.locator('.office-body').first()).toBeVisible();
  await oben('.office-card.open .office-body');
  await f.foto('aemter-chronik');

  // Verwaltung als Admin
  await f.anmelden('admin');
  await f.oeffnen('verwaltung');
  await f.foto('verwaltung');

  await f.oeffnen('verwaltung/mitglieder');
  await f.foto('mitgliederliste');
  await page.locator('.member-filters-toggle').click();
  await expect(page.locator('#member-filters')).toBeVisible();
  await f.foto('mitgliederliste-filter');

  // Seite eines Mitglieds (Max Muster: Punkte, Strafen und Eintritt aus den Beispieldaten)
  await page.locator('.member-filters-toggle').click();
  await page.locator('.member-row', { hasText: 'Muster' }).click();
  await expect(page.locator('.member-detail h2.member-name')).toBeVisible();
  const id = /mitglied\/(\d+)/.exec(page.url())?.[1];
  if (!id) throw new Error('Mitglied nicht geöffnet');
  await f.oeffnen(`verwaltung/mitglied/${id}`);
  await f.foto('mitglied-uebersicht');
  await f.oeffnen(`verwaltung/mitglied/${id}/eintritt`);
  await expect(page.locator('.sheet')).toBeVisible();
  await f.foto('mitglied-uebersicht-eintritt');

  // Geburtstag
  await f.oeffnen(`verwaltung/mitglied/${id}/geburtstag`);
  await expect(page.locator('.sheet')).toBeVisible();
  await f.foto('geburtstage-pflegen');

  // Konto und Rolle (aufgeklappt)
  await f.oeffnen(`verwaltung/mitglied/${id}/konto`);
  await expect(page.locator('.member-account[open]')).toBeVisible();
  await oben('.member-account summary');
  await f.foto('rollen-vergeben');
  await oben('.member-rights');
  await f.foto('rollen-vergeben-rechte');
  await oben('.reset-password');
  await f.foto('rollen-vergeben-konto');

  // Als ehemalig eintragen (nur das Formular, nicht speichern)
  await f.oeffnen(`verwaltung/mitglied/${id}/austritt`);
  await expect(page.locator('.sheet form')).toBeVisible();
  await f.foto('ehemalige-mitglieder-eintragen');

  // Ehemalige: Liste und Import (Vorschau, nicht übernehmen)
  await f.oeffnen('verwaltung/ehemalige');
  await f.foto('ehemalige-mitglieder');
  await f.oeffnen('verwaltung/ehemalige/import');
  await page
    .locator('textarea')
    .fill('vorname;nachname;eintritt;ausgeschieden;grund\nJosef;Hermes;Gründer;1995;verstorben\nKarl;Brinkmann;1962;2004;ausgetreten');
  await page.getByRole('button', { name: 'Vorschau' }).click();
  await expect(page.locator('.office-import-rows')).toBeVisible();
  await oben('.office-import .button-row');
  await f.foto('ehemalige-mitglieder-import');

  // Ämter verwalten
  await f.oeffnen('verwaltung/aemter');
  await f.foto('aemter-verwalten');
  await page.locator('.office-admin-group .row-title').first().click();
  await expect(page.locator('.office-admin-terms')).toBeVisible();
  await f.foto('aemter-verwalten-amt');
  await page.getByRole('button', { name: 'Amtszeit eintragen' }).click();
  const term = page.locator('form', { hasText: 'Neue Amtszeit' });
  await expect(term).toBeVisible();
  await term.locator('input[type=number]').first().fill(String(new Date().getFullYear()));
  await oben('.office-admin-terms + form');
  await f.foto('aemter-verwalten-amtszeit');

  // Mehrere auf einmal (ausgefüllt, nicht angelegt)
  await f.oeffnen('verwaltung/neu-mehrere');
  const rows = page.locator('.bulk-row');
  await rows.nth(0).getByLabel('Vorname').fill('Paula');
  await rows.nth(0).getByLabel('Nachname').fill('Peters');
  await rows.nth(1).getByLabel('Vorname').fill('Jonas');
  await rows.nth(1).getByLabel('Nachname').fill('Weber');
  await f.foto('mitglieder-mehrere-anlegen');

  // Import aus einer Datei (Vorschau, nicht übernehmen)
  await f.oeffnen('verwaltung/import');
  await f.foto('mitglieder-import');
  await page
    .locator('textarea')
    .fill(
      'Vorname;Nachname;Status;Geburtstag;Eintritt\nPaula;Peters;Mitglied;03.02.1995;2019\nJonas;Weber;;24.12.;2024\nOtto;Früher;ehemalig;;Gründer\nAnna;Schmitz;Mitglied;;',
    );
  await page.getByRole('button', { name: 'Vorschau' }).click();
  await expect(page.locator('.office-import-rows')).toBeVisible();
  await oben('.office-import .button-row');
  await f.foto('mitglieder-import-vorschau');

  // Einzeln anlegen: Formular und Zugangsdaten (legt „Paula Peters“ an)
  await f.oeffnen('verwaltung/neu');
  await f.foto('mitglieder-anlegen');
  await page.getByLabel('Vorname').fill('Paula');
  await page.getByLabel('Nachname').fill('Peters');
  await page.locator('form button[type=submit]').click();
  await expect(page.locator('.credentials-block')).toBeVisible();
  await page.evaluate(() => window.scrollTo(0, 0));
  await f.foto('mitglieder-anlegen-zugangsdaten');
};
export default gruppe;
