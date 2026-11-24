// Einstieg der App (index.html): Sprache festlegen, nur ihren Katalog laden, dann die App starten (main.tsx).
//
// Der Katalog einer Sprache ist groß (Englisch gut 400 KB) und gilt nur für die, die sie nutzen. Darum steckt er nicht im
// Startpaket, sondern kommt als eigene Datei, und nur, wenn die Sprache beim Start nicht Deutsch ist (i18n.ts legt sie
// beim Laden fest: Wahl am Konto, am Gerät, Browser, Basissprache). Module der App rufen t() schon beim Laden auf,
// deshalb startet die App erst danach. Fehlt der Katalog (z. B. ohne Netz vor dem ersten Speichern), erscheint die App
// auf Deutsch wie bei einem fehlenden Eintrag.
import { takeEarlyInstallPrompt } from './earlyInstall';
// Früh laden: nimmt Nachrichten des Service Workers an, bis die App da ist.
import './earlyMessages';
import { locale, setCatalog, SOURCE_LOCALE, type Catalog, type Locale } from './i18n';

void takeEarlyInstallPrompt; // früh laden: fängt das Installationsangebot ab, bevor die App da ist

const catalogs = import.meta.glob<Catalog>('../../core/src/I18n/*.json', { import: 'default' });

async function loadCatalog(l: Locale): Promise<void> {
  if (l === SOURCE_LOCALE) return;
  const load = catalogs[`../../core/src/I18n/${l}.json`];
  if (!load) return;
  try {
    setCatalog(l, await load());
  } catch {
    // Datei nicht erreichbar: deutsche Texte
  }
}

void loadCatalog(locale()).then(() => import('./main'));
