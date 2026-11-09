// Verweise im Changelog ([Text](app:einstellungen/rollen), scripts/changelog.php): Jedes Ziel ist eine Seite der App.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { helpSlug, type HelpContext } from '../src/helpSlug.ts';

const changelog = readFileSync(new URL('../../../CHANGELOG.md', import.meta.url), 'utf8');
const targets = [...new Set([...changelog.matchAll(/\]\(app:([^)\s]*)\)/g)].map((m) => m[1]))];

/** Admin mit allen Rechten und allen Bereichen: Er sieht jede Seite, auf die ein Verweis zeigen kann. */
const admin: HelpContext = { boardWriter: true, can: () => true, features: new Proxy({}, { get: () => true }) };

/**
 * Gibt es die Seite? Jede Seite hat eine eigene Hilfeseite (helpSlug). Unbekannte Ansichten und Seiten der Verwaltung
 * liefern null, unbekannte Seiten der Einstellungen die Hilfe der Übersicht.
 */
function isAppPage(path: string[]): boolean {
  const slug = helpSlug(path, admin);
  if (slug === null) return false;
  return !(path[0] === 'einstellungen' && path.length > 1 && slug === helpSlug(['einstellungen'], admin));
}

test('Jeder Verweis im Changelog führt auf eine Seite, die es in der App gibt', () => {
  assert.ok(targets.length > 0, 'keine Verweise app:… gefunden');
  for (const target of targets) assert.ok(isAppPage(target.split('/')), `app:${target} ist keine Seite der App`);
});

test('Verweise auf Seiten, die es nicht gibt, werden erkannt', () => {
  for (const p of [['gibtsnicht'], ['einstellungen', 'gibtsnicht'], ['verwaltung', 'gibtsnicht']]) assert.ok(!isAppPage(p), p.join('/'));
  for (const p of [['einstellungen'], ['einstellungen', 'rollen'], ['verwaltung', 'aemter']]) assert.ok(isAppPage(p), p.join('/'));
});
