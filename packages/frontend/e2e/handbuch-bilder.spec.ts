import { expect, test } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { DIR, fotograf } from './handbuch-bilder/fotograf';
import { GRUPPEN } from './handbuch-bilder/gruppen';

// Bildschirmfotos für die Anleitungen im Handbuch (docs/handbuch, ![…](bild:name)): je Bildschirm ein Überblick und
// Fotos wichtiger Schritte. Läuft gegen die eigenständige Version mit Beispieldaten (scripts/handbuch-bilder.sh), in
// Farben, Logo und Bezeichnungen der gebauten App. Am Ende muss es zu jedem Verweis ein Foto geben.

test.describe.configure({ mode: 'serial' });
// Handy-Format wie in der App (iPhone 13, 390 × 844), aber mit doppelter statt dreifacher Auflösung: scharf genug und
// deutlich kleiner.
test.use({ deviceScaleFactor: 2 });

test.beforeEach(async ({ page }) => {
  // Den Hinweis „App aufs Handy holen“ ausblenden: Er verdeckt sonst den Inhalt.
  await page.addInitScript(() => localStorage.setItem('kompanie-install-hint-dismissed', '1'));
});

for (const [name, gruppe] of Object.entries(GRUPPEN)) {
  test(`Bilder: ${name}`, async ({ page }) => {
    test.setTimeout(240_000);
    await gruppe(fotograf(page));
  });
}

test('Zu jedem Bild im Handbuch gibt es ein Foto', () => {
  const docs = new URL('../../../docs/handbuch/', import.meta.url);
  const wanted = new Set<string>();
  for (const file of readdirSync(docs).filter((f) => f.endsWith('.md'))) {
    for (const m of readFileSync(new URL(file, docs), 'utf8').matchAll(/\]\(bild:([a-z0-9-]+)\)/g)) wanted.add(m[1]);
  }
  const taken = new Set(readdirSync(DIR).map((f) => f.replace(/\.jpg$/, '')));
  expect([...wanted].filter((n) => !taken.has(n)).sort(), 'Fotos fehlen').toEqual([]);
  expect([...taken].filter((n) => !wanted.has(n)).sort(), 'Fotos ohne Verweis im Handbuch').toEqual([]);
});
