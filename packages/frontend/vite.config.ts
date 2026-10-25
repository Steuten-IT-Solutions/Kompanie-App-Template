import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { Plugin as PostcssPlugin } from 'postcss';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { thirdPartyLicenses } from './build/licenses';

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
// Dazu die Icons, die die App-Seite und die Benachrichtigungen nutzen (feste Namen: ihr Inhalt geht in die Kennung ein).
const PRECACHE_ICONS = ['icons/favicon-32.png', 'icons/apple-touch-icon.png', 'icons/icon-192.png'];

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
      const hash = createHash('sha256').update(files.join('\n'));
      const icons = PRECACHE_ICONS.filter((icon) => existsSync(join(outDir, icon)));
      for (const icon of icons) hash.update(icon).update(readFileSync(join(outDir, icon)));
      const build = hash.digest('hex').slice(0, 12);
      const sw = readFileSync(file, 'utf8');
      const out = sw
        .replace("const BUILD = 'dev';", () => `const BUILD = '${build}';`)
        .replace('const PRECACHE = [];', () => `const PRECACHE = ${JSON.stringify([...files, ...icons])};`);
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

// Darstellung hell oder dunkel unabhängig vom Gerät (Konto → Darstellung, src/theme.ts): Jede Regel in
// @media (prefers-color-scheme: dark) gilt nur noch ohne data-theme="light" an <html>, und eine Kopie ohne @media gilt
// mit data-theme="dark". :where() hält die Spezifität gleich, damit die Regeln wie bisher wirken. generated/theme.css
// bleibt dabei unverändert (Download-Seite und Handbuch nutzen es ohne Umschalter).
function themeOverride(): PostcssPlugin {
  const scoped = (selector: string, attr: string) =>
    /^(:root|html)(?![\w-])/.test(selector) ? selector.replace(/^(:root|html)/, `$1:where(${attr})`) : `:where(:root${attr}) ${selector}`;
  const done = new WeakSet<object>();
  return {
    postcssPlugin: 'kompanie-theme-override',
    AtRule: {
      media(at) {
        if (done.has(at) || at.params.replace(/\s+/g, '') !== '(prefers-color-scheme:dark)') return;
        done.add(at);
        const copy = at.clone();
        copy.walkRules((rule) => {
          rule.selectors = rule.selectors.map((s) => scoped(s, "[data-theme='dark']"));
        });
        at.walkRules((rule) => {
          rule.selectors = rule.selectors.map((s) => scoped(s, ":not([data-theme='light'])"));
        });
        at.after(copy.nodes);
      },
    },
  };
}

// Der Build landet in dist/. Die Adapter liefern index.html unter der Adresse der App aus
// und biegen die relativen Pfade (base: './') auf ihr Verzeichnis um.
export default defineConfig({
  base: './',
  plugins: [
    react(),
    brandHtml(),
    pdfjsDecoders(),
    precacheList(),
    thirdPartyLicenses({ root: fileURLToPath(new URL('../..', import.meta.url)) }),
  ],
  css: { postcss: { plugins: [themeOverride()] } },
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
