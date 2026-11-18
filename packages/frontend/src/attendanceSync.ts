/**
 * Offline gespeicherte Anwesenheit übertragen (Warteschlange: attendanceOutbox.ts).
 *
 * Die App versucht es beim Start, sobald der Browser wieder online meldet, beim Öffnen einer Anwesenheitsliste und vor
 * dem Abmelden. Immer nur ein Durchlauf gleichzeitig; die Listen nacheinander, die älteste zuerst.
 */
import { useEffect, useState } from 'react';
import { api, ApiError, can, config, inBackground, type AttendanceInput, type AttendanceStatus } from './api';
import { createOutbox, OUTBOX_AREA, OUTBOX_CHANGED, validEntry, type PendingSave } from './attendanceOutbox';
import { clearOfflineQueues, createOfflineQueue, failureAction } from './offlineQueue';
import { dataUpdated } from './dataCache';
import { noteRejected } from './offlineRejectedStore';
import { previewRole } from './preview';

function storage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

export const attendanceOutbox = createOutbox(createOfflineQueue(OUTBOX_AREA, storage(), () => config?.user?.id ?? 0, validEntry));

/** Beim Abmelden: alle offline gespeicherten Änderungen vom Gerät löschen. */
export function clearOfflineChanges(): void {
  clearOfflineQueues(storage());
}

export function outboxChanged(): void {
  window.dispatchEvent(new Event(OUTBOX_CHANGED));
}

/** Offene Änderungen einer Liste; aktualisiert sich, wenn sich die Warteschlange ändert. */
export function usePendingAttendance(id: number): PendingSave | null {
  const [pending, setPending] = useState(() => attendanceOutbox.get(id));
  useEffect(() => {
    const update = () => setPending(attendanceOutbox.get(id));
    update();
    window.addEventListener(OUTBOX_CHANGED, update);
    // Anderes Fenster der App auf demselben Gerät
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener(OUTBOX_CHANGED, update);
      window.removeEventListener('storage', update);
    };
  }, [id]);
  return pending;
}

/** Listen mit offenen Änderungen (für die Übersicht). */
export function usePendingAttendanceIds(): number[] {
  const [ids, setIds] = useState(() => attendanceOutbox.ids());
  useEffect(() => {
    const update = () => setIds(attendanceOutbox.ids());
    window.addEventListener(OUTBOX_CHANGED, update);
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener(OUTBOX_CHANGED, update);
      window.removeEventListener('storage', update);
    };
  }, []);
  return ids;
}

/** Anfrage für „Speichern“: offene Änderungen der Liste und die neuen zusammen (die neuen gewinnen). */
export function saveInput(
  id: number,
  pending: PendingSave | null,
  entries: Record<number, AttendanceStatus | null>,
  free: { title: string; date: string } | null,
): AttendanceInput {
  return { id, entries: { ...pendingEntries(pending), ...entries }, ...(free ?? {}) };
}

const pendingEntries = (p: PendingSave | null): Record<number, AttendanceStatus | null> =>
  Object.fromEntries(Object.entries(p?.entries ?? {}).map(([k, v]) => [Number(k), v]));

export interface SyncResult {
  sent: number;
  /** Abgelehnte Änderungen (Titel der Liste und Meldung des Servers); sie sind verworfen. */
  rejected: { label: string; message: string }[];
}

let running: Promise<SyncResult> | null = null;

/** Offene Änderungen senden. Ohne Verbindung bleibt alles für den nächsten Versuch liegen. */
export function syncAttendance(): Promise<SyncResult> {
  running ??= run().finally(() => (running = null));
  return running;
}

async function run(): Promise<SyncResult> {
  const result: SyncResult = { sent: 0, rejected: [] };
  // In der Vorschau lehnt der Server jede Änderung ab; ohne Recht gibt es nichts zu senden.
  if (!config?.user || previewRole() || !can('manage_attendance')) return result;
  for (const id of attendanceOutbox.ids()) {
    const pending = attendanceOutbox.get(id);
    if (!pending) continue;
    try {
      // Still: keine Weiterleitung zur Anmeldung, kein Eintrag unter „Fehler melden“; die Änderungen bleiben liegen.
      await inBackground(() =>
        api.saveAttendance({
          id,
          entries: pendingEntries(pending),
          ...(pending.title !== undefined ? { title: pending.title } : {}),
          ...(pending.date !== undefined ? { date: pending.date } : {}),
        }),
      );
      attendanceOutbox.remove(id, pending);
      result.sent++;
    } catch (e) {
      const status = e instanceof ApiError ? e.status : 0;
      if (failureAction(status) === 'retry') break;
      attendanceOutbox.remove(id, pending);
      result.rejected.push({ label: pending.label, message: (e as Error).message });
    }
  }
  noteRejected('attendance', result.rejected);
  if (result.sent > 0 || result.rejected.length > 0) {
    outboxChanged();
    // Offene Ansichten laden den Stand vom Server.
    dataUpdated();
  }
  return result;
}

/** Meldung nach einem Durchlauf, oder null, wenn nichts passiert ist. */
export function syncMessage(r: SyncResult): string | null {
  if (r.rejected.length > 0) {
    const first = r.rejected[0];
    return `Offline gespeicherte Anwesenheit${first.label ? ` „${first.label}“` : ''} wurde nicht übernommen: ${first.message}`;
  }
  return r.sent > 0 ? 'Offline gespeicherte Anwesenheit übertragen' : null;
}
