// Messung des Hochladens nach Google Drive in Worten (src/backupUploadStats.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { uploadStatsText } from '../src/backupUploadStats.ts';

test('nennt Größe, Stücke, Dauer, Rate und die Anteile', () => {
  const text = uploadStatsText({
    bytes: 50 * 1024 * 1024,
    chunks: 7,
    start_seconds: 0.312,
    read_seconds: 0.08,
    send_seconds: 40,
    slowest_seconds: 6.4,
    total_seconds: 40.6,
  });
  assert.equal(
    text,
    '50 MB in 7 Stücken, 41 s (10,5 Mbit/s) · Verbindung zu Google: 0,3 s · Lesen von der Platte: 0,1 s · langsamstes Stück: 6,4 s',
  );
});

test('ein Stück: ohne langsamstes Stück; ohne Sendezeit ohne Rate', () => {
  const text = uploadStatsText({
    bytes: 2048,
    chunks: 1,
    start_seconds: 0,
    read_seconds: 0,
    send_seconds: 0,
    slowest_seconds: 0,
    total_seconds: 0.2,
  });
  assert.equal(text, '2 KB in 1 Stück, 0,2 s · Verbindung zu Google: 0 s · Lesen von der Platte: 0 s');
});
