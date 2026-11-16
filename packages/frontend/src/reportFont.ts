// Schrift für den Bericht als PDF (reportPdf.ts, ReportFonts): Source Sans 3 (SIL Open Font License 1.1, Paket
// source-sans von Adobe), unverändert als TTF, normal und fett. Sie kennt die Buchstaben europäischer Sprachen (z. B.
// „ł“, „ő“, „č“), anders als die Standardschrift Helvetica der PDF-Programme (Windows-1252). jsPDF bettet beim Speichern
// nur die benutzten Zeichen ein (gemessen: ein Bericht mit 7 Seiten in beiden Schnitten hat 94 KB). Eigenes Teilstück,
// erst beim Erstellen geladen und nicht vorab gespeichert (vite.config.ts): Es ist groß und nur für den Bericht nötig.
import regularUrl from 'source-sans/TTF/SourceSans3-Regular.ttf?inline';
import boldUrl from 'source-sans/TTF/SourceSans3-Bold.ttf?inline';

/** Inhalt einer data:-Adresse (Base64), wie jsPDF ihn für addFileToVFS braucht */
const base64 = (url: string) => url.slice(url.indexOf(',') + 1);

export const regular = base64(regularUrl);
export const bold = base64(boldUrl);
