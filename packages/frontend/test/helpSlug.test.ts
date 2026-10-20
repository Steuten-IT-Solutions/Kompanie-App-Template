// Knopf „?“ (src/helpSlug.ts): jede Seite führt zu einer Hilfeseite, die es im Handbuch gibt.
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { test } from 'node:test';
import { helpSlug } from '../src/helpSlug.ts';

const pages = new Set(readdirSync(new URL('../../../docs/handbuch/', import.meta.url)).map((f) => f.replace(/\.md$/, '')));

test('Seiten führen zu vorhandenen Hilfeseiten', () => {
  const paths = [
    ['termine'],
    ['termine', 'verwalten'],
    ['termine', 'geburtstage'],
    ['dokumente'],
    ['dokumente', 'verwalten'],
    ['meins', 'punkte'],
    ['meins', 'strafen'],
    ['meins', 'dienstgrad'],
    ['meins', 'anwesenheit'],
    ['verwaltung'],
    ['verwaltung', 'katalog'],
    ['verwaltung', 'strafe'],
    ['verwaltung', 'befoerderungen'],
    ['verwaltung', 'titel'],
    ['verwaltung', 'anwesenheit'],
    ['brett'],
    ['umfragen'],
    ['umfragen', 'verwalten'],
    ['inventar'],
    ['konto'],
    ['benachrichtigungen'],
    ['installieren'],
    ['einrichtung'],
    ...['bereiche', 'rollen', 'google', 'kalender', 'galerie', 'news', 'benachrichtigungen', 'zustand', 'wartung', 'datensicherung'].map(
      (s) => ['einstellungen', s],
    ),
  ];
  for (const p of paths) {
    const slug = helpSlug(p, { boardWriter: false });
    assert.ok(slug && pages.has(slug), `${p.join('/')} → ${slug}`);
  }
  assert.equal(helpSlug(['brett'], { boardWriter: true }), 'schwarzes-brett-schreiben');
  assert.equal(helpSlug(['meins'], { boardWriter: false, meinsFirst: 'strafen' }), 'meine-strafen');
});

test('Ohne passende Seite das Verzeichnis', () => {
  assert.equal(helpSlug(['galerie'], { boardWriter: false }), null);
  assert.equal(helpSlug(['einstellungen'], { boardWriter: false }), null);
  assert.equal(helpSlug(['hilfe'], { boardWriter: false }), null);
});
