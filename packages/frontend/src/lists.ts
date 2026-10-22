// Listen (Issue #121): CSV für Excel und Sortieren nach Namen, ohne Abhängigkeiten (auch in den Tests nutzbar).

/** Zeile einer Liste, wie sie zum Sortieren gebraucht wird (Mitglied oder frei). */
export interface SortableRow {
  kind: 'member' | 'free';
  name: string;
  first_name?: string;
  last_name?: string;
  deleted?: boolean;
}

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

/** Dateiname aus dem Titel: ohne Zeichen, die Dateisysteme nicht mögen. */
export function csvFileName(title: string): string {
  const base = title
    .replace(/[\\/:*?"<>|\u0000-\u001F]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 80);
  return `${base || 'liste'}.csv`;
}

const cmp = (a: string, b: string) => a.localeCompare(b, 'de', { sensitivity: 'base', numeric: true });

/** Vor- und Nachname einer Zeile; bei freien Zeilen ist das letzte Wort der Nachname (wie splitName in memberSort.ts). */
function nameParts(r: SortableRow): { first: string; last: string } {
  if (r.kind === 'member' && r.first_name !== undefined) return { first: r.first_name, last: r.last_name ?? '' };
  const parts = r.name.trim().split(/\s+/);
  const last = parts.length > 1 ? (parts.pop() ?? '') : '';
  return { first: parts.join(' '), last };
}

/**
 * „Nach Namen sortieren“: Mitglieder und freie Zeilen mit Namen nach Nachname, dann Vorname (ohne Nachnamen zählt der
 * Vorname, wie in der Mitgliederliste); leere freie Zeilen und Zeilen gelöschter Konten danach, in ihrer Reihenfolge.
 */
export function sortRowsByName<T extends SortableRow>(rows: T[]): T[] {
  const named = rows.filter((r) => !r.deleted && r.name.trim() !== '');
  const rest = rows.filter((r) => r.deleted || r.name.trim() === '');
  const sorted = [...named].sort((a, b) => {
    const x = nameParts(a);
    const y = nameParts(b);
    return cmp(x.last || x.first, y.last || y.first) || cmp(x.first, y.first);
  });
  return [...sorted, ...rest];
}
