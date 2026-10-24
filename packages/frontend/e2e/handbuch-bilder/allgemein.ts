import { expect, type Locator } from '@playwright/test';
import { fotograf, type Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „allgemein“ (siehe gruppen.ts): Rahmen der App, Termine, Kalender, Geburtstage, News,
// Galerie, Anmelden, Mein Konto, Benachrichtigungen, App installieren, Fehler melden, Öffentliche Termine pflegen.
// Beispieldaten: tests/standalone/handbuch-daten/10-allgemein.php.
const gruppe: Gruppe = async (f) => {
  const p = f.page;
  // Nachrichten des Schwarzen Bretts (Banner oben) ausblenden: Sie gehören nicht zu diesen Bildschirmen.
  const ohneBrett = async () => {
    const zu = p.locator('.board-dismiss');
    while ((await zu.count()) > 0) await zu.first().click();
  };
  // Ausschnitt ohne Kopfzeile und untere Leiste: Bei langen Ausschnitten lägen sie sonst darüber.
  const ausschnitt = async (name: string, teil: Locator) => {
    const stil = await p.addStyleTag({ content: '.topbar, .tabbar { visibility: hidden !important; }' });
    await f.foto(name, teil);
    await stil.evaluate((el) => el.remove());
  };

  // Gast: Startseite, öffentliche Termine, News, Anmelden, App installieren
  await f.anmelden();
  await f.foto('app-ueberblick-gast');
  await f.foto('termine-und-kalender');
  await p.locator('.event-head').first().click();
  await expect(p.locator('.event-body')).toBeVisible();
  await f.foto('termine-und-kalender-termin');

  // Hinweis „Die App aufs Handy holen“ (auf dem iPhone mit „So geht's“)
  // (Ohne Neuladen der Seite: Der Test setzt den Schalter bei jedem Laden. Auf „App installieren“ fehlt der Hinweis,
  // beim Wechsel zu „News“ liest er den Schalter neu.)
  await f.oeffnen('installieren');
  await p.evaluate(() => localStorage.removeItem('kompanie-install-hint-dismissed'));
  await f.oeffnen('news');
  await f.foto('app-ueberblick-hinweis', p.locator('.install-hint'));
  await p.locator('.install-hint-close').click();

  await f.oeffnen('news');
  await f.foto('neuigkeiten');
  await p.locator('.news-card').first().click();
  await expect(p.locator('.news-article')).toBeVisible();
  await f.foto('neuigkeiten-beitrag');

  await f.oeffnen('installieren');
  await f.foto('app-installieren');
  await p.locator('.segmented button').nth(1).click();
  await f.foto('app-installieren-android');

  // Geteilter Link auf eine Seite für Angemeldete
  await f.oeffnen('dokumente');
  await expect(p.locator('.login-first')).toBeVisible();
  await f.foto('anmelden-hinweis');
  await f.oeffnen('anmelden');
  await p.getByLabel('Benutzername').fill('mitglied1');
  await p.getByLabel('Passwort').fill('test1234');
  await p.getByLabel('Passwort').evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await f.foto('anmelden');

  // Admin: alle Knöpfe unten
  await f.anmelden('admin');
  await ohneBrett();
  await f.foto('app-ueberblick');

  // Mitglied: Kalender, Geburtstage, Galerie, Konto
  await f.anmelden('mitglied1');
  await ohneBrett();
  await f.foto('kalender');
  await p.locator('.event-head').first().click();
  await expect(p.locator('.event-body')).toBeVisible();
  await f.foto('kalender-termin');

  await f.oeffnen('termine/geburtstage');
  await f.foto('geburtstage');

  await f.oeffnen('meins/konto');
  await f.foto('anmelden-und-konto');
  await ausschnitt('geburtstage-eintragen', p.locator('.birthday-card'));
  await ausschnitt('anmelden-und-konto-passwort', p.locator('form.account-card').filter({ hasText: 'Aktuelles Passwort' }));
  await p.locator('.meins-menu .admin-menu-toggle').click();
  await expect(p.locator('.admin-menu-list')).toBeVisible();
  await f.foto('anmelden-und-konto-menue');

  await f.oeffnen('galerie');
  await f.foto('galerie');
  await p.locator('.album-card').first().click();
  await expect(p.locator('.photo-grid')).toBeVisible();
  await f.foto('galerie-album');
  await p.locator('.photo[data-intern]').first().click();
  await expect(p.locator('.lightbox')).toBeVisible();
  await f.foto('galerie-album-bild');
  await p.keyboard.press('Escape');

  // Benachrichtigungen auf dem iPhone im Browser: nur der Hinweis auf die installierte App
  await f.oeffnen('benachrichtigungen');
  await f.foto('benachrichtigungen-iphone', p.locator('.push-card'));

  // Fehler melden: von einer Seite aus über „?“
  await f.oeffnen('galerie');
  await p.locator('.topbar-help').click();
  await p.locator('.hilfe-melden').click();
  await expect(p.locator('.report-form')).toBeVisible();
  await p.getByLabel('Was klappt nicht? (Pflicht)').fill('Das Album „Sommerfest“ lädt bei mir nicht, es bleibt grau.');
  await p.getByLabel('Was hast du erwartet? (freiwillig)').fill('Dass die Bilder erscheinen.');
  await f.foto('fehler-melden');
  await ausschnitt('fehler-melden-senden', p.locator('.report-details'));

  // Öffentliche Termine pflegen (Rolle mit dem Recht für Termine)
  await f.anmelden('verwalter');
  await ohneBrett();
  await f.oeffnen('termine/verwalten');
  await f.foto('termine-pflegen');
  await p.locator('.add-event').click();
  const form = p.locator('form.event-form');
  await form.getByLabel('Titel', { exact: true }).fill('Preisschießen');
  await form.getByLabel('Datum', { exact: true }).fill('2026-11-21');
  await form.getByLabel('Beginn (optional)').fill('14:00');
  await form.getByLabel('Ende (optional)').fill('18:00');
  await form.getByLabel('Ort', { exact: true }).fill('Schießstand');
  await form.getByLabel('Beschreibung').fill('Offenes Preisschießen für alle Bürgerinnen und Bürger.');
  await ausschnitt('termine-pflegen-formular', form);

  // Benachrichtigungen, wie sie in der installierten App aussehen (dort gibt es sie auch auf dem iPhone)
  const app = await p.context().newPage();
  await app.addInitScript(() => Object.defineProperty(Navigator.prototype, 'standalone', { get: () => true }));
  const g = fotograf(app);
  await g.anmelden('mitglied1');
  await g.oeffnen('benachrichtigungen');
  await expect(app.locator('.push-on')).toBeVisible();
  await g.foto('benachrichtigungen');
  await g.anmelden();
  await g.oeffnen('benachrichtigungen');
  await expect(app.locator('.push-on')).toBeVisible();
  await g.foto('benachrichtigungen-gast', app.locator('.push-card'));
  await app.close();
};
export default gruppe;
