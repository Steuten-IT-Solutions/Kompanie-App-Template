/**
 * Offline vorgemerkte Zu- und Absagen übertragen (Warteschlange: responseOutbox.ts). Wann: wie die Anwesenheit
 * (offlineSync.ts). Immer nur ein Durchlauf gleichzeitig, die älteste Antwort zuerst.
 */
import { useEffect, useState } from 'react';
import { api, ApiError, config, inBackground } from './api';
import { createOfflineQueue, failureAction, type KeyValue } from './offlineQueue';
import { RESPONSES_AREA, RESPONSES_CHANGED, validResponse, type PendingResponse } from './responseOutbox';
import { noteRejected } from './offlineRejectedStore';
import { previewRole } from './preview';

function storage(): KeyValue | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

const queue = createOfflineQueue<PendingResponse>(
  RESPONSES_AREA,
  storage(),
  () => config?.user?.id ?? 0,
  (raw) => validResponse(raw),
);

function changed(): void {
  window.dispatchEvent(new Event(RESPONSES_CHANGED));
}

export const responseOutbox = {
  get: (id: string): PendingResponse | null => queue.all()[id] ?? null,
  count: (): number => Object.keys(queue.all()).length,
  /** Vormerken (null: nichts mehr zu senden); false: Das Gerät kann nichts speichern. */
  set(id: string, p: PendingResponse | null): boolean {
    const items = queue.all();
    if (p) items[id] = p;
    else delete items[id];
    const ok = queue.replace(items);
    changed();
    return ok;
  },
  /** Nach dem Senden: nur entfernen, wenn inzwischen nichts Neueres vorgemerkt wurde. */
  remove(id: string, sent: PendingResponse): void {
    const items = queue.all();
    if (items[id]?.at !== sent.at) return;
    delete items[id];
    queue.replace(items);
    changed();
  },
};

/** Vorgemerkte Antwort zu einem Termin; aktualisiert sich, wenn sich die Warteschlange ändert. */
export function usePendingResponse(id: string): PendingResponse | null {
  const [pending, setPending] = useState(() => responseOutbox.get(id));
  useEffect(() => {
    const update = () => setPending(responseOutbox.get(id));
    update();
    window.addEventListener(RESPONSES_CHANGED, update);
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener(RESPONSES_CHANGED, update);
      window.removeEventListener('storage', update);
    };
  }, [id]);
  return pending;
}

export interface ResponseSyncResult {
  sent: number;
  rejected: { label: string; message: string }[];
}

let running: Promise<ResponseSyncResult> | null = null;

/** Vorgemerkte Antworten senden. Ohne Verbindung bleibt alles für den nächsten Versuch liegen. */
export function syncResponses(): Promise<ResponseSyncResult> {
  running ??= run().finally(() => (running = null));
  return running;
}

async function run(): Promise<ResponseSyncResult> {
  const result: ResponseSyncResult = { sent: 0, rejected: [] };
  if (!config?.user || previewRole()) return result;
  const items = Object.entries(queue.all()).sort(([, a], [, b]) => a.at - b.at);
  for (const [id, pending] of items) {
    try {
      await inBackground(() => (pending.status === null ? api.withdrawResponse(id) : api.respondEvent(id, pending.status, pending.note)));
      responseOutbox.remove(id, pending);
      result.sent++;
    } catch (e) {
      const status = e instanceof ApiError ? e.status : 0;
      if (failureAction(status) === 'retry') break;
      responseOutbox.remove(id, pending);
      result.rejected.push({ label: pending.label, message: (e as Error).message });
    }
  }
  noteRejected('response', result.rejected);
  return result;
}

/** Meldung nach einem Durchlauf, oder null, wenn nichts passiert ist. */
export function responseSyncMessage(r: ResponseSyncResult): string | null {
  if (r.rejected.length > 0) {
    const first = r.rejected[0];
    return `Offline vorgemerkte Rückmeldung${first.label ? ` zu „${first.label}“` : ''} wurde nicht übernommen: ${first.message}`;
  }
  return r.sent > 0 ? 'Offline vorgemerkte Zu- und Absagen übertragen' : null;
}
