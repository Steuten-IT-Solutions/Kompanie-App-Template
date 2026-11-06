import type { GalleryAlbum, GalleryPhoto } from './api';
import { albumFileName, downloadablePhotos, fetchPhoto, photoFileName, uniqueNames } from './downloadFiles';
import { saveFile } from './saveFile';

/**
 * Herunterladen aus der Galerie (Einstellung „Knöpfe zum Herunterladen zeigen“): ein Bild aus der Großansicht oder ein
 * ganzes Album als ZIP. Geladen wird die Originalauflösung (die App liefert sie als JPEG), sonst die 1600er. Videos
 * liegen nicht auf dem Server (sie kommen beim Abspielen aus Google Drive) und fehlen deshalb. Das ZIP entsteht im
 * Browser, damit kein langer Lauf auf dem Webspace nötig ist; die Bilder werden dabei nicht noch einmal gepackt.
 */

export async function downloadPhoto(photo: GalleryPhoto, index: number): Promise<void> {
  const blob = await fetchPhoto(photo);
  await saveFile(blob, photoFileName(photo, index), { mime: 'image/jpeg' });
}

/** Höchstens so viele Bilder gleichzeitig laden (der Server erzeugt Originale nur in wenigen Anfragen zugleich). */
const PARALLEL = 2;

/**
 * Alle Bilder eines Albums als ZIP. onProgress bekommt die Zahl der geladenen Bilder. Bilder, die gerade nicht zu laden
 * sind, fehlen; ihre Zahl steht im Ergebnis. Ist gar keines zu laden, ein Fehler.
 */
export async function downloadAlbum(
  album: GalleryAlbum,
  onProgress: (done: number, total: number) => void,
  signal?: AbortSignal,
): Promise<{ saved: number; missing: number }> {
  const photos = downloadablePhotos(album.photos);
  const names = uniqueNames(photos.map((p, i) => photoFileName(p, i)));
  const blobs: (Blob | null)[] = new Array(photos.length).fill(null);
  let next = 0;
  let done = 0;
  onProgress(0, photos.length);
  const worker = async () => {
    while (next < photos.length) {
      const i = next++;
      try {
        blobs[i] = await fetchPhoto(photos[i], signal);
      } catch (e) {
        if ((e as Error).name === 'AbortError') throw e;
      }
      onProgress(++done, photos.length);
    }
  };
  await Promise.all(Array.from({ length: Math.min(PARALLEL, photos.length) }, worker));
  const saved = blobs.filter((b) => b !== null).length;
  if (saved === 0) throw new Error('Die Bilder lassen sich gerade nicht laden. Bitte später erneut versuchen.');

  // Gespeichert, nicht gepackt (JPEG ist schon gepackt): schnell und ohne Rechenlast auf dem Handy.
  const { Zip, ZipPassThrough } = await import('fflate');
  const chunks: Uint8Array[] = [];
  const zip = new Zip();
  const finished = new Promise<void>((resolve, reject) => {
    zip.ondata = (err, chunk, final) => {
      if (err) return reject(err);
      chunks.push(chunk);
      if (final) resolve();
    };
  });
  for (let i = 0; i < photos.length; i++) {
    const blob = blobs[i];
    if (!blob) continue;
    const entry = new ZipPassThrough(names[i]);
    zip.add(entry);
    entry.push(new Uint8Array(await blob.arrayBuffer()), true);
    blobs[i] = null;
  }
  zip.end();
  await finished;
  await saveFile(new Blob(chunks as BlobPart[], { type: 'application/zip' }), albumFileName(album), { title: album.title });
  return { saved, missing: photos.length - saved };
}
