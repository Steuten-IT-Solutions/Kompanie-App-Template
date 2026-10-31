import { useState, type FormEvent } from 'react';
import { api, config, type EventInput, type PublicEvent } from '../api';
import { formatEventWhen } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

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

/** Admins pflegen hier die öffentliche Terminliste (App für Gäste und ggf. die Webseite). */
export default function TermineVerwalten({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(sources.events.load, sources.events.deps, sources.events.key);
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
        await api.createEvent(form);
        onToast('Termin angelegt');
      } else if (typeof editing === 'number') {
        await api.updateEvent(editing, form);
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
    if (!window.confirm(`Termin „${ev.title}“ wirklich löschen?`)) return;
    try {
      await api.deleteEvent(ev.id);
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
      <p className="muted small">
        Diese Termine sehen Gäste in der App.
        {config?.variant === 'wordpress' && (
          <>
            {' '}
            Auf der Webseite zeigt sie der Shortcode <code>[kompanie_termine]</code>.
          </>
        )}
        {config?.features.calendar && ' Mitglieder sehen weiterhin den Kalender mit allen Terminen.'}
      </p>

      {editing !== null ? (
        <form className="form card event-form" onSubmit={submit}>
          <h3>{editing === 'new' ? 'Neuer öffentlicher Termin' : 'Termin bearbeiten'}</h3>
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
          + Öffentlichen Termin anlegen
        </button>
      )}

      <h2 className="section-title">Kommende öffentliche Termine</h2>
      {loading && !data ? (
        <Loading />
      ) : error ? (
        <ErrorBox message={error} onRetry={reload} />
      ) : (data ?? []).length === 0 ? (
        <Empty>Noch keine öffentlichen Termine.</Empty>
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
