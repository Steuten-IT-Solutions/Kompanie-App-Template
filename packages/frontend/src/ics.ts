import type { CalendarEvent } from './api';
import brand from './generated/brand';

function esc(s: string): string {
  return s.replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
}

function utcStamp(d: Date): string {
  return d
    .toISOString()
    .replace(/[-:]/g, '')
    .replace(/\.\d{3}/, '');
}

/** Erzeugt eine .ics-Datei für einen Termin, damit er in den eigenen Kalender übernommen werden kann. */
export function eventToIcs(ev: CalendarEvent): string {
  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    `PRODID:-//${esc(brand.name)}//App//DE`,
    'BEGIN:VEVENT',
    `UID:${ev.id}@${window.location.host}`,
    `DTSTAMP:${utcStamp(new Date())}`,
  ];
  if (ev.all_day) {
    lines.push(`DTSTART;VALUE=DATE:${ev.start.replace(/-/g, '')}`);
    lines.push(`DTEND;VALUE=DATE:${(ev.end || ev.start).replace(/-/g, '')}`);
  } else {
    lines.push(`DTSTART:${utcStamp(new Date(ev.start))}`);
    lines.push(`DTEND:${utcStamp(new Date(ev.end || ev.start))}`);
  }
  lines.push(`SUMMARY:${esc(ev.title)}`);
  if (ev.location) lines.push(`LOCATION:${esc(ev.location)}`);
  if (ev.description) lines.push(`DESCRIPTION:${esc(ev.description)}`);
  lines.push('END:VEVENT', 'END:VCALENDAR');
  return lines.join('\r\n');
}

export function downloadIcs(ev: CalendarEvent): void {
  const blob = new Blob([eventToIcs(ev)], { type: 'text/calendar;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${ev.title.replace(/[^\wäöüÄÖÜß -]/g, '').trim() || 'termin'}.ics`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
