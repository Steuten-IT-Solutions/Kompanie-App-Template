import { api, ApiError, type GalleryAdminAlbumDetail, type GallerySubmission } from './api';
import { readBase64 } from './backupFile';

/**
 * Videos in die Galerie hochladen (Issue #350): MP4 und WebM bis 1 GB, in Stücken (jede Anfrage bleibt unter der
 * Grenze des Servers). Vorher zeichnet der Browser ein Standbild als Vorschau und liest Maße und Dauer.
 */
export const VIDEO_ACCEPT = '.mp4,.webm';
export const MAX_VIDEO_BYTES = 1024 * 1024 * 1024;
/** Versuche je Stück bei Netzfehlern oder Fehlern des Servers (5xx). */
const ATTEMPTS = 3;
/** Längste Kante des Standbilds */
const POSTER_EDGE = 1600;

export function isVideoFile(file: File): boolean {
  return /\.(mp4|webm)$/i.test(file.name);
}

/** „2 Bilder“, „1 Video“, „2 Bilder und 1 Video“ */
export function countFiles(files: File[]): string {
  const videos = files.filter(isVideoFile).length;
  const images = files.length - videos;
  const parts = [];
  if (images > 0) parts.push(images === 1 ? '1 Bild' : `${images} Bilder`);
  if (videos > 0) parts.push(videos === 1 ? '1 Video' : `${videos} Videos`);
  return parts.join(' und ');
}

export interface VideoMeta {
  /** JPEG als data:-Adresse; null, wenn der Browser das Video nicht zeichnen kann */
  poster: string | null;
  width: number;
  height: number;
  /** Sekunden */
  duration: number;
}

function once(el: HTMLVideoElement, event: string, ms = 15000): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = window.setTimeout(() => done(new Error('Zeit abgelaufen')), ms);
    const ok = () => done();
    const fail = () => done(new Error('Video nicht lesbar'));
    function done(err?: Error) {
      window.clearTimeout(timer);
      el.removeEventListener(event, ok);
      el.removeEventListener('error', fail);
      if (err) reject(err);
      else resolve();
    }
    el.addEventListener(event, ok);
    el.addEventListener('error', fail);
  });
}

/** Standbild (kurz nach dem Anfang), Maße und Dauer; was der Browser nicht lesen kann, bleibt leer. */
export async function videoMeta(file: Blob): Promise<VideoMeta> {
  const url = URL.createObjectURL(file);
  const video = document.createElement('video');
  video.muted = true;
  video.playsInline = true;
  video.preload = 'auto';
  const meta: VideoMeta = { poster: null, width: 0, height: 0, duration: 0 };
  try {
    const loaded = once(video, 'loadeddata');
    video.src = url;
    await loaded;
    meta.width = video.videoWidth;
    meta.height = video.videoHeight;
    meta.duration = Number.isFinite(video.duration) ? video.duration : 0;
    const seeked = once(video, 'seeked');
    video.currentTime = meta.duration > 0 ? Math.min(1, meta.duration / 10) : 0.1;
    await seeked;
    if (meta.width > 0 && meta.height > 0) {
      const scale = Math.min(1, POSTER_EDGE / Math.max(meta.width, meta.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(meta.width * scale);
      canvas.height = Math.round(meta.height * scale);
      canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
      const jpeg = canvas.toDataURL('image/jpeg', 0.85);
      meta.poster = jpeg.startsWith('data:image/jpeg') ? jpeg : null;
    }
  } catch {
    // ohne Standbild: In der Galerie erscheint eine Kachel mit ▶
  } finally {
    video.removeAttribute('src');
    video.load();
    URL.revokeObjectURL(url);
  }
  return meta;
}

function sizeLabel(bytes: number): string {
  return bytes >= 1024 * 1024 * 1024
    ? `${(bytes / 1024 / 1024 / 1024).toFixed(1).replace('.', ',')} GB`
    : `${Math.round(bytes / 1024 / 1024)} MB`;
}

const wait = (ms: number) => new Promise((r) => window.setTimeout(r, ms));

interface Options {
  albumId: number;
  intern?: boolean;
  /** Anteil 0 bis 1 */
  onProgress?: (share: number) => void;
}

async function upload<T extends GalleryAdminAlbumDetail | GallerySubmission>(
  file: File,
  purpose: 'album' | 'submission',
  { albumId, intern, onProgress }: Options,
): Promise<T> {
  if (file.size > MAX_VIDEO_BYTES) {
    throw new Error(`„${file.name}“ ist ${sizeLabel(file.size)} groß, erlaubt sind höchstens 1 GB.`);
  }
  const meta = await videoMeta(file);
  const session = await api.startVideoUpload({ album_id: albumId, name: file.name, size: file.size, purpose });
  try {
    let offset = 0;
    onProgress?.(0);
    while (offset < file.size) {
      const data = await readBase64(file.slice(offset, offset + session.chunk_size));
      for (let attempt = 1; ; attempt++) {
        try {
          offset = (await api.videoUploadChunk(session.id, offset, data)).received;
          break;
        } catch (e) {
          // Netz weg oder Server überlastet: dasselbe Stück noch einmal (der Server nimmt es nur einmal)
          const retry = e instanceof ApiError && (e.status === 0 || e.status >= 500);
          if (!retry || attempt >= ATTEMPTS) throw e;
          await wait(1000 * attempt);
        }
      }
      onProgress?.(offset / file.size);
    }
    return await api.finishVideoUpload<T>({
      upload: session.id,
      poster: meta.poster,
      width: meta.width,
      height: meta.height,
      duration: meta.duration,
      intern,
      consent: purpose === 'submission',
    });
  } catch (e) {
    void api.cancelVideoUpload(session.id).catch(() => undefined);
    throw e;
  }
}

/** Video direkt in ein Album (manage_settings); Antwort wie beim Hochladen von Bildern */
export const uploadAlbumVideo = (file: File, options: Options) => upload<GalleryAdminAlbumDetail>(file, 'album', options);

/** Video einreichen (submit_gallery); das Einverständnis ist im Formular bestätigt */
export const submitVideo = (file: File, options: Options) => upload<GallerySubmission>(file, 'submission', options);
