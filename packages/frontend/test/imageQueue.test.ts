// Bilder neben der Kette der Daten vorladen (src/imageQueue.ts): nacheinander, jedes einmal, eilige sofort.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createImageQueue } from '../src/imageQueue.ts';

function deferred() {
  let resolve!: () => void;
  const promise = new Promise<void>((r) => (resolve = r));
  return { promise, resolve };
}

const tick = () => new Promise((r) => setTimeout(r, 0));

test('Nacheinander: das nächste Bild erst, wenn das vorige fertig ist', async () => {
  const q = createImageQueue();
  const log: string[] = [];
  const a = deferred();
  void q.add('a', () => (log.push('a'), a.promise));
  const b = q.add('b', async () => void log.push('b'));
  await tick();
  assert.deepEqual(log, ['a']);
  a.resolve();
  await b;
  assert.deepEqual(log, ['a', 'b']);
});

test('Jedes Bild nur einmal, auch wenn es mehrfach angestellt wird', async () => {
  const q = createImageQueue();
  let loads = 0;
  await q.add('x', async () => void loads++);
  await q.add('x', async () => void loads++);
  await q.add('x', async () => void loads++, true);
  assert.equal(loads, 1);
});

test('Eilig: sofort, auch wenn die Schlange noch wartet; später angestellt nicht noch einmal', async () => {
  const q = createImageQueue();
  const log: string[] = [];
  const slow = deferred();
  void q.add('a', () => (log.push('a'), slow.promise));
  const queued = q.add('n', async () => void log.push('n-queued'));
  await tick();
  await q.add('n', async () => void log.push('n-urgent'), true);
  assert.deepEqual(log, ['a', 'n-urgent']);
  slow.resolve();
  await queued;
  assert.deepEqual(log, ['a', 'n-urgent']);
});

test('Fehler halten die Schlange nicht auf', async () => {
  const q = createImageQueue();
  const log: string[] = [];
  void q.add('kaputt', () => Promise.reject(new Error('404')));
  await q.add('ok', async () => void log.push('ok'));
  assert.deepEqual(log, ['ok']);
});
