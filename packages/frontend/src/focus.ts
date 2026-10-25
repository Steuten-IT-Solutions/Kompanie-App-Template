import { useEffect, useRef, useState } from 'react';

/** Kennung eines Eintrags aus der Adresse (z. B. termine/termin/<id>): nur Buchstaben, Ziffern, „-“ und „_“. */
export function focusId(segment: string | undefined): string | undefined {
  return segment && /^[A-Za-z0-9_-]+$/.test(segment) ? segment : undefined;
}

/** Wie lange ein angesprungener Eintrag hervorgehoben bleibt. */
export const FLASH_MS = 2500;

/**
 * Zu einem Eintrag scrollen, auf den eine Benachrichtigung zeigt (Issue #190), sobald er in der Liste steht. Der Eintrag
 * trägt `data-focus-id`; zurück kommt die Kennung, solange er hervorgehoben sein soll (Klasse `focused`). Je Kennung
 * nur einmal: Kommen später frische Daten, springt die Liste nicht noch einmal. Fehlt der Eintrag (gelöscht oder
 * vorbei), bleibt die Liste, wie sie ist.
 */
export function useFocusEntry(id: string | undefined, found: boolean): string | null {
  const done = useRef<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    if (!id || !found || done.current === id) return;
    done.current = id;
    const el = document.querySelector(`[data-focus-id="${CSS.escape(id)}"]`);
    const smooth = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    // Mitte statt oben: Die Kopfzeile steht fest und würde den Eintrag sonst verdecken.
    el?.scrollIntoView({ block: 'center', behavior: smooth ? 'smooth' : 'auto' });
    setFlash(id);
  }, [id, found]);

  useEffect(() => {
    if (!flash) return;
    const t = setTimeout(() => setFlash(null), FLASH_MS);
    return () => clearTimeout(t);
  }, [flash]);

  return flash;
}
