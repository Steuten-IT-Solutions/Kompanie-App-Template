/// <reference types="vite/client" />

/** Ordner der pdf.js-Bild-Dekodierer unter assets/ (vite.config.ts) */
declare const __PDFJS_DIR__: string;

/** Lizenz der App und der Drittanbieter-Bausteine, beim Bauen eingesetzt (build/licenses.ts, Issue #173) */
declare module 'virtual:kompanie-lizenzen' {
  const lizenzen: {
    projekt: { name: string; license: string; repository: string; text: string };
    pakete: Array<{
      name: string;
      version: string;
      lizenz: string;
      angabe: string;
      quelle: 'npm' | 'composer';
      homepage: string;
      copyright: string[];
      text: string;
    }>;
  };
  export default lizenzen;
}
