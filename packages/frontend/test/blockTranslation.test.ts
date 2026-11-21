// Fassungen von Blöcken in einer weiteren Sprache (src/blocks/blockTranslation.ts): was gesendet wird, was fehlt.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { missingCount, translatableFields, translationInput } from '../src/blocks/blockTranslation.ts';
import type { BlockDraft } from '../src/blocks/blockDrafts.ts';

const german = [
  { id: 'a1', type: 'text', audience: 'all', html: '<p>Hallo</p>' },
  { id: 'a2', type: 'notice', audience: 'all', title: '', text: 'Parken am Markt', tone: 'info' },
  {
    id: 'a3',
    type: 'facts',
    audience: 'all',
    title: '',
    items: [
      { value: '1848', label: 'gegründet' },
      { value: '250', label: 'Mitglieder' },
    ],
  },
  { id: 'a4', type: 'divider', audience: 'all', style: 'line' },
] as unknown as BlockDraft[];

test('übersetzbar ist nur, was es auf Deutsch gibt', () => {
  assert.deepEqual(translatableFields(german[1]), ['text']);
  assert.deepEqual(translatableFields(german[3]), []);
  assert.deepEqual(translatableFields({ id: 'x', type: 'text', audience: 'all', html: '<p></p>' } as BlockDraft), []);
});

test('leere Felder fallen weg, Listen werden mit den deutschen Teilen ergänzt', () => {
  const out = translationInput(german, {
    a1: { html: '<p></p>' },
    a2: { text: ' Park at the market ', tone: 'warning' },
    a3: { items: [{ label: 'founded' }] },
    zz: { html: '<p>?</p>' },
  });
  assert.deepEqual(out, {
    a2: { text: 'Park at the market' },
    a3: {
      items: [
        { value: '1848', label: 'founded' },
        { value: '250', label: 'Mitglieder' },
      ],
    },
  });
  assert.equal(missingCount(german, {}), 3);
  assert.equal(missingCount(german, { a1: { html: '<p>Hello</p>' }, a2: { text: 'Park' } }), 1);
});
