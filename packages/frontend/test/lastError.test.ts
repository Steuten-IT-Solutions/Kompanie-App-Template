// Letzte Fehlermeldung und Ausgangsseite für „Fehler melden“ (src/lastError.ts, Issue #64).
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { test } from 'node:test';
import { forgetError, lastError, noteError, reportOrigin, resetLastError, setReportOrigin } from '../src/lastError.ts';

test('Merkt nur die letzte Meldung, einzeilig und gekürzt', () => {
  resetLastError();
  assert.equal(lastError(), null);
  noteError('Erster Fehler');
  noteError('  Zweiter\n  Fehler  ');
  assert.equal(lastError()?.message, 'Zweiter Fehler');
  noteError('   ');
  assert.equal(lastError()?.message, 'Zweiter Fehler', 'leere Meldungen zählen nicht');
  noteError('x'.repeat(2000));
  assert.equal(lastError()?.message.length, 500);
});

test('Ausgangsseite der Hilfe', () => {
  resetLastError();
  assert.equal(reportOrigin(), null);
  setReportOrigin('#/termine/verwalten');
  assert.equal(reportOrigin(), '#/termine/verwalten');
});

test('Keine Handbuchseite heißt wie das Formular (#/hilfe/melden)', () => {
  const pages = readdirSync(new URL('../../../docs/handbuch/', import.meta.url)).map((f) => f.replace(/\.md$/, ''));
  assert.ok(!pages.includes('melden'));
});

test('forgetError: vergisst nur die eigene Meldung, eine neuere bleibt', () => {
  resetLastError();
  noteError('A');
  const b = noteError('B');
  forgetError(b);
  assert.equal(lastError()?.message, 'A');
  const c = noteError('C');
  noteError('D');
  forgetError(c);
  assert.equal(lastError()?.message, 'D');
  forgetError(null);
  assert.equal(lastError()?.message, 'D');
});
