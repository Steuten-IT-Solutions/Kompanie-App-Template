// Jede Kachel der Verwaltung hat ein Symbol (src/adminNav.tsx): sonst steht dort ein leerer Kreis.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const src = readFileSync(new URL('../src/adminNav.tsx', import.meta.url), 'utf8');

test('Symbol für jede Kachel aus adminTileCatalog', () => {
  const catalog = src.slice(src.indexOf('export function adminTileCatalog'), src.indexOf('export function setupTabs'));
  const tiles = [...catalog.matchAll(/^\s+(\w+): \{ label/gm)].map((m) => m[1]);
  const icons = new Set(
    [...src.slice(src.indexOf('const ADMIN_ICONS'), src.indexOf('export function AdminIcon')).matchAll(/^ {2}(\w+):/gm)].map((m) => m[1]),
  );
  assert.ok(tiles.length > 20, 'Kacheln gefunden');
  assert.deepEqual(
    tiles.filter((t) => !icons.has(t)),
    [],
  );
});
