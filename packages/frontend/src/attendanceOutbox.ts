/**
 * Offline gespeicherte Änderungen an Anwesenheitslisten (Warteschlange auf dem Gerät).
 *
 * Ohne Verbindung scheitert „Speichern“ in Verwaltung → Anwesenheit. Damit die Auswahl nicht verloren geht, legt die
 * App die Änderungen je Liste hier ab (localStorage, übersteht Neustart und Update der App), zeigt sie in der Liste an
 * und sendet sie, sobald wieder Netz da ist (attendanceSync.ts). Gesendet wird nur, was geändert wurde, je Person: Der
 * Server übernimmt genau diese Einträge, Einträge anderer Personen bleiben.
 *
 * Speicher, Konto und Abmelden regelt die allgemeine Warteschlange (offlineQueue.ts).
 *
 * Reine Logik ohne API, damit sie sich ohne Browser testen lässt.
 */
import type { AttendanceDetail, AttendanceStatus } from './api';
import type { OfflineQueue } from './offlineQueue';

/** Bereich in der allgemeinen Warteschlange (Speicher `kompanie-offline/anwesenheit`) */
export const OUTBOX_AREA = 'anwesenheit';
/** Ereignis nach jeder Änderung der Warteschlange: Ansichten zeigen den neuen Stand. */
export const OUTBOX_CHANGED = 'kompanie-anwesenheit-offline-changed';

const STATUSES: (AttendanceStatus | null)[] = ['present', 'excused', 'absent', null];

export interface PendingSave {
  /** Person → neuer Status (null = Eintrag entfernt) */
  entries: Record<string, AttendanceStatus | null>;
  /** Nur freie Listen: neuer Titel bzw. neues Datum */
  title?: string;
  date?: string;
  /** Titel der Liste zum Zeitpunkt des Speicherns, für Meldungen */
  label: string;
  /** Zuletzt geändert (ms) */
  at: number;
}

function validPending(raw: unknown): PendingSave | null {
  if (!raw || typeof raw !== 'object') return null;
  const p = raw as Record<string, unknown>;
  if (!p.entries || typeof p.entries !== 'object') return null;
  const entries: Record<string, AttendanceStatus | null> = {};
  for (const [id, status] of Object.entries(p.entries as Record<string, unknown>)) {
    if (/^\d+$/.test(id) && STATUSES.includes(status as AttendanceStatus | null)) entries[id] = status as AttendanceStatus | null;
  }
  return {
    entries,
    ...(typeof p.title === 'string' ? { title: p.title } : {}),
    ...(typeof p.date === 'string' ? { date: p.date } : {}),
    label: typeof p.label === 'string' ? p.label : '',
    at: typeof p.at === 'number' ? p.at : 0,
  };
}

const isEmpty = (p: PendingSave) => Object.keys(p.entries).length === 0 && p.title === undefined && p.date === undefined;

/** Prüft einen gelesenen Eintrag der Warteschlange (Schlüssel = ID der Liste). */
export function validEntry(raw: unknown, id: string): PendingSave | null {
  const valid = /^\d+$/.test(id) ? validPending(raw) : null;
  return valid && !isEmpty(valid) ? valid : null;
}

/** Warteschlange der Anwesenheit auf der allgemeinen (createOfflineQueue(OUTBOX_AREA, …, validEntry)). */
export function createOutbox(queue: OfflineQueue<PendingSave>) {
  const read = () => queue.all();
  const write = (lists: Record<string, PendingSave>) => queue.replace(lists);

  return {
    /** Listen mit offenen Änderungen (älteste zuerst) */
    ids(): number[] {
      return Object.entries(read())
        .sort((a, b) => a[1].at - b[1].at)
        .map(([id]) => Number(id));
    },
    get(id: number): PendingSave | null {
      return read()[String(id)] ?? null;
    },
    /** Änderungen ablegen; spätere überschreiben frühere derselben Person. false: Das Gerät kann nichts speichern. */
    add(
      id: number,
      change: Omit<PendingSave, 'at' | 'entries'> & { entries: Record<string | number, AttendanceStatus | null> },
      now = Date.now(),
    ): boolean {
      const lists = read();
      const before = lists[String(id)];
      const next: PendingSave = {
        entries: { ...before?.entries, ...Object.fromEntries(Object.entries(change.entries).map(([k, v]) => [String(k), v])) },
        title: change.title ?? before?.title,
        date: change.date ?? before?.date,
        label: change.label || before?.label || '',
        at: now,
      };
      if (next.title === undefined) delete next.title;
      if (next.date === undefined) delete next.date;
      lists[String(id)] = next;
      return write(lists);
    },
    /**
     * Gesendetes entfernen. Was nach dem Senden noch dazukam (andere Person oder anderer Status), bleibt in der
     * Warteschlange.
     */
    remove(id: number, sent: PendingSave): void {
      const lists = read();
      const now = lists[String(id)];
      if (!now) return;
      for (const [person, status] of Object.entries(sent.entries)) {
        if (person in now.entries && now.entries[person] === status) delete now.entries[person];
      }
      if (now.title === sent.title) delete now.title;
      if (now.date === sent.date) delete now.date;
      if (isEmpty(now)) delete lists[String(id)];
      write(lists);
    },
    /** Offene Änderungen einer Liste verwerfen (Liste gelöscht). */
    discard(id: number): void {
      const lists = read();
      if (!(String(id) in lists)) return;
      delete lists[String(id)];
      write(lists);
    },
    /** Alles verwerfen (Abmelden). */
    clear(): void {
      queue.clear();
    },
  };
}

export type Outbox = ReturnType<typeof createOutbox>;

/** Liste, wie sie mit den offenen Änderungen aussieht (Status je Person, bei freien Listen Titel und Datum). */
export function applyPending(list: AttendanceDetail, pending: PendingSave | null): AttendanceDetail {
  if (!pending) return list;
  const people = list.people.map((p) => (String(p.id) in pending.entries ? { ...p, status: pending.entries[String(p.id)] } : p));
  const counts = { present: 0, excused: 0, absent: 0 };
  for (const p of people) if (p.status) counts[p.status]++;
  return {
    ...list,
    people,
    counts,
    ...(list.source === 'free' && pending.title !== undefined ? { title: pending.title } : {}),
    ...(list.source === 'free' && pending.date !== undefined ? { date: pending.date } : {}),
  };
}

/** Anzahl der Personen mit offenen Änderungen */
export const pendingCount = (pending: PendingSave | null) => (pending ? Object.keys(pending.entries).length : 0);
