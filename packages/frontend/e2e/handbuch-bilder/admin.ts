import { expect } from '@playwright/test';
import type { Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „admin“ (siehe gruppen.ts): Einstellungen, Einrichtungsassistent, Vorschau als Rolle und
// Wartungsmodus. Läuft als letzte Gruppe; der Wartungsmodus wird am Ende ein- und wieder ausgeschaltet.
const gruppe: Gruppe = async (f) => {
  const { page } = f;
  const knopf = (name: string) => page.getByRole('button', { name, exact: true });
  // Ausschnitt mit hohem Fenster und ohne die festen Leisten oben und unten: Sonst verdecken sie den Rand des Ausschnitts.
  const ausschnitt = async (name: string, selector: string) => {
    await page.setViewportSize({ width: 390, height: 2400 });
    const stil = await page.addStyleTag({ content: '.topbar, .tabbar { visibility: hidden !important; }' });
    await f.foto(name, page.locator(selector));
    await stil.evaluate((el) => el.remove());
    await page.setViewportSize({ width: 390, height: 844 });
  };
  await f.anmelden('admin');

  // Übersicht der Einstellungen
  await f.oeffnen('einstellungen');
  await f.foto('admin-einstellungen');

  // Bereiche
  await f.oeffnen('einstellungen/bereiche');
  await f.foto('admin-bereiche');

  // Rollen: Übersicht, neue Rolle (nicht angelegt), Vorschau starten
  await f.oeffnen('einstellungen/rollen');
  await f.foto('admin-rollen-und-mitglieder');
  await knopf('+ Neue Rolle').click();
  const form = page.locator('form.role-form');
  await form.getByLabel('Name der Rolle').fill('Anwärter');
  await form.getByLabel(/^Mitgliederbereich/).uncheck();
  await form.getByLabel(/^Interner Kalender/).check();
  await form.getByLabel(/^Beim Anlegen neuer Konten wählbar/).check();
  await form.getByLabel(/^Wer Mitglieder anlegen darf/).check();
  await ausschnitt('admin-rollen-und-mitglieder-neu', 'form.role-form');
  await form.getByRole('button', { name: 'Abbrechen' }).click();
  await ausschnitt('admin-vorschau-starten', '.role-preview');

  // Vorschau als Mitglied (wie „Ansehen“ bei der Rolle Mitglied), dann über das Banner beenden
  await page.evaluate(() => sessionStorage.setItem('kompanie-preview', 'member'));
  await page.goto('/app/#/');
  await page.reload();
  await expect(page.locator('.preview-banner')).toBeVisible();
  await expect(page.locator('[data-loading]')).toHaveCount(0, { timeout: 15_000 });
  await f.foto('admin-vorschau');
  await page.locator('.preview-banner button').click();
  await expect(page).toHaveURL(/#\/einstellungen\/rollen/);
  await expect(page.locator('.preview-banner')).toHaveCount(0);

  // Benachrichtigungen
  await f.oeffnen('einstellungen/benachrichtigungen');
  await f.foto('admin-benachrichtigungen');

  // E-Mail: Kompanieadresse und Mailserver (Beispielwerte, nicht gespeichert)
  await f.oeffnen('einstellungen/email');
  const email = page.locator('#einstellungen-email');
  await email.getByLabel('Kompanieadresse (Absender)').fill('info@musterkompanie.example');
  await email.getByLabel('Mailserver (SMTP)').fill('smtp.musterkompanie.example');
  await email.getByLabel('Benutzername').fill('info@musterkompanie.example');
  await f.foto('admin-email');

  // Google-Zugang: Übersicht und das Formular mit Client-ID (nicht abgeschickt)
  await f.oeffnen('einstellungen/google');
  await f.foto('admin-google');
  const verbinden = page.locator('#einstellungen-google form');
  await verbinden.getByLabel('Client-ID').fill('123456789012-beispiel.apps.googleusercontent.com');
  await verbinden.getByLabel('Clientschlüssel (Client-Secret)').fill('GOCSPX-beispiel');
  await ausschnitt('admin-google-verbinden', '#einstellungen-google form');

  // Galerie: Übersicht und Abgleich
  await f.oeffnen('einstellungen/galerie');
  await f.foto('admin-galerie');
  await knopf('Jetzt abgleichen').click();
  await expect(page.locator('#einstellungen-galerie [role=status]')).toContainText(/Fertig|Abgeglichen/, { timeout: 30_000 });
  await ausschnitt('admin-galerie-abgleich', '#einstellungen-galerie');

  // Kalender: Übersicht und Test
  await f.oeffnen('einstellungen/kalender');
  await f.foto('admin-kalender');
  await knopf('Kalender testen').click();
  await expect(page.locator('#einstellungen-kalender [role=status]')).toBeVisible({ timeout: 30_000 });
  await ausschnitt('admin-kalender-test', '#einstellungen-kalender');

  // Neuigkeiten: Übersicht und Test
  await f.oeffnen('einstellungen/news');
  await f.foto('admin-neuigkeiten');
  await knopf('Neuigkeiten testen').click();
  await expect(page.locator('#einstellungen-news [role=status]')).toBeVisible({ timeout: 30_000 });
  await ausschnitt('admin-neuigkeiten-test', '#einstellungen-news');

  // Nutzung (Issue #175): Zahlen aus den Aufrufen der Gruppen davor
  await f.oeffnen('einstellungen/nutzung');
  await expect(page.getByRole('heading', { name: 'Aktive Personen je Tag' })).toBeVisible();
  await f.foto('admin-nutzung');

  // Zustand und Updates mit Fehlerprotokoll (Einträge aus handbuch-daten/60-admin.php)
  await f.oeffnen('einstellungen/zustand');
  await f.foto('admin-zustand');
  await ausschnitt('admin-zustand-fehlerprotokoll', 'section[aria-labelledby=errorlog-title]');

  // Datensicherung
  await f.oeffnen('einstellungen/datensicherung');
  await f.foto('admin-updates-und-sicherung');

  // Was ist neu
  await f.oeffnen('einstellungen/neuerungen');
  await f.foto('admin-neuerungen');

  // Einrichtungsassistent: Schritte durchgehen, ohne abzuschließen (die Bereiche bleiben, wie sie sind)
  await f.oeffnen('einrichtung');
  await f.foto('admin-einrichtung');
  await knopf('Weiter').click(); // Bereiche speichern → Kalender
  await expect(page.locator('#einstellungen-kalender')).toBeVisible();
  await knopf('Weiter').click(); // → Google-Zugang und Galerie
  await expect(page.locator('#einstellungen-galerie')).toBeVisible();
  await page.locator('.setup-steps').scrollIntoViewIfNeeded();
  await f.foto('admin-einrichtung-galerie');
  await knopf('Weiter').click(); // → Neuigkeiten
  await expect(page.locator('#einstellungen-news')).toBeVisible();
  await knopf('Weiter').click(); // → Mitglieder übernehmen
  await expect(page.getByRole('heading', { name: 'Mitglieder übernehmen' })).toBeVisible();
  // Nur der Abschnitt: Der Google-Zugang aus dem Schritt davor bleibt in der App derzeit stehen (gleicher key).
  await ausschnitt('admin-einrichtung-mitglieder', '.settings > section:has(h3:text-is("Mitglieder übernehmen"))');
  await page.locator('.settings > .button-row').getByRole('button', { name: 'Weiter', exact: true }).click(); // → Fast fertig
  await expect(page.getByRole('heading', { name: 'Fast fertig' })).toBeVisible();
  await ausschnitt('admin-einrichtung-fertig', '.settings > section:has(h3:text-is("Fast fertig"))');

  // Wartungsmodus: Begründung eintragen, einschalten, Banner und Wartungsseite, dann wieder beenden
  await f.oeffnen('einstellungen/wartung');
  await page
    .getByLabel('Begründung (optional, erscheint auf der Wartungsseite)')
    .fill('Umstellung auf die neue Mitgliederliste, bis ca. 18 Uhr.');
  await f.foto('admin-wartungsmodus');
  page.once('dialog', (d) => void d.accept());
  await knopf('Wartungsmodus einschalten').click();
  await expect(page.locator('.maintenance-banner')).toBeVisible();
  await expect(page.locator('.toast')).toHaveCount(0, { timeout: 10_000 });
  await f.foto('admin-wartungsmodus-banner');
  // Wartungsseite, wie sie alle anderen sehen: ohne Anmeldung, danach wieder als Admin
  const cookies = await page.context().cookies();
  await page.context().clearCookies();
  await page.goto('/app/');
  await expect(page.locator('.maintenance-page')).toBeVisible();
  await f.foto('admin-wartungsmodus-seite');
  await page.context().addCookies(cookies);
  await page.goto('/app/#/einstellungen/wartung');
  await page.reload();
  await knopf('Wartungsmodus beenden').click();
  await expect(page.locator('.maintenance-banner')).toHaveCount(0);
};
export default gruppe;
