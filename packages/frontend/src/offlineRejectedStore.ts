/**
 * Abgelehnte offline vorgemerkte Änderungen auf dem Gerät aufheben und anzeigen (Logik: offlineRejected.ts). Die
 * Übertragungen (attendanceSync.ts, responseSync.ts, bookingSync.ts) legen hier ab, was der Server abgelehnt hat;
 * OfflineRejectedBanner.tsx zeigt es, bis die Person es wegtippt.
 */
import { useEffect, useState } from 'react';
import { config } from './api';
import { createOfflineQueue, type KeyValue } from './offlineQueue';
import {
  REJECTED_AREA,
  REJECTED_CHANGED,
  sortedRejected,
  validRejected,
  withRejected,
  type RejectedChange,
  type RejectedKind,
} from './offlineRejected';

function storage(): KeyValue | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

const queue = createOfflineQueue<RejectedChange>(
  REJECTED_AREA,
  storage(),
  () => config?.user?.id ?? 0,
  (raw) => validRejected(raw),
);

function changed(): void {
  window.dispatchEvent(new Event(REJECTED_CHANGED));
}

/** Abgelehnte Änderungen eines Bereichs aufheben (leer: nichts zu tun). */
export function noteRejected(kind: RejectedKind, rejected: { label: string; message: string }[]): void {
  if (rejected.length === 0) return;
  queue.replace(
    withRejected(
      queue.all(),
      rejected.map((r) => ({ kind, ...r })),
      Date.now(),
    ),
  );
  changed();
}

/** Eine (key) oder alle Ablehnungen als gelesen entfernen. */
export function dismissRejected(key?: string): void {
  if (key === undefined) {
    queue.clear();
  } else {
    const items = queue.all();
    delete items[key];
    queue.replace(items);
  }
  changed();
}

/** Abgelehnte Änderungen, neueste zuerst; aktualisiert sich mit dem Speicher. */
export function useRejected(): [string, RejectedChange][] {
  const [items, setItems] = useState(() => sortedRejected(queue.all()));
  useEffect(() => {
    const update = () => setItems(sortedRejected(queue.all()));
    update();
    window.addEventListener(REJECTED_CHANGED, update);
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener(REJECTED_CHANGED, update);
      window.removeEventListener('storage', update);
    };
  }, []);
  return items;
}

/**
 * Den Browser bitten, den Speicher dieser Seite nicht von selbst zu räumen (Storage API), sobald etwas vorgemerkt
 * ist. Chrome, Edge und Safari entscheiden ohne Rückfrage, Firefox kann einmal nachfragen; ohne die Schnittstelle passiert nichts.
 */
let persistAsked = false;
export function askPersistentStorage(): void {
  if (persistAsked) return;
  persistAsked = true;
  try {
    const s = navigator.storage;
    if (!s?.persist || !s.persisted) return;
    void s
      .persisted()
      .then((already) => (already ? true : s.persist()))
      .catch(() => undefined);
  } catch {
    // nichts zu tun
  }
}
