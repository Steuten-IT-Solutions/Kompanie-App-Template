import type { GalleryAlbum, GalleryPhoto } from './api';
import {
  albumFileName,
  downloadablePhotos,
  fetchMedia,
  isVideoEntry,
  mediaFileName,
  storedZip,
  uniqueNames,
  ZIP_MAX_BYTES,
} from './downloadFiles';
import { saveFile } from './saveFile';

/**
 * Herunterladen aus der Galerie (Einstellung „Knöpfe zum Herunterladen zeigen“): ein Bild oder Video aus der Großansicht
 * oder ein ganzes Album als ZIP. Bilder kommen in Originalauflösung (die App liefert sie als JPEG), sonst als 1600er;
 * Videos unverändert aus der Quelle, Stück für Stück über die App (downloadFiles.ts). Das ZIP entsteht im Browser, damit
 * kein langer Lauf auf dem Webspace nötig ist; die Dateien werden dabei nicht noch einmal gepackt.
 */

export async function downloadMedia(
  photo: GalleryPhoto,
  index: number,
  onProgress?: (loaded: number, total: number) => void,
): Promise<void> {
  const blob = await fetchMedia(photo, undefined, onProgress);
  const video = isVideoEntry(photo);
  await saveFile(blob, mediaFileName(photo, index), { mime: video ? photo.mime || 'video/mp4' : 'image/jpeg' });
}

/** Höchstens so viele Dateien gleichzeitig laden (der Server erzeugt Originale nur in wenigen Anfragen zugleich). */
const PARALLEL = 2;

/**
 * Alle Bilder und Videos eines Albums als ZIP. onProgress bekommt die Zahl der geladenen Dateien. Was gerade nicht zu
 * laden ist, fehlt; die Zahl steht im Ergebnis. Ist gar nichts zu laden, ein Fehler. Über 4 GB bricht es ab.
 */
export async function downloadAlbum(
  album: GalleryAlbum,
  onProgress: (done: number, total: number) => void,
  signal?: AbortSignal,
): Promise<{ saved: number; missing: number }> {
  const photos = downloadablePhotos(album.photos);
  const names = uniqueNames(photos.map((p, i) => mediaFileName(p, i)));
  const blobs: (Blob | null)[] = new Array(photos.length).fill(null);
  let next = 0;
  let done = 0;
  let bytes = 0;
  const tooBig = new Error('Das Album ist zu groß für ein ZIP (über 4 GB). Lade die Videos einzeln in der Großansicht herunter.');
  onProgress(0, photos.length);
  const worker = async () => {
    while (next < photos.length) {
      const i = next++;
      try {
        const blob = await fetchMedia(photos[i], signal);
        bytes += blob.size;
        if (bytes > ZIP_MAX_BYTES) throw tooBig;
        blobs[i] = blob;
      } catch (e) {
        if ((e as Error).name === 'AbortError' || e === tooBig) throw e;
      }
      onProgress(++done, photos.length);
    }
  };
  await Promise.all(Array.from({ length: Math.min(PARALLEL, photos.length) }, worker));
  const files = photos.flatMap((_, i) => (blobs[i] ? [{ name: names[i], blob: blobs[i]! }] : []));
  if (files.length === 0) throw new Error('Die Dateien lassen sich gerade nicht laden. Bitte später erneut versuchen.');
  await saveFile(await storedZip(files), albumFileName(album), { title: album.title });
  return { saved: files.length, missing: photos.length - files.length };
}
