import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

const require = createRequire(import.meta.url);

// Name und Farben der Kompanie in index.html einsetzen (aus src/generated/brand.json,
// erzeugt von packages/branding vor jedem Build).
function brandHtml(): Plugin {
  return {
    name: 'kompanie-brand-html',
    transformIndexHtml(html) {
      const brand = JSON.parse(readFileSync(new URL('./src/generated/brand.json', import.meta.url), 'utf8'));
      // Ersetzungen als Funktion: $-Zeichen im Namen (z. B. „$'“) sind sonst Ersetzungsmuster.
      const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
      return html
        .replace(/%KOMPANIE_NAME%/g, () => esc(brand.name))
        .replace(/%KOMPANIE_SHORT_NAME%/g, () => esc(brand.shortName))
        .replace(/%KOMPANIE_DESCRIPTION%/g, () => esc(brand.description))
        .replace(/%KOMPANIE_THEME_COLOR%/g, () => esc(brand.themeColor));
    },
  };
}

// Liste der Programmdateien und eine Build-Kennung in den Service Worker einsetzen (Vorab-Speicher).
// public/sw.js wird unverändert nach dist/ kopiert; danach ersetzt dieser Schritt die Platzhalter.
function precacheList(): Plugin {
  let files: string[] = [];
  let outDir = 'dist';
  return {
    name: 'kompanie-precache',
    apply: 'build',
    configResolved(config) {
      outDir = config.build.outDir.startsWith('/') ? config.build.outDir : join(config.root, config.build.outDir);
    },
    generateBundle(_options, bundle) {
      // Die Bild-Dekodierer von pdf.js (selten gebraucht, groß) erst bei Bedarf speichern.
      files = Object.keys(bundle)
        .filter((f) => f.startsWith('assets/') && !f.startsWith(`assets/${PDFJS_DIR}`))
        .sort();
    },
    closeBundle() {
      const file = join(outDir, 'sw.js');
      const build = createHash('sha256').update(files.join('\n')).digest('hex').slice(0, 12);
      const sw = readFileSync(file, 'utf8');
      const out = sw
        .replace("const BUILD = 'dev';", () => `const BUILD = '${build}';`)
        .replace('const PRECACHE = [];', () => `const PRECACHE = ${JSON.stringify(files)};`);
      if (out === sw) throw new Error('sw.js: Platzhalter für den Vorab-Speicher nicht gefunden.');
      writeFileSync(file, out);
    },
  };
}

// pdf.js (Issue #2): Bilder in gescannten PDFs (JBIG2, JPEG 2000) dekodiert pdf.js mit eigenen Modulen, die es zur
// Laufzeit unter festem Namen nachlädt. Hier die reinen JavaScript-Fassungen (keine .wasm-Dateien: manche Webserver
// kennen den Typ nicht, und die Veröffentlichung nimmt nur bekannte Dateitypen an). Ordner mit der Version im Namen,
// weil Dateien unter assets/ ein Jahr unverändert gespeichert werden dürfen.
const pdfjsVersion: string = JSON.parse(readFileSync(require.resolve('pdfjs-dist/package.json'), 'utf8')).version;
const PDFJS_DIR = `pdfjs-${pdfjsVersion}/`;

function pdfjsDecoders(): Plugin {
  return {
    name: 'kompanie-pdfjs-decoders',
    apply: 'build',
    generateBundle() {
      for (const name of ['jbig2_nowasm_fallback.js', 'openjpeg_nowasm_fallback.js']) {
        this.emitFile({
          type: 'asset',
          fileName: `assets/${PDFJS_DIR}${name}`,
          source: readFileSync(require.resolve(`pdfjs-dist/wasm/${name}`)),
        });
      }
    },
  };
}

// Der Build landet in dist/. Die Adapter liefern index.html unter der Adresse der App aus
// und biegen die relativen Pfade (base: './') auf ihr Verzeichnis um.
export default defineConfig({
  base: './',
  plugins: [react(), brandHtml(), pdfjsDecoders(), precacheList()],
  define: { __PDFJS_DIR__: JSON.stringify(PDFJS_DIR) },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: 'assets',
    // Die PDF-Anzeige (pdf.js, Issue #2) wird erst beim Öffnen eines Dokuments nachgeladen.
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        // Der pdf.js-Worker hieße sonst *.mjs: Manche Webserver liefern .mjs ohne JavaScript-Typ aus (dann startet
        // der Worker nicht), und die Veröffentlichung nimmt nur bekannte Dateitypen an (scripts/import-build.php).
        assetFileNames: (info) => (info.names?.[0]?.endsWith('.mjs') ? 'assets/[name]-[hash].js' : 'assets/[name]-[hash][extname]'),
      },
    },
  },
});
