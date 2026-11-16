// Bericht als PDF (Verwaltung → Bericht): Zeiträume zur Schnellwahl, Beschriftungen und der Dateiname. Ohne jsPDF, damit
// die Ansicht und die Tests es laden können; das PDF selbst baut reportPdf.ts.

/** 'JJJJ-MM-TT' als TT.MM.JJJJ */
export function dayLabel(ymd: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : ymd;
}

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export interface ReportRange {
  from: string;
  to: string;
}

export interface ReportPreset {
  id: 'year' | 'last-year' | 'twelve-months';
  label: string;
  range: ReportRange;
}

/** Schnellwahl ab heute ('JJJJ-MM-TT'): dieses Jahr (bis heute), letztes Jahr, die letzten 12 Monate. */
export function reportPresets(today: string): ReportPreset[] {
  const [y, m, d] = today.split('-').map(Number);
  return [
    { id: 'year', label: 'Dieses Jahr', range: { from: `${y}-01-01`, to: today } },
    { id: 'last-year', label: 'Letztes Jahr', range: { from: `${y - 1}-01-01`, to: `${y - 1}-12-31` } },
    // Am Tag nach demselben Datum vor einem Jahr beginnen (am 29.02. gilt im Vorjahr der 28.02.): genau 12 Monate bis heute
    {
      id: 'twelve-months',
      label: 'Letzte 12 Monate',
      range: { from: ymd(new Date(y - 1, m - 1, Math.min(d, new Date(y - 1, m, 0).getDate()) + 1)), to: today },
    },
  ];
}

/** Zeitraum zum Lesen: „01.01.2026 bis 31.12.2026“, an einem Tag nur das Datum */
export function rangeLabel(r: ReportRange): string {
  return r.from === r.to ? dayLabel(r.from) : `${dayLabel(r.from)} bis ${dayLabel(r.to)}`;
}

/** Dateiname: bericht-2026-01-01-bis-2026-12-31.pdf */
export function reportFileName(r: ReportRange): string {
  return r.from === r.to ? `bericht-${r.from}.pdf` : `bericht-${r.from}-bis-${r.to}.pdf`;
}
