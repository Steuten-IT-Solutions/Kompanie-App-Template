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
 * Spaltenbreiten in ganzen Pixeln: nach Anteil (weights), aber mindestens so breit wie verlangt (minimums, z. B. für
 * Namen, die nicht umbrechen sollen); was die so verbreiterten Spalten mehr brauchen, geben die anderen nach ihrem
 * Anteil ab. Reicht der Platz dafür nicht, bleibt es bei den Anteilen. Die letzte Spalte nimmt den Rundungsrest.
 */
export function columnWidths(weights: number[], minimums: number[], available: number): number[] {
  const fixed = weights.map(() => false);
  let shares = weights.map(() => 0);
  for (;;) {
    const free = available - minimums.reduce((s, m, i) => s + (fixed[i] ? m : 0), 0);
    const weight = weights.reduce((s, w, i) => s + (fixed[i] ? 0 : w), 0);
    if (free < 0 || (weight === 0 && free > 0 && fixed.some((f) => !f))) {
      shares = proportional(weights, available);
      break;
    }
    shares = weights.map((w, i) => (fixed[i] ? minimums[i] : weight > 0 ? (free * w) / weight : 0));
    const more = shares.map((s, i) => !fixed[i] && minimums[i] > s);
    if (!more.some(Boolean)) break;
    more.forEach((m, i) => m && (fixed[i] = true));
  }
  const widths = shares.map(Math.floor);
  if (widths.length > 0) widths[widths.length - 1] += available - widths.reduce((a, b) => a + b, 0);
  return widths;
}

function proportional(weights: number[], available: number): number[] {
  const total = weights.reduce((a, b) => a + b, 0) || 1;
  return weights.map((w) => (available * w) / total);
}
