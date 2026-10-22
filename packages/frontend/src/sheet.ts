// Tabellen für Importe lesen: CSV als Text, Excel (.xlsx) als Tabulator-getrennter Text. Der Server prüft beides gleich
// (Domain\CsvTable). Die Excel-Datei wird nur hier im Browser gelesen; dafür lädt die App fflate erst beim Import.

/** Datei lesen: UTF-8, sonst Windows-1252 (so speichert Excel unter Windows „CSV (Trennzeichen-getrennt)“). */
export async function readText(file: File): Promise<string> {
  const bytes = await file.arrayBuffer();
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return new TextDecoder('windows-1252').decode(bytes);
  }
}

/** CSV oder Excel-Datei als Text für den Import. */
export async function readSheet(file: File): Promise<string> {
  const name = file.name.toLowerCase();
  if (name.endsWith('.xls') || name.endsWith('.ods') || name.endsWith('.numbers')) {
    throw new Error('Dieses Format kann die App nicht lesen. Bitte in Excel bzw. LibreOffice „Speichern unter“ als .xlsx oder CSV wählen.');
  }
  if (!name.endsWith('.xlsx')) return readText(file);
  const { unzipSync } = await import('fflate');
  const bytes = new Uint8Array(await file.arrayBuffer());
  return toTsv(xlsxRows(bytes, unzipSync));
}

type Unzip = (data: Uint8Array, opts?: { filter?: (file: { name: string }) => boolean }) => Record<string, Uint8Array>;

/** Zellen als Tabulator-getrennter Text; Zellen mit Anführungszeichen in Anführungszeichen, Umbrüche als Leerzeichen. */
export function toTsv(rows: string[][]): string {
  return rows
    .map((row) =>
      row
        .map((cell) => {
          const c = cell.replace(/[\t\r\n]+/g, ' ');
          return c.includes('"') ? `"${c.replace(/"/g, '""')}"` : c;
        })
        .join('\t'),
    )
    .join('\n');
}

/** Erstes Tabellenblatt einer .xlsx-Datei als Zeilen mit Zellen (Text, Zahlen, Datum als TT.MM.JJJJ). */
export function xlsxRows(bytes: Uint8Array, unzip: Unzip): string[][] {
  let files: Record<string, Uint8Array>;
  try {
    files = unzip(bytes, { filter: (f) => (f.name.startsWith('xl/') && f.name.endsWith('.xml')) || f.name.endsWith('.rels') });
  } catch {
    throw new Error('Die Datei ist keine gültige Excel-Datei (.xlsx).');
  }
  const text = (path: string): string => (files[path] ? new TextDecoder('utf-8').decode(files[path]) : '');
  const workbook = text('xl/workbook.xml');
  if (!workbook) throw new Error('Die Datei ist keine gültige Excel-Datei (.xlsx).');
  const sheetPath = firstSheetPath(workbook, text('xl/_rels/workbook.xml.rels'));
  const sheet = text(sheetPath);
  if (!sheet) throw new Error('Das erste Tabellenblatt fehlt in der Datei.');
  const shared = sharedStrings(text('xl/sharedStrings.xml'));
  const dates = dateStyles(text('xl/styles.xml'));
  const date1904 = /<workbookPr\b[^>]*\bdate1904="(?:1|true)"/.test(workbook);

  const rows: string[][] = [];
  for (const rowMatch of sheet.matchAll(/<row\b([^>]*?)(?:\/>|>([\s\S]*?)<\/row>)/g)) {
    const rowNumber = Number(attr(rowMatch[1], 'r')) || rows.length + 1;
    const cells: string[] = [];
    for (const cellMatch of (rowMatch[2] ?? '').matchAll(/<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g)) {
      const attrs = cellMatch[1];
      const ref = attr(attrs, 'r');
      const col = ref ? columnIndex(ref) : cells.length;
      while (cells.length < col) cells.push('');
      cells[col] = cellValue(attrs, cellMatch[2] ?? '', shared, dates, date1904);
    }
    while (rows.length < rowNumber - 1) rows.push([]);
    rows[rowNumber - 1] = cells;
  }
  // Leere Zellen am Ende jeder Zeile und leere Zeilen am Ende weglassen.
  const trimmed = rows.map((r) => {
    let n = r.length;
    while (n > 0 && r[n - 1].trim() === '') n--;
    return r.slice(0, n);
  });
  while (trimmed.length > 0 && trimmed[trimmed.length - 1].length === 0) trimmed.pop();
  return trimmed;
}

function firstSheetPath(workbook: string, rels: string): string {
  const sheet = workbook.match(/<sheet\b[^>]*>/)?.[0] ?? '';
  const id = sheet.match(/\br:id="([^"]+)"/)?.[1] ?? sheet.match(/:id="([^"]+)"/)?.[1];
  if (id) {
    for (const rel of rels.matchAll(/<Relationship\b[^>]*>/g)) {
      if (attr(rel[0], 'Id') === id) {
        const target = attr(rel[0], 'Target');
        return target.startsWith('/') ? target.slice(1) : `xl/${target}`;
      }
    }
  }
  return 'xl/worksheets/sheet1.xml';
}

function sharedStrings(xml: string): string[] {
  return [...xml.matchAll(/<si\b[^>]*>([\s\S]*?)<\/si>/g)].map((m) => texts(m[1]));
}

/** Text aus <t>-Elementen (auch mehrere Abschnitte mit Formatierung), ohne Lautschrift (rPh). */
function texts(xml: string): string {
  return [...xml.replace(/<rPh\b[\s\S]*?<\/rPh>/g, '').matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map((t) => unescapeXml(t[1])).join('');
}

/** Index je Zellformat (s), ob es ein Datum zeigt. */
function dateStyles(xml: string): boolean[] {
  const custom = new Map<number, string>();
  for (const m of xml.matchAll(/<numFmt\b[^>]*>/g)) {
    custom.set(Number(attr(m[0], 'numFmtId')), unescapeXml(attr(m[0], 'formatCode')));
  }
  const xfs = xml.match(/<cellXfs\b[^>]*>([\s\S]*?)<\/cellXfs>/)?.[1] ?? '';
  return [...xfs.matchAll(/<xf\b[^>]*>/g)].map((m) => {
    const id = Number(attr(m[0], 'numFmtId'));
    if ((id >= 14 && id <= 22) || (id >= 45 && id <= 47) || (id >= 27 && id <= 36) || (id >= 50 && id <= 58)) return true;
    const code = custom.get(id);
    // Ohne Text in Anführungszeichen, Farben und Gebietsschemata in [ ]: Tag oder Jahr heißt Datum (m allein: Minuten).
    return code !== undefined && /[dy]/i.test(code.replace(/"[^"]*"|\[[^\]]*\]|\\./g, ''));
  });
}

function cellValue(attrs: string, inner: string, shared: string[], dates: boolean[], date1904: boolean): string {
  const type = attr(attrs, 't');
  const v = inner.match(/<v\b[^>]*>([\s\S]*?)<\/v>/)?.[1];
  if (type === 'inlineStr') return texts(inner.match(/<is\b[^>]*>([\s\S]*?)<\/is>/)?.[1] ?? '').trim();
  if (v === undefined) return '';
  if (type === 's') return (shared[Number(v)] ?? '').trim();
  if (type === 'str') return unescapeXml(v).trim();
  if (type === 'b') return v === '1' ? 'WAHR' : 'FALSCH';
  if (type === 'e') return '';
  const n = Number(v);
  if (!Number.isFinite(n)) return unescapeXml(v).trim();
  if (dates[Number(attr(attrs, 's')) || 0]) return excelDate(n, date1904);
  // Ganze Zahlen ohne Nachkommastellen; sonst mit Komma wie in einem deutschen Excel.
  return Number.isInteger(n) ? String(n) : String(Number(n.toPrecision(15))).replace('.', ',');
}

/** Excel-Datumszahl als TT.MM.JJJJ (Uhrzeit fällt weg). */
export function excelDate(serial: number, date1904 = false): string {
  const days = Math.floor(serial) + (date1904 ? 1462 : 0);
  // Tag 0 ist der 30.12.1899 (Excel zählt den 29.02.1900 mit, den es nicht gab; davor weichen die Daten um einen Tag ab).
  const d = new Date(Date.UTC(1899, 11, 30) + days * 86400000);
  const pad = (x: number) => String(x).padStart(2, '0');
  return `${pad(d.getUTCDate())}.${pad(d.getUTCMonth() + 1)}.${d.getUTCFullYear()}`;
}

/** „AB12“ → 27 (Spalte, ab 0) */
function columnIndex(ref: string): number {
  let n = 0;
  for (const ch of ref.toUpperCase().match(/^[A-Z]+/)?.[0] ?? 'A') n = n * 26 + (ch.charCodeAt(0) - 64);
  return n - 1;
}

function attr(attrs: string, name: string): string {
  return attrs.match(new RegExp(`(?:^|\\s)${name}="([^"]*)"`))?.[1] ?? '';
}

function unescapeXml(s: string): string {
  return s
    .replace(/_x([0-9A-Fa-f]{4})_/g, (_, h: string) => String.fromCharCode(parseInt(h, 16)))
    .replace(/&(#x[0-9a-fA-F]+|#\d+|lt|gt|quot|apos|amp);/g, (_, e: string) =>
      e === 'lt'
        ? '<'
        : e === 'gt'
          ? '>'
          : e === 'quot'
            ? '"'
            : e === 'apos'
              ? "'"
              : e === 'amp'
                ? '&'
                : String.fromCodePoint(e[1] === 'x' ? parseInt(e.slice(2), 16) : Number(e.slice(1))),
    );
}
