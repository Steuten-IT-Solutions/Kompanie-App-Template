/** Was defaultOpenJoint von einer gemeinsamen Amtszeit braucht (OfficeGroupTerm aus api.ts). */
export type JointTermYears = { id: number; current: boolean; start_year: number; end_year: number | null };

/**
 * Welche gemeinsamen Amtszeiten einer Gruppe anfangs offen sind: die laufenden; läuft keine, die neueste (spätester
 * Beginn, bei gleichem Beginn das spätere Ende). Alle anderen sind zu.
 */
export function defaultOpenJoint(terms: JointTermYears[]): number[] {
  const current = terms.filter((t) => t.current);
  if (current.length > 0) return current.map((t) => t.id);
  const newest = terms.reduce<(typeof terms)[number] | null>(
    (best, t) =>
      !best || t.start_year > best.start_year || (t.start_year === best.start_year && (t.end_year ?? 0) > (best.end_year ?? 0)) ? t : best,
    null,
  );
  return newest ? [newest.id] : [];
}
