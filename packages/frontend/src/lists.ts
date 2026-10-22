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
