/**
 * Zahl im Kreis an einer Kachel der Verwaltung: wie viel dort gerade ansteht (Beförderungen, Anfragen auf einen Zugang,
 * Fehlermeldungen). Die Zahlen liefert der Server nur an Berechtigte (sonst null, siehe /config und /admin/promotions).
 */
export type TileCounts = {
  /** Mitglieder, die zur Beförderung bereit sind */
  promotions?: number | null;
  /** offene Anfragen auf einen Zugang */
  registrations?: number | null;
  /** offene Fehlermeldungen */
  reports?: number | null;
};

export type TileCount = { count: number; text: string; title: string };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** Zahl und Erklärung (title) für eine Kachel; null, wenn dort nichts ansteht oder die Kachel keine Zahl hat. */
export function tileCount(id: string, counts: TileCounts): TileCount | null {
  const make = (n: number | null | undefined, title: (n: number) => string): TileCount | null =>
    n && n > 0 ? { count: n, text: n > 99 ? '99+' : String(n), title: title(n) } : null;
  switch (id) {
    case 'befoerderungen':
      return make(counts.promotions, (n) => `${plural(n, 'Mitglied', 'Mitglieder')} zur Beförderung bereit`);
    case 'anfragen':
      return make(counts.registrations, (n) => `${plural(n, 'offene Anfrage', 'offene Anfragen')} auf einen Zugang`);
    case 'meldungen':
      return make(counts.reports, (n) => plural(n, 'offene Fehlermeldung', 'offene Fehlermeldungen'));
    default:
      return null;
  }
}
