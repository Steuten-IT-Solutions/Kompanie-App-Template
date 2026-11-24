// Bezeichnungen der Kompanie in der Sprache der App (src/brandLocale.ts): Vorgaben übersetzt, eigene Namen bleiben.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { localizeBrand, type LocalizableBrand } from '../src/brandLocale.ts';
import { setCatalog, setLocale, type Catalog } from '../src/i18n.ts';
import en from '../../core/src/I18n/en.json' with { type: 'json' };

// In der App lädt boot.ts den Katalog der Sprache; hier gleich den englischen.
setCatalog('en', en as Catalog);

const doc = { documentElement: { lang: 'de' } };

function brand(extra: Partial<LocalizableBrand> = {}): LocalizableBrand {
  return {
    description: 'Die App der Musterkompanie',
    greeting: 'Hallo, {name}',
    guestWelcome: 'Willkommen!',
    labels: {
      roles: { guest: 'Gast', member: 'Mitglied', manager: 'Spieß', admin: 'Admin' },
      sections: { events: 'Termine', penalties: 'Kasse', titles: 'Titel', points: 'Punkte', catalog: 'Punktekatalog', intern: 'Intern' },
      rank: 'Dienstgrad',
    },
    ...extra,
  };
}

test('Auf Deutsch bleibt alles, wie es ist', () => {
  const b = brand();
  localizeBrand(b, 'de');
  assert.deepEqual(b, brand());
});

test('Auf Englisch: Vorgaben übersetzt, eigene Namen bleiben', () => {
  setLocale('en', doc);
  try {
    const b = brand();
    localizeBrand(b);
    assert.equal(b.labels.sections.events, 'Events');
    assert.equal(b.labels.sections.titles, 'Titles');
    assert.equal(b.labels.sections.penalties, 'Kasse');
    assert.equal(b.labels.sections.catalog, 'Points catalogue');
    assert.equal(b.labels.roles.member, 'Member');
    assert.equal(b.labels.roles.manager, 'Spieß');
    assert.equal(b.labels.rank, 'Rank');
    assert.equal(b.greeting, 'Hello, {name}');
    assert.equal(b.guestWelcome, 'Willkommen!');
  } finally {
    setLocale('de', doc);
  }
});

test('translations.en aus kompanie.json geht vor', () => {
  setLocale('en', doc);
  try {
    const b = brand({
      translations: {
        en: {
          greeting: 'Ahoy, {name}',
          guestWelcome: 'Welcome!',
          labels: { roles: { manager: 'Sergeant' }, sections: { penalties: 'Kitty', events: 'Dates' }, rank: 'Grade' },
        },
      },
    });
    b.labels.sections.points = 'Taler';
    b.labels.sections.catalog = 'Talerkatalog';
    localizeBrand(b);
    assert.equal(b.labels.roles.manager, 'Sergeant');
    assert.equal(b.labels.sections.penalties, 'Kitty');
    assert.equal(b.labels.sections.events, 'Dates');
    assert.equal(b.labels.sections.catalog, 'Taler catalogue');
    assert.equal(b.labels.rank, 'Grade');
    assert.equal(b.greeting, 'Ahoy, {name}');
    assert.equal(b.guestWelcome, 'Welcome!');
  } finally {
    setLocale('de', doc);
  }
});

test('Basissprache Englisch: eigene Namen sind englisch, translations.de übersetzt, Vorgaben auf Deutsch deutsch', () => {
  setLocale('de', doc);
  const own = brand({
    labels: { roles: { guest: 'Gast', member: 'Fellow' }, sections: { events: 'Termine', penalties: 'Fines' }, rank: 'Dienstgrad' },
    translations: { de: { labels: { sections: { penalties: 'Strafen' } } }, en: { labels: { sections: { penalties: 'Kasse' } } } },
  });
  localizeBrand(own, 'de', 'en');
  assert.equal(own.labels.sections.penalties, 'Strafen', 'Eintrag für Deutsch');
  assert.equal(own.labels.roles.member, 'Fellow', 'eigener Name ohne Eintrag bleibt');
  assert.equal(own.labels.sections.events, 'Termine');
  setLocale('en', doc);
  const b = brand({
    labels: { roles: { guest: 'Gast', member: 'Fellow' }, sections: { events: 'Termine', penalties: 'Fines' }, rank: 'Dienstgrad' },
    translations: { en: { labels: { sections: { penalties: 'Kasse' } } } },
  });
  localizeBrand(b, 'en', 'en');
  assert.equal(b.labels.sections.penalties, 'Fines', 'kein Eintrag für die Basissprache');
  assert.equal(b.labels.sections.events, 'Events', 'Vorgabe in der Basissprache übersetzt');
  assert.equal(b.labels.roles.guest, 'Guest');
  assert.equal(b.greeting, 'Hello, {name}');
  setLocale('de', doc);
});
