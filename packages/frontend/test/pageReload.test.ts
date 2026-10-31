// Neu laden für eine geänderte App-Seite (src/pageReload.ts): nur kurz nach dem Start, nicht in der Vorschau, höchstens
// einmal je Minute.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { RELOAD_AGAIN_MS, RELOAD_WINDOW_MS, shouldReload } from '../src/pageReload.ts';

const NOW = 1_000_000_000;

test('Nur neue Tokens: kein Neuladen', () => {
  assert.equal(shouldReload({ reload: false }, 500, null, NOW, false), false);
});

test('Mehr geändert: kurz nach dem Start neu laden, später nicht (dann beim nächsten Öffnen)', () => {
  assert.equal(shouldReload({ reload: true }, 500, null, NOW, false), true);
  assert.equal(shouldReload({ reload: true }, RELOAD_WINDOW_MS, null, NOW, false), true);
  assert.equal(shouldReload({ reload: true }, RELOAD_WINDOW_MS + 1, null, NOW, false), false);
});

test('In der Vorschau nie neu laden', () => {
  assert.equal(shouldReload({ reload: true }, 500, null, NOW, true), false);
});

test('Höchstens einmal je Minute, auch wenn die Uhr zurückspringt', () => {
  assert.equal(shouldReload({ reload: true }, 500, NOW - 1000, NOW, false), false);
  assert.equal(shouldReload({ reload: true }, 500, NOW - RELOAD_AGAIN_MS - 1, NOW, false), true);
  assert.equal(shouldReload({ reload: true }, 500, NOW + 5000, NOW, false), true);
});
