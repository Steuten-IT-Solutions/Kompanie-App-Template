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

/** Inhalt für die API: verschlüsselt als backup_base64, sonst als Text (wie bisher). */
export function backupPayload(bytes: Uint8Array): { backup: string } | { backup_base64: string } {
  return isEncryptedBackup(bytes) ? { backup_base64: toBase64(bytes) } : { backup: new TextDecoder().decode(bytes) };
}
