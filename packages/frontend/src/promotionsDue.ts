import { useEffect, useState } from 'react';
import { api, can, config, type Promotion } from './api';
import { dataCache } from './dataCache';
import { dueCount, type NewlyDue } from './promotionCheck';

/**
 * Zahl der Mitglieder, die zur Beförderung bereit sind, für die Kachel „Beförderungen“. Nur im Speicher; nach
 * einer eigenen Änderung (siehe dataCache.generation) lädt der nächste Aufruf neu.
 */
let count: number | null = null;
let loadedAt = -1;
let pending: Promise<Promotion[] | null> | null = null;
let pendingAt = -1;
const listeners = new Set<(n: number | null) => void>();

/** Beförderungsübersicht sichtbar: Dienstgrade verwalten, mit den Bereichen Dienstgrade und Punkte. */
export const promotionsAllowed = (): boolean => !!config?.features.ranks && !!config?.features.points && can('manage_ranks');

/** Zahl aus einer frisch geladenen Beförderungsliste übernehmen. */
export function notePromotions(list: Promotion[], since = dataCache.generation()) {
  if (since !== dataCache.generation()) return;
  count = dueCount(list);
  loadedAt = since;
  listeners.forEach((l) => l(count));
}

/** Beförderungsliste neu laden und die Zahl aktualisieren; null ohne Recht oder bei einem Fehler. */
export function refreshPromotions(): Promise<Promotion[] | null> {
  if (!promotionsAllowed()) return Promise.resolve(null);
  const since = dataCache.generation();
  // Eine laufende Anfrage nur mitnehmen, wenn sie nach der letzten eigenen Änderung begann (sonst ist sie veraltet).
  if (pending && pendingAt === since) return pending;
  pendingAt = since;
  const request: Promise<Promotion[] | null> = api
    .promotions()
    .then((list) => {
      notePromotions(list, since);
      return list;
    })
    .catch(() => {
      // Nicht bei jedem Anzeigen erneut versuchen; die nächste eigene Änderung lädt wieder.
      loadedAt = since;
      return null;
    })
    .finally(() => {
      if (pending === request) pending = null;
    });
  pending = request;
  return request;
}

/* Wer nach dem Vergeben von Punkten neu eine Schwelle erreicht hat: Hinweis oben in der Verwaltung, bis er geschlossen
 * wird (das Formular steht auf mehreren Seiten, nach dem Speichern geht es teils woandershin). */
let reached: NewlyDue[] = [];
const reachedListeners = new Set<(r: NewlyDue[]) => void>();

export function setReached(next: NewlyDue[]) {
  reached = next;
  reachedListeners.forEach((l) => l(reached));
}

export function useReached(): NewlyDue[] {
  const [r, setR] = useState(reached);
  useEffect(() => {
    reachedListeners.add(setR);
    setR(reached);
    return () => {
      reachedListeners.delete(setR);
    };
  }, []);
  return r;
}

/** Zahl für die Kachel; lädt beim ersten Mal und nach eigenen Änderungen neu. */
export function usePromotionsDue(): number | null {
  const allowed = promotionsAllowed();
  const [n, setN] = useState(allowed ? count : null);
  useEffect(() => {
    if (!allowed) return;
    listeners.add(setN);
    setN(count);
    if (loadedAt !== dataCache.generation()) void refreshPromotions();
    return () => {
      listeners.delete(setN);
    };
  });
  return allowed ? n : null;
}
