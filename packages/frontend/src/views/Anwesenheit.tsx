import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  api,
  ApiError,
  ATTENDANCE_LABELS,
  can,
  countsText,
  config,
  type AttendanceCounts,
  type AttendanceDetail,
  type AttendanceEvent,
  type AttendanceInput,
  type AttendanceStatus,
  type ResponseStatus,
} from '../api';
import { formatDate, formatEventWhen, roleCount, todayYmd } from '../format';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Caps, Empty, ErrorBox, Loading, useAsync } from '../ui';
import { prefetchOnTap, sources } from '../viewData';
import { confirmAsk } from '../ask';
import { applyPending, pendingCount, type PendingSave } from '../attendanceOutbox';
import {
  attendanceOutbox,
  outboxChanged,
  saveInput,
  syncAttendance,
  syncMessage,
  usePendingAttendance,
  usePendingAttendanceIds,
} from '../attendanceSync';

const sec = brand.labels.sections;
const STATUSES: AttendanceStatus[] = ['present', 'excused', 'absent'];
const RESPONSE_LABELS: Record<ResponseStatus, string> = { yes: 'zugesagt', no: 'abgesagt' };

/** Wer abgesagt hat und noch keinen Eintrag hat, ist als „entschuldigt“ vorausgewählt (gespeichert erst mit der Liste). */
function withDeclines(list: AttendanceDetail, saved: Record<number, AttendanceStatus | null>) {
  return Object.fromEntries(list.people.map((p) => [p.id, saved[p.id] ?? (p.response === 'no' ? 'excused' : null)]));
}
const EVENTS_SHOWN = 8;

/** Verwaltung → Anwesenheit: #/verwaltung/anwesenheit[/<id>] */
export default function Anwesenheit({ id, onToast }: { id?: number; onToast: (msg: string) => void }) {
  return id ? <ListView key={id} id={id} onToast={onToast} /> : <Overview onToast={onToast} />;
}

/* ------------------------------------------------------------------ */

function Overview({ onToast }: { onToast: (msg: string) => void }) {
  // Mit Schlüssel: Beim Zurückkehren aus einer Liste steht die Übersicht sofort da (statt „Lädt …“).
  const { data, error, loading, reload } = useAsync(
    sources.attendanceOverview.load,
    sources.attendanceOverview.deps,
    sources.attendanceOverview.key,
  );
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const pendingIds = usePendingAttendanceIds();

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [events, lists] = data!;

  async function open(body: AttendanceInput) {
    setBusy(true);
    try {
      const list = await api.saveAttendance(body);
      navigate(`verwaltung/anwesenheit/${list.id}`);
    } catch (err) {
      onToast((err as Error).message);
      setBusy(false);
    }
  }

  function createFree(e: FormEvent) {
    e.preventDefault();
    if (!title.trim()) return onToast('Bitte einen Titel angeben.');
    void open({ source: 'free', title: title.trim(), date });
  }

  return (
    <div className="attendance">
      {config?.features.calendar &&
        (typeof events === 'string' ? (
          <>
            <h3 className="section-title">Termin aus dem Kalender</h3>
            <ErrorBox message={events} onRetry={reload} />
          </>
        ) : (
          <>
            {events.some((ev) => ev.today) && (
              <>
                <h3 className="section-title">Heute</h3>
                <EventList
                  events={events.filter((ev) => ev.today).reverse()}
                  empty=""
                  more="Weitere Termine von heute anzeigen"
                  busy={busy}
                  onOpen={open}
                />
              </>
            )}
            <h3 className="section-title">Kommende Termine</h3>
            <p className="muted small">Hier kannst du schon vorher eintragen, wer entschuldigt ist.</p>
            <EventList
              events={events.filter((ev) => ev.upcoming).reverse()}
              empty="Im Kalender steht nichts an."
              more="Weitere kommende Termine anzeigen"
              busy={busy}
              onOpen={open}
            />
            <h3 className="section-title">Vergangene Termine</h3>
            <EventList
              events={events.filter((ev) => !ev.upcoming && !ev.today)}
              empty="In den letzten 90 Tagen stand nichts an."
              more="Ältere Termine anzeigen"
              busy={busy}
              onOpen={open}
            />
          </>
        ))}

      <form className="form card free-event" onSubmit={createFree}>
        <h3>Freier Termin</h3>
        <p className="muted small">Für Anlässe, die nicht im Kalender stehen, z. B. einen spontanen Arbeitsdienst.</p>
        <div className="field-row">
          <label className="field">
            <span>Titel</span>
            <input type="text" maxLength={191} placeholder="z. B. Arbeitsdienst" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field">
            <span>Datum</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? 'Legt an …' : 'Liste anlegen'}
        </button>
      </form>

      <h3 className="section-title">Letzte Listen</h3>
      {lists.length === 0 ? (
        <Empty>Noch keine Anwesenheitslisten.</Empty>
      ) : (
        <ul className="card-list attendance-lists">
          {lists.map((l) => (
            <li
              key={l.id}
              className="card row clickable"
              onClick={() => navigate(`verwaltung/anwesenheit/${l.id}`)}
              {...prefetchOnTap(sources.attendance(l.id))}
            >
              <div className="row-main">
                <div className="row-title">
                  {l.title} {pendingIds.includes(l.id) && <span className="badge badge-level-guest">Noch nicht übertragen</span>}
                </div>
                <div className="row-sub">
                  {formatDate(l.date)} · {countsText(l.counts)}
                </div>
              </div>
              <span className="chevron" aria-hidden="true">
                ›
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Termine aus dem Kalender: die ersten EVENTS_SHOWN, der Rest auf Tipp. */
function EventList({
  events,
  empty,
  more,
  busy,
  onOpen,
}: {
  events: AttendanceEvent[];
  empty: string;
  more: string;
  busy: boolean;
  onOpen: (body: AttendanceInput) => void;
}) {
  const [showAll, setShowAll] = useState(false);
  if (events.length === 0) return <Empty>{empty}</Empty>;
  return (
    <>
      <ul className="card-list attendance-events">
        {(showAll ? events : events.slice(0, EVENTS_SHOWN)).map((ev) => (
          <li
            key={ev.key}
            className={`card row clickable ${busy ? 'disabled' : ''}`}
            onClick={() =>
              busy
                ? undefined
                : ev.list_id
                  ? navigate(`verwaltung/anwesenheit/${ev.list_id}`)
                  : onOpen({ source: 'calendar', event_key: ev.key })
            }
          >
            <div className="row-main">
              <div className="row-title">
                {ev.title} {ev.list_id && <span className="badge badge-level-guest">Liste vorhanden</span>}
              </div>
              <div className="row-sub">{formatEventWhen(ev)}</div>
            </div>
            <span className="chevron" aria-hidden="true">
              ›
            </span>
          </li>
        ))}
      </ul>
      {!showAll && events.length > EVENTS_SHOWN && (
        <button className="btn btn-link" onClick={() => setShowAll(true)}>
          {more} ({events.length - EVENTS_SHOWN})
        </button>
      )}
    </>
  );
}

/* ------------------------------------------------------------------ */

function ListView({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const s = sources.attendance(id);
  const { data, error, loading, reload, setData } = useAsync(s.load, s.deps, s.key);
  const pending = usePendingAttendance(id);
  // Offline gespeicherte Änderungen jetzt übertragen, wenn es geht (attendanceSync.ts).
  useEffect(() => {
    void syncAttendance().then((r) => {
      const msg = syncMessage(r);
      if (msg) onToast(msg);
    });
  }, [id, onToast]);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  // Mit den offline gespeicherten Änderungen, bis sie übertragen sind.
  return <Editor key={data!.updated_at} list={applyPending(data!, pending)} pending={pending} onSaved={setData} onToast={onToast} />;
}

function Editor({
  list,
  pending,
  onSaved,
  onToast,
}: {
  list: AttendanceDetail;
  /** Offline gespeicherte, noch nicht übertragene Änderungen (in `list` schon eingerechnet) */
  pending: PendingSave | null;
  onSaved: (l: AttendanceDetail) => void;
  onToast: (msg: string) => void;
}) {
  const initial = useMemo(() => Object.fromEntries(list.people.map((p) => [p.id, p.status])), [list]);
  const [statuses, setStatuses] = useState<Record<number, AttendanceStatus | null>>(() => withDeclines(list, initial));
  const [title, setTitle] = useState(list.title);
  const [date, setDate] = useState(list.date);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [responding, setResponding] = useState<number | null>(null);
  const free = list.source === 'free';
  const declines = list.people.filter((p) => p.response === 'no' && !initial[p.id]);
  const declinesOpen = declines.filter((p) => !statuses[p.id]);
  const preselected = declines.some((p) => statuses[p.id] === 'excused');
  const changes = Object.fromEntries(list.people.filter((p) => statuses[p.id] !== initial[p.id]).map((p) => [p.id, statuses[p.id]]));
  const dirty = Object.keys(changes).length > 0 || (free && (title.trim() !== list.title || date !== list.date));
  // Kommender Termin: nur „entschuldigt“ (bei freien Listen nach dem Datum im Feld)
  const upcoming = free && date !== list.date ? date > todayYmd() : list.upcoming;

  const filtered = useMemo(
    () => list.people.filter((p) => p.name.toLowerCase().includes(query.trim().toLowerCase())),
    [list.people, query],
  );
  const counts: AttendanceCounts = { present: 0, excused: 0, absent: 0 };
  let open = 0;
  for (const p of list.people) {
    const s = statuses[p.id];
    if (s) counts[s]++;
    else open++;
  }
  const points = !upcoming && !!config?.features.points && can('manage_points');
  const penalties = !upcoming && !!config?.features.penalties && can('manage_penalties');
  const toBook = list.people.filter((p) => statuses[p.id] === 'present' && p.member && !p.booked).length;
  const presentMembers = list.people.filter((p) => statuses[p.id] === 'present' && p.member).length;
  const toPenalize = list.people.filter((p) => statuses[p.id] === 'absent' && p.member).length;

  async function save(): Promise<boolean> {
    if (free && !title.trim()) {
      onToast('Bitte einen Titel angeben.');
      return false;
    }
    setBusy(true);
    const freeInput = free ? { title: title.trim(), date } : null;
    try {
      // Offline gespeicherte Änderungen gehen mit.
      const saved = await api.saveAttendance(saveInput(list.id, pending, changes, freeInput));
      if (pending) {
        attendanceOutbox.remove(list.id, pending);
        outboxChanged();
      }
      onSaved(saved);
      return true;
    } catch (e) {
      // Keine Verbindung: auf dem Gerät ablegen und später übertragen (attendanceSync.ts).
      if (
        e instanceof ApiError &&
        e.status === 0 &&
        attendanceOutbox.add(list.id, { entries: changes, ...(freeInput ?? {}), label: freeInput?.title ?? list.title })
      ) {
        outboxChanged();
        onToast('Keine Verbindung. Auf diesem Gerät gespeichert, die App überträgt es, sobald du wieder online bist.');
      } else {
        onToast((e as Error).message);
      }
      return false;
    } finally {
      setBusy(false);
    }
  }

  /** Für jemanden zu- oder absagen (z. B. nach einem Anruf); eine Absage wählt „entschuldigt“ vor. */
  async function respondFor(personId: number, status: ResponseStatus | null) {
    if (!list.event_key) return;
    setResponding(personId);
    try {
      const r = await api.respondFor(list.event_key, personId, status);
      onSaved({ ...list, people: list.people.map((p) => (p.id === personId ? { ...p, response: r.status, response_note: r.note } : p)) });
      if (!initial[personId]) {
        if (r.status === 'no' && !statuses[personId]) setStatuses({ ...statuses, [personId]: 'excused' });
        if (r.status !== 'no' && statuses[personId] === 'excused') setStatuses({ ...statuses, [personId]: null });
      }
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setResponding(null);
    }
  }

  async function saveAndGo(target: 'punkte' | 'strafe') {
    if (!dirty || (await save())) navigate(`verwaltung/${target}/anwesenheit-${list.id}`);
  }

  async function remove() {
    if (!(await confirmAsk(`Anwesenheitsliste „${list.title}“ wirklich löschen? Gebuchte ${sec.points} bleiben erhalten.`))) return;
    setBusy(true);
    try {
      await api.deleteAttendance(list.id);
      attendanceOutbox.discard(list.id);
      outboxChanged();
      onToast('Liste gelöscht');
      navigate('verwaltung/anwesenheit');
    } catch (e) {
      onToast((e as Error).message);
      setBusy(false);
    }
  }

  return (
    <div className="attendance">
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/anwesenheit')}>
        ‹ {sec.attendance}
      </button>
      {free ? (
        <div className="field-row attendance-meta">
          <label className="field">
            <span>Titel</span>
            <input type="text" maxLength={191} value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field">
            <span>Datum</span>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </label>
        </div>
      ) : (
        <>
          <h2 className="member-name">{list.title}</h2>
          <p className="muted small">{formatDate(list.date)}</p>
        </>
      )}
      <p className="attendance-counts" role="status">
        {countsText(counts, open)}
      </p>
      {upcoming && <p className="muted small">Der Termin kommt erst noch. Bis dahin kannst du nur „entschuldigt“ eintragen.</p>}
      {preselected && (
        <p className="muted small">Wer abgesagt hat, ist als „entschuldigt“ vorausgewählt. Gespeichert ist das erst mit „Speichern“.</p>
      )}
      {declinesOpen.length > 0 && (
        <button
          type="button"
          className="btn btn-secondary btn-block attendance-declines"
          onClick={() => setStatuses({ ...statuses, ...Object.fromEntries(declinesOpen.map((p) => [p.id, 'excused' as const])) })}
        >
          Absagen übernehmen ({declinesOpen.length})
        </button>
      )}
      {pending && (
        <p className="attendance-pending small" role="status">
          Noch nicht übertragen: offline gespeicherte Änderungen
          {pendingCount(pending) > 0 && ` bei ${pendingCount(pending)} ${pendingCount(pending) === 1 ? 'Person' : 'Personen'}`}. Die App
          sendet sie, sobald du wieder online bist.{' '}
          <button
            type="button"
            className="btn btn-link inline-link"
            onClick={() =>
              void syncAttendance().then((r) => onToast(syncMessage(r) ?? 'Noch keine Verbindung. Die App versucht es später wieder.'))
            }
          >
            Jetzt übertragen
          </button>
        </p>
      )}
      {!dirty && !pending && <GroupCounts list={list} />}

      <div className="picker">
        <div className="picker-head">
          <input
            type="search"
            placeholder="Person suchen …"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label="Person suchen"
          />
          {!upcoming && (
            <button
              type="button"
              className="btn btn-link nowrap"
              onClick={() => setStatuses({ ...statuses, ...Object.fromEntries(filtered.map((p) => [p.id, 'present' as const])) })}
            >
              Alle anwesend
            </button>
          )}
        </div>
        <div className="attendance-grid attendance-head" aria-hidden="true">
          <span />
          {STATUSES.map((st) => (
            <span key={st} className={`attendance-col attendance-col-${st}`}>
              {ATTENDANCE_LABELS[st]}
            </span>
          ))}
        </div>
        <ul className="picker-list attendance-list">
          {filtered.map((p) => (
            <li key={p.id} className="attendance-grid" role="radiogroup" aria-label={p.name}>
              <span className="attendance-name">
                <span>{p.name}</span>
                {p.booked && <span className="attendance-booked">{sec.points} gebucht</span>}
                {list.responses_open && p.may_respond ? (
                  <select
                    className={`attendance-response attendance-response-${p.response ?? 'none'}`}
                    value={p.response ?? ''}
                    disabled={responding === p.id}
                    aria-label={`Rückmeldung von ${p.name}`}
                    onChange={(e) => void respondFor(p.id, (e.target.value || null) as ResponseStatus | null)}
                  >
                    <option value="">keine Rückmeldung</option>
                    <option value="yes">{RESPONSE_LABELS.yes}</option>
                    <option value="no">{RESPONSE_LABELS.no}</option>
                  </select>
                ) : (
                  p.response && (
                    <span className={`attendance-response attendance-response-${p.response}`}>{RESPONSE_LABELS[p.response]}</span>
                  )
                )}
                {p.response_note && <span className="attendance-response-note">„{p.response_note}“</span>}
              </span>
              {STATUSES.map((st) => (
                <span key={st} className="attendance-cell">
                  <input
                    type="radio"
                    className={`attendance-check attendance-check-${st}`}
                    name={`att-${p.id}`}
                    checked={statuses[p.id] === st}
                    // Kommender Termin: anwesend/fehlt gesperrt; ein schon gesetztes Häkchen lässt sich noch entfernen.
                    disabled={upcoming && st !== 'excused' && statuses[p.id] !== st}
                    onChange={() => setStatuses({ ...statuses, [p.id]: st })}
                    onClick={() => statuses[p.id] === st && setStatuses({ ...statuses, [p.id]: null })}
                    aria-label={`${p.name}: ${ATTENDANCE_LABELS[st]}`}
                  />
                </span>
              ))}
            </li>
          ))}
          {filtered.length === 0 && <li className="muted">Niemand gefunden.</li>}
        </ul>
      </div>

      <button
        className="btn btn-block attendance-save"
        disabled={busy || !dirty}
        onClick={() => save().then((ok) => ok && onToast('Anwesenheit gespeichert'))}
      >
        {busy ? 'Speichert …' : dirty ? 'Speichern' : pending ? 'Auf dem Gerät gespeichert' : 'Gespeichert'}
      </button>
      {(points || penalties) && (
        <div className="button-row attendance-book">
          {points && (
            <button className="btn" disabled={busy || toBook === 0} onClick={() => saveAndGo('punkte')}>
              <Caps text={sec.points} /> für {toBook} {toBook === 1 ? 'Anwesenden' : 'Anwesende'}
            </button>
          )}
          {penalties && (
            <button className="btn btn-warn" disabled={busy || toPenalize === 0} onClick={() => saveAndGo('strafe')}>
              Eintrag für {toPenalize} {toPenalize === 1 ? 'Fehlenden' : 'Fehlende'}
            </button>
          )}
        </div>
      )}
      {points && toBook === 0 && presentMembers > 0 && <p className="muted small">{sec.points} für alle Anwesenden bereits gebucht.</p>}
      <p className="muted small">
        Tippe ein gesetztes Häkchen noch einmal an, um es zu entfernen.{' '}
        {list.updated_by_name && <>Zuletzt gespeichert von {list.updated_by_name}. </>}
        <button className="btn btn-link inline-link danger-link" disabled={busy} onClick={remove}>
          Liste löschen
        </button>
      </p>
    </div>
  );
}

/** Aufteilung der gespeicherten Zähler nach Gruppen (Einstellungen → Rollen → Anwesenheit). */
function GroupCounts({ list }: { list: AttendanceDetail }) {
  const rows = STATUSES.filter((s) => list.counts_by_group[s].length > 0);
  if (rows.length === 0) return null;
  return (
    <ul className="attendance-groups small muted">
      {rows.map((s) => (
        <li key={s}>
          <strong>{ATTENDANCE_LABELS[s]}:</strong> {list.counts_by_group[s].map((g) => roleCount(g.label, g.count)).join(' · ')}
        </li>
      ))}
    </ul>
  );
}
