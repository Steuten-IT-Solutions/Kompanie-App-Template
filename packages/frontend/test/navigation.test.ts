// Einrichtbare Navigation (src/navigation.ts, Issue #277): Standard wie bisher, eigene Einstellung, Entwurf bearbeiten.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  buildNav,
  draftError,
  draftOf,
  menuOf,
  moveTo,
  NAV_TARGETS,
  placeOf,
  removeMenu,
  unplaced,
  type NavContext,
  type NavTarget,
} from '../src/navigation.ts';

const ctx = (visible: NavTarget[], intern = false): NavContext => ({
  visible: (t) => visible.includes(t),
  intern,
  label: (t) => t.toUpperCase(),
});
const keys = (e: { key: string }[]) => e.map((x) => x.key);

test('Standard mit dem Recht Intern: Termine, News, Galerie, Kompanien und Intern mit seinen Bereichen', () => {
  const nav = buildNav(null, ctx([...NAV_TARGETS], true));
  assert.deepEqual(keys(nav), ['termine', 'news', 'galerie', 'kompanien', 'intern']);
  // Höchstens fünf Hauptpunkte: Sponsoren rutscht vorne ins Untermenü
  assert.deepEqual(nav[4].children, ['sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog']);
  const noCompanies = buildNav(
    null,
    ctx(
      NAV_TARGETS.filter((t) => t !== 'kompanien'),
      true,
    ),
  );
  assert.deepEqual(keys(noCompanies), ['termine', 'news', 'galerie', 'sponsoren', 'intern']);
  assert.equal(menuOf(nav, 'dokumente')?.key, 'intern');
  assert.equal(menuOf(nav, 'termine'), undefined);
});

test('Standard ohne das Recht Intern: Mitglieder und Dokumente als eigene Tabs, der Rest nicht', () => {
  assert.deepEqual(keys(buildNav(null, ctx([...NAV_TARGETS]))), [
    'termine',
    'news',
    'galerie',
    'kompanien',
    'sponsoren',
    'mitglieder',
    'dokumente',
  ]);
  assert.deepEqual(keys(buildNav(undefined, ctx(['mitglieder', 'brett']))), ['mitglieder'], 'nur Sichtbares');
  assert.deepEqual(keys(buildNav(null, ctx(['termine'], true))), ['termine'], 'Intern ohne sichtbaren Bereich entfällt');
});

test('Eigene Navigation: Reihenfolge, Untermenüs, ausgeblendete und neue Bereiche', () => {
  const setting = {
    items: [
      'galerie',
      { label: 'Verein', icon: 'stern', items: ['mitglieder', 'brett'] },
      { label: 'Leer', icon: 'ordner', items: ['dienstgrade'] },
      'termine',
    ],
    hidden: ['news'],
  };
  const nav = buildNav(setting, ctx(['galerie', 'mitglieder', 'termine', 'news', 'dokumente', 'punktekatalog']));
  // Das zweite Untermenü hat keinen sichtbaren Bereich und entfällt; Adressen zählen trotzdem alle Untermenüs.
  assert.deepEqual(keys(nav), ['galerie', 'menue/1', 'termine', 'dokumente', 'punktekatalog']);
  assert.deepEqual(nav[1], { key: 'menue/1', label: 'Verein', icon: 'stern', children: ['mitglieder'] });
  assert.equal(menuOf(nav, 'mitglieder')?.key, 'menue/1');
  assert.ok(!keys(nav).includes('news'), 'ausgeblendet');
});

test('Entwurf: Standard als Ausgangspunkt, verschieben, Untermenü entfernen, prüfen', () => {
  assert.deepEqual(draftOf(null).items.slice(0, 4), ['termine', 'news', 'galerie', 'kompanien']);
  assert.deepEqual((draftOf(null).items[4] as { items: string[] }).items[0], 'sponsoren', 'passt nicht unten hin: ins Untermenü');
  assert.deepEqual(unplaced(draftOf(null)), []);
  assert.equal(draftError(draftOf(null)), null);
  // Kompanien und Sponsoren aus: nicht im Entwurf, belegen keinen Hauptpunkt
  let d = draftOf(null, (t) => t !== 'sponsoren' && t !== 'kompanien');
  assert.deepEqual(unplaced(d), ['kompanien', 'sponsoren']);
  assert.equal(placeOf(d, 'dokumente'), 0);
  d = moveTo(d, 'dokumente', 'main');
  assert.equal(placeOf(d, 'dokumente'), 'main');
  assert.equal(draftError(d), null);
  d = moveTo(d, 'news', 'hidden');
  assert.equal(placeOf(d, 'news'), 'hidden');
  d = moveTo(d, 'news', 0);
  assert.equal(placeOf(d, 'news'), 0);
  d = removeMenu(d, 0);
  assert.deepEqual(d.items, ['termine', 'galerie', 'dokumente']);
  assert.deepEqual(d.hidden.sort(), ['brett', 'dienstgrade', 'mitglieder', 'news', 'punktekatalog']);
  d = { ...d, items: [...d.items, 'news', 'brett', 'mitglieder'] };
  assert.match(draftError(d) ?? '', /höchstens 5/);
  assert.match(draftError({ items: [{ label: ' ', icon: 'ordner', items: ['news'] }], hidden: [] }) ?? '', /Namen/);
  assert.match(draftError({ items: [{ label: 'A', icon: 'ordner', items: [] }], hidden: [] }) ?? '', /mindestens einen/);
});

test('Entwurf aus einer Einstellung ist eine Kopie', () => {
  const setting = { items: [{ label: 'A', icon: 'ordner', items: ['news'] }], hidden: [] as string[] };
  const d = draftOf(setting);
  moveTo(d, 'termine', 0);
  (d.items[0] as { items: string[] }).items.push('galerie');
  assert.deepEqual(setting.items[0].items, ['news']);
  assert.deepEqual(unplaced(setting).length, NAV_TARGETS.length - 1);
});
