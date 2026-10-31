// Neu laden für eine geänderte App-Seite (src/pageReload.ts): nur kurz nach dem Start, nicht in der Vorschau, höchstens
// dreimal je Minute.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { RELOAD_AGAIN_MS, RELOAD_MAX, RELOAD_WINDOW_MS, recentReloads, shouldReload } from '../src/pageReload.ts';

const NOW = 1_000_000_000;

test('Nur neue Tokens: kein Neuladen', () => {
  assert.equal(shouldReload({ reload: false }, 500, [], NOW, false), false);
});

test('Mehr geändert: kurz nach dem Start neu laden, später nicht (dann beim nächsten Öffnen)', () => {
  assert.equal(shouldReload({ reload: true }, 500, [], NOW, false), true);
  assert.equal(shouldReload({ reload: true }, RELOAD_WINDOW_MS, [], NOW, false), true);
  assert.equal(shouldReload({ reload: true }, RELOAD_WINDOW_MS + 1, [], NOW, false), false);
});

test('In der Vorschau nie neu laden', () => {
  assert.equal(shouldReload({ reload: true }, 500, [], NOW, true), false);
});

test('Mehrere Änderungen kurz hintereinander kommen an, aber keine Schleife', () => {
  const two = [NOW - 20_000, NOW - 1000];
  assert.equal(shouldReload({ reload: true }, 500, two, NOW, false), true, 'zweite und dritte Änderung');
  const full = Array.from({ length: RELOAD_MAX }, (_, i) => NOW - 1000 * (i + 1));
  assert.equal(shouldReload({ reload: true }, 500, full, NOW, false), false, 'dann nicht mehr');
  // Ältere zählen nicht mehr, ebenso Zeitpunkte aus der Zukunft (Uhr zurückgestellt).
  const old = full.map((t) => t - RELOAD_AGAIN_MS);
  assert.equal(shouldReload({ reload: true }, 500, old, NOW, false), true);
  assert.deepEqual(recentReloads([NOW + 5000, NOW - 10], NOW), [NOW - 10]);
});
