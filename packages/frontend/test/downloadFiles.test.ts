// Herunterladen aus der Galerie (src/downloadFiles.ts): Dateinamen, doppelte Namen, welche Fassung geladen wird.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { GalleryPhoto } from '../src/api.ts';
import { albumFileName, downloadablePhotos, fetchPhoto, photoFileName, uniqueNames } from '../src/downloadFiles.ts';

const photo = (over: Partial<GalleryPhoto> = {}): GalleryPhoto => ({
  id: 1,
  type: 'image',
  thumb: '/t',
  large: '/l',
  original: '/o',
  width: 4,
  height: 3,
  intern: false,
  name: 'IMG_0001.HEIC',
  ready: true,
  ...over,
});

test('Dateiname: Name aus der Galerie mit .jpg, ungültige Zeichen ersetzt, sonst „Bild n“', () => {
  assert.equal(photoFileName({ name: 'IMG_0001.HEIC' }, 0), 'IMG_0001.jpg');
  assert.equal(photoFileName({ name: 'Fest: Umzug/1.jpeg' }, 0), 'Fest Umzug 1.jpg');
  assert.equal(photoFileName({ name: '' }, 4), 'Bild 5.jpg');
  assert.equal(albumFileName({ title: 'Schützenfest', year: '2026' }), 'Schützenfest 2026.zip');
  assert.equal(albumFileName({ title: '?', year: '' }), 'Album.zip');
});

test('Gleiche Namen im ZIP werden durchnummeriert (ohne Rücksicht auf Groß/klein)', () => {
  assert.deepEqual(uniqueNames(['a.jpg', 'A.jpg', 'a.jpg', 'b.jpg']), ['a.jpg', 'A (2).jpg', 'a (3).jpg', 'b.jpg']);
});

test('Videos lassen sich nicht herunterladen', () => {
  const list = [photo(), photo({ id: 2, type: 'video', video: '/v', original: undefined }), photo({ id: 3, type: undefined })];
  assert.deepEqual(
    downloadablePhotos(list).map((p) => p.id),
    [1, 3],
  );
});

test('Erst die Originalauflösung, sonst die 1600er; der Platzhalter (202) zählt nicht', async () => {
  const real = globalThis.fetch;
  const asked: string[] = [];
  const answer = (map: Record<string, [number, string]>) =>
    (async (url: string) => {
      asked.push(url);
      const [status, type] = map[url] ?? [404, 'text/plain'];
      return new Response(status === 200 || status === 202 ? 'x' : null, { status, headers: { 'content-type': type } });
    }) as typeof fetch;
  try {
    globalThis.fetch = answer({ '/o': [200, 'image/jpeg'] });
    assert.equal((await fetchPhoto(photo())).size, 1);
    assert.deepEqual(asked, ['/o']);

    asked.length = 0;
    globalThis.fetch = answer({ '/o': [404, 'text/plain'], '/l': [200, 'image/jpeg'] });
    await fetchPhoto(photo());
    assert.deepEqual(asked, ['/o', '/l']);

    globalThis.fetch = answer({ '/o': [202, 'image/svg+xml'], '/l': [202, 'image/svg+xml'] });
    await assert.rejects(fetchPhoto(photo()), /noch nicht fertig/);
  } finally {
    globalThis.fetch = real;
  }
});
