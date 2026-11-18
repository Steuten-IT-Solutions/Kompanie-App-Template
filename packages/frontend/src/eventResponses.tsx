// Zu- und Absagen bei Terminen des internen Kalenders: Knöpfe unter dem Termin, Hinweis und (für alle, die Termine
// pflegen) die Namen im aufgeklappten Termin. Der Server prüft Recht und Frist (EventResponseService).
import { useState } from 'react';
import {
  api,
  ApiError,
  eventResponsesOn,
  type CalendarEvent,
  type EventResponseOverview,
  type EventResponses,
  type ResponseStatus,
} from './api';
import { applyPendingResponse, pendingFor, responseKey } from './responseOutbox';
import { shouldQueue } from './offlineQueue';
import { askPersistentStorage } from './offlineRejectedStore';
import { responseOutbox, usePendingResponse } from './responseSync';
import { formatDate } from './format';
import { ErrorBox, Loading } from './ui';

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** „3 Zusagen · 1 Absage“; leer, wenn noch niemand geantwortet hat. */
export function responseCounts(r: EventResponses): string {
  const parts = [];
  if (r.yes > 0) parts.push(plural(r.yes, 'Zusage', 'Zusagen'));
  if (r.no > 0) parts.push(plural(r.no, 'Absage', 'Absagen'));
  return parts.join(' · ');
}

/**
 * Zusagen und Absagen direkt unter dem Termin. Ein Tipp auf die gewählte Antwort nimmt sie zurück. Nach Ablauf der Frist
 * nur noch die eigene Antwort und die Zähler.
 */
/**
 * Antwort senden; scheitert das aus einem Grund, den ein späterer Versuch behebt (keine Verbindung, keine Antwort in
 * der Zeit, Server gestört, Sitzung abgelaufen), vormerken (responseOutbox.ts) und später übertragen. Gesendet und
 * vorgemerkt wird unter der stabilen Kennung des Termins (responseKey), die auch nach dem Verschieben eines Termins
 * aus iCal gilt. true: gesendet oder vorgemerkt.
 */
async function send(
  ev: CalendarEvent,
  status: ResponseStatus | null,
  note: string,
  onChange: (ev: CalendarEvent) => void,
  setError: (msg: string | null) => void,
): Promise<boolean> {
  const server = ev.responses!;
  const key = responseKey(ev);
  const before = responseOutbox.get(key);
  try {
    const next = status === null ? await api.withdrawResponse(key) : await api.respondEvent(key, status, note);
    // Gesendet: Eine ältere Vormerkung ist damit überholt.
    if (before) responseOutbox.remove(key, before);
    onChange(next);
    return true;
  } catch (e) {
    if (e instanceof ApiError && shouldQueue(e) && responseOutbox.set(key, pendingFor(server, status, note, ev.title, Date.now()))) {
      askPersistentStorage();
      return true;
    }
    setError((e as Error).message);
    return false;
  }
}

/** Hinweis, solange eine offline vorgemerkte Antwort noch nicht übertragen ist. */
function PendingHint() {
  return <p className="event-response-pending small">Noch nicht übertragen – die App sendet es, sobald die Verbindung wieder klappt.</p>;
}

export function ResponseBar({ ev, onChange }: { ev: CalendarEvent; onChange: (ev: CalendarEvent) => void }) {
  const pending = usePendingResponse(responseKey(ev));
  // Ausgeschaltet (Einstellungen → Kalender) keine Knöpfe, auch nicht aus offline gespeicherten Terminen
  const r = ev.responses && eventResponsesOn() ? applyPendingResponse(ev.responses, pending) : undefined;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!r) return null;
  const counts = responseCounts(r);

  async function choose(status: ResponseStatus) {
    if (!r) return;
    setBusy(true);
    setError(null);
    await send(ev, r.mine === status ? null : status, r.note, onChange, setError);
    setBusy(false);
  }

  const choice = (status: ResponseStatus, label: string, chosen: string) => (
    <button
      type="button"
      className={`btn event-response event-response-${status}${r.mine === status ? ' chosen' : ''}`}
      aria-pressed={r.mine === status}
      disabled={busy}
      onClick={() => void choose(status)}
    >
      {r.mine === status ? chosen : label}
    </button>
  );

  const until = ev.respond_until ? `Zu- und absagen bis ${formatDate(ev.respond_until)}` : '';
  return (
    <div className="event-responses">
      {r.can_respond && r.open && (
        <div className="event-response-row" role="group" aria-label={`Rückmeldung zu ${ev.title}`}>
          {choice('yes', 'Zusagen', 'Zugesagt')}
          {choice('no', 'Absagen', 'Abgesagt')}
        </div>
      )}
      {r.can_respond && !r.open && (
        <p className="event-response-closed small">
          {r.mine === 'yes' ? 'Du hast zugesagt.' : r.mine === 'no' ? 'Du hast abgesagt.' : 'Zu- und absagen ist nicht mehr möglich.'}
        </p>
      )}
      {(counts || (until && r.open)) && (
        <p className="event-response-counts small muted">{[counts, r.open ? until : ''].filter(Boolean).join(' · ')}</p>
      )}
      {pending && <PendingHint />}
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

/** Im aufgeklappten Termin: eigener Hinweis (nach der Antwort) und für alle, die Termine pflegen, wer wie geantwortet hat. */
export function ResponseDetails({ ev, onChange }: { ev: CalendarEvent; onChange: (ev: CalendarEvent) => void }) {
  const pending = usePendingResponse(responseKey(ev));
  // Ausgeschaltet (Einstellungen → Kalender) keine Knöpfe, auch nicht aus offline gespeicherten Terminen
  const r = ev.responses && eventResponsesOn() ? applyPendingResponse(ev.responses, pending) : undefined;
  const [note, setNote] = useState(r?.note ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [list, setList] = useState<EventResponseOverview | null>(null);
  const [listError, setListError] = useState<string | null>(null);
  const [listOpen, setListOpen] = useState(false);
  if (!r) return null;
  const canNote = r.can_respond && r.open && r.mine !== null;

  async function saveNote() {
    if (!r?.mine) return;
    setBusy(true);
    setError(null);
    await send(ev, r.mine, note, onChange, setError);
    setBusy(false);
  }

  async function toggleList() {
    if (listOpen) return setListOpen(false);
    setListOpen(true);
    setListError(null);
    try {
      setList(await api.eventResponses(ev.id));
    } catch (e) {
      setListError((e as Error).message);
    }
  }

  return (
    <>
      {canNote && (
        <div className="event-response-note stack">
          <label className="field">
            <span>Hinweis zu deiner {r.mine === 'yes' ? 'Zusage' : 'Absage'} (optional)</span>
            <input type="text" maxLength={191} value={note} placeholder="z. B. komme später" onChange={(e) => setNote(e.target.value)} />
          </label>
          {note.trim() !== r.note && (
            <button type="button" className="btn btn-secondary btn-block" disabled={busy} onClick={() => void saveNote()}>
              {busy ? 'Speichert …' : 'Hinweis speichern'}
            </button>
          )}
          {error && (
            <p className="form-error small" role="alert">
              {error}
            </p>
          )}
        </div>
      )}
      {r.can_list && (
        <div className="event-response-list stack">
          <button type="button" className="btn btn-secondary btn-block" aria-expanded={listOpen} onClick={() => void toggleList()}>
            {listOpen ? 'Rückmeldungen ausblenden' : 'Rückmeldungen ansehen'}
          </button>
          {listOpen && (listError ? <ErrorBox message={listError} /> : !list ? <Loading shape="text" /> : <ResponseNames list={list} />)}
        </div>
      )}
    </>
  );
}

function ResponseNames({ list }: { list: EventResponseOverview }) {
  const group = (title: string, people: { id: number; name: string; note?: string }[]) => (
    <section className="event-response-group">
      <h4>
        {title} ({people.length})
      </h4>
      {people.length === 0 ? (
        <p className="small muted">Niemand</p>
      ) : (
        <ul>
          {people.map((p) => (
            <li key={p.id}>
              {p.name}
              {p.note && <span className="muted"> – {p.note}</span>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
  return (
    <div className="event-response-groups">
      {group('Zugesagt', list.yes)}
      {group('Abgesagt', list.no)}
      {group('Noch keine Rückmeldung', list.none)}
    </div>
  );
}
