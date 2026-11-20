/**
 * Zahl im Kreis an einer Kachel: wie viel dort gerade ansteht (Beförderungen, Aufnahmen, Anfragen auf einen Zugang,
 * Fehlermeldungen in der Verwaltung).
 * Die Zahlen liefert der Server nur an Berechtigte (sonst null, siehe /config und /admin/promotions).
 */
export type TileCounts = {
  /** Mitglieder, die zur Beförderung bereit sind */
  promotions?: number | null;
  /** offene Anfragen auf einen Zugang */
  registrations?: number | null;
  /** offene Fehlermeldungen */
  reports?: number | null;
  /** Anwärter, die die Voraussetzung für die Aufnahme erfüllen */
  admissions?: number | null;
  /** Anwärter kurz vor der Aufnahme (noch nicht erfüllt) */
  admissionsSoon?: number | null;
};

export type TileCount = { count: number; text: string; title: string };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** Zahl und Erklärung (title) für eine Kachel; null, wenn dort nichts ansteht oder die Kachel keine Zahl hat. */
const make = (n: number | null | undefined, title: (n: number) => string): TileCount | null =>
  n && n > 0 ? { count: n, text: n > 99 ? '99+' : String(n), title: title(n) } : null;

/**
 * Zweite Zahl in einem Kreis in Akzentfarbe neben der ersten: was bald ansteht (bisher nur Aufnahmen: Anwärter kurz
 * davor, z. B. noch eine Teilnahme oder Mindestdauer in höchstens 30 Tagen); null ohne.
 */
export function tileSoonCount(id: string, counts: TileCounts): TileCount | null {
  if (id !== 'aufnahmen') return null;
  return make(counts.admissionsSoon, (n) => `${plural(n, 'Anwärter steht', 'Anwärter stehen')} kurz vor der Aufnahme`);
}

/** Erklärung beider Zahlen einer Kachel in einem Satz (title des Knopfs); undefined ohne Zahl. */
export function tileTitle(first: TileCount | null, soon: TileCount | null): string | undefined {
  const parts = [first?.title, soon?.title].filter((t): t is string => !!t);
  return parts.length > 0 ? parts.join(', ') : undefined;
}

export function tileCount(id: string, counts: TileCounts): TileCount | null {
  switch (id) {
    case 'befoerderungen':
      return make(counts.promotions, (n) => `${plural(n, 'Mitglied', 'Mitglieder')} zur Beförderung bereit`);
    case 'aufnahmen':
      return make(counts.admissions, (n) => `${plural(n, 'Anwärter erfüllt', 'Anwärter erfüllen')} die Voraussetzung für die Aufnahme`);
    case 'anfragen':
      return make(counts.registrations, (n) => `${plural(n, 'offene Anfrage', 'offene Anfragen')} auf einen Zugang`);
    case 'meldungen':
      return make(counts.reports, (n) => plural(n, 'offene Fehlermeldung', 'offene Fehlermeldungen'));
    default:
      return null;
  }
}
