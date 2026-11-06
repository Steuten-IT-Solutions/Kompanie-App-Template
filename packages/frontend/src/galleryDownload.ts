import type { GalleryAlbum, GalleryPhoto } from './api';
import { albumFileName, downloadablePhotos, fetchMedia, isVideoEntry, mediaFileName, storedZip, uniqueNames } from './downloadFiles';
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
 * laden ist, fehlt; die Zahl steht im Ergebnis. Ist gar nichts zu laden, ein Fehler. Über 4 GB wird es ein
 * ZIP64; Grenze ist dann der Platz, den der Browser für Dateien hat.
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
  onProgress(0, photos.length);
  const worker = async () => {
    while (next < photos.length) {
      const i = next++;
      try {
        blobs[i] = await fetchMedia(photos[i], signal);
      } catch (e) {
        if ((e as Error).name === 'AbortError') throw e;
      }
      onProgress(++done, photos.length);
    }
  };
  await Promise.all(Array.from({ length: Math.min(PARALLEL, photos.length) }, worker));
  const files = photos.flatMap((_, i) => (blobs[i] ? [{ name: names[i], blob: blobs[i]! }] : []));
  if (files.length === 0) throw new Error('Die Dateien lassen sich gerade nicht laden. Bitte später erneut versuchen.');
  let zip: Blob;
  try {
    zip = await storedZip(files);
  } catch {
    // Liest der Browser die geladenen Dateien nicht mehr (meist fehlt ihm Platz, z. B. im privaten Fenster), meldet er nur
    // einen allgemeinen Fehler.
    throw new Error(
      'Das ZIP ließ sich nicht erstellen, vermutlich fehlt dem Browser Platz. Lade die Videos einzeln in der Großansicht herunter oder nutze kein privates Fenster.',
    );
  }
  await saveFile(zip, albumFileName(album), { title: album.title });
  return { saved: files.length, missing: photos.length - files.length };
}
