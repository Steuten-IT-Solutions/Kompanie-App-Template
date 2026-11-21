import { useState, type FormEvent } from 'react';
import { api, eventResponsesOn, type EventInput, type PublicEvent } from '../api';
import { formatEventWhen } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { t } from '../i18n';
import { fieldTranslationsInput, FieldTranslationsEditor, type FieldTranslations, type TranslatedField } from '../translationFields';

/** Was es je weitere Sprache gibt (Plan „Mehrsprachigkeit“, PR 9d); Datum, Zeit und Link gelten für alle. */
const TRANSLATED: TranslatedField[] = [
  { key: 'title', label: (language) => t('Titel auf {language}', { language }), maxLength: 191 },
  { key: 'location', label: (language) => t('Ort auf {language}', { language }), maxLength: 191 },
  { key: 'description', label: (language) => t('Beschreibung auf {language}', { language }), multiline: true },
];
const TRANSLATED_KEYS = TRANSLATED.map((f) => f.key);

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
    respond_until: ev.respond_until ?? '',
  };
}

/** Was je Liste anders ist: öffentliche Termine (manage_events) oder der interne Kalender in der App (manage_calendar). */
const KINDS = {
  public: {
    source: sources.eventsSource,
    create: api.createEvent,
    update: api.updateEvent,
    remove: api.deleteEvent,
    newTitle: t('Neuer öffentlicher Termin'),
    add: t('+ Öffentlichen Termin anlegen'),
    listTitle: t('Kommend und öffentlich'),
    empty: t('Noch keine öffentlichen Einträge.'),
    hint: null,
  },
  internal: {
    source: sources.calendarEntries,
    create: api.createCalendarEntry,
    update: api.updateCalendarEntry,
    remove: api.deleteCalendarEntry,
    newTitle: t('Neuer interner Termin'),
    add: t('+ Internen Termin anlegen'),
    listTitle: t('Kommend im internen Kalender'),
    empty: t('Noch keine internen Termine.'),
    hint: t('Diese Termine sehen nur Angemeldete mit dem internen Kalender. Die öffentlichen Termine bleiben davon getrennt.'),
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
  const [translations, setTranslations] = useState<FieldTranslations>({});
  const [before, setBefore] = useState<FieldTranslations | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const set = (key: keyof EventInput) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

  function startEdit(ev: PublicEvent | null) {
    setFormError(null);
    setForm(ev ? toInput(ev) : EMPTY);
    const own = (ev?.translations ?? {}) as FieldTranslations;
    setTranslations(own);
    setBefore(ev ? own : undefined);
    setEditing(ev ? ev.id : 'new');
    window.scrollTo(0, 0);
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!form.title.trim()) return setFormError(t('Bitte einen Titel angeben.'));
    if (!form.start_date) return setFormError(t('Bitte ein Startdatum angeben.'));
    setBusy(true);
    // Den Anmeldeschluss gibt es nur im internen Kalender.
    const base: EventInput = internal ? form : { ...form, respond_until: undefined };
    const data: EventInput = { ...base, translations: fieldTranslationsInput(TRANSLATED_KEYS, translations, before) };
    try {
      if (editing === 'new') {
        await kind.create(data);
        onToast(t('Termin angelegt'));
      } else if (typeof editing === 'number') {
        await kind.update(editing, data);
        onToast(t('Termin gespeichert'));
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
    if (!(await confirmAsk(t('Termin „{title}“ wirklich löschen?', { title: ev.title })))) return;
    try {
      await kind.remove(ev.id);
      onToast(t('Termin gelöscht'));
      reload();
    } catch (err) {
      onToast((err as Error).message);
    }
  }

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('termine')}>
        ‹ {t('Zu den Terminen')}
      </button>

      {editing !== null ? (
        <form className="form card event-form" onSubmit={submit}>
          <h3>{editing === 'new' ? kind.newTitle : t('Termin bearbeiten')}</h3>
          <label className="field">
            <span>{t('Titel')}</span>
            <input type="text" maxLength={191} value={form.title} onChange={set('title')} required />
          </label>
          <div className="field-row">
            <label className="field">
              <span>{t('Datum')}</span>
              <input type="date" value={form.start_date} onChange={set('start_date')} required />
            </label>
            <label className="field">
              <span>{t('Bis (optional)')}</span>
              <input type="date" value={form.end_date} min={form.start_date} onChange={set('end_date')} />
            </label>
          </div>
          <div className="field-row">
            <label className="field">
              <span>{t('Beginn (optional)')}</span>
              <input type="time" value={form.start_time} onChange={set('start_time')} />
            </label>
            <label className="field">
              <span>{t('Ende (optional)')}</span>
              <input type="time" value={form.end_time} onChange={set('end_time')} />
            </label>
          </div>
          <p className="muted small">{t('Ohne Uhrzeit gilt der Termin als ganztägig.')}</p>
          {internal && eventResponsesOn() && (
            <>
              <label className="field">
                <span>{t('Anmeldeschluss (optional)')}</span>
                <input type="date" value={form.respond_until ?? ''} max={form.start_date || undefined} onChange={set('respond_until')} />
              </label>
              <p className="muted small">{t('Zu- und absagen geht bis zum Ende dieses Tages, ohne Anmeldeschluss bis zum Beginn.')}</p>
            </>
          )}
          <label className="field">
            <span>{t('Ort')}</span>
            <input type="text" maxLength={191} value={form.location} onChange={set('location')} />
          </label>
          <label className="field">
            <span>{t('Beschreibung')}</span>
            <textarea rows={3} value={form.description} onChange={set('description')} />
          </label>
          <FieldTranslationsEditor
            fields={TRANSLATED}
            german={{ title: form.title, location: form.location, description: form.description }}
            value={translations}
            onChange={setTranslations}
            hint={(language) =>
              t(
                'Wer die App auf {language} nutzt, sieht Titel, Ort und Beschreibung in dieser Fassung. Was leer bleibt, erscheint auf Deutsch.',
                {
                  language,
                },
              )
            }
          />
          <label className="field">
            <span>{t('Link (optional)')}</span>
            <input type="url" placeholder="https://…" value={form.url} onChange={set('url')} />
          </label>
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              {busy ? t('Speichert …') : t('Speichern')}
            </button>
            <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
              {t('Abbrechen')}
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
                <div className="row-actions icon-row">
                  <IconButton icon="edit" label={t('Bearbeiten')} onClick={() => startEdit(ev)} />
                  <IconButton icon="delete" label={t('Löschen')} variant="danger" onClick={() => remove(ev)} />
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
