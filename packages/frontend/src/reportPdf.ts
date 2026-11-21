// Bericht als PDF (Verwaltung → Bericht): die Tabellen, die der Server liefert (GET /admin/report), als PDF-Datei. Wie
// das Listen-PDF (listPdf.ts) mit jsPDF im Browser und erst beim Erstellen geladen, aber mit eingebetteter Schrift
// (reportFont.ts), damit alle Buchstaben stimmen.
//
// Aufbau: Deckblatt mit Titelbalken in der Farbe der Kompanie, Zeitraum und Inhalt (mit Seitenzahlen); je Bereich eine
// neue Seite (quer bei breiten Tabellen) mit Überschrift und Tabellen. Tabellenkopf auf jeder Seite, Zeilen werden nicht
// geteilt, Fußzeile mit Kompanie, Zeitraum und Seitenzahl. Maße in Millimetern, A4, 12 mm Rand.
import { jsPDF } from 'jspdf';
import type { Report, ReportSection, ReportTable } from './api';
import { columnWidths } from './lists';
import { t } from './i18n';
import { rangeLabel, reportFileName } from './report';
import { saveFile } from './saveFile';

export interface ReportPdfMeta {
  /** Name der Kompanie */
  company: string;
  /** Farbe der Kompanie (#rrggbb) */
  color: string;
  /** Logo (Adresse eines Bilds); ohne oder wenn es sich nicht laden lässt: ohne Logo */
  logoUrl: string | null;
}

/** Schrift im PDF (Dateien aus reportFont.ts, Base64) */
export interface ReportFonts {
  regular: string;
  bold: string;
}

const REPORT_FONT = 'SourceSans3';
const PT = 25.4 / 72;
const MARGIN = 12;
const PAGE = { portrait: [210, 297], landscape: [297, 210] } as const;
const FONT = 9.5;
const LINE = FONT * PT * 1.25;
const PAD_X = 1.6;
const PAD_Y = 1.1;
const ROW_MIN = 6;
const BORDER = 25.4 / 96;
const BORDER_COLOR = '#777777';
/** Platz unten für die Fußzeile */
const FOOTER = 8;
/** Spaltenbreiten in Zehntelmillimetern (columnWidths rechnet in ganzen Einheiten) */
const UNIT = 10;
/** Lange Texte (wrap) mindestens so breit */
const WRAP_MIN = 30;

type Rgb = [number, number, number];

function rgb(hex: string): Rgb {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim());
  return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [51, 51, 51];
}

/** Zeilen abwechselnd hinterlegt: Farbe der Kompanie zu 15 % auf Weiß */
const tint = ([r, g, b]: Rgb): Rgb => [
  Math.round(r * 0.15 + 255 * 0.85),
  Math.round(g * 0.15 + 255 * 0.85),
  Math.round(b * 0.15 + 255 * 0.85),
];

/** Logo als JPEG auf weißem Grund; null, wenn es sich nicht laden lässt. */
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

/** Steuerzeichen (z. B. Zeilenumbrüche in Gründen) als Leerzeichen: jsPDF bräche sonst an fremden Stellen um */
const clean = (s: string) => s.replace(/[\u0000-\u001f\u007f]+/g, ' ');

/** Das PDF; logo: fertiges Bild (data:-Adresse, JPEG) oder null. */
export function buildReportPdf(report: Report, meta: ReportPdfMeta, logo: string | null, fonts: ReportFonts): jsPDF {
  const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
  doc.addFileToVFS(`${REPORT_FONT}-Regular.ttf`, fonts.regular);
  doc.addFont(`${REPORT_FONT}-Regular.ttf`, REPORT_FONT, 'normal');
  doc.addFileToVFS(`${REPORT_FONT}-Bold.ttf`, fonts.bold);
  doc.addFont(`${REPORT_FONT}-Bold.ttf`, REPORT_FONT, 'bold');
  const period = rangeLabel(report);
  doc.setProperties({ title: t('Bericht {period}', { period }), subject: meta.company, creator: meta.company });
  const color = rgb(meta.color);
  const tinted = tint(color);
  const font = (style: 'normal' | 'bold', size: number) => {
    doc.setFont(REPORT_FONT, style);
    doc.setFontSize(size);
  };

  let pageW: number = PAGE.portrait[0];
  let pageH: number = PAGE.portrait[1];
  let y = MARGIN;

  /** Balken in der Farbe der Kompanie mit Logo, Titel und Untertitel; Höhe zurück */
  const bar = (title: string, subtitle: string, withLogo: boolean): number => {
    const width = pageW - 2 * MARGIN;
    const logoSize = withLogo && logo ? 16 : 0;
    const padX = 4;
    const textW = width - 2 * (padX + (logoSize ? logoSize + 3 : 0));
    font('bold', withLogo ? 18 : 14);
    const titleSize = withLogo ? 18 : 14;
    const titleLines: string[] = doc.splitTextToSize(clean(title), textW);
    font('normal', 10);
    const subLines: string[] = subtitle ? doc.splitTextToSize(clean(subtitle), textW) : [];
    const titleLine = titleSize * PT * 1.15;
    const subLine = 10 * PT * 1.25;
    const textH = titleLines.length * titleLine + (subLines.length ? 1 + subLines.length * subLine : 0);
    const h = Math.max(textH + 6, logoSize + 6, 12);
    doc.setFillColor(...color);
    doc.rect(MARGIN, y, width, h, 'F');
    if (logoSize && logo) {
      const ly = y + (h - logoSize) / 2;
      doc.setFillColor(255, 255, 255);
      doc.roundedRect(MARGIN + padX, ly, logoSize, logoSize, 1.6, 1.6, 'F');
      doc.addImage(logo, 'JPEG', MARGIN + padX + 0.6, ly + 0.6, logoSize - 1.2, logoSize - 1.2);
    }
    doc.setTextColor(255, 255, 255);
    let ty = y + (h - textH) / 2;
    font('bold', titleSize);
    for (const l of titleLines) {
      doc.text(l, pageW / 2, ty + titleLine / 2, { align: 'center', baseline: 'middle' });
      ty += titleLine;
    }
    font('normal', 10);
    ty += 1;
    for (const l of subLines) {
      doc.text(l, pageW / 2, ty + subLine / 2, { align: 'center', baseline: 'middle' });
      ty += subLine;
    }
    doc.setTextColor(0, 0, 0);
    return h;
  };

  const newPage = (orientation: 'portrait' | 'landscape') => {
    doc.addPage('a4', orientation);
    [pageW, pageH] = PAGE[orientation];
    y = MARGIN;
  };
  const bottom = () => pageH - MARGIN - FOOTER;

  // --- Deckblatt ---
  y += bar(t('Bericht'), period, true) + 4;
  font('normal', 9);
  doc.setTextColor(68, 68, 68);
  doc.text(meta.company, MARGIN, y, { baseline: 'middle' });
  doc.text(t('Erstellt am {date}', { date: report.created }), pageW - MARGIN, y, { align: 'right', baseline: 'middle' });
  y += 10;
  doc.setTextColor(0, 0, 0);
  font('bold', 13);
  doc.text(t('Inhalt'), MARGIN, y, { baseline: 'middle' });
  y += 8;
  const tocY: number[] = [];
  font('normal', 11);
  for (const s of report.sections) {
    tocY.push(y);
    doc.text(clean(s.title) + (s.dated ? '' : ' ' + t('(Stand heute)')), MARGIN, y, { baseline: 'middle' });
    y += 7;
  }
  y += 4;
  font('normal', 9);
  doc.setTextColor(68, 68, 68);
  const note: string[] = doc.splitTextToSize(
    t(
      'Der Bericht kann persönliche Daten enthalten. Gib ihn nur an Personen weiter, die sie sehen dürfen, und lösche ihn, wenn du ihn nicht mehr brauchst.',
    ),
    pageW - 2 * MARGIN,
  );
  doc.text(note, MARGIN, y);
  doc.setTextColor(0, 0, 0);

  // --- Abschnitte ---
  const sectionPages: number[] = [];
  for (const s of report.sections) {
    newPage(s.landscape ? 'landscape' : 'portrait');
    sectionPages.push(doc.getNumberOfPages());
    y += bar(s.title, s.dated ? period : t('Stand heute ({date})', { date: report.created }), false) + 5;
    s.tables.forEach((t, i) => {
      if (i > 0) y += 5;
      drawTable(s, t);
    });
  }

  function drawTable(s: ReportSection, t: ReportTable) {
    const width = pageW - 2 * MARGIN;
    const cols = t.columns;
    // Spaltenbreiten: kurze Spalten so breit wie ihr längster Inhalt, lange Texte (wrap) teilen sich den Rest.
    font('bold', FONT);
    const head = cols.map((c) => doc.getTextWidth(clean(c.label)));
    font('normal', FONT);
    const natural = cols.map((c, i) =>
      c.wrap ? null : Math.ceil((Math.max(head[i], ...t.rows.map((r) => doc.getTextWidth(clean(r[i] ?? '')))) + 2 * PAD_X + 0.5) * UNIT),
    );
    // Ohne lange Texte und wenn alles passt: so schmal wie nötig (eine kurze Summentabelle zieht sich nicht über die Seite).
    const fits = natural.every((n) => n !== null) && natural.reduce<number>((a, n) => a + (n ?? 0), 0) <= width * UNIT;
    const widths = (
      fits
        ? natural.map((n) => n ?? 0)
        : columnWidths(
            cols.map(() => 1),
            cols.map((c) => (c.wrap ? WRAP_MIN * UNIT : 0)),
            Math.floor(width * UNIT),
            natural,
          )
    ).map((w) => w / UNIT);
    const x = widths.reduce((acc, w) => [...acc, acc[acc.length - 1] + w], [MARGIN]);
    const right = x[x.length - 1];
    const lines = (text: string, i: number) => doc.splitTextToSize(clean(text), Math.max(1, widths[i] - 2 * PAD_X)) as string[];

    font('bold', FONT);
    const headLines = cols.map((c, i) => lines(c.label, i));
    const headH = Math.max(ROW_MIN, ...headLines.map((l) => l.length * LINE + 2 * PAD_Y));
    const drawHead = () => {
      doc.setFillColor(...color);
      doc.rect(MARGIN, y, right - MARGIN, headH, 'F');
      doc.setDrawColor(BORDER_COLOR);
      doc.setLineWidth(BORDER);
      doc.rect(MARGIN, y, right - MARGIN, headH, 'S');
      doc.setTextColor(255, 255, 255);
      font('bold', FONT);
      cols.forEach((c, i) => {
        const top = y + (headH - headLines[i].length * LINE) / 2;
        headLines[i].forEach((l, k) =>
          doc.text(l, c.num ? x[i + 1] - PAD_X : x[i] + PAD_X, top + k * LINE + LINE / 2, {
            align: c.num ? 'right' : 'left',
            baseline: 'middle',
          }),
        );
      });
      doc.setTextColor(0, 0, 0);
      y += headH;
    };

    // Überschrift der Tabelle bleibt mit dem Kopf und der ersten Zeile zusammen.
    const titleH = t.title ? 7 : 0;
    if (y + titleH + headH + ROW_MIN > bottom()) newPage(s.landscape ? 'landscape' : 'portrait');
    if (t.title) {
      font('bold', 11);
      doc.text(clean(t.title), MARGIN, y + 3, { baseline: 'middle' });
      y += titleH;
    }
    if (t.rows.length === 0) {
      font('normal', FONT);
      doc.setTextColor(90, 90, 90);
      doc.text(clean(t.empty), MARGIN, y + 2, { baseline: 'middle' });
      doc.setTextColor(0, 0, 0);
      y += 6;
      return;
    }
    drawHead();
    font('normal', FONT);
    t.rows.forEach((r, ri) => {
      const cells = cols.map((_, i) => lines(r[i] ?? '', i));
      const h = Math.max(ROW_MIN, ...cells.map((l) => l.length * LINE + 2 * PAD_Y));
      if (y + h > bottom()) {
        newPage(s.landscape ? 'landscape' : 'portrait');
        drawHead();
        font('normal', FONT);
      }
      if (ri % 2 === 1) {
        doc.setFillColor(...tinted);
        doc.rect(MARGIN, y, right - MARGIN, h, 'F');
      }
      doc.setDrawColor(BORDER_COLOR);
      doc.setLineWidth(BORDER);
      doc.rect(MARGIN, y, right - MARGIN, h, 'S');
      for (let i = 1; i < cols.length; i++) doc.line(x[i], y, x[i], y + h);
      cols.forEach((c, i) => {
        const top = y + (h - cells[i].length * LINE) / 2;
        cells[i].forEach((l, k) =>
          doc.text(l, c.num ? x[i + 1] - PAD_X : x[i] + PAD_X, top + k * LINE + LINE / 2, {
            align: c.num ? 'right' : 'left',
            baseline: 'middle',
          }),
        );
      });
      y += h;
    });
  }

  // --- Seitenzahlen im Inhalt, Fußzeile auf jeder Seite ---
  const pages = doc.getNumberOfPages();
  doc.setPage(1);
  font('normal', 11);
  tocY.forEach((ty, i) =>
    doc.text(t('Seite {page}', { page: sectionPages[i] }), PAGE.portrait[0] - MARGIN, ty, { align: 'right', baseline: 'middle' }),
  );
  for (let p = 1; p <= pages; p++) {
    doc.setPage(p);
    const w = doc.internal.pageSize.getWidth();
    const h = doc.internal.pageSize.getHeight();
    font('normal', 8);
    doc.setTextColor(68, 68, 68);
    doc.text(t('{company} · Bericht {period}', { company: meta.company, period }), MARGIN, h - MARGIN / 2 - 1, { baseline: 'middle' });
    doc.text(t('Seite {page} von {pages}', { page: p, pages }), w - MARGIN, h - MARGIN / 2 - 1, { align: 'right', baseline: 'middle' });
  }
  doc.setTextColor(0, 0, 0);
  return doc;
}

/** Erstellen und herunterladen (auf dem iPhone über „Teilen“). */
export async function downloadReportPdf(report: Report, meta: ReportPdfMeta): Promise<void> {
  // Die Schrift ist ein eigenes, großes Teilstück: erst jetzt laden (nicht vorab gespeichert, vite.config.ts).
  const [fonts, logo] = await Promise.all([import('./reportFont'), meta.logoUrl ? logoJpeg(meta.logoUrl) : Promise.resolve(null)]);
  const blob = buildReportPdf(report, meta, logo, fonts).output('blob');
  await saveFile(blob, reportFileName(report), { title: t('Bericht {period}', { period: rangeLabel(report) }), mime: 'application/pdf' });
}
