// Sicherungsdatei beim Wiederherstellen (src/backupFile.ts): verschlüsselt erkennen, als Base64 senden.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BACKUP_MAGIC, backupPayload, isEncryptedBackup, toBase64 } from '../src/backupFile.ts';

const enc = new TextEncoder();

test('verschlüsselte Sicherung am Anfang erkennen', () => {
  assert.equal(isEncryptedBackup(enc.encode(BACKUP_MAGIC + '{"v":1}')), true);
  assert.equal(isEncryptedBackup(enc.encode('{"format":"kompanie-app-backup"}')), false);
  assert.equal(isEncryptedBackup(enc.encode('KOMPANIE')), false);
});

test('Base64 auch für große Dateien, gleich wie Node', () => {
  const bytes = new Uint8Array(200_000).map((_, i) => (i * 31) % 256);
  assert.equal(toBase64(bytes), Buffer.from(bytes).toString('base64'));
  assert.equal(toBase64(new Uint8Array()), '');
});

test('Inhalt für die API', () => {
  assert.deepEqual(backupPayload(enc.encode('{"ä":1}')), { backup: '{"ä":1}' });
  const encrypted = enc.encode(BACKUP_MAGIC + 'x');
  assert.deepEqual(backupPayload(encrypted), { backup_base64: Buffer.from(encrypted).toString('base64') });
});
