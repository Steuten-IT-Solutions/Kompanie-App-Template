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

/** Dateiname eines Videos: der Name aus der Galerie mit seiner Endung (das Video kommt unverändert aus der Quelle). */
export function videoFileName(photo: Pick<GalleryPhoto, 'name' | 'mime'>, index: number): string {
  const name = safeFileName(photo.name ?? '', '');
  if (/\.[A-Za-z0-9]{1,5}$/.test(name)) return name;
  const ext = photo.mime === 'video/webm' ? '.webm' : '.mp4';
  return `${name || `Video ${index + 1}`}${ext}`;
}

/** Dateiname eines Eintrags der Galerie (Bild oder Video). */
export function mediaFileName(photo: GalleryPhoto, index: number): string {
  return isVideoEntry(photo) ? videoFileName(photo, index) : photoFileName(photo, index);
}

export function isVideoEntry(photo: Pick<GalleryPhoto, 'type' | 'video'>): boolean {
  return photo.type === 'video' && !!photo.video;
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

/** Bilder und Videos eines Albums, die sich herunterladen lassen. */
export function downloadablePhotos(photos: GalleryPhoto[]): GalleryPhoto[] {
  return photos.filter((p) => (p.type === 'video' ? !!p.video : !!(p.original || p.large)));
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

const wait = (ms: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new DOMException('Abgebrochen', 'AbortError'));
    });
  });

/** So oft fragt die App einen Ausschnitt erneut an (Server gerade ausgelastet: 503, oder die Verbindung riss ab). */
const VIDEO_RETRIES = 5;

/**
 * Ein Video als Datei. Der Server reicht Videos in Ausschnitten (höchstens 4 MB, Antwort 206 mit Content-Range) aus der
 * Quelle durch und speichert sie nicht; die App fragt deshalb Stück für Stück an, bis das Video vollständig ist.
 * onProgress bekommt geladene und gesamte Bytes.
 */
export async function fetchVideo(
  photo: GalleryPhoto,
  onProgress?: (loaded: number, total: number) => void,
  signal?: AbortSignal,
): Promise<Blob> {
  const url = photo.video;
  const failed = () => new Error('Das Video lässt sich gerade nicht laden. Bitte später erneut versuchen.');
  if (!url) throw failed();
  const parts: Blob[] = [];
  let from = 0;
  let total = -1;
  let retries = 0;
  while (total < 0 || from < total) {
    let res: Response;
    try {
      res = await fetch(url, { credentials: 'same-origin', signal, headers: { Range: `bytes=${from}-` } });
    } catch (e) {
      if ((e as Error).name === 'AbortError' || ++retries > VIDEO_RETRIES) throw (e as Error).name === 'AbortError' ? e : failed();
      await wait(1000, signal);
      continue;
    }
    if ((res.status === 503 || res.status === 502) && ++retries <= VIDEO_RETRIES) {
      await wait(1000, signal);
      continue;
    }
    if (res.status === 200) return res.blob(); // ganz auf einmal (z. B. ein Server ohne Ausschnitte)
    const size = /\/(\d+)\s*$/.exec(res.headers.get('content-range') ?? '');
    if (res.status !== 206 || !size) throw failed();
    const part = await res.blob();
    if (part.size === 0) throw failed();
    total = Number(size[1]);
    parts.push(part);
    from += part.size;
    retries = 0;
    onProgress?.(from, total);
  }
  return new Blob(parts, { type: photo.mime || 'video/mp4' });
}

/** Bild oder Video als Datei. */
export function fetchMedia(photo: GalleryPhoto, signal?: AbortSignal, onProgress?: (loaded: number, total: number) => void): Promise<Blob> {
  return isVideoEntry(photo) ? fetchVideo(photo, onProgress, signal) : fetchPhoto(photo, signal);
}

let crcTable: Uint32Array | null = null;

/** CRC-32 (wie in ZIP), über die Datei in Stücken gelesen. */
export async function crc32(blob: Blob): Promise<number> {
  if (!crcTable) {
    crcTable = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      crcTable[n] = c >>> 0;
    }
  }
  const table = crcTable;
  let crc = 0xffffffff;
  const reader = blob.stream().getReader();
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    for (let i = 0; i < value.length; i++) crc = table[(crc ^ value[i]) & 0xff] ^ (crc >>> 8);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

/** Ab hier braucht ein Wert im ZIP die Erweiterung ZIP64 (0xffffffff selbst ist dort das Zeichen „steht in ZIP64“). */
const ZIP32_MAX = 0xffffffff;
const ZIP16_MAX = 0xffff;

/** 64-Bit-Zahl (bis 2^53) als zwei 32-Bit-Hälften, ohne BigInt. */
function setUint64(view: DataView, at: number, value: number): void {
  view.setUint32(at, value % 0x100000000, true);
  view.setUint32(at + 4, Math.floor(value / 0x100000000), true);
}

/**
 * ZIP ohne Packen („stored“: Bilder und Videos sind schon gepackt). Die Dateien werden nicht kopiert: Das Ergebnis setzt
 * sich aus den Kopfdaten und den Dateien selbst zusammen, so braucht auch ein großes Album kaum zusätzlichen Speicher.
 * Über 4 GB (oder bei mehr als 65535 Dateien) schreibt es die Erweiterung ZIP64, aber nur bei den Einträgen, die sie
 * brauchen; ein kleines Album bleibt ein gewöhnliches ZIP.
 */
export async function storedZip(
  files: { name: string; blob: Blob }[],
  date = new Date(),
  /** Nur für Tests: ab dieser Größe ZIP64 (sonst ab 4 GB). */
  zip64From = ZIP32_MAX,
): Promise<Blob> {
  const enc = new TextEncoder();
  const time = (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2);
  const day = ((Math.max(1980, date.getFullYear()) - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
  const parts: BlobPart[] = [];
  const central: BlobPart[] = [];
  let offset = 0;
  let cdSize = 0;
  for (const f of files) {
    const name = enc.encode(f.name);
    const crc = await crc32(f.blob);
    const size = f.blob.size;
    const bigSize = size >= zip64From;
    const bigOffset = offset >= zip64From;
    const version = bigSize || bigOffset ? 45 : 20;
    // Lokaler Kopf: Bei ZIP64 stehen beide Größen (je 8 Byte) im Zusatzfeld 0x0001.
    const localExtra = bigSize ? 20 : 0;
    const local = new DataView(new ArrayBuffer(30 + localExtra));
    local.setUint32(0, 0x04034b50, true);
    local.setUint16(4, bigSize ? 45 : 20, true);
    local.setUint16(6, 0x0800, true); // Namen in UTF-8
    local.setUint16(8, 0, true);
    local.setUint16(10, time, true);
    local.setUint16(12, day, true);
    local.setUint32(14, crc, true);
    local.setUint32(18, bigSize ? ZIP32_MAX : size, true);
    local.setUint32(22, bigSize ? ZIP32_MAX : size, true);
    local.setUint16(26, name.length, true);
    local.setUint16(28, localExtra, true);
    if (bigSize) {
      local.setUint16(30, 0x0001, true);
      local.setUint16(32, 16, true);
      setUint64(local, 34, size);
      setUint64(local, 42, size);
    }
    parts.push(local.buffer.slice(0, 30), name as Uint8Array<ArrayBuffer>);
    if (localExtra) parts.push(local.buffer.slice(30));
    parts.push(f.blob);
    // Zentralverzeichnis: Im Zusatzfeld stehen nur die Werte, die 0xffffffff tragen, in dieser Reihenfolge.
    const extraLen = (bigSize ? 16 : 0) + (bigOffset ? 8 : 0);
    const centralExtra = extraLen ? 4 + extraLen : 0;
    const c = new DataView(new ArrayBuffer(46));
    c.setUint32(0, 0x02014b50, true);
    c.setUint16(4, version, true);
    c.setUint16(6, version, true);
    c.setUint16(8, 0x0800, true);
    c.setUint16(10, 0, true);
    c.setUint16(12, time, true);
    c.setUint16(14, day, true);
    c.setUint32(16, crc, true);
    c.setUint32(20, bigSize ? ZIP32_MAX : size, true);
    c.setUint32(24, bigSize ? ZIP32_MAX : size, true);
    c.setUint16(28, name.length, true);
    c.setUint16(30, centralExtra, true);
    c.setUint32(42, bigOffset ? ZIP32_MAX : offset, true);
    central.push(c.buffer, name as Uint8Array<ArrayBuffer>);
    if (centralExtra) {
      const x = new DataView(new ArrayBuffer(centralExtra));
      x.setUint16(0, 0x0001, true);
      x.setUint16(2, extraLen, true);
      let at = 4;
      if (bigSize) {
        setUint64(x, at, size);
        setUint64(x, at + 8, size);
        at += 16;
      }
      if (bigOffset) setUint64(x, at, offset);
      central.push(x.buffer);
    }
    offset += 30 + localExtra + name.length + size;
    cdSize += 46 + name.length + centralExtra;
  }
  const tail: BlobPart[] = [];
  const zip64End = files.length >= ZIP16_MAX || cdSize >= zip64From || offset >= zip64From;
  if (zip64End) {
    // Ende des Zentralverzeichnisses als ZIP64-Satz (56 Byte) und der Verweis darauf (20 Byte).
    const r = new DataView(new ArrayBuffer(56 + 20));
    r.setUint32(0, 0x06064b50, true);
    setUint64(r, 4, 44);
    r.setUint16(12, 45, true);
    r.setUint16(14, 45, true);
    setUint64(r, 24, files.length);
    setUint64(r, 32, files.length);
    setUint64(r, 40, cdSize);
    setUint64(r, 48, offset);
    r.setUint32(56, 0x07064b50, true);
    setUint64(r, 64, offset + cdSize);
    r.setUint32(72, 1, true);
    tail.push(r.buffer);
  }
  const end = new DataView(new ArrayBuffer(22));
  end.setUint32(0, 0x06054b50, true);
  // Mit ZIP64 tragen alle Felder das Zeichen „steht im ZIP64-Satz“.
  end.setUint16(8, zip64End ? ZIP16_MAX : files.length, true);
  end.setUint16(10, zip64End ? ZIP16_MAX : files.length, true);
  end.setUint32(12, zip64End ? ZIP32_MAX : cdSize, true);
  end.setUint32(16, zip64End ? ZIP32_MAX : offset, true);
  tail.push(end.buffer);
  return new Blob([...parts, ...central, ...tail], { type: 'application/zip' });
}
