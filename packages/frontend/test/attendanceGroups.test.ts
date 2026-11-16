// Aufteilung nach Gruppen in der Anwesenheitsliste (src/attendanceGroups.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { showGroupCounts } from '../src/attendanceGroups.ts';

test('gespeicherte Liste am oder nach dem Tag des Termins zeigt die Aufteilung', () => {
  assert.equal(showGroupCounts({ dirty: false, pending: false, upcoming: false }), true);
});

test('kommender Termin zeigt keine Aufteilung', () => {
  assert.equal(showGroupCounts({ dirty: false, pending: false, upcoming: true }), false);
});

test('ungespeicherte oder nicht übertragene Änderungen zeigen keine Aufteilung', () => {
  assert.equal(showGroupCounts({ dirty: true, pending: false, upcoming: false }), false);
  assert.equal(showGroupCounts({ dirty: false, pending: true, upcoming: false }), false);
});
