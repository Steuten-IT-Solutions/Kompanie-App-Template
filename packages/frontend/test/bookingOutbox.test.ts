// Offline gebuchte Punkte und Strafen (src/bookingOutbox.ts): nur gültige Einträge aus dem Speicher, Kennung der Buchung
// passt zum Schlüssel, neue Kennungen sind eindeutig und vom Server angenommen (8–36 Zeichen, Buchstaben, Ziffern, -).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { newRequestId, validBooking } from '../src/bookingOutbox.ts';

const ok = {
  kind: 'points',
  body: { request_id: 'abcd-1234', user_ids: [1, 2], points: 3, reason: 'Arbeitsdienst' },
  label: '3 Punkte',
  at: 5,
};

test('gültige Buchung bleibt erhalten, mit allen Angaben der Anfrage', () => {
  assert.deepEqual(validBooking(ok, 'abcd-1234'), ok);
  assert.deepEqual(validBooking({ ...ok, kind: 'penalty', label: undefined }, 'abcd-1234'), { ...ok, kind: 'penalty', label: '' });
});

test('kaputte oder fremde Einträge werden verworfen', () => {
  assert.equal(validBooking(null, 'abcd-1234'), null);
  assert.equal(validBooking({ ...ok, kind: 'status' }, 'abcd-1234'), null);
  assert.equal(validBooking(ok, 'anderer-key'), null, 'Schlüssel und Kennung gehören zusammen');
  assert.equal(validBooking({ ...ok, body: { ...ok.body, request_id: 'kurz' } }, 'kurz'), null);
  assert.equal(validBooking({ ...ok, body: { ...ok.body, user_ids: [] } }, 'abcd-1234'), null);
  assert.equal(validBooking({ ...ok, body: { ...ok.body, user_ids: ['1'] } }, 'abcd-1234'), null);
  assert.equal(validBooking({ ...ok, at: 'gestern' }, 'abcd-1234'), null);
});

test('neue Kennungen: Format des Servers, auch ohne crypto.randomUUID, und verschieden', () => {
  const pattern = /^[A-Za-z0-9-]{8,36}$/;
  assert.match(newRequestId(), pattern);
  let n = 0;
  const fallback = newRequestId(() => (n++ % 16) / 16, null);
  assert.match(fallback, pattern);
  assert.equal(fallback.length, 36);
  const ids = new Set(Array.from({ length: 50 }, () => newRequestId()));
  assert.equal(ids.size, 50);
});
