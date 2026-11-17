// Neue Version erkennen und Neustart anbieten (src/appUpdate.ts): wie oft die App nachfragt, Hinweis an und aus.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  CHECK_AGAIN_MS,
  CHECK_OPEN_MS,
  dismissRestart,
  offerRestart,
  shouldCheck,
  subscribeUpdate,
  updateAvailable,
} from '../src/appUpdate.ts';

const NOW = 1_000_000_000;

test('Nachfragen höchstens alle 15 Minuten, auch nach zurückgestellter Uhr', () => {
  assert.equal(shouldCheck(NOW, NOW), false);
  assert.equal(shouldCheck(NOW - CHECK_AGAIN_MS + 1, NOW), false);
  assert.equal(shouldCheck(NOW - CHECK_AGAIN_MS, NOW), true);
  assert.equal(shouldCheck(NOW + 60_000, NOW), true, 'letzte Nachfrage in der Zukunft');
  // Bei offener App fragt der Zeitgeber seltener, als die Sperre erlaubt: Jede Runde fragt wirklich nach.
  assert.ok(CHECK_OPEN_MS >= CHECK_AGAIN_MS);
  assert.equal(shouldCheck(NOW - CHECK_OPEN_MS, NOW), true);
});

test('Hinweis: anbieten, „Später“ blendet aus, eine neuere Version zeigt ihn wieder', () => {
  let calls = 0;
  const stop = subscribeUpdate(() => calls++);
  assert.equal(updateAvailable(), false);
  offerRestart();
  assert.equal(updateAvailable(), true);
  dismissRestart();
  assert.equal(updateAvailable(), false);
  offerRestart();
  assert.equal(updateAvailable(), true);
  assert.equal(calls, 3);
  stop();
  dismissRestart();
  assert.equal(calls, 3, 'abgemeldet');
});
