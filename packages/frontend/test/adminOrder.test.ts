// Reihenfolge der Kacheln in der Verwaltung (src/navigation.ts): je Gruppe, neue Kacheln an ihrem Standardplatz.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { ADMIN_SETUP, ADMIN_START, flatOrder, orderToSave, setupOrder, sortByIds, startOrder } from '../src/navigation.ts';

const ids = (t: { id: string }[]) => t.map((x) => x.id);

test('Ohne Einstellung: Standardreihenfolge, die Folge im Code bleibt', () => {
  assert.deepEqual(startOrder(undefined), [...ADMIN_START]);
  assert.deepEqual(setupOrder([]), [...ADMIN_SETUP], 'leer kommt vom Server als []');
  assert.equal(flatOrder({}), null);
  const tabs = [{ id: 'katalog' }, { id: 'mitglieder' }];
  assert.equal(sortByIds(tabs, flatOrder(undefined)), tabs);
});

test('Eingestellte Reihenfolge je Gruppe, Einrichtungen an der Stelle ihres Sammelknopfs', () => {
  const order = { start: ['einstellungen', 'einrichtungen', 'mitglieder'], setup: ['seiten', 'katalog'] };
  assert.deepEqual(startOrder(order).slice(0, 3), ['einstellungen', 'einrichtungen', 'mitglieder']);
  assert.deepEqual(setupOrder(order).slice(0, 3), ['seiten', 'katalog', 'dienstgrade']);
  const flat = flatOrder(order) ?? [];
  assert.deepEqual(flat.slice(0, 4), ['einstellungen', 'seiten', 'katalog', 'dienstgrade']);
  const tabs = [{ id: 'mitglieder' }, { id: 'katalog' }, { id: 'mail' }, { id: 'einstellungen' }];
  assert.deepEqual(ids(sortByIds(tabs, flat)), ['einstellungen', 'katalog', 'mitglieder', 'mail']);
});

test('Kacheln, die die Einstellung nicht kennt, bleiben hinter ihrem Vorgänger', () => {
  // Gespeichert vor einem Update ohne „ehemalige“, „mail“ nach vorn geholt: „ehemalige“ steht hinter „mitglieder“.
  const saved = ['mail', ...ADMIN_START.filter((id) => id !== 'ehemalige' && id !== 'mail')];
  const start = startOrder({ start: saved });
  assert.deepEqual(start.slice(0, 4), ['mail', 'mitglieder', 'ehemalige', 'anfragen']);
  assert.equal(new Set(start).size, ADMIN_START.length, 'jede Kachel genau einmal');
});

test('Speichern nur, was von der Standardreihenfolge abweicht', () => {
  assert.deepEqual(orderToSave([...ADMIN_START], [...ADMIN_SETUP]), {});
  const setup = [...ADMIN_SETUP].reverse();
  assert.deepEqual(orderToSave([...ADMIN_START], setup), { setup });
});

test('Kacheln wie im Kern (NavigationService::ADMIN_START, ADMIN_SETUP)', () => {
  const php = readFileSync(new URL('../../core/src/Domain/NavigationService.php', import.meta.url), 'utf8');
  const list = (name: string) =>
    [...(new RegExp(`const ${name} = \\[([^\\]]*)\\]`).exec(php)?.[1] ?? '').matchAll(/'([a-z]+)'/g)].map((m) => m[1]);
  assert.deepEqual(list('ADMIN_START'), [...ADMIN_START]);
  assert.deepEqual(list('ADMIN_SETUP'), [...ADMIN_SETUP]);
});
