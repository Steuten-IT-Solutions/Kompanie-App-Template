import { useState, type FormEvent } from 'react';
import { api, type EventInput, type PublicEvent } from '../api';
import { formatEventWhen } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { confirmAsk } from '../ask';

const EMPTY: EventInput = {
  title: '',
  start_date: '',
  start_time: '',
  end_date: '',
  end_time: '',
  location: '',
  description: '',
  url: '',
};

function toInput(ev: PublicEvent): EventInput {
  return {
    title: ev.title,
    start_date: ev.start_date,
    start_time: ev.start_time ?? '',
    end_date: ev.end_date ?? '',
    end_time: ev.end_time ?? '',
    location: ev.location,
    description: ev.description,
    url: ev.url,
  };
}

/** Was je Liste anders ist: öffentliche Termine (manage_events) oder der interne Kalender in der App (manage_calendar). */
const KINDS = {
  public: {
    source: sources.events,
    create: api.createEvent,
    update: api.updateEvent,
    remove: api.deleteEvent,
    newTitle: 'Neuer öffentlicher Termin',
    add: '+ Öffentlichen Termin anlegen',
    listTitle: 'Kommend und öffentlich',
    empty: 'Noch keine öffentlichen Einträge.',
    hint: null,
  },
  internal: {
    source: sources.calendarEntries,
    create: api.createCalendarEntry,
    update: api.updateCalendarEntry,
    remove: api.deleteCalendarEntry,
    newTitle: 'Neuer interner Termin',
    add: '+ Internen Termin anlegen',
    listTitle: 'Kommend im internen Kalender',
    empty: 'Noch keine internen Termine.',
    hint: 'Diese Termine sehen nur Angemeldete mit dem internen Kalender. Die öffentlichen Termine bleiben davon getrennt.',
  },
};

/**
 * Hier pflegt, wer darf, die öffentliche Terminliste (App für Gäste und ggf. die Webseite) oder mit `internal` die
 * Termine des internen Kalenders in der App.
 */
export default function TermineVerwalten({ onToast, internal = false }: { onToast: (msg: string) => void; internal?: boolean }) {
  const kind = KINDS[internal ? 'internal' : 'public'];
  const { data, error, loading, reload } = useAsync(kind.source.load, kind.source.deps, kind.source.key);
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [form, setForm] = useState<EventInput>(EMPTY);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = (key: keyof EventInput) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

  function startEdit(ev: PublicEvent | null) {
    setFormError(null);
    setForm(ev ? toInput(ev) : EMPTY);
    setEditing(ev ? ev.id : 'new');
    window.scrollTo(0, 0);
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!form.title.trim()) return setFormError('Bitte einen Titel angeben.');
    if (!form.start_date) return setFormError('Bitte ein Startdatum angeben.');
    setBusy(true);
    try {
      if (editing === 'new') {
        await kind.create(form);
        onToast('Termin angelegt');
      } else if (typeof editing === 'number') {
        await kind.update(editing, form);
        onToast('Termin gespeichert');
      }
      setEditing(null);
      reload();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove(ev: PublicEvent) {
    if (!(await confirmAsk(`Termin „${ev.title}“ wirklich löschen?`))) return;
    try {
      await kind.remove(ev.id);
      onToast('Termin gelöscht');
      reload();
    } catch (err) {
      onToast((err as Error).message);
    }
  }

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('termine')}>
        ‹ Zu den Terminen
      </button>

      {editing !== null ? (
        <form className="form card event-form" onSubmit={submit}>
          <h3>{editing === 'new' ? kind.newTitle : 'Termin bearbeiten'}</h3>
          <label className="field">
            <span>Titel</span>
            <input type="text" maxLength={191} value={form.title} onChange={set('title')} required />
          </label>
          <div className="field-row">
            <label className="field">
              <span>Datum</span>
              <input type="date" value={form.start_date} onChange={set('start_date')} required />
            </label>
            <label className="field">
              <span>Bis (optional)</span>
              <input type="date" value={form.end_date} min={form.start_date} onChange={set('end_date')} />
            </label>
          </div>
          <div className="field-row">
            <label className="field">
              <span>Beginn (optional)</span>
              <input type="time" value={form.start_time} onChange={set('start_time')} />
            </label>
            <label className="field">
              <span>Ende (optional)</span>
              <input type="time" value={form.end_time} onChange={set('end_time')} />
            </label>
          </div>
          <p className="muted small">Ohne Uhrzeit gilt der Termin als ganztägig.</p>
          <label className="field">
            <span>Ort</span>
            <input type="text" maxLength={191} value={form.location} onChange={set('location')} />
          </label>
          <label className="field">
            <span>Beschreibung</span>
            <textarea rows={3} value={form.description} onChange={set('description')} />
          </label>
          <label className="field">
            <span>Link (optional)</span>
            <input type="url" placeholder="https://…" value={form.url} onChange={set('url')} />
          </label>
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              {busy ? 'Speichert …' : 'Speichern'}
            </button>
            <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : (
        <button className="btn btn-block add-event" onClick={() => startEdit(null)}>
          {kind.add}
        </button>
      )}
      {kind.hint && <p className="muted small">{kind.hint}</p>}

      <h2 className="section-title">{kind.listTitle}</h2>
      {loading && !data ? (
        <Loading shape="text" />
      ) : error ? (
        <ErrorBox message={error} onRetry={reload} />
      ) : (data ?? []).length === 0 ? (
        <Empty>{kind.empty}</Empty>
      ) : (
        <ul className="card-list">
          {(data ?? []).map((ev) => (
            <li key={ev.id} className="card row">
              <div className="row-main">
                <div className="row-title">{ev.title}</div>
                <div className="row-sub">
                  {formatEventWhen(ev)}
                  {ev.location && ` · ${ev.location}`}
                </div>
                <div className="row-actions">
                  <button className="btn btn-small btn-secondary" onClick={() => startEdit(ev)}>
                    Bearbeiten
                  </button>
                  <button className="btn btn-small btn-danger" onClick={() => remove(ev)}>
                    Löschen
                  </button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
