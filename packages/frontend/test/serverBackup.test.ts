// Sicherungen auf dem Server (src/serverBackup.ts): Adresse zum Herunterladen, Beschriftung in der Liste.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { serverBackupLabel, serverBackupUrl } from '../src/serverBackup.ts';

test('Adresse mit schönen Pfaden und mit index.php?r=…', () => {
  const id = 'sicherung-20261111-120000-' + 'a'.repeat(32) + '.json';
  assert.equal(serverBackupUrl('https://example.org/app/sicherung', id), `https://example.org/app/sicherung?datei=${id}`);
  assert.equal(serverBackupUrl('https://example.org/index.php?r=/sicherung', id), `https://example.org/index.php?r=/sicherung&datei=${id}`);
  assert.equal(serverBackupUrl('https://example.org/app/sicherung', 'a b&c'), 'https://example.org/app/sicherung?datei=a%20b%26c');
});

test('Beschriftung: Art, Version vor Updates, Größe in MB', () => {
  const created = Date.UTC(2026, 10, 11, 12, 0) / 1000;
  const weekly = serverBackupLabel({ id: 'x', kind: 'weekly', created, size: 3 * 1024 * 1024, version: null });
  assert.match(weekly.title, /^Wöchentliche Sicherung vom /);
  assert.match(weekly.title, /2026/);
  assert.equal(weekly.sub, '3,0 MB');
  const update = serverBackupLabel({ id: 'y', kind: 'update', created, size: 1536 * 1024, version: '0.9.0.1' });
  assert.match(update.title, /^Sicherung vor dem Update vom /);
  assert.equal(update.sub, 'Version 0.9.0.1 · 1,5 MB');
  assert.equal(serverBackupLabel({ id: 'z', kind: 'update', created, size: 0, version: null }).sub, '0,0 MB');
});
