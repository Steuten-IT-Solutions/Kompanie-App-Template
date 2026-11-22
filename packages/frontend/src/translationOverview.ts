/** Hilfen für Einstellungen → Sprachen → Übersetzungen (views/Uebersetzungen.tsx). */
import type { TranslationItem } from './api';

/** Eindeutige Kennung eines Eintrags (Art, Kennung, Feld). */
export const keyOf = (i: Pick<TranslationItem, 'kind' | 'ref' | 'field'>): string => `${i.kind}\u0000${i.ref}\u0000${i.field}`;

/** Fehlt die Übersetzung? Nicht, wenn Leser ohne Eintrag schon eine eigene Fassung sehen (z. B. die übersetzte Vorgabe). */
export const isMissing = (i: TranslationItem, text = i.text): boolean => text.trim() === '' && i.fallback === i.source;

/** Passt der Eintrag zur Suche (Text der Basissprache, Übersetzung oder Vorgabe)? */
export function matches(i: TranslationItem, query: string, text = i.text): boolean {
  const q = query.trim().toLocaleLowerCase();
  return q === '' || [i.source, text, i.fallback].some((s) => s.toLocaleLowerCase().includes(q));
}
