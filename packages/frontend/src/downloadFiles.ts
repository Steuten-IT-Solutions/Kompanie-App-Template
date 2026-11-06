import type { GalleryAlbum, GalleryPhoto } from './api';

// Dateinamen und Laden der Dateien zum Herunterladen (ohne weitere Abhängigkeiten, damit die Tests sie direkt laden).

/** Zeichen, die in Dateinamen nicht gehen (Windows, macOS), durch Leerzeichen ersetzt. */
export function safeFileName(name: string, fallback: string): string {
  return (
    name
      .replace(/[\\/:*?"<>|\u0000-\u001f]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim() || fallback
  );
}

/** Dateiname eines Bilds: der Name aus der Galerie, immer mit .jpg (die App liefert JPEG). */
export function photoFileName(photo: Pick<GalleryPhoto, 'name'>, index: number): string {
  const base = (photo.name ?? '').replace(/\.[A-Za-z0-9]{1,5}$/, '');
  return `${safeFileName(base, `Bild ${index + 1}`)}.jpg`;
}

/** Name des ZIP: „Titel Jahr.zip“. */
export function albumFileName(album: Pick<GalleryAlbum, 'title' | 'year'>): string {
  return `${safeFileName(`${album.title} ${album.year}`, 'Album')}.zip`;
}

/** Gleiche Namen im ZIP durchnummerieren: „IMG_1.jpg“, „IMG_1 (2).jpg“. */
export function uniqueNames(names: string[]): string[] {
  const seen = new Set<string>();
  return names.map((name) => {
    const dot = name.lastIndexOf('.');
    const [stem, ext] = dot > 0 ? [name.slice(0, dot), name.slice(dot)] : [name, ''];
    let candidate = name;
    for (let n = 2; seen.has(candidate.toLowerCase()); n++) candidate = `${stem} (${n})${ext}`;
    seen.add(candidate.toLowerCase());
    return candidate;
  });
}

/** Bilder eines Albums, die sich herunterladen lassen (ohne Videos). */
export function downloadablePhotos(photos: GalleryPhoto[]): GalleryPhoto[] {
  return photos.filter((p) => p.type !== 'video' && !!(p.original || p.large));
}

/**
 * Ein Bild als Datei: zuerst die Originalauflösung, sonst die 1600er. Nur eine Antwort 200 zählt (202 ist der
 * Platzhalter eines Bilds, das noch aufbereitet wird).
 */
export async function fetchPhoto(photo: GalleryPhoto, signal?: AbortSignal): Promise<Blob> {
  for (const url of [photo.original, photo.large]) {
    if (!url) continue;
    let res: Response;
    try {
      res = await fetch(url, { credentials: 'same-origin', signal });
    } catch (e) {
      if ((e as Error).name === 'AbortError') throw e;
      continue;
    }
    if (res.status === 200 && (res.headers.get('content-type') ?? '').startsWith('image/')) return res.blob();
  }
  throw new Error('Das Bild lässt sich gerade nicht laden. Ist es noch nicht fertig aufbereitet, später erneut versuchen.');
}
