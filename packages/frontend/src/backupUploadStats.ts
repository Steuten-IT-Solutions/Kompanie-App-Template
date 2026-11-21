import type { BackupUploadStats } from './api';
import { localeTag, t, tn } from './i18n';

// Wie formatBytes (format.ts); hier ohne Import, damit der Test die Datei direkt laden kann.
const size = (b: number) =>
  b < 1024 * 1024 ? `${Math.round(b / 1024)} KB` : `${(b / (1024 * 1024)).toLocaleString(localeTag(), { maximumFractionDigits: 1 })} MB`;
const seconds = (s: number) => `${s.toLocaleString(localeTag(), { maximumFractionDigits: s < 10 ? 1 : 0 })} s`;

/**
 * Messung des letzten Hochladens nach Google Drive in Worten, z. B. „42 MB in 6 Stücken, 33 s (10,8 Mbit/s) …“. Die Rate
 * zählt nur das Senden der Stücke (ohne Lesen von der Platte und Beginn der Sitzung), in Megabit (10⁶ Bit) je Sekunde wie
 * bei Internetanschlüssen.
 */
export function uploadStatsText(s: BackupUploadStats): string {
  const rate = s.send_seconds > 0 ? (s.bytes * 8) / s.send_seconds / 1e6 : 0;
  const parts = [
    tn('{size} in {count} Stück, {time}', '{size} in {count} Stücken, {time}', s.chunks, {
      size: size(s.bytes),
      time: seconds(s.total_seconds),
    }) + (rate > 0 ? ` (${rate.toLocaleString(localeTag(), { maximumFractionDigits: 1 })} Mbit/s)` : ''),
    t('Verbindung zu Google: {time}', { time: seconds(s.start_seconds) }),
    t('Lesen von der Platte: {time}', { time: seconds(s.read_seconds) }),
  ];
  if (s.chunks > 1) parts.push(t('langsamstes Stück: {time}', { time: seconds(s.slowest_seconds) }));
  if (s.retries > 0) parts.push(tn('{count} neuer Versuch nach einem Fehler', '{count} neue Versuche nach Fehlern', s.retries));
  return parts.join(' · ');
}
