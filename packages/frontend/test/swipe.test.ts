// Wischen in der Mitgliederansicht (src/swipe.ts): weit oder schnell genug gezogen blättert, am Ende der Liste ist Schluss.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dragOffset, neighbors, releaseStep } from '../src/swipe.ts';

test('weit genug gezogen: nach links weiter, nach rechts zurück', () => {
  // Breite 400: ab einem Viertel (100 px)
  assert.equal(releaseStep(-120, 0, 400, true, true), 1);
  assert.equal(releaseStep(120, 0, 400, true, true), -1);
  // Schmale Karte: mindestens 60 px
  assert.equal(releaseStep(-50, 0, 160, true, true), 0);
  assert.equal(releaseStep(-60, 0, 160, true, true), 1);
});

test('zu kurz gezogen: Die Karte schnappt zurück', () => {
  assert.equal(releaseStep(-80, 0, 400, true, true), 0);
  assert.equal(releaseStep(0, 0, 400, true, true), 0);
});

test('schnell gewischt blättert auch mit kürzerem Weg, aber nicht gegen die Richtung', () => {
  assert.equal(releaseStep(-50, -0.8, 400, true, true), 1);
  assert.equal(releaseStep(50, 0.8, 400, true, true), -1);
  assert.equal(releaseStep(-50, 0.8, 400, true, true), 0);
  assert.equal(releaseStep(-30, -2, 400, true, true), 0);
});

test('am Anfang und Ende der Liste geht es nicht weiter', () => {
  assert.equal(releaseStep(-300, -1, 400, true, false), 0);
  assert.equal(releaseStep(300, 1, 400, false, true), 0);
});

test('ohne Nachbarn folgt die Karte dem Finger nur gebremst', () => {
  assert.equal(dragOffset(-100, true, true), -100);
  assert.equal(dragOffset(-100, true, false), -30);
  assert.equal(dragOffset(100, false, true), 30);
  assert.equal(dragOffset(100, true, false), 100);
});

test('Nachbarn in der Reihenfolge der Liste', () => {
  assert.deepEqual(neighbors([7, 3, 9], 3), { prev: 7, next: 9, index: 1 });
  assert.deepEqual(neighbors([7, 3, 9], 7), { prev: null, next: 3, index: 0 });
  assert.deepEqual(neighbors([7, 3, 9], 9), { prev: 3, next: null, index: 2 });
  assert.deepEqual(neighbors([7, 3, 9], 5), { prev: null, next: null, index: -1 });
  assert.deepEqual(neighbors([], 5), { prev: null, next: null, index: -1 });
});
