// Beförderungen (src/promotionCheck.ts): Zahl der zur Beförderung bereiten Mitglieder und wer nach einer Buchung neu
// eine Schwelle erreicht hat.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Promotion } from '../src/api.ts';
import { dueCount, isDue, isSoon, newlyDue, soonCount } from '../src/promotionCheck.ts';

const gefreiter = { id: 2, title: 'Gefreiter', badge_url: null };
const unteroffizier = { id: 3, title: 'Unteroffizier', badge_url: null };

function p(
  user_id: number,
  points: number,
  next: { id: number; title: string; min: number } | null,
  reachable = null as typeof gefreiter | null,
): Promotion {
  return {
    user_id,
    name: `Mitglied ${user_id}`,
    rank: null,
    points,
    next_rank: next && { id: next.id, title: next.title, min_points: next.min, badge_url: null },
    points_to_next: next ? next.min - points : null,
    reachable_rank: reachable,
    threshold_reached_since: null,
    below_current_threshold: false,
    soon: false,
  };
}

const toGefreiter = { id: 2, title: 'Gefreiter', min: 100 };

test('bereit ist, wer die Schwelle des nächsten Dienstgrads erreicht hat', () => {
  assert.equal(isDue(p(1, 99, toGefreiter)), false);
  assert.equal(isDue(p(1, 100, toGefreiter)), true);
  assert.equal(isDue(p(1, 500, null)), false);
  assert.equal(dueCount([p(1, 100, toGefreiter), p(2, 50, toGefreiter), p(3, 120, toGefreiter), p(4, 900, null)]), 2);
});

test('neu erreicht: vorher nicht bereit, jetzt bereit', () => {
  const before = [p(1, 90, toGefreiter), p(2, 150, toGefreiter, gefreiter), p(3, 10, toGefreiter)];
  const after = [p(1, 110, toGefreiter, gefreiter), p(2, 170, toGefreiter, gefreiter), p(3, 30, toGefreiter)];
  assert.deepEqual(newlyDue(before, after), [{ user_id: 1, name: 'Mitglied 1', rank: 'Gefreiter' }]);
});

test('schon bereit, aber jetzt eine weitere Schwelle erreicht: neu, mit dem höchsten erreichbaren Dienstgrad', () => {
  const before = [p(1, 150, toGefreiter, gefreiter)];
  const after = [p(1, 260, toGefreiter, unteroffizier)];
  assert.deepEqual(newlyDue(before, after), [{ user_id: 1, name: 'Mitglied 1', rank: 'Unteroffizier' }]);
});

test('ohne vorherigen Stand (z. B. neues Mitglied) zählt jede erreichte Schwelle', () => {
  assert.deepEqual(newlyDue([], [p(5, 100, toGefreiter, gefreiter)]), [{ user_id: 5, name: 'Mitglied 5', rank: 'Gefreiter' }]);
});

test('bald bereit: Kennzeichen des Servers, nur ohne erreichte Schwelle und ohne Unterschreiten', () => {
  const soon = { ...p(1, 95, toGefreiter), soon: true };
  assert.equal(isSoon(soon), true);
  assert.equal(isSoon(p(2, 95, toGefreiter)), false);
  assert.equal(isSoon({ ...p(3, 100, toGefreiter), soon: true }), false, 'bereit zählt im roten Kreis');
  assert.equal(isSoon({ ...soon, below_current_threshold: true }), false, 'unter der eigenen Schwelle');
  assert.equal(soonCount([soon, p(2, 95, toGefreiter), { ...p(4, 98, toGefreiter), soon: true }]), 2);
});
