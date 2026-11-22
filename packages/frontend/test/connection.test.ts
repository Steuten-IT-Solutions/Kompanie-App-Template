// Hinweis „Schlechte Verbindung“ (src/connection.ts): gesetzt, solange der Service Worker gespeicherte Versionen liefert.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isStale, noteResponse } from '../src/connection.ts';

test('gekennzeichnete Antwort setzt den Hinweis, die nächste aus dem Netz nimmt ihn weg', () => {
  assert.equal(isStale(), false);
  noteResponse(new Headers({ 'X-Kompanie-Stale': '1' }));
  assert.equal(isStale(), true);
  noteResponse(new Headers({ 'Content-Type': 'application/json' }));
  assert.equal(isStale(), false);
});
