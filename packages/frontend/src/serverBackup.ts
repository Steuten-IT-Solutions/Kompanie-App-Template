import type { ServerBackupFile } from './api';

/** Adresse zum Herunterladen einer Sicherung, die auf dem Server liegt (<App>/sicherung?datei=…). */
export function serverBackupUrl(backupUrl: string, id: string): string {
  return `${backupUrl}${backupUrl.includes('?') ? '&' : '?'}datei=${encodeURIComponent(id)}`;
}

/** Beschriftung einer Sicherung auf dem Server: Art und Datum, darunter Version (vor Updates) und Größe. */
export function serverBackupLabel(f: ServerBackupFile): { title: string; sub: string } {
  const when = new Date(f.created * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' });
  const size = (f.size / 1024 / 1024).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const title = f.kind === 'weekly' ? `Wöchentliche Sicherung vom ${when}` : `Sicherung vor dem Update vom ${when}`;
  return { title, sub: `${f.kind === 'update' && f.version ? `Version ${f.version} · ` : ''}${size} MB` };
}
