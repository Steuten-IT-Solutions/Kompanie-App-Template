// Anwesenheit einer Person in der Verwaltung (memberAttendance.tsx): Zeiträume zum Umschalten und Summen je Zeitraum.
// Ohne React, damit die Tests es direkt laden.

export type AttendanceStatus = 'present' | 'excused' | 'absent';

export interface AttendanceEntryLike {
  /** JJJJ-MM-TT */
  date: string;
  status: AttendanceStatus;
}

/** Ein Jahr (JJJJ) oder alle Termine */
export type AttendancePeriod = string | 'all';

export interface PeriodCounts {
  present: number;
  excused: number;
  absent: number;
  /** davon für Termine nach heute (bis zum Tag nur „entschuldigt“ möglich) */
  upcoming: number;
}

/** Jahre zum Umschalten: das laufende Jahr immer, dazu jedes Jahr mit Einträgen, die spätesten zuerst. */
export function attendanceYears(entries: AttendanceEntryLike[], today: string): string[] {
  const years = new Set<string>([today.slice(0, 4)]);
  for (const e of entries) years.add(e.date.slice(0, 4));
  return [...years].sort((a, b) => b.localeCompare(a));
}

/** Einträge im Zeitraum, Reihenfolge wie geliefert */
export function inPeriod<T extends AttendanceEntryLike>(entries: T[], period: AttendancePeriod): T[] {
  return period === 'all' ? entries : entries.filter((e) => e.date.slice(0, 4) === period);
}

/** Summen je Status im Zeitraum. Es zählen nur gespeicherte Einträge, also auch eine gespeicherte Entschuldigung für später. */
export function periodCounts(entries: AttendanceEntryLike[], period: AttendancePeriod, today: string): PeriodCounts {
  const out: PeriodCounts = { present: 0, excused: 0, absent: 0, upcoming: 0 };
  for (const e of inPeriod(entries, period)) {
    out[e.status] += 1;
    if (e.date > today) out.upcoming += 1;
  }
  return out;
}
