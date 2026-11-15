// Allgemeine Warteschlange für offline gemachte Änderungen (src/offlineQueue.ts): je Bereich und Konto, Löschen beim Abmelden.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clearOfflineQueues, createOfflineQueue, failureAction, OFFLINE_PREFIX } from '../src/offlineQueue.ts';

function memory() {
  const items = new Map<string, string>();
  return {
    items,
    getItem: (k: string) => items.get(k) ?? null,
    setItem: (k: string, v: string) => void items.set(k, v),
    removeItem: (k: string) => void items.delete(k),
    key: (i: number) => [...items.keys()][i] ?? null,
    get length() {
      return items.size;
    },
  };
}

const numbers = (raw: unknown) => (typeof raw === 'number' ? raw : null);

test('Bereiche getrennt, ungültige Einträge verworfen', () => {
  const storage = memory();
  const a = createOfflineQueue('a', storage, () => 1, numbers);
  const b = createOfflineQueue('b', storage, () => 1, numbers);
  assert.equal(a.replace({ x: 1 }), true);
  b.replace({ y: 2 });
  assert.deepEqual(a.all(), { x: 1 });
  assert.deepEqual(b.all(), { y: 2 });
  storage.setItem(`${OFFLINE_PREFIX}a`, JSON.stringify({ user: 1, items: { x: 'kaputt', z: 3 } }));
  assert.deepEqual(a.all(), { z: 3 });
});

test('nur für das Konto, das sie abgelegt hat', () => {
  const storage = memory();
  let user = 1;
  const q = createOfflineQueue('a', storage, () => user, numbers);
  q.replace({ x: 1 });
  user = 2;
  assert.deepEqual(q.all(), {});
});

test('leer: Eintrag im Speicher entfernt; Abmelden löscht alle Bereiche, sonst nichts', () => {
  const storage = memory();
  const a = createOfflineQueue('a', storage, () => 1, numbers);
  a.replace({});
  assert.equal(storage.items.size, 0);
  a.replace({ x: 1 });
  createOfflineQueue('b', storage, () => 1, numbers).replace({ y: 2 });
  storage.setItem('kompanie-theme', 'dark');
  clearOfflineQueues(storage);
  assert.deepEqual([...storage.items.keys()], ['kompanie-theme']);
});

test('ohne Speicher', () => {
  const q = createOfflineQueue('a', null, () => 1, numbers);
  assert.equal(q.replace({ x: 1 }), false);
  assert.deepEqual(q.all(), {});
  clearOfflineQueues(null);
});

test('später erneut senden oder verwerfen', () => {
  for (const status of [0, 401, 408, 429, 500, 503]) assert.equal(failureAction(status), 'retry', String(status));
  for (const status of [400, 403, 404, 409, 422]) assert.equal(failureAction(status), 'drop', String(status));
});
