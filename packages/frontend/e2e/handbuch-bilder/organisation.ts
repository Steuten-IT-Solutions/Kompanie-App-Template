import { expect, type Locator } from '@playwright/test';
import type { Gruppe } from './fotograf';

// Fotos für das Handbuch, Teil „organisation“ (siehe gruppen.ts): Inventar, Listen, Anwesenheit.
// Beispieldaten: tests/standalone/handbuch-daten/50-organisation.php
const gruppe: Gruppe = async (f) => {
  const p = f.page;
  /** Element in die Mitte des Bildschirms holen (für ein Foto des ganzen Bildschirms): Ausschnitte, die höher sind als
   * der Bildschirm, zeigen sonst Kopfzeile und Tab-Leiste mitten im Bild. */
  const mitte = (l: Locator) => l.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  /** Hinweis unten („Gespeichert“ …) abwarten, damit er nicht im nächsten Foto steht. */
  const ohneHinweis = () => expect(p.locator('.toast')).toHaveCount(0, { timeout: 10_000 });

  // Anwesenheit des Mitglieds
  await f.anmelden('mitglied1');
  await f.oeffnen('meins/anwesenheit');
  await f.foto('meine-anwesenheit');

  await f.anmelden('admin');

  // Inventar: Liste mit aufgeklapptem Material, Anlegen, Umlagern
  await f.oeffnen('inventar');
  await p.locator('.inventory-group-head', { hasText: 'Biertischgarnitur' }).click();
  await f.foto('inventar');
  await p.locator('.inventory-add').click();
  const form = p.locator('.inventory-form').first();
  await form.getByLabel('Bezeichnung').fill('Getränkekiste');
  await form.getByLabel('Menge').fill('20');
  await form.getByLabel('Einheit').fill('Stück');
  await form.getByLabel('Ort').fill('Vereinsheim');
  await form.getByLabel('Kategorie (optional)').fill('Festbedarf');
  await form.getByLabel('Grund (optional, steht im Verlauf)').fill('Spende');
  await f.foto('inventar-anlegen', form);
  await form.getByRole('button', { name: 'Abbrechen' }).click();
  const entry = p.locator('.inventory-entry[data-location="Vereinsheim"]').first();
  await entry.getByRole('button', { name: 'Umlagern' }).click();
  const move = entry.locator('.inventory-move');
  await move.getByLabel('Menge').fill('4');
  await move.getByLabel('Zielort').fill('Garage Festplatz');
  await move.getByLabel('Grund (optional)').fill('Für das Sommerfest');
  await mitte(move);
  await f.foto('inventar-umlagern');

  // Verlauf: gesamt und eines Eintrags
  await f.oeffnen('inventar/verlauf');
  await f.foto('inventar-verlauf');
  await f.oeffnen('inventar');
  await p.locator('.inventory-group-head', { hasText: 'Becher' }).click();
  await p.locator('.inventory-entry[data-location="Vereinsheim"]').getByRole('button', { name: 'Verlauf' }).click();
  await p.locator('.inventory-log').waitFor();
  await f.foto('inventar-verlauf-eintrag');

  // Listen: Übersicht, neue Liste
  await f.oeffnen('verwaltung/listen');
  await f.foto('listen');
  const neu = p.locator('form.card', { has: p.getByRole('heading', { name: 'Neue Liste' }) });
  await neu.getByLabel('Titel').fill('Sommerfest – Helfer');
  await f.foto('listen-anlegen', neu);

  // Eine Liste bearbeiten
  await p.locator('.card.row', { hasText: 'Ausflug – Anmeldung' }).getByRole('button', { name: 'Öffnen' }).click();
  await p.locator('.list-editor').waitFor();
  await f.foto('liste-bearbeiten');
  const spalten = p.locator('section.card', { has: p.getByRole('heading', { name: 'Spalten' }) });
  await mitte(spalten.locator('.list-column', { hasText: 'Leere Spalte' }).first());
  await f.foto('liste-bearbeiten-spalten');
  await mitte(p.locator('section.list-output .list-preview'));
  await f.foto('liste-bearbeiten-ausgabe');

  // „Mitglieder aktualisieren“: In der Liste der Jahreshauptversammlung fehlt eine Zeile; danach wieder übernehmen.
  await f.oeffnen('verwaltung/listen');
  await p.locator('.card.row', { hasText: 'Jahreshauptversammlung' }).getByRole('button', { name: 'Öffnen' }).click();
  await p.locator('.list-editor').waitFor();
  await p.getByRole('button', { name: 'Zeile 1 entfernen' }).click();
  await p.getByRole('button', { name: 'Mitglieder aktualisieren' }).click();
  const sync = p.locator('.list-sync');
  await sync.waitFor();
  await ohneHinweis();
  await mitte(sync);
  await f.foto('liste-bearbeiten-zeilen');
  await sync.getByRole('button', { name: 'Übernehmen' }).click();
  await sync.waitFor({ state: 'detached' });
  await ohneHinweis();

  // Anwesenheit: Übersicht, freier Termin
  await f.oeffnen('verwaltung/anwesenheit');
  await f.foto('anwesenheit-erfassen');
  const frei = p.locator('form.free-event');
  await frei.getByLabel('Titel').fill('Grillabend');
  await frei.getByLabel('Titel').blur();
  await f.foto('anwesenheit-erfassen-frei', frei);

  // Eine Anwesenheitsliste: Übersicht (mit gebuchten Punkten), eintragen, Punkte und Einträge daraus
  await p.locator('.attendance-lists .card', { hasText: 'Übungsabend' }).click();
  await p.locator('.attendance-list').waitFor();
  await f.foto('anwesenheitsliste');
  await f.oeffnen('verwaltung/anwesenheit');
  await p.locator('.attendance-lists .card', { hasText: 'Arbeitsdienst Vereinsheim' }).click();
  await p.locator('.attendance-list').waitFor();
  await p.getByRole('radio', { name: 'Alex Admin: anwesend' }).check();
  await f.foto('anwesenheitsliste-eintragen', p.locator('.picker'));
  await mitte(p.locator('.attendance-book'));
  await f.foto('anwesenheitsliste-buchen');
};
export default gruppe;
