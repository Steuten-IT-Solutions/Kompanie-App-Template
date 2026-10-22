// Wischen in der Mitgliederansicht (src/swipe.ts): nur deutlich waagerechte Bewegungen blättern, am Ende der Liste ist Schluss.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { neighbors, swipeStep } from '../src/swipe.ts';

test('nach links wischen blättert weiter, nach rechts zurück', () => {
  assert.equal(swipeStep(-80, 5), 1);
  assert.equal(swipeStep(80, -5), -1);
});

test('kurze oder schräge Bewegungen zählen nicht', () => {
  assert.equal(swipeStep(-40, 0), 0);
  assert.equal(swipeStep(-100, 60), 0);
  assert.equal(swipeStep(0, 300), 0);
});

test('Nachbarn in der Reihenfolge der Liste', () => {
  assert.deepEqual(neighbors([7, 3, 9], 3), { prev: 7, next: 9, index: 1 });
  assert.deepEqual(neighbors([7, 3, 9], 7), { prev: null, next: 3, index: 0 });
  assert.deepEqual(neighbors([7, 3, 9], 9), { prev: 3, next: null, index: 2 });
  assert.deepEqual(neighbors([7, 3, 9], 5), { prev: null, next: null, index: -1 });
  assert.deepEqual(neighbors([], 5), { prev: null, next: null, index: -1 });
});
