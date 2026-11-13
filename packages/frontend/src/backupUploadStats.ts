import type { BackupUploadStats } from './api';

// Wie formatBytes (format.ts); hier ohne Import, damit der Test die Datei direkt laden kann.
const size = (b: number) =>
  b < 1024 * 1024 ? `${Math.round(b / 1024)} KB` : `${(b / (1024 * 1024)).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`;
const seconds = (s: number) => `${s.toLocaleString('de-DE', { maximumFractionDigits: s < 10 ? 1 : 0 })} s`;

/**
 * Messung des letzten Hochladens nach Google Drive in Worten, z. B. „42 MB in 6 Stücken, 33 s (10,8 Mbit/s) …“. Die Rate
 * zählt nur das Senden der Stücke (ohne Lesen von der Platte und Beginn der Sitzung), in Megabit (10⁶ Bit) je Sekunde wie
 * bei Internetanschlüssen.
 */
export function uploadStatsText(s: BackupUploadStats): string {
  const chunks = s.chunks === 1 ? '1 Stück' : `${s.chunks} Stücken`;
  const rate = s.send_seconds > 0 ? (s.bytes * 8) / s.send_seconds / 1e6 : 0;
  const parts = [
    `${size(s.bytes)} in ${chunks}, ${seconds(s.total_seconds)}` +
      (rate > 0 ? ` (${rate.toLocaleString('de-DE', { maximumFractionDigits: 1 })} Mbit/s)` : ''),
    `Verbindung zu Google: ${seconds(s.start_seconds)}`,
    `Lesen von der Platte: ${seconds(s.read_seconds)}`,
  ];
  if (s.chunks > 1) parts.push(`langsamstes Stück: ${seconds(s.slowest_seconds)}`);
  return parts.join(' · ');
}
