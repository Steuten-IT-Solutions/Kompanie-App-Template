/**
 * Hochgeladene Sicherungsdatei: verschlüsselt (Storage\BackupCrypto, beginnt mit MAGIC) oder JSON wie bisher.
 * Verschlüsselte Dateien gehen als Base64 an POST /admin/backup, mit dem Passwort für Sicherungen.
 */
export const BACKUP_MAGIC = 'KOMPANIE-BACKUP-ENC\n';

export function isEncryptedBackup(bytes: Uint8Array): boolean {
  if (bytes.length < BACKUP_MAGIC.length) return false;
  for (let i = 0; i < BACKUP_MAGIC.length; i++) {
    if (bytes[i] !== BACKUP_MAGIC.charCodeAt(i)) return false;
  }
  return true;
}

/** Base64 in Stücken (String.fromCharCode mit zu vielen Argumenten sprengt sonst den Stack). */
export function toBase64(bytes: Uint8Array): string {
  let binary = '';
  const step = 0x8000;
  for (let i = 0; i < bytes.length; i += step) {
    binary += String.fromCharCode(...bytes.subarray(i, i + step));
  }
  return btoa(binary);
}

/** Ob die gewählte Datei eine verschlüsselte Sicherung ist (liest nur den Anfang). */
export async function isEncryptedFile(file: Blob): Promise<boolean> {
  return isEncryptedBackup(new Uint8Array(await file.slice(0, BACKUP_MAGIC.length).arrayBuffer()));
}

/**
 * Inhalt einer gewählten Datei für die API. Im Browser kodiert FileReader die verschlüsselte Datei selbst als Base64,
 * ohne Zwischenschritt über eine Zeichenkette der ganzen Datei (große Sicherungen mit vielen Fotos).
 */
export async function filePayload(file: Blob, encrypted?: boolean): Promise<{ backup: string } | { backup_base64: string }> {
  if (!(encrypted ?? (await isEncryptedFile(file)))) return { backup: await file.text() };
  if (typeof FileReader === 'undefined') return { backup_base64: toBase64(new Uint8Array(await file.arrayBuffer())) };
  return { backup_base64: await readBase64(file) };
}

/** Inhalt einer Datei als Base64 (ohne „data:…;base64,“), über FileReader; auch für Dateien an die API (encodeFile). */
export function readBase64(file: Blob): Promise<string> {
  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    // Nur der Base64-Teil: spart auf dem Server eine Kopie der Datei.
    reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
    reader.onerror = () => reject(new Error('Die Datei konnte nicht gelesen werden.'));
    reader.readAsDataURL(file);
  });
}
