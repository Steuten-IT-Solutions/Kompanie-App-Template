// Countdown-Block: Phase, angezeigte Angaben und nächster Zeitpunkt zum Neuzeichnen (ohne React, für Tests).

export type CountdownPhase = 'before' | 'running' | 'after';
export type CountdownUnit = 'day' | 'hour' | 'minute' | 'second';
export interface CountdownPart {
  value: number;
  unit: CountdownUnit;
}

const HOUR = 3_600_000;
const DAY = 24 * HOUR;

/** before: zählt noch; running: zwischen Beginn und Ende; after: vorbei (ohne Beginn ebenfalls). */
export function countdownPhase(now: number, startsAt: string | null, endsAt: string | null): CountdownPhase {
  const start = startsAt ? Date.parse(startsAt) : NaN;
  if (Number.isNaN(start)) return 'after';
  if (now < start) return 'before';
  const end = endsAt ? Date.parse(endsAt) : NaN;
  return !Number.isNaN(end) && now < end ? 'running' : 'after';
}

/**
 * Immer zwei Angaben: ab 24 Stunden Tage und Stunden, darunter Stunden und Minuten, unter einer Stunde Minuten und
 * Sekunden. Angebrochene Sekunden zählen voll, damit die Anzeige genau zum Beginn auf null springt.
 */
export function countdownParts(ms: number): [CountdownPart, CountdownPart] {
  const total = Math.max(0, Math.ceil(ms / 1000));
  if (total >= 86_400) {
    return [
      { value: Math.floor(total / 86_400), unit: 'day' },
      { value: Math.floor((total % 86_400) / 3600), unit: 'hour' },
    ];
  }
  if (total >= 3600) {
    return [
      { value: Math.floor(total / 3600), unit: 'hour' },
      { value: Math.floor((total % 3600) / 60), unit: 'minute' },
    ];
  }
  return [
    { value: Math.floor(total / 60), unit: 'minute' },
    { value: total % 60, unit: 'second' },
  ];
}

const LABELS: Record<CountdownUnit, [string, string]> = {
  day: ['Tag', 'Tage'],
  hour: ['Stunde', 'Stunden'],
  minute: ['Minute', 'Minuten'],
  second: ['Sekunde', 'Sekunden'],
};

/** Einheit in Einzahl oder Mehrzahl („1 Tag“, „0 Tage“, „2 Tage“). */
export function unitLabel(value: number, unit: CountdownUnit): string {
  return LABELS[unit][value === 1 ? 0 : 1];
}

/**
 * Millisekunden bis zur nächsten Änderung der Anzeige bei $ms Restzeit: unter einer Stunde je Sekunde, sonst je Minute
 * (höchstens bis zur Grenze einer Stunde, ab der Sekunden gezeigt werden).
 */
export function nextTick(ms: number): number {
  if (ms <= 0) return 1000;
  if (ms <= HOUR) return ((ms - 1) % 1000) + 1;
  return Math.min(((ms - 1) % 60_000) + 1, ms - HOUR);
}

/** Wartezeit bis zum nächsten Phasenwechsel (laufend: bis zum Ende), gedeckelt, damit Timer nicht überlaufen. */
export function nextChange(now: number, startsAt: string | null, endsAt: string | null): number | null {
  const phase = countdownPhase(now, startsAt, endsAt);
  if (phase === 'before') return nextTick(Date.parse(startsAt as string) - now);
  if (phase === 'running') return Math.min(Date.parse(endsAt as string) - now, DAY);
  return null;
}

/** Zeitpunkt in Ortszeit für Vorschau und Eingabe: Datum JJJJ-MM-TT, Uhrzeit HH:MM (ohne: 0:00 Uhr). */
export function localIso(date: string, time?: string | null): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
  const d = new Date(`${date}T${time && /^\d{2}:\d{2}$/.test(time) ? time : '00:00'}:00`);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

/**
 * Beginn und Ende eines frei eingegebenen Countdowns wie auf dem Server (CountdownBlock), aber in der Zeitzone des
 * Geräts (für die Vorschau beim Bearbeiten).
 */
export function previewRange(b: { date?: string; time?: string | null; end_date?: string | null; end_time?: string | null }): {
  starts_at: string | null;
  ends_at: string | null;
} {
  const starts_at = b.date ? localIso(b.date, b.time) : null;
  let ends_at: string | null = null;
  if (b.end_date && !b.end_time) {
    // Nur ein Enddatum: bis zum Ende dieses Tages (0:00 Uhr des Folgetags, auch bei Zeitumstellung)
    ends_at = localIso(nextDay(b.end_date));
  } else if (b.end_time && b.date) {
    ends_at = localIso(b.end_date || b.date, b.end_time);
  }
  return { starts_at, ends_at };
}

/** Folgetag eines Datums JJJJ-MM-TT (Kalenderrechnung ohne Zeitzone). */
export function nextDay(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
}
