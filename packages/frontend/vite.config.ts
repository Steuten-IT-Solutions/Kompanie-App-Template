import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

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
      files = Object.keys(bundle)
        .filter((f) => f.startsWith('assets/'))
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

// Der Build landet in dist/. Die Adapter liefern index.html unter der Adresse der App aus
// und biegen die relativen Pfade (base: './') auf ihr Verzeichnis um.
export default defineConfig({
  base: './',
  plugins: [react(), brandHtml(), precacheList()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: 'assets',
  },
});
