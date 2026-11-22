// Einstellungen → Sprachen → Übersetzungen (src/translationOverview.ts): was fehlt, was die Suche findet.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contentRequests, isMissing, isName, keyOf, matches } from '../src/translationOverview.ts';
import type { TranslationItem } from '../src/api.ts';

const item = (over: Partial<TranslationItem> = {}): TranslationItem => ({
  kind: 'rank',
  ref: '4',
  field: 'label',
  source: 'Feldwebel',
  text: '',
  fallback: 'Feldwebel',
  max: 60,
  ...over,
});

test('fehlt nur, wenn nichts eingetragen ist und Leser sonst den Text der Basissprache sehen', () => {
  assert.equal(isMissing(item()), true);
  assert.equal(isMissing(item({ text: 'Sergeant' })), false);
  assert.equal(isMissing(item(), '  '), true, 'nur Leerzeichen zählen nicht');
  assert.equal(isMissing(item(), 'Sergeant'), false, 'gerade getippt');
  // Feste Rolle: ohne Eintrag gilt die übersetzte Vorgabe
  assert.equal(isMissing(item({ kind: 'role', ref: 'guest', source: 'Gast', fallback: 'Guest' })), false);
});

test('Suche in Basissprache, Übersetzung und Vorgabe, ohne Groß- und Kleinschreibung', () => {
  assert.equal(matches(item(), ''), true);
  assert.equal(matches(item(), 'feld'), true);
  assert.equal(matches(item({ text: 'Sergeant' }), 'serg'), true);
  assert.equal(matches(item(), 'serg', 'Sergeant'), true, 'gerade getippt');
  assert.equal(matches(item({ source: 'Gast', fallback: 'Guest' }), 'guest'), true);
  assert.equal(matches(item(), 'kapitän'), false);
});

test('Kennung je Art, Eintrag und Feld', () => {
  assert.notEqual(keyOf(item()), keyOf(item({ kind: 'office' })));
  assert.equal(keyOf(item()), keyOf(item({ text: 'x' })));
});

test('Inhalte: je Inhalt eine Anfrage mit allen Feldern, Antworten als Liste', () => {
  const poll = (field: string, source: string, text = ''): TranslationItem =>
    item({ kind: 'poll', ref: '7', field, source, fallback: source, text, context: 'Grillfest?' });
  const all = [
    item({ kind: 'nav', ref: 'termine', source: 'Termine', fallback: 'Events' }),
    poll('title', 'Grillfest?', 'Barbecue?'),
    poll('option:0', 'Ja', 'Yes'),
    poll('option:1', 'Nein'),
    item({ kind: 'document_category', ref: '3', field: 'title', source: 'Protokolle', fallback: 'Protokolle' }),
  ];
  const edits: Record<string, string> = { [keyOf(all[3])]: 'No', [keyOf(all[0])]: 'Dates' };
  const reqs = contentRequests(all, (i) => edits[keyOf(i)] ?? i.text);
  assert.equal(reqs.length, 1, 'eigene Namen und Unverändertes nicht');
  assert.deepEqual(reqs[0].fields, { title: 'Barbecue?', options: ['Yes', 'No'] });
  assert.equal(reqs[0].kind, 'poll');
  assert.equal(reqs[0].keys.length, 3);

  // Alle Antworten geleert: leere Liste; Kategorie mit deutschem Namen
  const cleared = contentRequests(all, (i) => (i.field.startsWith('option:') ? '' : i.kind === 'document_category' ? 'Minutes' : i.text));
  assert.deepEqual(cleared[0].fields.options, []);
  assert.deepEqual(cleared[1], {
    kind: 'document_category',
    id: '3',
    title: 'Protokolle',
    fields: { title: 'Minutes' },
    keys: [keyOf(all[4])],
  });
  assert.equal(isName(all[0]), true);
  assert.equal(isName(all[1]), false);
  assert.equal(matches(all[1], 'grill'), true, 'Suche auch im Titel des Inhalts');
});
