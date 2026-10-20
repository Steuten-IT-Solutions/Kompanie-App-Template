import { useMemo, useState, type FormEvent } from 'react';
import {
  api,
  ATTENDANCE_LABELS,
  can,
  config,
  levelLabel,
  type AttendanceCounts,
  type AttendanceDetail,
  type AttendanceEvent,
  type AttendanceStatus,
  type AttendanceSummary,
} from '../api';
import { caps, formatDate, formatEventWhen, todayYmd } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const STATUSES: AttendanceStatus[] = ['present', 'excused', 'absent'];
const EVENTS_SHOWN = 8;

export const countsText = (c: AttendanceCounts): string => STATUSES.map((s) => `${c[s]} ${ATTENDANCE_LABELS[s]}`).join(' · ');

/** Verwaltung → Anwesenheit: #/verwaltung/anwesenheit[/<id>] */
export default function Anwesenheit({ id, onToast }: { id?: number; onToast: (msg: string) => void }) {
  return id ? <ListView key={id} id={id} onToast={onToast} /> : <Overview onToast={onToast} />;
}

/* ------------------------------------------------------------------ */

async function loadOverview(): Promise<[AttendanceEvent[] | string, AttendanceSummary[]]> {
  // Ohne erreichbaren Kalender bleiben freie Termine und die letzten Listen nutzbar.
  const events = config?.features.calendar ? api.attendanceEvents().catch((e: Error) => e.message) : Promise.resolve([]);
  return Promise.all([events, api.attendanceLists()]);
}

function Overview({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(loadOverview);
  const [showAll, setShowAll] = useState(false);
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [events, lists] = data!;

  async function open(body: Parameters<typeof api.saveAttendance>[0]) {
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
      {config?.features.calendar && (
        <>
          <h3 className="section-title">Termin aus dem Kalender</h3>
          {typeof events === 'string' ? (
            <ErrorBox message={events} onRetry={reload} />
          ) : events.length === 0 ? (
            <Empty>Keine Termine in den letzten 90 Tagen.</Empty>
          ) : (
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
                          : void open({ source: 'calendar', event_key: ev.key })
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
                  Ältere Termine anzeigen ({events.length - EVENTS_SHOWN})
                </button>
              )}
            </>
          )}
        </>
      )}

      <form className="form card free-event" onSubmit={createFree}>
        <h3>Freier Termin</h3>
        <p className="muted small">Für Termine, die nicht im Kalender stehen, z. B. einen spontanen Arbeitsdienst.</p>
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
            <li key={l.id} className="card row clickable" onClick={() => navigate(`verwaltung/anwesenheit/${l.id}`)}>
              <div className="row-main">
                <div className="row-title">{l.title}</div>
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

/* ------------------------------------------------------------------ */

function ListView({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.attendance(id), [id]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  return <Editor key={data!.updated_at} list={data!} onSaved={setData} onToast={onToast} />;
}

function Editor({
  list,
  onSaved,
  onToast,
}: {
  list: AttendanceDetail;
  onSaved: (l: AttendanceDetail) => void;
  onToast: (msg: string) => void;
}) {
  const initial = useMemo(() => Object.fromEntries(list.people.map((p) => [p.id, p.status])), [list]);
  const [statuses, setStatuses] = useState<Record<number, AttendanceStatus | null>>(initial);
  const [title, setTitle] = useState(list.title);
  const [date, setDate] = useState(list.date);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const free = list.source === 'free';
  const changes = Object.fromEntries(list.people.filter((p) => statuses[p.id] !== initial[p.id]).map((p) => [p.id, statuses[p.id]]));
  const dirty = Object.keys(changes).length > 0 || (free && (title.trim() !== list.title || date !== list.date));

  const filtered = useMemo(
    () => list.people.filter((p) => p.name.toLowerCase().includes(query.trim().toLowerCase())),
    [list.people, query],
  );
  const counts: AttendanceCounts = { present: 0, excused: 0, absent: 0 };
  for (const p of list.people) {
    const s = statuses[p.id];
    if (s) counts[s]++;
  }
  const points = !!config?.features.points && can('manage_points');
  const penalties = !!config?.features.penalties && can('manage_penalties');
  const toBook = list.people.filter((p) => statuses[p.id] === 'present' && p.member && !p.booked).length;
  const presentMembers = list.people.filter((p) => statuses[p.id] === 'present' && p.member).length;
  const toPenalize = list.people.filter((p) => statuses[p.id] === 'absent' && p.member).length;

  async function save(): Promise<boolean> {
    if (free && !title.trim()) {
      onToast('Bitte einen Titel angeben.');
      return false;
    }
    setBusy(true);
    try {
      onSaved(await api.saveAttendance({ id: list.id, entries: changes, ...(free ? { title: title.trim(), date } : {}) }));
      return true;
    } catch (e) {
      onToast((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function saveAndGo(target: 'punkte' | 'strafe') {
    if (!dirty || (await save())) navigate(`verwaltung/${target}/anwesenheit-${list.id}`);
  }

  async function remove() {
    if (!window.confirm(`Anwesenheitsliste „${list.title}“ wirklich löschen? Gebuchte Punkte bleiben erhalten.`)) return;
    setBusy(true);
    try {
      await api.deleteAttendance(list.id);
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
        ‹ Anwesenheit
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
        {countsText(counts)}
      </p>
      {!dirty && <GroupCounts list={list} />}

      <div className="picker">
        <div className="picker-head">
          <input
            type="search"
            placeholder="Person suchen …"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label="Person suchen"
          />
          <button
            type="button"
            className="btn btn-link nowrap"
            onClick={() => setStatuses({ ...statuses, ...Object.fromEntries(filtered.map((p) => [p.id, 'present' as const])) })}
          >
            Alle anwesend
          </button>
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
                <span>
                  {p.name}
                  {p.role !== 'member' && (
                    <span className={`badge ${p.member ? 'badge-role' : 'badge-level-guest'}`}>{caps(levelLabel(p.role))}</span>
                  )}
                </span>
                {p.booked && <span className="attendance-booked">Punkte gebucht</span>}
              </span>
              {STATUSES.map((st) => (
                <span key={st} className="attendance-cell">
                  <input
                    type="radio"
                    className={`attendance-check attendance-check-${st}`}
                    name={`att-${p.id}`}
                    checked={statuses[p.id] === st}
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
        {busy ? 'Speichert …' : dirty ? 'Speichern' : 'Gespeichert'}
      </button>
      {(points || penalties) && (
        <div className="button-row attendance-book">
          {points && (
            <button className="btn" disabled={busy || toBook === 0} onClick={() => saveAndGo('punkte')}>
              Punkte für {toBook} {toBook === 1 ? 'Anwesenden' : 'Anwesende'}
            </button>
          )}
          {penalties && (
            <button className="btn btn-warn" disabled={busy || toPenalize === 0} onClick={() => saveAndGo('strafe')}>
              Eintrag für {toPenalize} {toPenalize === 1 ? 'Fehlenden' : 'Fehlende'}
            </button>
          )}
        </div>
      )}
      {points && toBook === 0 && presentMembers > 0 && <p className="muted small">Punkte für alle Anwesenden bereits gebucht.</p>}
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
          <strong>{caps(ATTENDANCE_LABELS[s])}:</strong> {list.counts_by_group[s].map((g) => `${g.label} ${g.count}`).join(' · ')}
        </li>
      ))}
    </ul>
  );
}
