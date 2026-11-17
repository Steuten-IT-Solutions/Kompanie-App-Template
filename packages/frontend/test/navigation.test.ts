// Einrichtbare Navigation (src/navigation.ts, Issue #277): Standard wie bisher, eigene Einstellung, Entwurf bearbeiten.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  buildNav,
  draftError,
  draftOf,
  guestDraftOf,
  internOrderOf,
  isGuestView,
  navigationFor,
  isPageTarget,
  mergeOrder,
  menuOf,
  moveTo,
  NAV_TARGETS,
  orderBy,
  pageAllowedForGuests,
  pageTarget,
  placeOf,
  removeMenu,
  startChoices,
  startOf,
  tabbarClass,
  targetAllowed,
  unplaced,
  viewPath,
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
  assert.equal(draftError(d), null, 'beliebig viele Hauptpunkte (Issue #362)');
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

test('Eigene Seiten (Issue #287): jede Seite ein eigener Punkt, im Standard nach den Bereichen, unbekannte hinten', () => {
  const pages = [pageTarget(7), pageTarget(3)];
  const visible: NavTarget[] = ['termine', 'news', pageTarget(7), pageTarget(3), 'mitglieder'];
  const nav = buildNav(null, { ...ctx(visible), pages });
  assert.deepEqual(keys(nav), ['termine', 'news', 'seiten/7', 'seiten/3', 'mitglieder']);
  assert.equal(nav[2].icon, 'seiten');
  // Mit „Intern“: was unten nicht passt, kommt vorne ins Untermenü
  const intern = buildNav(null, {
    ...ctx(['termine', 'news', 'galerie', 'kompanien', pageTarget(7), pageTarget(3), 'mitglieder'], true),
    pages,
  });
  assert.deepEqual(keys(intern), ['termine', 'news', 'galerie', 'kompanien', 'intern']);
  assert.deepEqual(intern[4].children, ['seiten/7', 'seiten/3', 'mitglieder']);
  // Eigene Navigation: Seite in einem Untermenü, eine nicht lesbare fällt weg, eine neue hängt hinten an
  const setting = { items: ['termine', { label: 'Infos', icon: 'seiten', items: ['seiten/3', 'seiten/9'] }], hidden: [] as string[] };
  const own = buildNav(setting, { ...ctx(visible), pages });
  assert.deepEqual(keys(own), ['termine', 'menue/1', 'news', 'mitglieder', 'seiten/7']);
  assert.deepEqual(own[1].children, ['seiten/3']);
  assert.equal(menuOf(own, 'seiten/3')?.key, 'menue/1');
  assert.deepEqual(unplaced(setting, pages), [...NAV_TARGETS.filter((t) => t !== 'termine'), 'seiten/7']);
  assert.equal(placeOf(moveTo(draftOf(setting, undefined, pages), 'seiten/7', 0), 'seiten/7'), 0);
  assert.ok(isPageTarget('seiten/12') && !isPageTarget('seiten') && !isPageTarget('seiten/0') && !isPageTarget('seiten/1/2'));
});

test('Eigene Symbole und Name von „Intern“ (Issue #362)', () => {
  const nav = buildNav(null, { ...ctx([...NAV_TARGETS], true), icons: { galerie: 'kamera', intern: 'fahne' }, internLabel: 'Vorstand' });
  assert.equal(nav.find((e) => e.key === 'galerie')?.icon, 'kamera');
  assert.equal(nav.find((e) => e.key === 'termine')?.icon, 'termine', 'ohne eigenes das feste Symbol');
  const intern = nav.find((e) => e.key === 'intern');
  assert.deepEqual([intern?.label, intern?.icon], ['Vorstand', 'fahne']);
  assert.equal((draftOf(null, () => true, [], 'Vorstand').items.at(-1) as { label: string }).label, 'Vorstand');
});

test('Startseite: eingestellt, wenn sie in der Leiste steht, sonst der erste Punkt (Issue #362)', () => {
  const tabs = buildNav(
    { items: ['termine', { label: 'Mehr', icon: 'ordner', items: ['galerie', 'news'] }], hidden: [] },
    ctx(['termine', 'galerie']),
  );
  assert.equal(startOf(tabs, 'galerie'), 'galerie', 'Bereich in einem Untermenü');
  assert.equal(startOf(tabs, 'menue/1'), 'menue/1');
  assert.equal(startOf(tabs, 'news'), 'termine', 'darf die Person nicht sehen: erster Punkt');
  assert.equal(startOf(tabs, undefined), 'termine');
  assert.equal(startOf([], 'galerie'), undefined);
});

test('Startseite: eine eigene Seite öffnet die Seite, nicht die Übersicht der Seiten', () => {
  const tabs = buildNav({ items: [pageTarget(5), 'termine'], hidden: [] }, { ...ctx(['termine']), visible: () => true });
  const start = startOf(tabs, undefined) ?? '';
  assert.equal(start, 'seiten/5', 'erster Punkt der Navigation');
  assert.deepEqual(viewPath([], false, start), ['seiten', '5']);
  assert.deepEqual(viewPath([], false, startOf(tabs, 'seiten/5') ?? ''), ['seiten', '5'], 'als Startseite gewählt');
  assert.deepEqual(viewPath(['seiten'], true, start), ['seiten'], 'Aufruf von #/seiten bleibt die Übersicht');
  assert.deepEqual(viewPath(['termine'], true, start), ['termine']);
});

test('Startseite: Auswahl aus der Navigation, „Meins“ nur für Angemeldete', () => {
  const draft = { items: ['termine', { label: 'Mehr', icon: 'ordner', items: ['galerie'] }], hidden: ['news'] };
  const guest = startChoices(draft, false);
  assert.ok(guest.includes('menue/1') && guest.includes('galerie') && guest.includes('termine'));
  assert.ok(!guest.includes('news'), 'ausgeblendet');
  assert.ok(!guest.includes('meins'));
  assert.ok(startChoices(draft, true).includes('meins'));
  assert.ok(startChoices(null, false).includes('intern'), 'Standardnavigation: Intern');
});

test('Tab-Leiste: Stufen nach Zahl der Tabs, auf Wunsch nur Symbole', () => {
  assert.equal(tabbarClass(7, true), 'tabbar');
  assert.equal(tabbarClass(8, true), 'tabbar tabs-8');
  assert.equal(tabbarClass(11, true), 'tabbar tabs-8 tabs-10');
  assert.equal(tabbarClass(14, false), 'tabbar tabs-8 tabs-10 tabs-12 tabbar-icons');
});

test('Reihenfolge: eingestellte Folge, Unbekanntes hinter seinem Vorgänger', () => {
  assert.deepEqual(orderBy(['a', 'b', 'c', 'd'], ['c', 'a']), ['c', 'd', 'a', 'b'], 'b hinter a, d hinter c');
  assert.deepEqual(orderBy(['a', 'b', 'c'], ['x', 'c']), ['a', 'b', 'c'], 'ohne Vorgänger vorn, a und b vor c');
  assert.deepEqual(orderBy(['a', 'b'], undefined), ['a', 'b']);
  assert.deepEqual(mergeOrder(['a', 'x', 'b', 'c'], ['c', 'a', 'b']), ['c', 'x', 'a', 'b'], 'x behält seinen Platz');
});

test('„Intern“ der Standardnavigation in eigener Reihenfolge', () => {
  const visible: NavTarget[] = ['termine', 'news', 'galerie', 'mitglieder', 'dokumente', 'brett'];
  const nav = buildNav(null, { ...ctx(visible, true), internOrder: ['brett', 'mitglieder'] });
  assert.deepEqual(nav.at(-1)?.children, ['brett', 'mitglieder', 'dokumente']);
  const draft = draftOf(null, () => true, [], 'Intern', ['punktekatalog', 'sponsoren']);
  const menu = draft.items.at(-1);
  assert.equal(typeof menu === 'object' && menu.items[0], 'punktekatalog', 'Entwurf übernimmt die Reihenfolge');
});

test('Nur „Intern“ umgeordnet: es bleibt bei der Standardnavigation', () => {
  const initial = draftOf(null);
  assert.deepEqual(internOrderOf(initial, initial), [...(initial.items.at(-1) as { items: string[] }).items]);
  const menu = initial.items.at(-1) as { label: string; icon: string; items: string[] };
  const sorted = { ...initial, items: [...initial.items.slice(0, -1), { ...menu, items: [...menu.items].reverse() }] };
  assert.deepEqual(internOrderOf(initial, sorted), [...menu.items].reverse());
  const moved = moveTo(initial, 'termine', 0);
  assert.equal(internOrderOf(initial, moved), null, 'anderer Platz: eigene Navigation');
  const renamed = { ...initial, items: [...initial.items.slice(0, -1), { ...menu, label: 'Mehr' }] };
  assert.equal(internOrderOf(initial, renamed), null, 'anderer Name: eigene Navigation');
});

test('Leiste für Gäste: ohne Anmeldung und mit der Grundrolle Gast, sonst die der Angemeldeten', () => {
  assert.equal(isGuestView(null), true);
  assert.equal(isGuestView(undefined), true);
  assert.equal(isGuestView({ level: 'guest' }), true, 'auch angemeldet, z. B. ehemalige Mitglieder und die Vorschau als Gast');
  assert.equal(isGuestView({ level: 'member' }), false);
  assert.equal(isGuestView({ level: 'admin' }), false);

  const member = { items: ['termine', { label: 'Intern', icon: 'ordner', items: ['mitglieder'] }], hidden: [] };
  const guest = { items: ['mitglieder', 'termine'], hidden: [] };
  assert.equal(navigationFor({ navigation: member, navigation_guest: guest }, true), guest);
  assert.equal(navigationFor({ navigation: member, navigation_guest: guest }, false), member);
  assert.equal(navigationFor({ navigation: member, navigation_guest: null }, true), member, 'ohne eigene: wie Angemeldete');
  assert.equal(navigationFor({ navigation: null, navigation_guest: guest }, false), null, 'Angemeldete mit Standardnavigation');
  assert.equal(navigationFor({ navigation: null }, true), null);
  assert.equal(navigationFor(null, true), null);
});

test('Erster Entwurf für Gäste: Mitglieder und Dokumente aus Untermenüs nach unten, leere Untermenüs fallen weg', () => {
  const member = {
    items: [
      'termine',
      { label: 'Intern', icon: 'ordner', items: ['mitglieder', 'dokumente'] },
      { label: 'Mehr', icon: 'stern', items: ['brett'] },
    ],
    hidden: ['punktekatalog'],
  };
  assert.deepEqual(guestDraftOf(member), {
    items: ['termine', { label: 'Mehr', icon: 'stern', items: ['brett'] }, 'mitglieder', 'dokumente'],
    hidden: ['punktekatalog'],
  });
  assert.deepEqual(member.items[1], { label: 'Intern', icon: 'ordner', items: ['mitglieder', 'dokumente'] }, 'Vorlage unverändert');
  // Ausgeblendetes bleibt ausgeblendet, unten Stehendes bleibt, wo es ist.
  assert.deepEqual(guestDraftOf({ items: ['mitglieder', 'termine'], hidden: ['dokumente'] }), {
    items: ['mitglieder', 'termine'],
    hidden: ['dokumente'],
  });
  // Aus der Standardnavigation
  assert.deepEqual(guestDraftOf(draftOf(null)), {
    items: [
      'termine',
      'news',
      'galerie',
      'kompanien',
      { label: 'Intern', icon: 'ordner', items: ['sponsoren', 'brett', 'dienstgrade', 'punktekatalog'] },
      'mitglieder',
      'dokumente',
    ],
    hidden: [],
  });
});

test('targetAllowed: Bereich an und Recht, wie die Tab-Leiste und Einstellungen → Navigation es prüfen', () => {
  const caps =
    (...c: string[]) =>
    (cap: string) =>
      c.includes(cap);
  const on =
    (...f: string[]) =>
    (feature: string) =>
      f.includes(feature);
  const all = () => true;
  // Mit allen Rechten zählt nur, ob ein Bereich an ist.
  assert.equal(targetAllowed('kompanien', all, on()), false);
  assert.equal(targetAllowed('kompanien', all, on('companies')), true);
  assert.equal(targetAllowed('mitglieder', all, on('offices')), true);
  assert.equal(targetAllowed('termine', all, on('birthdays')), true);
  // Rechte der Rolle Gast: Dokumente und Punktekatalog nur mit ihrem Recht, das Brett nur mit view_board.
  const gast = caps('view_events', 'view_news', 'view_gallery', 'view_directory');
  const allOn = () => true;
  assert.deepEqual(
    NAV_TARGETS.filter((t) => targetAllowed(t, gast, allOn)),
    ['termine', 'news', 'galerie', 'mitglieder'],
  );
  assert.equal(targetAllowed('dokumente', caps('manage_documents'), allOn), true);
  assert.equal(targetAllowed('brett', caps('post_board'), allOn), false);
  assert.equal(targetAllowed('galerie', caps('view_gallery_internal'), allOn), true);
  assert.equal(targetAllowed('termine', caps('calendar'), on('events')), false);
});

test('pageAllowedForGuests: öffentliche Seiten alle, Seiten für Mitglieder nur mit view_board oder manage_pages', () => {
  const none = () => false;
  assert.equal(pageAllowedForGuests('public', none), true);
  assert.equal(pageAllowedForGuests('members', none), false);
  assert.equal(
    pageAllowedForGuests('members', (c) => c === 'view_board'),
    true,
  );
  assert.equal(pageAllowedForGuests(undefined, none), false);
});

test('mergeOrder: auch mit Untermenüs (Objekten) ordnet es nur die gezeigten Punkte, die übrigen bleiben an ihrem Platz', () => {
  const menu = { label: 'Verein', icon: 'ordner', items: ['brett'] };
  const full = ['termine', 'kompanien', menu, 'news'];
  // kompanien ist aus und wird nicht gezeigt; der Admin zieht news nach vorn.
  assert.deepEqual(mergeOrder(full, ['news', 'termine', menu]), ['news', 'kompanien', 'termine', menu]);
});
