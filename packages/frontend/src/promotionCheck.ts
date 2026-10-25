import type { Promotion } from './api';

type Due = Pick<Promotion, 'points_to_next' | 'next_rank'>;

/** Schwelle des nächsten Dienstgrads erreicht: zur Beförderung bereit (ohne höheren Dienstgrad nie). */
export function isDue(p: Due): boolean {
  return p.next_rank !== null && p.points_to_next !== null && p.points_to_next <= 0;
}

/** Anzahl der Mitglieder, die zur Beförderung bereit sind (Zahl an der Kachel „Beförderungen“). */
export function dueCount(list: Due[]): number {
  return list.filter(isDue).length;
}

export type NewlyDue = { user_id: number; name: string; rank: string };

/**
 * Wer nach einer Buchung neu zur Beförderung bereit ist: vorher nicht bereit oder jetzt für einen höheren erreichbaren
 * Dienstgrad (eine weitere Schwelle übersprungen). Genannt wird der höchste erreichbare Dienstgrad.
 */
export function newlyDue(before: Promotion[], after: Promotion[]): NewlyDue[] {
  const old = new Map(before.map((p) => [p.user_id, p]));
  return after
    .filter((p) => {
      if (!isDue(p)) return false;
      const prev = old.get(p.user_id);
      return !prev || !isDue(prev) || (prev.reachable_rank?.id ?? null) !== (p.reachable_rank?.id ?? null);
    })
    .map((p) => ({ user_id: p.user_id, name: p.name, rank: (p.reachable_rank ?? p.next_rank)!.title }));
}
