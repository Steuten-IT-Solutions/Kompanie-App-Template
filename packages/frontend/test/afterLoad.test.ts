// Nach „load“ ausführen, auch wenn die Seite schon fertig geladen ist (src/afterLoad.ts, App startet nachgeladen).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { afterLoad } from '../src/afterLoad.ts';

test('Seite schon geladen: läuft trotzdem (gleich danach)', async () => {
  let ran = 0;
  afterLoad(() => ran++, { readyState: 'complete' }, { addEventListener: () => assert.fail('kein Listener nötig') });
  assert.equal(ran, 0, 'nicht mitten im Aufruf');
  await new Promise((resolve) => setTimeout(resolve, 5));
  assert.equal(ran, 1);
});

test('Seite lädt noch: läuft beim Ereignis load, einmal', () => {
  let ran = 0;
  let listener: (() => void) | null = null;
  const win = {
    addEventListener: (type: string, fn: () => void, opts?: { once?: boolean }) => {
      assert.equal(type, 'load');
      assert.equal(opts?.once, true);
      listener = fn;
    },
  } as unknown as Pick<Window, 'addEventListener'>;
  afterLoad(() => ran++, { readyState: 'interactive' }, win);
  assert.equal(ran, 0);
  listener!();
  assert.equal(ran, 1);
});
