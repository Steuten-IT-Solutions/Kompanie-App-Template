// Seite als Link teilen und nach der Anmeldung dorthin zurück (src/share.ts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { rememberRoute, shareUrl, takeRememberedRoute } from '../src/share.ts';

const store = new Map<string, string>();
globalThis.sessionStorage = {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
} as Storage;

test('Link zur angezeigten Seite, mit Unterseite', () => {
  assert.equal(shareUrl('https://x.de/app/', 'news', 'news', ['news', 'abc-1']), 'https://x.de/app/#/news/abc-1');
  assert.equal(shareUrl('https://x.de/app/', 'galerie', 'galerie', ['galerie']), 'https://x.de/app/#/galerie');
  assert.equal(shareUrl('https://x.de/app/#/alt', 'termine', 'termine', ['termine']), 'https://x.de/app/#/termine');
});

test('Rückfall auf eine andere Ansicht: ohne fremde Unterseite', () => {
  assert.equal(shareUrl('https://x.de/app/', 'termine', 'dokumente', ['dokumente', '5']), 'https://x.de/app/#/termine');
  assert.equal(shareUrl('https://x.de/app/', 'termine', undefined, []), 'https://x.de/app/#/termine');
});

test('Gemerkte Seite gilt einmal', () => {
  rememberRoute('#/dokumente/5');
  assert.equal(takeRememberedRoute(), '#/dokumente/5');
  assert.equal(takeRememberedRoute(), null);
});

test('Anmeldeseite und leere Adresse werden nicht gemerkt', () => {
  rememberRoute('#/dokumente/5');
  rememberRoute('#/anmelden');
  assert.equal(takeRememberedRoute(), null);
  rememberRoute('');
  assert.equal(takeRememberedRoute(), null);
  rememberRoute('#/news');
  assert.equal(takeRememberedRoute(), '#/news');
});
