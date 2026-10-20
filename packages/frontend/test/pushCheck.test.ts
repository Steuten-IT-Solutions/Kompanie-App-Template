// Nachfrage zum Push-Abo beim Start (src/pushCheck.ts, Issue #18): nicht bei jedem Start, nur nach einer Änderung von
// Push-Adresse oder Konto und sonst einmal je Woche.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { PUSH_CHECK_MAX_AGE, pushCheckDone, pushCheckDue, pushFingerprint } from '../src/pushCheck.ts';

function memory(): Pick<Storage, 'getItem' | 'setItem'> & { data: Map<string, string> } {
  const data = new Map<string, string>();
  return {
    data,
    getItem: (k) => data.get(k) ?? null,
    setItem: (k, v) => {
      data.set(k, v);
    },
  };
}

const endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

test('Erster Start fragt, ein weiterer mit gleichem Gerät und Konto nicht', () => {
  const s = memory();
  const f = pushFingerprint(endpoint, 7);
  assert.equal(pushCheckDue(f, 1000, s), true);
  pushCheckDone(f, 1000, s);
  assert.equal(pushCheckDue(f, 2000, s), false);
  assert.ok(![...s.data.values()].some((v) => v.includes('fcm.googleapis.com')), 'die Adresse selbst wird nicht gespeichert');
});

test('Anderes Konto (z. B. Sitzung abgelaufen) oder neue Push-Adresse: wieder fragen', () => {
  const s = memory();
  pushCheckDone(pushFingerprint(endpoint, 7), 1000, s);
  assert.equal(pushCheckDue(pushFingerprint(endpoint, null), 2000, s), true, 'jetzt Gast');
  assert.equal(pushCheckDue(pushFingerprint(endpoint, 8), 2000, s), true, 'anderes Konto');
  assert.equal(pushCheckDue(pushFingerprint(endpoint + 'x', 7), 2000, s), true, 'neues Abo');
});

test('Nach einer Woche wieder fragen (Gast-Abo bleibt aktiv), ohne Speicher immer', () => {
  const s = memory();
  const f = pushFingerprint(endpoint, null);
  pushCheckDone(f, 1000, s);
  assert.equal(pushCheckDue(f, 1000 + PUSH_CHECK_MAX_AGE - 1, s), false);
  assert.equal(pushCheckDue(f, 1000 + PUSH_CHECK_MAX_AGE, s), true);
  assert.equal(pushCheckDue(f, 500, s), true, 'Uhr zurückgestellt');
  assert.equal(pushCheckDue(f, 2000, null), true);
  s.data.set('kompanie-push-check', '{kaputt');
  assert.equal(pushCheckDue(f, 2000, s), true);
});
