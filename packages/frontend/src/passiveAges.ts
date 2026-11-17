// Geburtstage der Passiven: die Liste der Alter, an die erinnert wird, aus dem Eingabefeld lesen.

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
    if (!/^\d+$/.test(part) || Number(part) < 1 || Number(part) > AGE_MAX) return `Bitte nur Alter von 1 bis ${AGE_MAX} angeben.`;
    ages.add(Number(part));
  }
  if (ages.size > AGES_MAX) return `Es gehen höchstens ${AGES_MAX} Alter.`;
  return [...ages].sort((a, b) => a - b);
}
