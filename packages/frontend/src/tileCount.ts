import { tn } from './i18n';

/**
 * Zahl im Kreis an einer Kachel: wie viel dort gerade ansteht (Beförderungen, Aufnahmen, Anfragen auf einen Zugang,
 * Fehlermeldungen in der Verwaltung).
 * Die Zahlen liefert der Server nur an Berechtigte (sonst null, siehe /config und /admin/promotions).
 */
export type TileCounts = {
  /** Mitglieder, die zur Beförderung bereit sind */
  promotions?: number | null;
  /** Mitglieder, die bald zur Beförderung bereit sind (Schwelle noch nicht erreicht) */
  promotionsSoon?: number | null;
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

/** Zahl und Erklärung (title) für eine Kachel; null, wenn dort nichts ansteht oder die Kachel keine Zahl hat. */
const make = (n: number | null | undefined, title: (n: number) => string): TileCount | null =>
  n && n > 0 ? { count: n, text: n > 99 ? '99+' : String(n), title: title(n) } : null;

/**
 * Zweite Zahl in einem Kreis in Akzentfarbe neben der ersten: was bald ansteht (Aufnahmen: Anwärter kurz davor, z. B.
 * noch eine Teilnahme oder Mindestdauer in höchstens 30 Tagen; Beförderungen: höchstens 1 Punkt bis zur
 * Schwelle); null ohne.
 */
export function tileSoonCount(id: string, counts: TileCounts): TileCount | null {
  switch (id) {
    case 'aufnahmen':
      return make(counts.admissionsSoon, (n) =>
        tn('{count} Anwärter steht kurz vor der Aufnahme', '{count} Anwärter stehen kurz vor der Aufnahme', n),
      );
    case 'befoerderungen':
      return make(counts.promotionsSoon, (n) =>
        tn('{count} Mitglied ist bald zur Beförderung bereit', '{count} Mitglieder sind bald zur Beförderung bereit', n),
      );
    default:
      return null;
  }
}

/** Erklärung beider Zahlen einer Kachel in einem Satz (title des Knopfs); undefined ohne Zahl. */
export function tileTitle(first: TileCount | null, soon: TileCount | null): string | undefined {
  const parts = [first?.title, soon?.title].filter((t): t is string => !!t);
  return parts.length > 0 ? parts.join(', ') : undefined;
}

export function tileCount(id: string, counts: TileCounts): TileCount | null {
  switch (id) {
    case 'befoerderungen':
      return make(counts.promotions, (n) => tn('{count} Mitglied zur Beförderung bereit', '{count} Mitglieder zur Beförderung bereit', n));
    case 'aufnahmen':
      return make(counts.admissions, (n) =>
        tn(
          '{count} Anwärter erfüllt die Voraussetzung für die Aufnahme',
          '{count} Anwärter erfüllen die Voraussetzung für die Aufnahme',
          n,
        ),
      );
    case 'anfragen':
      return make(counts.registrations, (n) =>
        tn('{count} offene Anfrage auf einen Zugang', '{count} offene Anfragen auf einen Zugang', n),
      );
    case 'meldungen':
      return make(counts.reports, (n) => tn('{count} offene Fehlermeldung', '{count} offene Fehlermeldungen', n));
    default:
      return null;
  }
}
