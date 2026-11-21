import type { Promotion, RankRef } from './api';
import { nameIn } from './names';

type Due = Pick<Promotion, 'points_to_next' | 'next_rank'>;
type Soon = Due & Pick<Promotion, 'soon' | 'below_current_threshold'>;

/** Schwelle des nächsten Dienstgrads erreicht: zur Beförderung bereit (ohne höheren Dienstgrad nie). */
export function isDue(p: Due): boolean {
  return p.next_rank !== null && p.points_to_next !== null && p.points_to_next <= 0;
}

/** Anzahl der Mitglieder, die zur Beförderung bereit sind (Zahl an der Kachel „Beförderungen“). */
export function dueCount(list: Due[]): number {
  return list.filter(isDue).length;
}

/**
 * Bald zur Beförderung bereit (zweiter Kreis an der Kachel, Abschnitt „Bald erreicht“): Der Server kennzeichnet, wem
 * höchstens 1 Punkt zur Schwelle fehlt; wer schon bereit ist oder unter der eigenen Schwelle liegt, nie.
 */
export function isSoon(p: Soon): boolean {
  return !!p.soon && !isDue(p) && !p.below_current_threshold;
}

/** Anzahl der Mitglieder, die bald zur Beförderung bereit sind. */
export function soonCount(list: Soon[]): number {
  return list.filter(isSoon).length;
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
    .map((p) => ({ user_id: p.user_id, name: p.name, rank: rankRefName((p.reachable_rank ?? p.next_rank)!) }));
}

/** Bezeichnung in der Sprache der App (eigener Name je Sprache, sonst die deutsche). */
function rankRefName(r: RankRef): string {
  return nameIn('rank', String(r.id), r.title);
}
