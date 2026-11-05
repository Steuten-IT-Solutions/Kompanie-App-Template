// Ersatz für Bausteine, die jsPDF nur für Funktionen braucht, die die App nicht nutzt: html2canvas und canvg (doc.html(),
// SVG-Bilder) und fast-png (PNG-Bilder; das Logo im PDF ist ein JPEG, listPdf.ts). So kommen sie nicht in den Build
// (vite.config.ts, resolve.alias).
function unused(): never {
  throw new Error('In der App nicht enthalten.');
}
export default unused;
export const decode = unused;
