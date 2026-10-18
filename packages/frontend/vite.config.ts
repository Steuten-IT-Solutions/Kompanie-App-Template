import { readFileSync } from 'node:fs';
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

// Der Build landet in dist/. Die Adapter liefern index.html unter der Adresse der App aus
// und biegen die relativen Pfade (base: './') auf ihr Verzeichnis um.
export default defineConfig({
  base: './',
  plugins: [react(), brandHtml()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: 'assets',
  },
});
