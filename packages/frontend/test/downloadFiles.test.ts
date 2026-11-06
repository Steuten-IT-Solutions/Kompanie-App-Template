// Herunterladen aus der Galerie (src/downloadFiles.ts): Dateinamen, doppelte Namen, welche Fassung geladen wird.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { GalleryPhoto } from '../src/api.ts';
import { unzipSync } from 'fflate';
import {
  albumFileName,
  crc32,
  downloadablePhotos,
  fetchPhoto,
  fetchVideo,
  mediaFileName,
  photoFileName,
  storedZip,
  uniqueNames,
  videoFileName,
} from '../src/downloadFiles.ts';

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

test('Bilder und Videos lassen sich herunterladen; Videos behalten ihre Endung', () => {
  const list = [
    photo(),
    photo({ id: 2, type: 'video', video: '/v', original: undefined }),
    photo({ id: 3, type: undefined }),
    photo({ id: 4, type: 'video', video: undefined }),
  ];
  assert.deepEqual(
    downloadablePhotos(list).map((p) => p.id),
    [1, 2, 3],
  );
  assert.equal(videoFileName({ name: 'Umzug.MP4', mime: 'video/mp4' }, 0), 'Umzug.MP4');
  assert.equal(videoFileName({ name: 'Umzug', mime: 'video/webm' }, 0), 'Umzug.webm');
  assert.equal(videoFileName({ name: '', mime: undefined }, 2), 'Video 3.mp4');
  assert.equal(mediaFileName(list[1], 1), 'IMG_0001.HEIC', 'Video: Name unverändert');
  assert.equal(mediaFileName(list[0], 0), 'IMG_0001.jpg');
});

test('Video: Stück für Stück mit Range, bis es vollständig ist; 503 wird erneut versucht', async () => {
  const real = globalThis.fetch;
  const data = new Uint8Array(10).map((_, i) => i);
  const ranges: string[] = [];
  let busy = 1;
  globalThis.fetch = (async (_url: string, init?: RequestInit) => {
    const range = new Headers(init?.headers).get('range') ?? '';
    ranges.push(range);
    if (busy-- > 0) return new Response(null, { status: 503 });
    const from = Number(/bytes=(\d+)-/.exec(range)![1]);
    const to = Math.min(from + 3, data.length - 1); // Ausschnitte von 4 Bytes wie VIDEO_CHUNK
    return new Response(data.slice(from, to + 1), { status: 206, headers: { 'content-range': `bytes ${from}-${to}/${data.length}` } });
  }) as typeof fetch;
  try {
    const seen: number[] = [];
    const blob = await fetchVideo(photo({ type: 'video', video: '/v', mime: 'video/mp4' }), (loaded) => seen.push(loaded));
    assert.deepEqual([...new Uint8Array(await blob.arrayBuffer())], [...data]);
    assert.equal(blob.type, 'video/mp4');
    assert.deepEqual(ranges, ['bytes=0-', 'bytes=0-', 'bytes=4-', 'bytes=8-']);
    assert.deepEqual(seen, [4, 8, 10]);

    globalThis.fetch = (async () => new Response(null, { status: 403 })) as typeof fetch;
    await assert.rejects(fetchVideo(photo({ type: 'video', video: '/v' })), /Video lässt sich gerade nicht laden/);
  } finally {
    globalThis.fetch = real;
  }
});

test('ZIP ohne Packen: lesbar, UTF-8-Namen, Prüfsummen stimmen', async () => {
  assert.equal(await crc32(new Blob(['123456789'])), 0xcbf43926);
  const zip = await storedZip([
    { name: 'Schützenfest.jpg', blob: new Blob([new Uint8Array([0xff, 0xd8, 1, 2])]) },
    { name: 'Umzug.mp4', blob: new Blob(['video']) },
  ]);
  const files = unzipSync(new Uint8Array(await zip.arrayBuffer()));
  assert.deepEqual(Object.keys(files), ['Schützenfest.jpg', 'Umzug.mp4']);
  assert.deepEqual([...files['Schützenfest.jpg']], [0xff, 0xd8, 1, 2]);
  assert.equal(new TextDecoder().decode(files['Umzug.mp4']), 'video');
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

/** Signaturen im ZIP von hinten: EOCD, davor bei ZIP64 Verweis und ZIP64-Satz. */
function zipTail(bytes: Uint8Array) {
  const v = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const end = bytes.length - 22;
  return {
    eocd: v.getUint32(end, true),
    entries: v.getUint16(end + 10, true),
    locator: end >= 20 ? v.getUint32(end - 20, true) : 0,
    zip64Entries: end >= 76 ? Number(v.getBigUint64(end - 76 + 32, true)) : 0,
    zip64CdOffset: end >= 76 ? Number(v.getBigUint64(end - 76 + 48, true)) : 0,
  };
}

test('Kleines ZIP bleibt ohne ZIP64', async () => {
  const zip = new Uint8Array(await (await storedZip([{ name: 'a.jpg', blob: new Blob(['abc']) }])).arrayBuffer());
  const t = zipTail(zip);
  assert.equal(t.eocd, 0x06054b50);
  assert.equal(t.entries, 1);
  assert.notEqual(t.locator, 0x07064b50);
  assert.equal(new DataView(zip.buffer).getUint16(4, true), 20); // Version 2.0 reicht
});

test('ZIP64: Größen und Versatz im Zusatzfeld, Ende als ZIP64-Satz; entpackbar', async () => {
  // Ab 0 Byte ZIP64, damit der Test keine 4 GB braucht. Die Werte stehen dann alle im Zusatzfeld.
  const zip = new Uint8Array(
    await (
      await storedZip(
        [
          { name: 'Schützenfest.jpg', blob: new Blob([new Uint8Array([0xff, 0xd8, 1, 2])]) },
          { name: 'Umzug.mp4', blob: new Blob(['video']) },
        ],
        new Date(),
        0,
      )
    ).arrayBuffer(),
  );
  const v = new DataView(zip.buffer);
  assert.equal(v.getUint16(4, true), 45); // Version 4.5 (ZIP64)
  assert.equal(v.getUint32(18, true), 0xffffffff); // Größe steht im Zusatzfeld
  assert.equal(v.getUint16(30 + v.getUint16(26, true), true), 0x0001); // Zusatzfeld ZIP64
  const t = zipTail(zip);
  assert.equal(t.eocd, 0x06054b50);
  assert.equal(t.entries, 0xffff);
  assert.equal(t.locator, 0x07064b50);
  assert.equal(t.zip64Entries, 2);
  assert.equal(v.getUint32(t.zip64CdOffset, true), 0x02014b50); // Zentralverzeichnis dort, wo der ZIP64-Satz sagt
  const files = unzipSync(zip);
  assert.deepEqual(Object.keys(files), ['Schützenfest.jpg', 'Umzug.mp4']);
  assert.deepEqual([...files['Schützenfest.jpg']], [0xff, 0xd8, 1, 2]);
  assert.equal(new TextDecoder().decode(files['Umzug.mp4']), 'video');
});

test('ZIP64 auch bei mehr als 65535 Dateien', async () => {
  const many = Array.from({ length: 0x10000 }, (_, i) => ({ name: `${i}.jpg`, blob: new Blob([String(i)]) }));
  const zip = new Uint8Array(await (await storedZip(many)).arrayBuffer());
  const t = zipTail(zip);
  assert.equal(t.locator, 0x07064b50);
  assert.equal(t.zip64Entries, 0x10000);
  const files = unzipSync(zip);
  assert.equal(Object.keys(files).length, 0x10000);
  assert.equal(new TextDecoder().decode(files['65535.jpg']), '65535');
});
