// Wischen in der Mitgliederansicht (src/swipe.ts): weit oder schnell genug gezogen blättert; ohne Nachbarn ist Schluss,
// als Karussell (wrap) geht es nach dem Ende wieder von vorn los.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dragOffset, neighbors, prefetchOrder, releaseStep } from '../src/swipe.ts';

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

test('Vorladen von der aktuellen Stelle nach außen, abwechselnd, bis die ganze Liste dran war', () => {
  assert.deepEqual(prefetchOrder([1, 2, 3, 4, 5, 6], 2), [4, 2, 5, 1, 6]);
  assert.deepEqual(prefetchOrder([1, 2, 3, 4], 0), [2, 3, 4]);
  assert.deepEqual(prefetchOrder([1, 2, 3, 4], 3), [3, 2, 1]);
  assert.deepEqual(prefetchOrder([1], 0), []);
  assert.deepEqual(prefetchOrder([1, 2], -1), []);
});

test('Karussell: Nach dem letzten kommt der erste, vor dem ersten der letzte', () => {
  assert.deepEqual(neighbors([7, 3, 9], 3, true), { prev: 7, next: 9, index: 1 });
  assert.deepEqual(neighbors([7, 3, 9], 7, true), { prev: 9, next: 3, index: 0 });
  assert.deepEqual(neighbors([7, 3, 9], 9, true), { prev: 3, next: 7, index: 2 });
  // Zwei Einträge: Der andere ist Vorgänger und Nachfolger zugleich.
  assert.deepEqual(neighbors([7, 3], 7, true), { prev: 3, next: 3, index: 0 });
  // Allein oder nicht in der Liste: keine Nachbarn.
  assert.deepEqual(neighbors([7], 7, true), { prev: null, next: null, index: 0 });
  assert.deepEqual(neighbors([7, 3, 9], 5, true), { prev: null, next: null, index: -1 });
});

test('Karussell: Vorladen nach außen über das Ende hinaus, jeder Eintrag einmal', () => {
  assert.deepEqual(prefetchOrder([1, 2, 3, 4, 5, 6], 0, true), [2, 6, 3, 5, 4]);
  assert.deepEqual(prefetchOrder([1, 2, 3, 4, 5], 4, true), [1, 4, 2, 3]);
  assert.deepEqual(prefetchOrder([1, 2], 1, true), [1]);
  assert.deepEqual(prefetchOrder([1], 0, true), []);
  assert.deepEqual(prefetchOrder([1, 2], -1, true), []);
});
