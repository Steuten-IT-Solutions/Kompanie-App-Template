/**
 * Ob unter dem Zähler einer Anwesenheitsliste die Aufteilung nach Gruppen steht: nur für den gespeicherten Stand (sonst
 * passt sie nicht zum Zähler) und erst ab dem Tag des Termins. Vorher gibt es nur „entschuldigt“, und die Zeile
 * wiederholt im Wesentlichen den Zähler.
 */
export function showGroupCounts(state: { dirty: boolean; pending: boolean; upcoming: boolean }): boolean {
  return !state.dirty && !state.pending && !state.upcoming;
}
