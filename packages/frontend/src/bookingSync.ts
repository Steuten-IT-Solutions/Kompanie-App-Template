/**
 * Offline gebuchte Punkte und Strafen übertragen (Warteschlange: bookingOutbox.ts). Wann: wie die Anwesenheit
 * (offlineSync.ts). Immer nur ein Durchlauf gleichzeitig, die älteste Buchung zuerst; dieselbe Kennung (request_id)
 * bei jedem Versuch, damit der Server nicht doppelt bucht.
 */
import { useEffect, useState } from 'react';
import { api, ApiError, config, inBackground } from './api';
import { BOOKINGS_AREA, BOOKINGS_CHANGED, validBooking, type BookingKind, type PendingBooking } from './bookingOutbox';
import { createOfflineQueue, failureAction, type KeyValue } from './offlineQueue';
import { noteRejected } from './offlineRejectedStore';
import { previewRole } from './preview';
import { t } from './i18n';

function storage(): KeyValue | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

const queue = createOfflineQueue<PendingBooking>(BOOKINGS_AREA, storage(), () => config?.user?.id ?? 0, validBooking);

function changed(): void {
  window.dispatchEvent(new Event(BOOKINGS_CHANGED));
}

export const bookingOutbox = {
  count: (kind?: BookingKind): number => Object.values(queue.all()).filter((b) => !kind || b.kind === kind).length,
  /** Vormerken; false: Das Gerät kann nichts speichern. */
  add(b: PendingBooking): boolean {
    const ok = queue.replace({ ...queue.all(), [b.body.request_id]: b });
    changed();
    return ok;
  },
  remove(id: string): void {
    const items = queue.all();
    if (!items[id]) return;
    delete items[id];
    queue.replace(items);
    changed();
  },
};

/** Anzahl noch nicht übertragener Buchungen einer Art; aktualisiert sich mit der Warteschlange. */
export function usePendingBookings(kind: BookingKind): number {
  const [count, setCount] = useState(() => bookingOutbox.count(kind));
  useEffect(() => {
    const update = () => setCount(bookingOutbox.count(kind));
    update();
    window.addEventListener(BOOKINGS_CHANGED, update);
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener(BOOKINGS_CHANGED, update);
      window.removeEventListener('storage', update);
    };
  }, [kind]);
  return count;
}

export interface BookingSyncResult {
  sent: number;
  rejected: { label: string; message: string }[];
}

let running: Promise<BookingSyncResult> | null = null;

/** Vorgemerkte Buchungen senden. Ohne Verbindung bleibt alles für den nächsten Versuch liegen. */
export function syncBookings(): Promise<BookingSyncResult> {
  running ??= run().finally(() => (running = null));
  return running;
}

async function run(): Promise<BookingSyncResult> {
  const result: BookingSyncResult = { sent: 0, rejected: [] };
  if (!config?.user || previewRole()) return result;
  const items = Object.entries(queue.all()).sort(([, a], [, b]) => a.at - b.at);
  for (const [id, b] of items) {
    try {
      await inBackground<unknown>(() =>
        b.kind === 'points'
          ? api.givePoints(b.body as Parameters<typeof api.givePoints>[0])
          : api.givePenalty(b.body as unknown as Parameters<typeof api.givePenalty>[0]),
      );
      bookingOutbox.remove(id);
      result.sent++;
    } catch (e) {
      const status = e instanceof ApiError ? e.status : 0;
      if (failureAction(status) === 'retry') break;
      bookingOutbox.remove(id);
      result.rejected.push({ label: b.label, message: (e as Error).message });
    }
  }
  noteRejected('booking', result.rejected);
  return result;
}

/** Meldung nach einem Durchlauf, oder null, wenn nichts passiert ist. */
export function bookingSyncMessage(r: BookingSyncResult): string | null {
  if (r.rejected.length > 0) {
    const first = r.rejected[0];
    return first.label
      ? t('Offline vorgemerkte Buchung „{label}“ wurde nicht übernommen: {message}', { label: first.label, message: first.message })
      : t('Offline vorgemerkte Buchung wurde nicht übernommen: {message}', { message: first.message });
  }
  return r.sent > 0 ? t('Offline vorgemerkte Punkte und Strafen übertragen') : null;
}
