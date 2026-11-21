// Geburtstage der Passiven: die Liste der Alter, an die erinnert wird, aus dem Eingabefeld lesen.

import { t } from './i18n';

/** Höchstes Alter und höchste Zahl der Alter (wie auf dem Server) */
const AGE_MAX = 120;
const AGES_MAX = 30;

/** Alter aus dem Feld, z. B. „50, 60, 70“: aufsteigende Liste ohne doppelte oder eine Fehlermeldung; leer = keine. */
export function parseAges(text: string): number[] | string {
  const parts = text
    .split(/[\s,;]+/)
    .map((x) => x.trim())
    .filter((x) => x !== '');
  const ages = new Set<number>();
  for (const part of parts) {
    if (!/^\d+$/.test(part) || Number(part) < 1 || Number(part) > AGE_MAX)
      return t('Bitte nur Alter von 1 bis {max} angeben.', { max: AGE_MAX });
    ages.add(Number(part));
  }
  if (ages.size > AGES_MAX) return t('Es gehen höchstens {max} Alter.', { max: AGES_MAX });
  return [...ages].sort((a, b) => a - b);
}
