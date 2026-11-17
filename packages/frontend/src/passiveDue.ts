/**
 * Ab wann der Beitrag eines Passiven fällig ist. Der Eintritt ist freier Text (Jahr, Datum oder z. B. „vor 2014“);
 * daraus zählt das erste Jahr. Vor dem Eintrittsjahr ist die Person noch nicht dabei, ihr Beitrag also nicht offen.
 */

/** Eintrittsjahr aus dem Text, z. B. „2027“, „01.03.2027“, „ab 2027“ → 2027; „vor 2014“ oder ohne Jahr → null. */
export function joinYear(joined: string | undefined | null): number | null {
  const text = (joined ?? '').trim();
  // „vor 2014“: schon lange dabei, das Jahr ist keine Grenze
  if (text === '' || /^vor\b/i.test(text)) return null;
  const m = /(?:^|\D)(1[89]\d\d|2\d\d\d)(?!\d)/.exec(text);
  return m ? Number(m[1]) : null;
}

/** Ist die Person im Jahr schon dabei (ohne erkennbares Eintrittsjahr: ja)? */
export function joinedBy(joined: string | undefined | null, year: number): boolean {
  const y = joinYear(joined);
  return y === null || year >= y;
}
