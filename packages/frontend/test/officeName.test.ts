// Bezeichnung eines Amts für eine einzelne Amtszeit (src/officeName.ts): Einzahl, sonst die Bezeichnung.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { officeSingular } from '../src/officeName.ts';

test('Einzahl des Amts, sonst seine Bezeichnung', () => {
  assert.equal(officeSingular({ title: 'Prinzen', holder_title: 'Prinz' }), 'Prinz');
  assert.equal(officeSingular({ title: 'Prinz', holder_title: null }), 'Prinz');
  assert.equal(officeSingular({ title: 'Kapitäne', holder_title: '' }), 'Kapitäne');
  assert.equal(officeSingular({ title: 'Kapitäne' }), 'Kapitäne', 'ältere Server ohne holder_title');
});
