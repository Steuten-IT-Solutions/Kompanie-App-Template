// Listen (Issue #121): CSV für Excel, ohne Abhängigkeiten (auch in den Tests nutzbar).

/**
 * Ein Feld für CSV: in Anführungszeichen, wenn nötig (RFC 4180). Texte, die mit =, +, -, @, Tab oder Wagenrücklauf
 * beginnen, bekommen ein ' davor, damit Excel sie nicht als Formel ausführt (CSV-Injection). Zahlen bleiben Zahlen.
 */
export function csvField(value: string | number): string {
  let s = typeof value === 'number' ? String(value).replace('.', ',') : value;
  if (typeof value === 'string' && /^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[";\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

/**
 * Handynummer für die CSV: „+49 171 1234567“ als „0049 171 1234567“. Mit dem + davor bekäme sie (gegen CSV-Injection)
 * ein ', das Excel mit anzeigt. Nur mit Leerzeichen: Ohne läse Excel die Ziffern als Zahl und strich die Nullen vorn.
 */
export function csvPhone(phone: string): string {
  return /^\+\d/.test(phone) && phone.includes(' ') ? `00${phone.slice(1)}` : phone;
}

/** Byte Order Mark: Daran erkennt Excel UTF-8. */
const BOM = String.fromCharCode(0xfeff);

/** CSV für ein deutsches Excel: UTF-8 mit BOM, Semikolon als Trennzeichen, Zeilenende CRLF. */
export function toCsv(header: string[], rows: (string | number)[][]): string {
  return BOM + [header, ...rows].map((r) => r.map(csvField).join(';')).join('\r\n') + '\r\n';
}

/** Dateiname (ohne Endung) aus dem Titel: ohne Zeichen, die Dateisysteme nicht mögen; leer bei leerem Titel. */
export function listFileBase(title: string): string {
  return title
    .replace(/[\\/:*?"<>|\u0000-\u001F]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80);
}

export function csvFileName(title: string): string {
  return `${listFileBase(title) || 'liste'}.csv`;
}

/**
 * Spaltenbreiten in ganzen Einheiten (Pixel bzw. Zehntelmillimeter im PDF): nach Anteil (weights), aber mindestens so
 * breit wie verlangt (minimums, z. B. für Namen, die nicht umbrechen sollen); was die so verbreiterten Spalten mehr
 * brauchen, geben die anderen nach ihrem Anteil ab. Reicht der Platz dafür nicht, bleibt es bei den Anteilen.
 *
 * Spalten mit natürlicher Breite (natural, Breite „automatisch“: so breit wie ihr längster Inhalt) bekommen genau diese;
 * die anderen teilen sich den Rest. Bleibt für die anderen zu wenig (je Spalte ihr Minimum, mindestens ein
 * Fünfundzwanzigstel der Breite), werden die automatischen im Verhältnis schmaler; gibt es nur automatische, füllen sie
 * die Breite im Verhältnis ihrer natürlichen Breiten. Die letzte Spalte nimmt den Rundungsrest.
 */
export function columnWidths(weights: number[], minimums: number[], available: number, natural: (number | null)[] = []): number[] {
  const auto = weights.map((_, i) => natural[i] != null);
  const autoTotal = weights.reduce((s, _, i) => s + (auto[i] ? (natural[i] as number) : 0), 0);
  let shares: number[];
  if (!auto.some(Boolean)) {
    shares = weightedShares(weights, minimums, available);
  } else if (auto.every(Boolean)) {
    shares = proportional(
      natural.map((n) => n ?? 0),
      available,
    );
  } else {
    const reserve = weights.reduce((s, _, i) => s + (auto[i] ? 0 : Math.max(minimums[i], available / 25)), 0);
    const factor = autoTotal > 0 ? Math.max(0, Math.min(autoTotal, available - reserve)) / autoTotal : 0;
    const autoWidths = weights.map((_, i) => (auto[i] ? (natural[i] as number) * factor : 0));
    const rest = available - autoWidths.reduce((a, b) => a + b, 0);
    const others = weights.map((_, i) => i).filter((i) => !auto[i]);
    const otherShares = weightedShares(
      others.map((i) => weights[i]),
      others.map((i) => minimums[i]),
      rest,
    );
    shares = autoWidths;
    others.forEach((i, j) => (shares[i] = otherShares[j]));
  }
  const widths = shares.map(Math.floor);
  if (widths.length > 0) widths[widths.length - 1] += available - widths.reduce((a, b) => a + b, 0);
  return widths;
}

function weightedShares(weights: number[], minimums: number[], available: number): number[] {
  const fixed = weights.map(() => false);
  for (;;) {
    const free = available - minimums.reduce((s, m, i) => s + (fixed[i] ? m : 0), 0);
    const weight = weights.reduce((s, w, i) => s + (fixed[i] ? 0 : w), 0);
    if (free < 0 || (weight === 0 && free > 0 && fixed.some((f) => !f))) return proportional(weights, available);
    const shares = weights.map((w, i) => (fixed[i] ? minimums[i] : weight > 0 ? (free * w) / weight : 0));
    const more = shares.map((s, i) => !fixed[i] && minimums[i] > s);
    if (!more.some(Boolean)) return shares;
    more.forEach((m, i) => m && (fixed[i] = true));
  }
}

function proportional(weights: number[], available: number): number[] {
  const total = weights.reduce((a, b) => a + b, 0) || 1;
  return weights.map((w) => (available * w) / total);
}

const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

/** Geburtstag in langer Form: „9. März 1990“, ohne Jahr „9. März“. */
export function longBirthday(b: { day: number; month: number; year?: number | null }): string {
  return `${b.day}. ${MONTHS[b.month - 1] ?? ''}${b.year ? ` ${b.year}` : ''}`;
}

/** Wert zum Sortieren einer Zelle: Zahl, Text oder null (leer). */
export type SortValue = number | string | null;

/**
 * Zeilen nach einem Wert sortieren (Liste → Zeilen → Sortieren): Zahlen nach Wert, Texte wie die Mitgliederliste
 * (Umlaute wie Grundbuchstaben, Zahlen im Text nach Wert). Zeilen ohne Wert stehen in beiden Richtungen am Ende; bei
 * gleichem Wert bleibt die bisherige Reihenfolge.
 */
export function sortRowsBy<T>(rows: T[], value: (row: T, index: number) => SortValue, dir: 'asc' | 'desc'): T[] {
  const sign = dir === 'asc' ? 1 : -1;
  const keyed = rows.map((row, i) => {
    const v = value(row, i);
    return { row, i, v: typeof v === 'string' && v.trim() === '' ? null : v };
  });
  keyed.sort((a, b) => {
    if (a.v === null || b.v === null) return (a.v === null ? 1 : 0) - (b.v === null ? 1 : 0) || a.i - b.i;
    const c =
      typeof a.v === 'number' && typeof b.v === 'number'
        ? a.v - b.v
        : String(a.v).localeCompare(String(b.v), 'de', { sensitivity: 'base', numeric: true });
    return sign * c || a.i - b.i;
  });
  return keyed.map((k) => k.row);
}
