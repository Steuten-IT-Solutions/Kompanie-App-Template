// Offline gespeicherte Anwesenheit (src/attendanceOutbox.ts): Warteschlange je Liste und Konto, Anzeige, Umgang mit Fehlern.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { AttendanceDetail } from '../src/api.ts';
import { applyPending, createOutbox as outboxOn, OUTBOX_AREA, pendingCount, validEntry } from '../src/attendanceOutbox.ts';
import { createOfflineQueue, OFFLINE_PREFIX, type KeyValue } from '../src/offlineQueue.ts';

const OUTBOX_KEY = OFFLINE_PREFIX + OUTBOX_AREA;
const createOutbox = (storage: KeyValue | null, user: () => number) => outboxOn(createOfflineQueue(OUTBOX_AREA, storage, user, validEntry));

function memory() {
  const items = new Map<string, string>();
  return {
    items,
    getItem: (k: string) => items.get(k) ?? null,
    setItem: (k: string, v: string) => void items.set(k, v),
    removeItem: (k: string) => void items.delete(k),
  };
}

const list = (source: 'calendar' | 'free' = 'calendar'): AttendanceDetail => ({
  id: 7,
  source,
  title: 'Übungsabend',
  date: '2026-11-15',
  upcoming: false,
  counts: { present: 1, excused: 0, absent: 0 },
  event_key: source === 'calendar' ? 'i12' : null,
  updated_by_name: null,
  updated_at: '2026-11-15 18:00:00',
  people: [
    { id: 1, name: 'Anna', role: 'member', group: 'member', member: true, status: 'present', booked: false },
    { id: 2, name: 'Bernd', role: 'member', group: 'member', member: true, status: null, booked: false },
    { id: 3, name: 'Clara', role: 'member', group: 'member', member: true, status: null, booked: false },
  ],
  counts_by_group: { present: [], excused: [], absent: [] },
});

test('Änderungen bleiben auf dem Gerät und überstehen einen Neustart', () => {
  const storage = memory();
  assert.equal(createOutbox(storage, () => 5).add(7, { entries: { 2: 'present' }, label: 'Übungsabend' }, 100), true);
  const again = createOutbox(storage, () => 5);
  assert.deepEqual(again.ids(), [7]);
  assert.deepEqual(again.get(7), { entries: { 2: 'present' }, label: 'Übungsabend', at: 100 });
});

test('spätere Änderungen derselben Person gewinnen, andere Personen bleiben', () => {
  const box = createOutbox(memory(), () => 5);
  box.add(7, { entries: { 2: 'present', 3: 'absent' }, label: 'A' }, 1);
  box.add(7, { entries: { 3: 'excused', 1: null }, label: 'A' }, 2);
  assert.deepEqual(box.get(7)?.entries, { 1: null, 2: 'present', 3: 'excused' });
});

test('Listen in der Reihenfolge der letzten Änderung', () => {
  const box = createOutbox(memory(), () => 5);
  box.add(9, { entries: { 1: 'present' }, label: '' }, 20);
  box.add(7, { entries: { 1: 'present' }, label: '' }, 10);
  assert.deepEqual(box.ids(), [7, 9]);
});

test('ein anderes Konto sieht die Änderungen nicht', () => {
  const storage = memory();
  createOutbox(storage, () => 5).add(7, { entries: { 2: 'present' }, label: '' });
  const other = createOutbox(storage, () => 6);
  assert.deepEqual(other.ids(), []);
  assert.equal(other.get(7), null);
});

test('nach dem Senden bleibt nur, was inzwischen dazukam', () => {
  const box = createOutbox(memory(), () => 5);
  box.add(7, { entries: { 2: 'present' }, label: '' }, 1);
  const sent = box.get(7)!;
  // Während des Sendens geändert: Bernd jetzt „fehlt“, dazu Clara
  box.add(7, { entries: { 2: 'absent', 3: 'excused' }, label: '' }, 2);
  box.remove(7, sent);
  assert.deepEqual(box.get(7)?.entries, { 2: 'absent', 3: 'excused' });
  box.remove(7, box.get(7)!);
  assert.equal(box.get(7), null);
  assert.deepEqual(box.ids(), []);
});

test('leere Warteschlange entfernt den Eintrag im Speicher, discard und clear verwerfen', () => {
  const storage = memory();
  const box = createOutbox(storage, () => 5);
  box.add(7, { entries: { 2: 'present' }, label: '' });
  box.remove(7, box.get(7)!);
  assert.equal(storage.items.has(OUTBOX_KEY), false);
  box.add(7, { entries: { 2: 'present' }, label: '' });
  box.add(8, { entries: { 2: 'present' }, label: '' });
  box.discard(7);
  assert.deepEqual(box.ids(), [8]);
  box.clear();
  assert.equal(storage.items.has(OUTBOX_KEY), false);
});

test('Titel und Datum freier Listen', () => {
  const box = createOutbox(memory(), () => 5);
  box.add(7, { entries: {}, title: 'Arbeitsdienst', date: '2026-11-14', label: 'Arbeitsdienst' });
  const shown = applyPending(list('free'), box.get(7));
  assert.equal(shown.title, 'Arbeitsdienst');
  assert.equal(shown.date, '2026-11-14');
  // Listen aus dem Kalender behalten ihre Momentaufnahme
  assert.equal(applyPending(list('calendar'), box.get(7)).title, 'Übungsabend');
});

test('Anzeige mit offenen Änderungen: Status und Zähler', () => {
  const shown = applyPending(list(), { entries: { 1: null, 2: 'present', 3: 'excused' }, label: '', at: 0 });
  assert.deepEqual(
    shown.people.map((p) => p.status),
    [null, 'present', 'excused'],
  );
  assert.deepEqual(shown.counts, { present: 1, excused: 1, absent: 0 });
  const unchanged = list();
  assert.equal(applyPending(unchanged, null), unchanged);
  assert.equal(pendingCount({ entries: { 1: null, 2: 'present' }, label: '', at: 0 }), 2);
  assert.equal(pendingCount(null), 0);
});

test('ungültiger oder fremder Inhalt im Speicher wird ignoriert', () => {
  const storage = memory();
  storage.setItem(OUTBOX_KEY, '{kaputt');
  assert.deepEqual(createOutbox(storage, () => 5).ids(), []);
  storage.setItem(
    OUTBOX_KEY,
    JSON.stringify({ user: 5, items: { 7: { entries: { 2: 'gelb', x: 'present', 3: 'absent' } }, y: { entries: {} } } }),
  );
  const box = createOutbox(storage, () => 5);
  assert.deepEqual(box.ids(), [7]);
  assert.deepEqual(box.get(7)?.entries, { 3: 'absent' });
});

test('ohne Speicher (privater Modus) meldet add false', () => {
  const broken = {
    getItem: () => null,
    setItem: () => {
      throw new Error('voll');
    },
    removeItem: () => undefined,
  };
  assert.equal(createOutbox(broken, () => 5).add(7, { entries: { 1: 'present' }, label: '' }), false);
  assert.equal(createOutbox(null, () => 5).add(7, { entries: { 1: 'present' }, label: '' }), false);
});
