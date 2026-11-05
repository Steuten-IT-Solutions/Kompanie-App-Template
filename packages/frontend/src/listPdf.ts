// Listen als PDF-Datei (Liste → Ausgabe → „PDF herunterladen“): dieselbe Tabelle wie im Ausdruck (PrintSheet in
// views/ListenVerwalten.tsx), aber direkt als Datei, ohne Druckdialog. Erst beim Herunterladen geladen (jsPDF ist groß).
//
// Schrift: Helvetica, eine der Standardschriften jedes PDF-Programms (nichts einzubetten). Sie kennt die Zeichen
// westeuropäischer Sprachen (Windows-1252); andere Buchstaben stehen ohne Akzent da (z. B. „ł“ bleibt „?“).
// Maße in Millimetern wie @page im Ausdruck: A4, 12 mm Rand, Seitenzahl unten rechts, Tabellenkopf auf jeder Seite.
import { jsPDF } from 'jspdf';
import { columnWidths, listFileBase } from './lists';

export interface ListPdfColumn {
  label: string;
  /** Anteil an der Breite (wie im Ausdruck) */
  weight: number;
  /** Breite „automatisch“: so breit wie der längste Inhalt */
  auto: boolean;
  /** Name: bricht nicht um (Spalte mindestens so breit wie der längste) */
  name: boolean;
  /** Gedrehte Überschrift (schmale Spalten außer Nr.) */
  rotated: boolean;
  /** Leere Spalte; mit checkbox ein Kästchen je Zeile */
  blank: boolean;
  checkbox: boolean;
}

export interface ListPdfCell {
  text: string;
  /** Zahl: rechtsbündig */
  num: boolean;
}

export interface ListPdfInput {
  title: string;
  subtitle: string;
  /** Unter dem Balken links (Name der Kompanie) und rechts (Datum der Liste, Stand) */
  metaLeft: string;
  metaRight: string;
  orientation: 'portrait' | 'landscape';
  /** Farbe der Kompanie (#rrggbb) */
  color: string;
  /** Logo (Adresse eines Bilds); ohne oder wenn es sich nicht laden lässt: ohne Logo */
  logoUrl: string | null;
  columns: ListPdfColumn[];
  rows: ListPdfCell[][];
}

const PT = 25.4 / 72;
const MARGIN = 12;
const PAGE = { portrait: [210, 297], landscape: [297, 210] } as const;
/** Wie styles.css (.list-sheet, .list-table): 10,5 pt, Innenabstand 6 px bzw. 3 px, Zeilen mindestens 8,5 mm */
const FONT = 10.5;
const PAD_X = 6 * (25.4 / 96);
const PAD_Y = 3 * (25.4 / 96);
const HEAD_PAD_Y = 5 * (25.4 / 96);
const ROW_MIN = 8.5;
const LINE = FONT * PT * 1.2;
const BORDER = 25.4 / 96;
const BORDER_COLOR = '#555555';
/** Gedrehte Überschrift: beginnt 3 mm über der Unterkante, oben 1,5 mm Luft; Kopf 12 bis 50 mm hoch */
const ROTATED_BOTTOM = 3;
const ROTATED_TOP = 1.5;
/** Spaltenbreiten in Zehntelmillimetern (columnWidths rechnet in ganzen Einheiten) */
const UNIT = 10;

/** Zeichen, die Helvetica (Windows-1252) über Latin-1 hinaus kennt */
const CP1252 = new Set('€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ');

/** Text für die Standardschrift: unbekannte Buchstaben ohne Akzent, sonst „?“. */
export function pdfText(s: string): string {
  let out = '';
  for (const ch of s) {
    const code = ch.codePointAt(0) ?? 0;
    if ((code >= 0x20 && code <= 0x7e) || (code >= 0xa0 && code <= 0xff) || CP1252.has(ch)) out += ch;
    else if (code < 0x20) out += ' ';
    else {
      const plain = ch.normalize('NFD').replace(/[̀-ͯ]/g, '');
      out += [...plain].every((c) => (c.codePointAt(0) ?? 0) <= 0xff) && plain !== '' ? plain : '?';
    }
  }
  return out;
}

function rgb(hex: string): [number, number, number] {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim());
  return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [51, 51, 51];
}

/** Zeilen abwechselnd hinterlegt: Farbe der Kompanie zu 20 % auf Weiß (wie color-mix im Ausdruck) */
const tint = ([r, g, b]: [number, number, number]): [number, number, number] => [
  Math.round(r * 0.2 + 255 * 0.8),
  Math.round(g * 0.2 + 255 * 0.8),
  Math.round(b * 0.2 + 255 * 0.8),
];

/** Logo als JPEG auf weißem Grund (der Kasten um das Logo ist ohnehin weiß); null, wenn es sich nicht laden lässt. */
async function logoJpeg(url: string): Promise<string | null> {
  try {
    const img = new Image();
    img.src = url;
    await img.decode();
    const size = 192;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d');
    if (!ctx || !img.naturalWidth || !img.naturalHeight) return null;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, size, size);
    const scale = Math.min(size / img.naturalWidth, size / img.naturalHeight);
    const w = img.naturalWidth * scale;
    const h = img.naturalHeight * scale;
    ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h);
    return canvas.toDataURL('image/jpeg', 0.92);
  } catch {
    return null;
  }
}

export function pdfFileName(title: string): string {
  return `${listFileBase(title) || 'liste'}.pdf`;
}

/** Das PDF als Datei; logo: fertiges Bild (data:-Adresse, JPEG) oder null. */
export function buildListPdf(input: ListPdfInput, logo: string | null): jsPDF {
  const [pageW, pageH] = PAGE[input.orientation];
  const doc = new jsPDF({ orientation: input.orientation, unit: 'mm', format: 'a4', compress: true });
  doc.setProperties({ title: pdfText(input.title), creator: pdfText(input.metaLeft) });
  const color = rgb(input.color);
  const width = pageW - 2 * MARGIN;
  const cols = input.columns;
  const left = MARGIN;

  // --- Kopf der Seite: Balken mit Logo, Titel und Untertitel, darunter Kompanie und Datum ---
  const logoSize = 36 * (25.4 / 96);
  const barPadX = 10 * (25.4 / 96);
  const barPadY = 6 * (25.4 / 96);
  const headingW = width - 2 * (barPadX + logoSize + 10 * (25.4 / 96));
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  const titleLines: string[] = doc.splitTextToSize(pdfText(input.title), headingW);
  const titleLine = 17 * PT * 1.15;
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  const subLines: string[] = input.subtitle.trim() !== '' ? doc.splitTextToSize(pdfText(input.subtitle), headingW) : [];
  const subLine = 10 * PT * 1.2;
  const textH = titleLines.length * titleLine + (subLines.length > 0 ? 0.5 + subLines.length * subLine : 0);
  const barH = Math.max(12, textH + 2 * barPadY, logoSize + 2 * barPadY);
  doc.setFillColor(...color);
  doc.rect(left, MARGIN, width, barH, 'F');
  if (logo) {
    const lx = left + barPadX;
    const ly = MARGIN + (barH - logoSize) / 2;
    doc.setFillColor(255, 255, 255);
    doc.roundedRect(lx, ly, logoSize, logoSize, 1.6, 1.6, 'F');
    const inset = 2 * (25.4 / 96);
    doc.addImage(logo, 'JPEG', lx + inset, ly + inset, logoSize - 2 * inset, logoSize - 2 * inset);
  }
  doc.setTextColor(255, 255, 255);
  let ty = MARGIN + (barH - textH) / 2;
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  for (const l of titleLines) {
    doc.text(l, pageW / 2, ty + titleLine / 2, { align: 'center', baseline: 'middle' });
    ty += titleLine;
  }
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  ty += 0.5;
  for (const l of subLines) {
    doc.text(l, pageW / 2, ty + subLine / 2, { align: 'center', baseline: 'middle' });
    ty += subLine;
  }
  doc.setFontSize(8.5);
  doc.setTextColor(68, 68, 68);
  const metaY = MARGIN + barH + 1 + 8.5 * PT * 0.6;
  doc.text(pdfText(input.metaLeft), left, metaY, { baseline: 'middle' });
  doc.text(pdfText(input.metaRight), left + width, metaY, { align: 'right', baseline: 'middle' });
  const tableTop = metaY + 8.5 * PT * 0.6 + 2.1;

  // --- Spaltenbreiten wie im Ausdruck, gemessen mit der Schrift des PDFs ---
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(FONT);
  const rotatedLabels = cols.filter((c) => c.rotated).map((c) => pdfText(c.label));
  const longest = Math.max(0, ...rotatedLabels.map((l) => doc.getTextWidth(l)));
  const headRotated =
    rotatedLabels.length > 0 ? Math.min(50, Math.max(12, (longest + 7 * (25.4 / 96)) / Math.SQRT2 + ROTATED_BOTTOM + ROTATED_TOP)) : 0;
  const headWidths = cols.map((c) => doc.getTextWidth(pdfText(c.label)));
  doc.setFont('helvetica', 'normal');
  const cellWidth = (i: number) => Math.max(0, ...input.rows.map((r) => doc.getTextWidth(pdfText(r[i]?.text ?? ''))));
  const extra = 2 * PAD_X + 1;
  const minimums = cols.map((c, i) => (c.name ? Math.ceil((cellWidth(i) + extra) * UNIT) : 0));
  const natural = cols.map((c, i) =>
    c.auto ? Math.ceil((Math.max(cellWidth(i), headWidths[i], c.checkbox ? 3.4 : 0) + extra) * UNIT) : null,
  );
  // Der gekippte Kopf der letzten gedrehten Spalte ragt oben um seine Höhe nach rechts: Platz dafür am Ende lassen.
  const lastRotated = cols.map((c) => c.rotated).lastIndexOf(true);
  let spare = 0;
  let widths: number[] = [];
  for (let round = 0; round < 4; round++) {
    widths = columnWidths(
      cols.map((c) => c.weight),
      minimums,
      Math.floor((width - spare) * UNIT),
      natural,
    ).map((w) => w / UNIT);
    const tail = widths.slice(lastRotated + 1).reduce((a, b) => a + b, 0);
    const next = lastRotated < 0 ? 0 : Math.max(0, headRotated - tail);
    if (Math.abs(next - spare) < 0.05) break;
    spare = next;
  }
  const x = widths.reduce((acc, w) => [...acc, acc[acc.length - 1] + w], [left]);
  const tableRight = x[x.length - 1];

  // --- Tabellenkopf (auf jeder Seite) ---
  doc.setFont('helvetica', 'bold');
  const headLines = cols.map((c, i) =>
    c.rotated ? [] : (doc.splitTextToSize(pdfText(c.label), Math.max(1, widths[i] - 2 * PAD_X)) as string[]),
  );
  const headH = Math.max(headRotated, ...headLines.map((l) => l.length * LINE + 2 * HEAD_PAD_Y), 0);

  /** Wie weit gekippte Köpfe davor in Höhe y (von oben) in die gerade Spalte i hineinragen */
  const coverAt = (i: number, y: number) => {
    const r = cols
      .slice(0, i)
      .map((c) => c.rotated)
      .lastIndexOf(true);
    return r < 0 ? 0 : Math.max(0, x[r + 1] + (headH - y) - x[i]);
  };

  const drawHead = (top: number) => {
    const bottom = top + headH;
    doc.setFillColor(...color);
    cols.forEach((c, i) => !c.rotated && doc.rect(x[i], top, widths[i], headH, 'F'));
    doc.setDrawColor(BORDER_COLOR);
    doc.setLineWidth(BORDER);
    cols.forEach((c, i) => {
      if (!c.rotated && (i === 0 || !cols[i - 1].rotated)) doc.line(x[i], top, x[i], bottom);
    });
    if (cols.length > 0 && !cols[cols.length - 1].rotated) doc.line(tableRight, top, tableRight, bottom);
    cols.forEach((c, i) => {
      if (!c.rotated) return;
      if (!(i > 0 && cols[i - 1].rotated)) {
        // Dreieck vor der ersten gedrehten Spalte: schließt an die gerade Spalte davor an
        doc.lines(
          [
            [headH, 0],
            [-headH, headH],
          ],
          x[i],
          top,
          [1, 1],
          'F',
          true,
        );
      }
      doc.lines(
        [
          [widths[i], 0],
          [headH, -headH],
          [-widths[i], 0],
        ],
        x[i],
        bottom,
        [1, 1],
        'F',
        true,
      );
    });
    cols.forEach((c, i) => {
      if (!c.rotated) return;
      doc.line(x[i], bottom, x[i] + headH, top);
      if (!(cols[i + 1] && cols[i + 1].rotated)) doc.line(x[i + 1], bottom, x[i + 1] + headH, top);
    });
    doc.line(left, top, tableRight + spare, top);
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(FONT);
    const half = FONT * PT * 0.35;
    cols.forEach((c, i) => {
      if (c.rotated) {
        // Mitte des Textes auf der Mittellinie des Felds: unten in der Spaltenmitte, 3 mm höher also 3 mm weiter rechts
        const sx = (x[i] + x[i + 1]) / 2 + ROTATED_BOTTOM;
        const sy = bottom - ROTATED_BOTTOM;
        doc.text(pdfText(c.label), sx + half * Math.SQRT1_2, sy + half * Math.SQRT1_2, { angle: 45 });
        return;
      }
      const inner = widths[i] - 2 * PAD_X;
      // Ragt ein gekippter Kopf davor hinein, beginnt der Text rechts davon (Zeile für Zeile).
      let lines = headLines[i];
      let blockTop = top + (headH - lines.length * LINE) / 2;
      if (coverAt(i, 0) > 0) {
        for (let round = 0; round < 3; round++) {
          const inset = coverAt(i, Math.max(0, blockTop - top));
          lines = doc.splitTextToSize(pdfText(c.label), Math.max(1, inner - inset)) as string[];
          blockTop = Math.max(top + HEAD_PAD_Y, top + (headH - lines.length * LINE) / 2);
        }
      }
      lines.forEach((l, k) => {
        const y = blockTop + k * LINE;
        const inset = coverAt(i, y - top);
        const from = x[i] + PAD_X + inset;
        const to = x[i + 1] - PAD_X;
        doc.text(l, (from + Math.max(from, to)) / 2, y + LINE / 2, { align: 'center', baseline: 'middle' });
      });
    });
  };

  // --- Zeilen ---
  const tinted = tint(color);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(FONT);
  const bodyLines = input.rows.map((r) =>
    cols.map((c, i) =>
      c.blank || (r[i]?.text ?? '') === '' ? [] : (doc.splitTextToSize(pdfText(r[i].text), Math.max(1, widths[i] - 2 * PAD_X)) as string[]),
    ),
  );
  let page = 1;
  let y = tableTop;
  drawHead(y);
  y += headH;
  input.rows.forEach((r, ri) => {
    const h = Math.max(ROW_MIN, ...bodyLines[ri].map((l) => l.length * LINE + 2 * PAD_Y));
    if (y + h > pageH - MARGIN) {
      doc.addPage('a4', input.orientation);
      page++;
      y = MARGIN;
      drawHead(y);
      y += headH;
    }
    if (ri % 2 === 0) {
      doc.setFillColor(...tinted);
      doc.rect(left, y, tableRight - left, h, 'F');
    }
    doc.setDrawColor(BORDER_COLOR);
    doc.setLineWidth(BORDER);
    doc.rect(left, y, tableRight - left, h, 'S');
    for (let i = 1; i < cols.length; i++) doc.line(x[i], y, x[i], y + h);
    doc.setTextColor(0, 0, 0);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(FONT);
    cols.forEach((c, i) => {
      if (c.blank) {
        if (c.checkbox) {
          const s = 3.4;
          doc.setDrawColor(0, 0, 0);
          doc.rect((x[i] + x[i + 1] - s) / 2, y + (h - s) / 2, s, s, 'S');
          doc.setDrawColor(BORDER_COLOR);
        }
        return;
      }
      const lines = bodyLines[ri][i];
      const top = y + (h - lines.length * LINE) / 2;
      const num = r[i]?.num ?? false;
      lines.forEach((l, k) =>
        doc.text(l, num ? x[i + 1] - PAD_X : x[i] + PAD_X, top + k * LINE + LINE / 2, {
          align: num ? 'right' : 'left',
          baseline: 'middle',
        }),
      );
    });
    y += h;
  });

  // --- Seitenzahlen unten rechts ---
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(8);
  doc.setTextColor(0, 0, 0);
  for (let p = 1; p <= page; p++) {
    doc.setPage(p);
    doc.text(`Seite ${p} von ${page}`, pageW - MARGIN, pageH - MARGIN / 2, { align: 'right', baseline: 'middle' });
  }
  return doc;
}

/** Erstellen und herunterladen. */
export async function downloadListPdf(input: ListPdfInput): Promise<void> {
  const logo = input.logoUrl ? await logoJpeg(input.logoUrl) : null;
  // Wie die CSV über einen Link mit download (jsPDF.save nennt die Datei nicht in jedem Browser wie gewünscht).
  const blob = buildListPdf(input, logo).output('blob');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = pdfFileName(input.title);
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
