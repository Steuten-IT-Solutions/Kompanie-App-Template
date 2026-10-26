// Kennung eines Eintrags aus der Adresse (src/focus.ts, Issue #190): nur unbedenkliche Zeichen, sonst keine.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { focusId } from '../src/focus.ts';

test('Kennungen aus Kalender, öffentlichen Terminen und Datenbank', () => {
  assert.equal(focusId('0cc175b9c0f1b6a831c399e269772661'), '0cc175b9c0f1b6a831c399e269772661');
  assert.equal(focusId('p12'), 'p12');
  assert.equal(focusId('7'), '7');
});

test('fehlend oder mit anderen Zeichen: keine Kennung', () => {
  assert.equal(focusId(undefined), undefined);
  assert.equal(focusId(''), undefined);
  assert.equal(focusId('a"]'), undefined);
  assert.equal(focusId('a%2Fb'), undefined);
});
