// Einstellungen → Sprachen → Übersetzungen (src/translationOverview.ts): was fehlt, was die Suche findet.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isMissing, keyOf, matches } from '../src/translationOverview.ts';
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
