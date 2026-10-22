import type { Locator } from '@playwright/test';
import type { Fotograf, Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „punkte“ (siehe gruppen.ts): Meins (Übersicht, Punkte, Strafen, Dienstgrad) und die
// Verwaltung von Punkten, Katalog, Strafen, Dienstgraden, Beförderungen und Titeln, dazu die Fenster auf der Seite eines
// Mitglieds. Beispieldaten: tests/standalone/handbuch-daten/20-punkte.php. Formulare werden nur ausgefüllt, nie
// abgeschickt, damit die Daten für die anderen Fotos gleich bleiben.

/** Kennung einer Testperson über die Mitgliederliste der Verwaltung (Nachname; die Liste zeigt „Nachname, Vorname“). */
async function mitgliedId(f: Fotograf, nachname: string): Promise<string> {
  await f.oeffnen('verwaltung/mitglieder');
  await f.page.locator('main li', { hasText: nachname }).first().click();
  await f.page.waitForURL(/#\/verwaltung\/mitglied\/\d+/);
  return /#\/verwaltung\/mitglied\/(\d+)/.exec(f.page.url())![1];
}

/** Ausschnitt in die Mitte des Bildschirms holen, damit Kopfzeile und Leiste unten ihn nicht verdecken. */
async function mittig(loc: Locator): Promise<Locator> {
  await loc.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  return loc;
}

/** Eine Person in der Auswahl der Mitglieder ankreuzen. */
async function ankreuzen(f: Fotograf, name: string): Promise<void> {
  await f.page.locator('.picker-list label', { hasText: name }).click();
}

const gruppe: Gruppe = async (f) => {
  const { page } = f;

  // Meins: ein einfaches Mitglied (Max Muster)
  await f.anmelden('mitglied1');
  await f.oeffnen('meins/uebersicht');
  await f.foto('meins-uebersicht');
  await page.locator('.meins-menu .admin-menu-toggle').click();
  await f.foto('meins-uebersicht-menue');
  // Wieder zuklappen (Tippen, nicht Escape: sonst bliebe der Rahmen der Tastaturauswahl auf den nächsten Fotos)
  await page.locator('.meins-menu .admin-menu-toggle').click();
  await f.oeffnen('meins/punkte');
  await f.foto('meine-punkte');
  await f.oeffnen('meins/strafen');
  await f.foto('meine-strafen');
  await f.oeffnen('meins/dienstgrad');
  await f.foto('mein-dienstgrad');

  // Punkte, Katalog und Strafen: Rolle „Spieß“
  await f.anmelden('verwalter');
  await f.oeffnen('verwaltung/punkte');
  await f.foto('punkte-vergeben');
  const vorlage = page.getByLabel('Vorlage aus dem Katalog');
  const wert = await vorlage.locator('option', { hasText: 'Arbeitsdienst' }).getAttribute('value');
  await vorlage.selectOption(wert!);
  await ankreuzen(f, 'Max Muster');
  await ankreuzen(f, 'Erika Beispiel');
  await f.foto('punkte-vergeben-ausgefuellt');

  await f.oeffnen('verwaltung/katalog');
  await f.foto('punktekatalog');
  const neu = page.locator('form.card');
  await neu.locator('input[type=text]').fill('Königsschießen');
  await neu.locator('input[type=number]').fill('5');
  await f.foto('punktekatalog-neu', await mittig(neu));

  await f.oeffnen('verwaltung/strafe');
  await f.foto('strafen-verwalten');
  await page.getByPlaceholder('2,50').fill('5,00');
  await page.getByPlaceholder('z. B. Zu spät gekommen').fill('Fehlen beim Arbeitsdienst');
  await ankreuzen(f, 'Max Muster');
  await f.foto('strafen-verwalten-ausgefuellt');

  const max = await mitgliedId(f, 'Muster');
  await f.oeffnen(`verwaltung/mitglied/${max}/punkte`);
  await f.foto('mitglied-punkte');
  await f.oeffnen(`verwaltung/mitglied/${max}/strafen`);
  await f.foto('mitglied-strafen');

  // Dienstgrade, Beförderungen und Titel: Admin
  await f.anmelden('admin');
  await f.oeffnen('verwaltung/dienstgrade');
  await f.foto('dienstgrade-verwalten');
  await f.foto('dienstgrade-verwalten-abzeichen', await mittig(page.locator('.card-list > li').nth(1)));
  const rangNeu = page.locator('form.card');
  await rangNeu.locator('input[type=text]').fill('Oberfeldwebel');
  await rangNeu.locator('input[type=number]').fill('150');
  await f.foto('dienstgrade-verwalten-neu', await mittig(rangNeu));

  await f.oeffnen('verwaltung/befoerderungen');
  await f.foto('befoerderungen');
  await page.locator('label.toggle input[type=checkbox]').check();
  await f.foto('befoerderungen-degradieren');
  await page.locator('label.toggle input[type=checkbox]').uncheck();
  // Aus der Übersicht öffnen: Der nächste Dienstgrad ist schon vorgeschlagen.
  await page.locator('.promotions li.due').first().click();
  await page.locator('.sheet .rank-change').waitFor();
  await f.foto('mitglied-dienstgrad');

  await f.oeffnen('verwaltung/titel');
  await f.foto('titel-verwalten');
  const maxAdmin = await mitgliedId(f, 'Muster');
  await f.oeffnen(`verwaltung/mitglied/${maxAdmin}/titel`);
  await f.foto('mitglied-titel');
};
export default gruppe;
