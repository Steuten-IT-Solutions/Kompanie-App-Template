// Offline vorgemerkte Zu- und Absagen (src/responseOutbox.ts): Anzeige wie nach dem Senden, nichts vormerken, was dem
// Stand auf dem Server entspricht, und nur gültige Einträge aus dem Speicher.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyPendingResponse, pendingFor, responseKey, validResponse } from '../src/responseOutbox.ts';
import type { EventResponses } from '../src/api.ts';

const server = (mine: 'yes' | 'no' | null, yes = 3, no = 1, note = ''): EventResponses => ({
  mine,
  note,
  yes,
  no,
  until: '2026-12-01T00:00:00Z',
  open: true,
  can_respond: true,
  can_list: false,
});

test('vorgemerkte Absage statt Zusage: eigene Antwort und Zähler wie nach dem Senden', () => {
  const r = applyPendingResponse(server('yes'), { status: 'no', note: 'krank', label: 'Probe', at: 1 });
  assert.equal(r.mine, 'no');
  assert.equal(r.note, 'krank');
  assert.equal(r.yes, 2);
  assert.equal(r.no, 2);
});

test('vorgemerktes Zurücknehmen: keine eigene Antwort, Hinweis leer', () => {
  const r = applyPendingResponse(server('no', 3, 1, 'später'), { status: null, note: '', label: 'Probe', at: 1 });
  assert.equal(r.mine, null);
  assert.equal(r.note, '');
  assert.equal(r.no, 0);
  assert.equal(r.yes, 3);
});

test('ohne Vormerkung bleibt der Stand vom Server', () => {
  const s = server('yes');
  assert.equal(applyPendingResponse(s, null), s);
});

test('nichts vormerken, was dem Server entspricht (offline zugesagt und wieder zurückgenommen)', () => {
  assert.equal(pendingFor(server(null), null, '', 'Probe', 5), null);
  assert.equal(pendingFor(server('yes', 3, 1, 'a'), 'yes', 'a', 'Probe', 5), null);
  assert.deepEqual(pendingFor(server('yes', 3, 1, 'a'), 'yes', 'b', 'Probe', 5), { status: 'yes', note: 'b', label: 'Probe', at: 5 });
  assert.deepEqual(pendingFor(server('yes'), null, 'x', 'Probe', 5), { status: null, note: '', label: 'Probe', at: 5 });
});

test('aus dem Speicher nur gültige Einträge', () => {
  assert.deepEqual(validResponse({ status: 'no', note: '', label: 'P', at: 2 }), { status: 'no', note: '', label: 'P', at: 2 });
  assert.deepEqual(validResponse({ status: null, note: '', label: 'P', at: 2 }), { status: null, note: '', label: 'P', at: 2 });
  assert.equal(validResponse({ status: 'vielleicht', note: '', label: 'P', at: 2 }), null);
  assert.equal(validResponse({ status: 'yes', label: 'P', at: 2 }), null);
  assert.equal(validResponse(null), null);
});

test('stabile Kennung des Termins, bei älteren Servern die id', () => {
  assert.equal(responseKey({ id: 'abc', responses: { ...server(null), key: 'stabil' } }), 'stabil');
  assert.equal(responseKey({ id: 'abc', responses: server(null) }), 'abc');
  assert.equal(responseKey({ id: 'abc' }), 'abc');
});
