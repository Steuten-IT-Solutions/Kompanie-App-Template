import { api, ApiError } from './api';
import { readBase64 } from './backupFile';

/**
 * Update-Paket hochladen statt herunterladen: in Stücken (jede Anfrage bleibt unter der Grenze des Servers). Der Server
 * spielt nur ein Paket ein, das genau so in der signierten Update-Liste steht.
 */
export const UPDATE_ACCEPT = '.zip,application/zip';
/** Versuche je Stück bei Netzfehlern oder Fehlern des Servers (5xx). */
const ATTEMPTS = 3;

const wait = (ms: number) => new Promise((r) => window.setTimeout(r, ms));

/**
 * Datei hochladen; gibt die Kennung der Sitzung zurück (für check und install). Bei einem Fehler ist die Sitzung gelöscht.
 *
 * @param onProgress Anteil 0 bis 1
 */
export async function uploadUpdatePackage(file: File, onProgress?: (share: number) => void): Promise<string> {
  if (!/\.zip$/i.test(file.name)) throw new Error('Bitte das Update-Paket als ZIP-Datei wählen (z. B. kompanie-app-standalone.zip).');
  const session = await api.startUpdateUpload(file.size);
  try {
    let offset = 0;
    onProgress?.(0);
    while (offset < file.size) {
      const data = await readBase64(file.slice(offset, offset + session.chunk_size));
      for (let attempt = 1; ; attempt++) {
        try {
          offset = (await api.updateUploadChunk(session.id, offset, data)).received;
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
    return session.id;
  } catch (e) {
    void api.cancelUpdateUpload(session.id).catch(() => undefined);
    throw e;
  }
}
