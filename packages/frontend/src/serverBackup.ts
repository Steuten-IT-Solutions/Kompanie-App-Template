import type { ServerBackupFile } from './api';
import { localeTag, t } from './i18n';

/** Adresse zum Herunterladen einer Sicherung, die auf dem Server liegt (<App>/sicherung?datei=…). */
export function serverBackupUrl(backupUrl: string, id: string): string {
  return `${backupUrl}${backupUrl.includes('?') ? '&' : '?'}datei=${encodeURIComponent(id)}`;
}

/** Beschriftung einer Sicherung auf dem Server: Art und Datum, darunter Version (vor Updates) und Größe. */
export function serverBackupLabel(f: ServerBackupFile): { title: string; sub: string } {
  const when = new Date(f.created * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' });
  const size = (f.size / 1024 / 1024).toLocaleString(localeTag(), { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const title = f.kind === 'weekly' ? t('Wöchentliche Sicherung vom {when}', { when }) : t('Sicherung vor dem Update vom {when}', { when });
  return { title, sub: `${f.kind === 'update' && f.version ? `${t('Version {version}', { version: f.version })} · ` : ''}${size} MB` };
}
