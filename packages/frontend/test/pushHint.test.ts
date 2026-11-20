// Hinweis „Benachrichtigungen einschalten“ auf der Startseite (src/pushHint.ts): nur, solange der Browser noch nicht
// nach der Erlaubnis gefragt hat, und nicht für eine Weile nach „Nicht jetzt“.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  PUSH_HINT_KEY,
  PUSH_HINT_PAUSE,
  PUSH_HINT_ROUND,
  PUSH_HINT_ROUND_KEY,
  pushHintDue,
  pushHintLater,
  pushHintRoundDone,
  pushHintRoundPending,
} from '../src/pushHint.ts';

function memory(roundDone = true) {
  const data = new Map<string, string>();
  if (roundDone) data.set(PUSH_HINT_ROUND_KEY, String(PUSH_HINT_ROUND));
  return { data, getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => void data.set(k, v) };
}

test('nur, solange der Browser noch nicht gefragt hat', () => {
  const s = memory();
  assert.equal(pushHintDue('default', 1000, s), true);
  assert.equal(pushHintDue('granted', 1000, s), false);
  assert.equal(pushHintDue('denied', 1000, s), false);
});

test('„Nicht jetzt“ blendet ihn für eine Weile aus', () => {
  const s = memory();
  pushHintLater(1000, s);
  assert.equal(s.data.get(PUSH_HINT_KEY), '1000');
  assert.equal(pushHintDue('default', 1000 + PUSH_HINT_PAUSE - 1, s), false);
  assert.equal(pushHintDue('default', 1000 + PUSH_HINT_PAUSE, s), true);
});

test('unsinnige Werte und fehlender Speicher zeigen ihn', () => {
  const s = memory();
  s.setItem(PUSH_HINT_KEY, 'kaputt');
  assert.equal(pushHintDue('default', 1000, s), true);
  s.setItem(PUSH_HINT_KEY, '5000');
  assert.equal(pushHintDue('default', 1000, s), true, 'Zeit in der Zukunft (Uhr verstellt)');
  assert.equal(pushHintDue('default', 1000, null), true);
});

test('neue Runde: einmal für alle, auch nach „Nicht jetzt“ oder früher erteilter Erlaubnis, nie bei Blockieren', () => {
  const s = memory(false);
  pushHintLater(1000, s);
  assert.equal(pushHintRoundPending(s), true);
  assert.equal(pushHintDue('default', 2000, s), true, 'trotz „Nicht jetzt“');
  assert.equal(pushHintDue('granted', 2000, s), true, 'Erlaubnis da, aber ausgeschaltet');
  assert.equal(pushHintDue('denied', 2000, s), false);
  pushHintRoundDone(s);
  assert.equal(pushHintRoundPending(s), false);
  assert.equal(pushHintDue('default', 2000, s), false, 'danach gilt wieder die Pause');
  assert.equal(pushHintDue('granted', 2000, s), false);
  s.setItem(PUSH_HINT_ROUND_KEY, String(PUSH_HINT_ROUND - 1));
  assert.equal(pushHintRoundPending(s), true, 'ältere Runde');
  assert.equal(pushHintRoundPending(null), false, 'ohne Speicher nicht bei jedem Start');
});
