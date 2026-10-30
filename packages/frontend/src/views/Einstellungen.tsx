import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import brand from '../generated/brand';
import {
  api,
  can,
  config,
  applyBirthdayUpcomingDays,
  applyFeatures,
  newsTitle,
  refreshRoles,
  type AdminSettings,
  type FailedPhoto,
  type Feature,
  type FeatureInfo,
  type MailSettings,
  type NewsSourceType,
} from '../api';
import { EmailField, ErrorBox, Loading, useAsync } from '../ui';
import { normalizeEmail } from '../email';
import Neuerungen from './Neuerungen';
import Rollen from './Rollen';
import Wartung from './Wartung';
import Nutzung from './Nutzung';
import { MaintenanceCard } from '../maintenance';
import { goBack, navigate, redirect } from '../router';
import { formatBytes } from '../format';

const sec = brand.labels.sections;

/** Seiten der Einstellungen: Adresse (#/einstellungen/<id>), Titel und ein Satz für die Übersicht. */
const PAGES = [
  { id: 'bereiche', title: 'Bereiche', text: 'Welche Bereiche die App für alle hat.' },
  { id: 'rollen', title: 'Rollen', text: 'Wer was in der App darf.' },
  { id: 'benachrichtigungen', title: 'Benachrichtigungen', text: 'Angemeldete Geräte und Uhrzeit der Erinnerung.' },
  { id: 'geburtstage', title: sec.birthdays, text: `Wie lange vorher anstehende ${sec.birthdays} bei den ${sec.events} erscheinen.` },
  { id: 'email', title: 'E-Mail', text: `Kompanieadresse und Mailserver, z. B. für Nachrichten aus „${sec.board}“.` },
  { id: 'google', title: 'Google-Zugang', text: `Verbindung zu Google für ${sec.gallery} und Kalender.` },
  { id: 'galerie', title: sec.gallery, text: 'Fotoalben aus einem Ordner in Google Drive.' },
  { id: 'kalender', title: 'Kalender', text: 'Kalender für Mitglieder aus Google Kalender oder per iCal.' },
  { id: 'news', title: newsTitle(), text: `Woher die ${newsTitle()} kommen.` },
  { id: 'nutzung', title: 'Nutzung', text: 'Wer die App wann nutzt und welche Bereiche.' },
  { id: 'zustand', title: 'Zustand und Updates', text: 'Ampel je Bereich, installierte Version und Updates.' },
  { id: 'datensicherung', title: 'Datensicherung', text: 'Sicherung herunterladen oder einspielen.' },
  { id: 'wartung', title: 'Wartungsmodus', text: 'Die App vorübergehend nur für Admins öffnen.' },
  { id: 'neuerungen', title: 'Was ist neu', text: 'Änderungen von Version zu Version.' },
] as const;

/**
 * Einstellungen für Admins (beide Varianten): eine Übersicht, jede Gruppe auf einer eigenen Seite. Geheime Werte zeigt
 * der Server nie an, nur ob sie gesetzt sind. Bei WordPress kommen die Neuigkeiten aus den Beiträgen der Website; dort
 * gibt es keine Seite dafür.
 */
export default function Einstellungen({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  // Rückkehr von Google landet auf #/einstellungen?google=1: gleich zur Seite Google-Zugang (sie liest ?google=1).
  const [fromGoogle] = useState(() => !path[0] && new URLSearchParams(window.location.search).has('google'));
  useEffect(() => {
    if (fromGoogle) redirect('einstellungen/google');
  }, [fromGoogle]);
  const page = PAGES.find((p) => p.id === path[0]);
  if (fromGoogle && !page) return <Loading />;
  if (!page) return <Overview />;
  return (
    <div className="settings">
      <button type="button" className="btn btn-link back" onClick={() => goBack('einstellungen')}>
        ‹ Einstellungen
      </button>
      {page.id === 'bereiche' && <Bereiche onToast={onToast} />}
      {page.id === 'rollen' && <Rollen onToast={onToast} />}
      {page.id === 'benachrichtigungen' && <PushSettings onToast={onToast} />}
      {page.id === 'geburtstage' && <BirthdaySettingsSection onToast={onToast} />}
      {page.id === 'email' && <MailSection onToast={onToast} />}
      {page.id === 'nutzung' && <Nutzung onToast={onToast} />}
      {page.id === 'zustand' && <Wartung part="zustand" onToast={onToast} />}
      {page.id === 'wartung' && <MaintenanceCard onToast={onToast} />}
      {page.id === 'datensicherung' && <Wartung part="datensicherung" onToast={onToast} />}
      {page.id === 'neuerungen' && <Neuerungen />}
      {(page.id === 'google' || page.id === 'kalender' || page.id === 'galerie' || page.id === 'news') && (
        <Verbindung page={page.id} onToast={onToast} />
      )}
    </div>
  );
}

/**
 * Übersicht: je Seite eine Zeile; Neuigkeiten nur, wenn die Installation eine eigene News-Quelle hat, Geburtstage nur
 * mit dem Bereich.
 */
function Overview() {
  // Erst nach dem Laden zeigen (beim nächsten Öffnen sofort aus dem Speicher): Sonst rückt „Neuigkeiten“ nachträglich
  // ein und schiebt die Zeilen darunter weg.
  const { data, loading } = useAsync(() => api.settings(), [], 'settings');
  if (loading && !data) return <Loading />;
  const pages = PAGES.filter(
    (p) =>
      (p.id !== 'news' || !!data?.news) &&
      (p.id !== 'nutzung' || can('view_activity')) &&
      (p.id !== 'geburtstage' || !!config?.features.birthdays),
  );
  return (
    <ul className="card-list settings-overview">
      {pages.map((p) => (
        <li key={p.id}>
          <button type="button" className="card row clickable settings-link" onClick={() => navigate(`einstellungen/${p.id}`)}>
            <span className="row-main">
              <span className="row-title">{p.title}</span>
              <span className="row-sub">{p.text}</span>
            </span>
            <span className="settings-chevron" aria-hidden="true">
              ›
            </span>
          </button>
        </li>
      ))}
    </ul>
  );
}

/** Google-Zugang, Kalender, Galerie oder Neuigkeiten: alle aus den gemeinsamen Einstellungen (/admin/settings). */
function Verbindung({ page, onToast }: { page: 'google' | 'kalender' | 'galerie' | 'news'; onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData, revision } = useAsync(() => api.settings(), [], 'settings');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  // key: Die Formulare übernehmen ihre Felder einmal; weicht der frische Stand vom gespeicherten ab, neu aufbauen.
  const props = { settings: s, onSaved: setData, onToast };
  if (page === 'google') return <GoogleAccess key={revision} {...props} />;
  if (page === 'kalender') return <Calendar key={revision} {...props} />;
  if (page === 'galerie') return <Gallery key={revision} {...props} />;
  return s.news ? <News key={revision} {...props} /> : null;
}

/**
 * Benachrichtigungen: wie viele Geräte angemeldet sind, wann die Erinnerung an Termine am nächsten Tag kommt und ob und ab
 * wann am Geburtstag benachrichtigt wird.
 */
function PushSettings({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.pushAdmin(), [], 'admin/push');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const p = data!;

  async function save(change: () => ReturnType<typeof api.pushAdmin>) {
    setBusy(true);
    setSaveError(null);
    try {
      setData(await change());
      onToast('Gespeichert');
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-benachrichtigungen">
      <h3>Benachrichtigungen</h3>
      {p.supported ? (
        <p className="small muted">
          {p.devices === 1 ? '1 Gerät' : `${p.devices} Geräte`} angemeldet ({p.accounts} {p.accounts === 1 ? 'Konto' : 'Konten'}, {p.guests}{' '}
          {p.guests === 1 ? 'Gast' : 'Gäste'}).
          {p.queued > 0 && ` ${p.queued} Nachrichten warten auf den Versand.`}
        </p>
      ) : (
        <p className="small" role="alert">
          Der Server kann keine Benachrichtigungen senden. Es fehlt: {p.missing.join(', ')}. Dein Hoster kann das in den PHP-Einstellungen
          einschalten.
        </p>
      )}
      <label className="field">
        <span>Erinnerung an Termine am nächsten Tag ab</span>
        <select value={p.reminderHour} disabled={busy} onChange={(e) => save(() => api.setPushReminderHour(Number(e.target.value)))}>
          <HourOptions />
        </select>
      </label>
      {p.birthdays && (
        <>
          <label className="toggle">
            <input
              type="checkbox"
              checked={p.birthdays.enabled}
              disabled={busy}
              onChange={(e) => save(() => api.setBirthdayNotify({ birthday_notify: e.target.checked }))}
            />
            Am Geburtstag eines Mitglieds alle anderen benachrichtigen
          </label>
          {p.birthdays.enabled && (
            <label className="field">
              <span>Benachrichtigung am Geburtstag ab</span>
              <select
                value={p.birthdays.hour}
                disabled={busy}
                onChange={(e) => save(() => api.setBirthdayNotify({ birthday_hour: Number(e.target.value) }))}
              >
                <HourOptions />
              </select>
            </label>
          )}
        </>
      )}
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
      <p className="small muted">
        Jede Person wählt auf ihrem Gerät selbst, worüber sie benachrichtigt wird (Meins → Konto → Benachrichtigungen).
      </p>
    </section>
  );
}

/** Bis zu so vielen Tagen im Voraus zeigt die Karte „Anstehende Geburtstage“ einen Geburtstag (wie im Kern). */
const UPCOMING_DAYS_MAX = 30;

/** Geburtstage (Issue #1): wie viele Tage im Voraus die Karte „Anstehende Geburtstage“ bei den Terminen einen zeigt. */
function BirthdaySettingsSection({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.birthdaySettings(), [], 'admin/birthdays');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (!config?.features.birthdays) return <p className="muted">Der Bereich {sec.birthdays} ist ausgeschaltet.</p>;
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  async function save(days: number) {
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.setBirthdayUpcomingDays(days);
      setData(next);
      applyBirthdayUpcomingDays(next.upcoming_days);
      onToast('Gespeichert');
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-geburtstage">
      <h3>{sec.birthdays}</h3>
      <label className="field">
        <span>Anstehende {sec.birthdays} anzeigen</span>
        <select value={data!.upcoming_days} disabled={busy} onChange={(e) => save(Number(e.target.value))}>
          {Array.from({ length: UPCOMING_DAYS_MAX + 1 }, (_, d) => (
            <option key={d} value={d}>
              {d === 0 ? 'nur am Tag selbst' : d === 1 ? '1 Tag vorher' : `${d} Tage vorher`}
            </option>
          ))}
        </select>
      </label>
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
      <p className="small muted">
        So lange vorher erscheint ein Geburtstag oben bei den {sec.events} unter „Anstehende {sec.birthdays}“, für alle, die {sec.birthdays}{' '}
        sehen.
      </p>
    </section>
  );
}

/**
 * E-Mail (Issue #172): Kompanieadresse (Absender) und SMTP des Mailservers, Verbindung prüfen, Testmail, Versand in
 * Portionen. Das Passwort zeigt der Server nie an; leer lassen behält es.
 */
function MailSection({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.mailSettings(), [], 'admin/mail');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  return <MailForm key={JSON.stringify(data)} settings={data!} onSaved={setData} onToast={onToast} />;
}

function MailForm({
  settings,
  onSaved,
  onToast,
}: {
  settings: MailSettings;
  onSaved: (s: MailSettings) => void;
  onToast: (msg: string) => void;
}) {
  const [form, setForm] = useState({
    from: settings.from,
    from_name: settings.from_name,
    host: settings.host,
    port: String(settings.port || 587),
    secure: settings.secure,
    user: settings.user,
    password: '',
    batch_size: String(settings.batch_size),
    batch_pause: String(settings.batch_pause),
  });
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [testTo, setTestTo] = useState('');
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm({ ...form, [k]: e.target.value });

  async function run<T>(action: () => Promise<T>, done: (r: T) => void) {
    setBusy(true);
    setMessage(null);
    try {
      done(await action());
    } catch (err) {
      setMessage({ ok: false, text: (err as Error).message });
    } finally {
      setBusy(false);
    }
  }

  function save(e: FormEvent) {
    e.preventDefault();
    const { password, port, batch_size, batch_pause, ...rest } = form;
    void run(
      () =>
        api.updateMailSettings({
          ...rest,
          from: normalizeEmail(rest.from),
          port: Number(port),
          batch_size: Number(batch_size),
          batch_pause: Number(batch_pause),
          ...(password !== '' ? { password } : {}),
        }),
      (s) => {
        onSaved(s);
        onToast('Gespeichert');
      },
    );
  }

  return (
    <form className="card settings-section form" id="einstellungen-email" onSubmit={save}>
      <h3>E-Mail</h3>
      <p className="small muted">
        Über diese Adresse verschickt die App Mails, z. B. Nachrichten aus „{sec.board}“ an die Mitglieder. Antworten und nicht zustellbare
        Mails gehen an diese Adresse. Die Zugangsdaten stehen beim Anbieter des Postfachs (Hoster oder Mailanbieter) unter „SMTP“ oder
        „Postausgangsserver“.
      </p>
      {settings.unavailable && (
        <p className="form-error" role="alert">
          {settings.unavailable}
        </p>
      )}
      <EmailField
        label="Kompanieadresse (Absender)"
        className="mail-from"
        value={form.from}
        onChange={(from) => setForm({ ...form, from })}
        placeholder="info@beispiel.de"
      />
      <label className="field">
        <span>Absendername</span>
        <input value={form.from_name} onChange={set('from_name')} placeholder={settings.from_name_default} />
      </label>
      <label className="field">
        <span>Mailserver (SMTP)</span>
        <input className="mail-host" value={form.host} onChange={set('host')} placeholder="smtp.beispiel.de" autoComplete="off" />
      </label>
      <div className="field-row">
        <label className="field">
          <span>Port</span>
          <input type="number" min={1} max={65535} value={form.port} onChange={set('port')} />
        </label>
        <label className="field">
          <span>Verschlüsselung</span>
          <select value={form.secure} onChange={(e) => setForm({ ...form, secure: e.target.value as MailSettings['secure'] })}>
            <option value="starttls">STARTTLS (meist Port 587)</option>
            <option value="ssl">SSL/TLS (meist Port 465)</option>
            <option value="none">keine</option>
          </select>
        </label>
      </div>
      <label className="field">
        <span>Benutzername</span>
        <input value={form.user} onChange={set('user')} autoComplete="off" />
      </label>
      <label className="field">
        <span>Passwort{settings.password_set ? ' (gespeichert – leer lassen, um es zu behalten)' : ''}</span>
        <input type="password" value={form.password} onChange={set('password')} autoComplete="new-password" />
      </label>
      <details>
        <summary>Versand in Portionen</summary>
        <p className="small muted">
          Manche Hoster erlauben nur eine bestimmte Zahl Mails pro Stunde. Dann hier eine Portionsgröße und eine Pause eintragen. 0
          bedeutet: alle auf einmal bzw. ohne Pause. Die App versucht es alle 15 Minuten; eine Pause dauert deshalb mindestens bis zum
          nächsten Lauf.
        </p>
        <div className="field-row">
          <label className="field">
            <span>Mails je Portion</span>
            <input type="number" min={0} value={form.batch_size} onChange={set('batch_size')} />
          </label>
          <label className="field">
            <span>Pause (Minuten)</span>
            <input type="number" min={0} value={form.batch_pause} onChange={set('batch_pause')} />
          </label>
        </div>
      </details>
      <button className="btn btn-block" type="submit" disabled={busy}>
        Speichern
      </button>
      {settings.configured && (
        <>
          <div className="button-row">
            <button
              type="button"
              className="btn btn-secondary mail-check"
              disabled={busy}
              onClick={() =>
                void run(
                  () => api.checkMail(),
                  (r) => {
                    onSaved(r.settings);
                    setMessage({ ok: r.ok, text: r.message + (r.ok ? ` ${maxSizeText(r.size)}` : '') });
                  },
                )
              }
            >
              Verbindung prüfen
            </button>
          </div>
          <EmailField label="Testmail an (leer: deine eigene Adresse)" value={testTo} onChange={setTestTo} />
          <button
            type="button"
            className="btn btn-secondary mail-test"
            disabled={busy}
            onClick={() =>
              void run(
                () => api.testMail(normalizeEmail(testTo) || undefined),
                (r) => setMessage({ ok: r.sent, text: r.message }),
              )
            }
          >
            Testmail senden
          </button>
        </>
      )}
      {message && (
        <p className={message.ok ? 'small mail-result' : 'form-error mail-result'} role={message.ok ? 'status' : 'alert'}>
          {message.text}
        </p>
      )}
      <p className="small muted">
        {settings.server_max_size ? maxSizeText(settings.server_max_size) : 'Der Mailserver hat noch keine Höchstgröße für Mails gemeldet.'}
        {settings.pending > 0 && ` ${settings.pending} Mails warten auf den Versand.`}
      </p>
    </form>
  );
}

function maxSizeText(size: number | null): string {
  return size
    ? `Der Mailserver nimmt Mails bis ${formatBytes(size)} an (Bilder eingerechnet).`
    : 'Der Mailserver meldet keine Höchstgröße.';
}

/** Volle Stunden 00:00 bis 23:00 für die Auswahl einer Uhrzeit. */
function HourOptions() {
  return (
    <>
      {Array.from({ length: 24 }, (_, h) => (
        <option key={h} value={h}>
          {String(h).padStart(2, '0')}:00 Uhr
        </option>
      ))}
    </>
  );
}

/**
 * Zum Abschnitt eines Bereichs (Kalender, Galerie, News, Google-Zugang), z. B. aus „noch nicht eingerichtet“: steht er
 * auf derselben Seite (Einrichtungsassistent), dorthin scrollen, sonst seine Seite der Einstellungen öffnen.
 */
function scrollToSetup(setup: string) {
  const el = document.getElementById(`einstellungen-${setup}`);
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  else navigate(`einstellungen/${setup}`);
}

/**
 * Schalter je Bereich mit einem Satz, wofür er da ist. Gemeinsam für die Ersteinrichtung und die Einstellungen.
 * Ein Bereich, der einen anderen braucht, lässt sich erst einschalten, wenn der andere an ist.
 */
export function FeatureSwitches({
  list,
  value,
  onChange,
  disabled,
  onSetup,
}: {
  list: FeatureInfo[];
  value: Record<Feature, boolean>;
  onChange: (key: Feature, on: boolean) => void;
  disabled?: boolean;
  /** Link „einrichten“ bei Bereichen, die an, aber noch nicht eingerichtet sind */
  onSetup?: (setup: string) => void;
}) {
  const label = (key: Feature) => list.find((f) => f.key === key)?.label ?? key;
  return (
    <div className="feature-list">
      {list.map((f) => {
        const missing = f.requires.filter((r) => !value[r]);
        const blocked = !value[f.key] && missing.length > 0;
        return (
          <label key={f.key} className="toggle feature-toggle">
            <input
              type="checkbox"
              checked={value[f.key]}
              disabled={disabled || blocked}
              onChange={(e) => onChange(f.key, e.target.checked)}
            />
            <span>
              <strong>{f.label}</strong>
              <span className="small muted feature-desc">{f.description}</span>
              {blocked && <span className="small feature-desc">Braucht {missing.map(label).join(' und ')}.</span>}
              {onSetup && value[f.key] && f.configured === false && f.setup && (
                <span className="small feature-desc">
                  Noch nicht eingerichtet.{' '}
                  <button type="button" className="btn btn-link btn-inline" onClick={() => onSetup(f.setup!)}>
                    Jetzt einrichten
                  </button>
                </span>
              )}
            </span>
          </label>
        );
      })}
    </div>
  );
}

/**
 * Einstellungen → Bereiche: Jeder Schalter speichert sofort; beim Ausschalten wird nachgefragt.
 * Jeder geladene Stand geht auch an die App (Tabs), z. B. wenn ein Bereich gerade eingerichtet wurde.
 */
function Bereiche({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(
    () =>
      api.features().then((list) => {
        applyFeatures(list);
        return list;
      }),
    [],
    'features',
  );
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const value = Object.fromEntries(list.map((f) => [f.key, f.enabled])) as Record<Feature, boolean>;

  async function toggle(key: Feature, on: boolean) {
    const name = list.find((f) => f.key === key)?.label ?? key;
    if (
      !on &&
      !window.confirm(`„${name}“ wird für alle ausgeblendet. Die Daten bleiben erhalten und sind beim Wiedereinschalten wieder da.`)
    )
      return;
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.setFeatures({ [key]: on });
      setData(next);
      applyFeatures(next);
      await refreshRoles();
      onToast(on ? `${name} eingeschaltet` : `${name} ausgeschaltet`);
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-bereiche">
      <h3>Bereiche</h3>
      <p className="small muted">
        Welche Bereiche die App für alle hat. Ausgeschaltete Bereiche verschwinden aus der App; ihre Daten bleiben erhalten.
      </p>
      <FeatureSwitches list={list} value={value} onChange={toggle} disabled={busy} onSetup={scrollToSetup} />
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
    </section>
  );
}

export type SectionProps = { settings: AdminSettings; onSaved: (s: AdminSettings) => void; onToast: (msg: string) => void };

function Section({
  id,
  title,
  children,
  onSubmit,
  busy,
  error,
}: {
  id?: string;
  title: string;
  children: ReactNode;
  onSubmit: (e: FormEvent) => void;
  busy: boolean;
  error: string | null;
}) {
  return (
    <form className="form card settings-section" id={id ? `einstellungen-${id}` : undefined} onSubmit={onSubmit}>
      <h3>{title}</h3>
      {children}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Speichert …' : 'Speichern'}
      </button>
    </form>
  );
}

/** Speichern mit Fehleranzeige; gemeinsam für alle Abschnitte. */
function useSave(onSaved: (s: AdminSettings) => void, onToast: (msg: string) => void) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function save(input: Parameters<typeof api.updateSettings>[0], done?: () => void) {
    setBusy(true);
    setError(null);
    try {
      onSaved(await api.updateSettings(input));
      onToast('Gespeichert');
      done?.();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return { busy, error, setError, save };
}

/** Kehrt der Admin von Google zurück (?google=1)? Liest den Parameter einmal und entfernt ihn aus der Adresse. */
function takeGoogleReturn(): boolean {
  const params = new URLSearchParams(window.location.search);
  if (!params.has('google')) return false;
  params.delete('google');
  const search = params.toString();
  // Den Stand des Verlaufseintrags behalten (router.ts: Kennung für die Scrollposition).
  window.history.replaceState(window.history.state, '', window.location.pathname + (search ? `?${search}` : '') + window.location.hash);
  return true;
}

/**
 * Google-Zugang für Galerie und Kalender: „Mit Google verbinden“ mit dem eigenen OAuth-Client der Installation,
 * darunter als Alternative das Dienstkonto. Nach „Speichern und mit Google verbinden“ geht es zu Google; Google leitet
 * an die Weiterleitungs-URI zurück, der Server danach hierher (?google=1) und das Ergebnis steht in last_result.
 */
export function GoogleAccess({ settings, onSaved, onToast }: SectionProps) {
  const g = settings.google;
  const [returned] = useState(takeGoogleReturn);
  const [clientId, setClientId] = useState(g.client_id);
  const [secret, setSecret] = useState('');
  const [key, setKey] = useState('');
  const [replaceKey, setReplaceKey] = useState(false);
  const [connecting, setConnecting] = useState(false);
  const [connectError, setConnectError] = useState<string | null>(null);
  const { busy, error, save, setError } = useSave(onSaved, onToast);
  // Ergebnis der Rückkehr von Google nur direkt danach zeigen (höchstens 15 Minuten alt).
  const result = returned && g.last_result && Date.now() / 1000 - g.last_result.at < 900 ? g.last_result : null;
  const showKeyField = !g.service_account_email || replaceKey;

  useEffect(() => {
    if (returned) document.getElementById('einstellungen-google')?.scrollIntoView({ block: 'start' });
  }, [returned]);

  async function connect(e: FormEvent) {
    e.preventDefault();
    if (!clientId.trim()) return setConnectError('Bitte die Client-ID eintragen.');
    setConnecting(true);
    setConnectError(null);
    try {
      const { url } = await api.googleConnect(clientId.trim(), secret.trim());
      window.location.assign(url);
    } catch (err) {
      setConnectError((err as Error).message);
      setConnecting(false);
    }
  }

  async function disconnect() {
    if (!window.confirm('Verbindung zu Google trennen? Galerie und Kalender lesen danach nur noch über ein Dienstkonto, falls hinterlegt.'))
      return;
    setConnectError(null);
    try {
      onSaved(await api.googleDisconnect());
      onToast('Verbindung getrennt');
    } catch (err) {
      setConnectError((err as Error).message);
    }
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(g.redirect_uri);
      onToast('Kopiert');
    } catch {
      onToast('Bitte die Adresse markieren und kopieren.');
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-google">
      <h3>Google-Zugang</h3>
      <p className="muted small">
        Über diesen Zugang liest die App den Galerie-Ordner aus Google Drive und private Google Kalender (nur lesen). Empfohlen:{' '}
        <strong>Mit Google verbinden</strong> über einen eigenen OAuth-Client. Wie du ihn anlegst, steht in der Hilfe unter „Google
        einrichten“.
      </p>
      {result && (
        <p className={result.ok ? 'small' : 'form-error small'} role={result.ok ? 'status' : 'alert'}>
          {result.ok ? '✓ ' : ''}
          {result.message}
        </p>
      )}
      <label className="field">
        <span>Weiterleitungs-URI (im OAuth-Client bei Google eintragen)</span>
        <input type="text" readOnly value={g.redirect_uri} onFocus={(e) => e.currentTarget.select()} />
      </label>
      <div className="button-row">
        <button type="button" className="btn btn-secondary" onClick={copy}>
          Adresse kopieren
        </button>
      </div>
      {g.connected ? (
        <div className="small">
          <p>
            ✓ Verbunden mit Google
            {g.email ? (
              <>
                {' '}
                als <code>{g.email}</code>
              </>
            ) : (
              ''
            )}
            .{' '}
            {g.calendar_access
              ? 'Es darf auch den Kalender lesen.'
              : 'Den Kalender darf es noch nicht lesen. Für einen privaten Kalender: Verbindung trennen und neu verbinden, dabei beide Häkchen setzen.'}
          </p>
          <div className="button-row">
            <button type="button" className="btn btn-link" onClick={disconnect}>
              Verbindung trennen
            </button>
          </div>
        </div>
      ) : (
        <form className="form" onSubmit={connect}>
          <label className="field">
            <span>Client-ID</span>
            <input
              type="text"
              autoComplete="off"
              spellCheck={false}
              placeholder="….apps.googleusercontent.com"
              value={clientId}
              onChange={(e) => setClientId(e.target.value)}
            />
          </label>
          <label className="field">
            <span>Clientschlüssel (Client-Secret)</span>
            <input
              type="password"
              autoComplete="off"
              placeholder={g.client_secret_set && clientId.trim() === g.client_id ? 'gespeichert – leer lassen, um ihn zu behalten' : ''}
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
            />
          </label>
          <button className="btn btn-block" type="submit" disabled={connecting}>
            {connecting ? 'Weiter zu Google …' : 'Speichern und mit Google verbinden'}
          </button>
          <p className="muted small">
            Danach meldest du dich bei Google mit dem Konto an, das den Galerie-Ordner und den Kalender sehen kann. Zeigt Google „Google hat
            diese App nicht überprüft“, wähle „Erweitert“ und dann „Weiter zu …“ und setze beide Häkchen (Google Drive und Google Kalender).
          </p>
        </form>
      )}
      {connectError && (
        <p className="form-error" role="alert">
          {connectError}
        </p>
      )}
      <details open={!!g.service_account_email && !g.connected}>
        <summary>Alternative: Dienstkonto mit JSON-Schlüssel</summary>
        <p className="muted small">
          Nur nötig, wenn du nicht „Mit Google verbinden“ nutzt. Den Galerie-Ordner (Betrachter) und ggf. den Kalender („Alle Termindetails
          sehen“) für die Adresse des Dienstkontos freigeben.
        </p>
        {g.service_account_email && (
          <div className="small">
            Dienstkonto: <code>{g.service_account_email}</code>
            {g.connected ? ' (wird nicht genutzt, solange die Verbindung mit Google besteht)' : ''}
            <div className="button-row">
              <button type="button" className="btn btn-link" onClick={() => setReplaceKey(!replaceKey)}>
                {replaceKey ? 'Schlüssel behalten' : 'Schlüssel ersetzen'}
              </button>
              <button
                type="button"
                className="btn btn-link"
                onClick={() => window.confirm('Schlüssel des Dienstkontos entfernen?') && save({ drive_service_account: '' })}
              >
                Schlüssel entfernen
              </button>
            </div>
          </div>
        )}
        {showKeyField && (
          <>
            <label className="field">
              <span>{g.service_account_email ? 'Neuer Schlüssel des Dienstkontos (JSON)' : 'Schlüssel des Dienstkontos (JSON)'}</span>
              <textarea
                rows={4}
                spellCheck={false}
                value={key}
                onChange={(e) => setKey(e.target.value)}
                placeholder='{"type": "service_account", …}'
              />
            </label>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={busy}
              onClick={() =>
                key.trim()
                  ? save({ drive_service_account: key.trim() }, () => {
                      setKey('');
                      setReplaceKey(false);
                    })
                  : setError('Bitte den Inhalt der JSON-Datei einfügen.')
              }
            >
              {busy ? 'Speichert …' : 'Schlüssel speichern'}
            </button>
          </>
        )}
        {error && (
          <p className="form-error" role="alert">
            {error}
          </p>
        )}
      </details>
    </section>
  );
}

/** Kurzbeschreibung des Google-Zugangs für Kalender und Galerie ('' ohne Zugang). */
function googleAccessLabel(g: AdminSettings['google']): string {
  if (g.connected) return g.email ? `die Verbindung mit Google (${g.email})` : 'die Verbindung mit Google';
  if (g.service_account_email) return `das Dienstkonto ${g.service_account_email}`;
  return '';
}

/** Verweis auf den Abschnitt Google-Zugang (in den Einstellungen und im Assistenten darüber). */
function GoogleLink() {
  return (
    <button type="button" className="btn btn-link btn-inline" onClick={() => scrollToSetup('google')}>
      Google-Zugang
    </button>
  );
}

/** Zwischenspeicher des Kalenders in Minuten (Grenzen wie SettingsService::update auf dem Server). */
const CACHE_MINUTES = { min: 1, max: 1440 };

export function Calendar({ settings, onSaved, onToast }: SectionProps) {
  const c = settings.calendar;
  const [url, setUrl] = useState('');
  const [ids, setIds] = useState(c.calendar_ids);
  const [minutes, setMinutes] = useState(String(c.cache_minutes));
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const access = googleAccessLabel(settings.google);
  const status = {
    ics: '✓ Eingerichtet über die geheime iCal-Adresse.',
    google: '✓ Eingerichtet über die Google-API (Kalender-IDs und API-Schlüssel).',
    google_connection: `✓ Eingerichtet über die Kalender-IDs und ${access || 'den Google-Zugang'}.`,
    none: 'Noch kein Kalender eingerichtet.',
  }[c.source];

  async function test() {
    setTesting(true);
    setResult(null);
    setError(null);
    try {
      const r = await api.testCalendar();
      setResult(
        r.count === 0
          ? 'Verbindung klappt, aber es gibt keine kommenden Termine.'
          : `${r.count} Termine gefunden, z. B. „${r.sample[0].title}“.`,
      );
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setTesting(false);
    }
  }

  return (
    <Section
      id="kalender"
      title="Kalender für Mitglieder"
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        const idsChanged = ids.trim() !== c.calendar_ids;
        // Die Grenzen prüfen der Browser (min/max am Feld) und genau noch einmal der Server.
        const cacheMinutes = Number(minutes);
        // Nur die Dauer geändert: auch ohne neue Adresse oder IDs speichern (Issue #177).
        const cache = cacheMinutes !== c.cache_minutes ? { calendar_cache_minutes: cacheMinutes } : {};
        if (!url.trim() && !idsChanged) {
          if ('calendar_cache_minutes' in cache) return save(cache);
          if (c.source !== 'none') return onToast('Nichts geändert – der Kalender ist schon eingerichtet.');
          return setError('Bitte die iCal-Adresse oder Kalender-IDs eintragen.');
        }
        save({ ...(url.trim() ? { calendar_ics_url: url.trim() } : {}), calendar_ids: ids.trim(), ...cache }, () => setUrl(''));
      }}
    >
      <p className="muted small">
        Zwei Wege: Für einen privaten Google Kalender die <strong>Kalender-ID</strong> eintragen; gelesen wird über den <GoogleLink />{' '}
        (Verbindung mit Erlaubnis für den Kalender oder Dienstkonto, für das der Kalender freigegeben ist). Oder ohne Google-Zugang die{' '}
        <strong>geheime Adresse im iCal-Format</strong> einfügen. Sind Kalender-IDs eingetragen und darf der Google-Zugang den Kalender
        lesen, gelten sie vor der iCal-Adresse.
      </p>
      <p className="small">{status}</p>
      {c.connection_needs_calendar_access && (
        <p className="small">
          Die Kalender-IDs <code>{c.calendar_ids}</code> sind eingetragen, aber die Verbindung mit Google darf den Kalender nicht lesen
          (beim Verbinden nicht erlaubt). Unter <GoogleLink /> die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den
          Kalender setzen{c.source === 'ics' ? '; bis dahin gilt die iCal-Adresse' : ''}.
        </p>
      )}
      <label className="field">
        <span>Google-Kalender-IDs</span>
        <input
          type="text"
          autoComplete="off"
          spellCheck={false}
          placeholder="abc123@group.calendar.google.com"
          value={ids}
          onChange={(e) => setIds(e.target.value)}
        />
        <span className="small muted">
          In Google Kalender: Einstellungen des Kalenders → Kalender integrieren → Kalender-ID. Mehrere durch Komma trennen.
          {!access && ' Dafür zuerst den Google-Zugang einrichten.'}
        </span>
      </label>
      <label className="field">
        <span>{c.ics_url_set ? 'Neue iCal-Adresse (ersetzt die bisherige)' : 'Geheime iCal-Adresse'}</span>
        <input
          type="url"
          inputMode="url"
          autoComplete="off"
          placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
        />
        <span className="small muted">
          In Google Kalender: Einstellungen des Kalenders → Kalender integrieren → Geheime Adresse im iCal-Format. Sie wird nicht wieder
          angezeigt.
        </span>
      </label>
      <label className="field">
        <span>Aktualisieren alle … Minuten</span>
        <input
          type="number"
          inputMode="numeric"
          min={CACHE_MINUTES.min}
          max={CACHE_MINUTES.max}
          step={1}
          required
          value={minutes}
          onChange={(e) => setMinutes(e.target.value)}
        />
        <span className="small muted">
          So lange zeigt die App die zuletzt geladenen Termine, bevor sie bei Google neu nachfragt ({CACHE_MINUTES.min} bis{' '}
          {CACHE_MINUTES.max} Minuten). Kürzere Zeiten zeigen Änderungen schneller, bedeuten aber mehr Abrufe bei Google, und das Öffnen der
          Termine dauert dann öfter länger. Zusätzlich erneuert die App den Kalender stündlich im Hintergrund. „Kalender testen“ und
          Speichern laden sofort neu.
        </span>
      </label>
      {c.source !== 'none' && (
        <div className="button-row">
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? 'Prüft …' : 'Kalender testen'}
          </button>
          <button
            type="button"
            className="btn btn-link"
            onClick={() =>
              window.confirm('Kalender wirklich entfernen?') &&
              save({ calendar_ics_url: '', calendar_ids: '', google_api_key: '' }, () => setIds(''))
            }
          >
            Entfernen
          </button>
        </div>
      )}
      {result && (
        <p className="small" role="status">
          {result}
        </p>
      )}
    </Section>
  );
}

export function Gallery({ settings, onSaved, onToast }: SectionProps) {
  const g = settings.gallery;
  const [root, setRoot] = useState(g.root);
  const [progress, setProgress] = useState<string | null>(null);
  const [syncing, setSyncing] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const access = googleAccessLabel(settings.google);

  async function sync() {
    setSyncing(true);
    setError(null);
    try {
      // Erst abgleichen, dann so lange aufbereiten, bis nichts mehr aussteht.
      let r = await api.syncGallery(false);
      setProgress(`${r.albums} Alben, ${r.photos} Bilder und Videos gefunden – ${r.pending} werden noch aufbereitet …`);
      for (let round = 0; r.pending > 0 && r.processed > 0 && round < 50; round++) {
        r = await api.syncGallery(true);
        setProgress(`${r.albums} Alben, ${r.photos} Bilder und Videos – noch ${r.pending} …`);
      }
      const parts = [`${r.albums} Alben, ${r.photos} Bilder und Videos.`];
      if (r.pending > 0) parts.push(`${r.pending} Bilder werden stündlich automatisch weiter aufbereitet.`);
      if (r.failed > 0) parts.push(`${r.failed} Bilder ließen sich nicht laden (Liste unten).`);
      setProgress((r.pending > 0 || r.failed > 0 ? 'Abgeglichen: ' : 'Fertig: ') + parts.join(' '));
      onSaved(await api.settings()); // Stand des Abgleichs (z. B. alte Fehlermeldung) aktualisieren
    } catch (err) {
      setError((err as Error).message);
      setProgress(null);
    } finally {
      setSyncing(false);
    }
  }

  return (
    <Section
      id="galerie"
      title={`${sec.gallery} aus Google Drive`}
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        save({ gallery_root: root.trim() });
      }}
    >
      <p className="muted small">
        Hier den Link zum Galerie-Ordner in Google Drive eintragen. Darin je Jahr ein Ordner, darin je Album ein Ordner; Bilder im
        Unterordner <em>intern</em> sehen nur Mitglieder.
      </p>
      <p className="small">
        {access ? (
          <>
            Gelesen wird über {access} (<GoogleLink />
            ).
          </>
        ) : (
          <>
            Noch kein Zugang zu Google: zuerst unter <GoogleLink /> mit Google verbinden oder ein Dienstkonto hinterlegen.
          </>
        )}
      </p>
      <label className="field">
        <span>Link zum Galerie-Ordner</span>
        <input
          type="text"
          autoComplete="off"
          placeholder="https://drive.google.com/drive/folders/…"
          value={root}
          onChange={(e) => setRoot(e.target.value)}
        />
      </label>
      {g.status.error && <p className="form-error small">Letzter Abgleich: {g.status.error}</p>}
      {g.configured && (
        <button type="button" className="btn btn-secondary" onClick={sync} disabled={syncing}>
          {syncing ? 'Gleicht ab …' : 'Jetzt abgleichen'}
        </button>
      )}
      {progress && (
        <p className="small" role="status">
          {progress}
        </p>
      )}
      {(g.status.failed ?? 0) > 0 && (
        <FailedPhotos
          key={g.status.failed}
          count={g.status.failed ?? 0}
          disabled={syncing}
          onRetry={async () => {
            await api.retryGallery();
            await sync();
          }}
        />
      )}
    </Section>
  );
}

/**
 * Bilder, die sich nicht laden ließen: Dateiname, Album, Fehlermeldung und Link zur Datei in Google Drive. Die Liste
 * lädt erst beim Aufklappen. „Erneut versuchen“ reiht alle wieder ein, auch nach mehreren gescheiterten Versuchen.
 */
function FailedPhotos({ count, disabled, onRetry }: { count: number; disabled: boolean; onRetry: () => Promise<void> }) {
  const [open, setOpen] = useState(false);
  const [list, setList] = useState<{ photos: FailedPhoto[]; total: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [retrying, setRetrying] = useState(false);

  useEffect(() => {
    if (!open || list) return;
    api.galleryFailed().then(setList, (err: Error) => setError(err.message));
  }, [open, list]);

  return (
    <details className="gallery-failed" onToggle={(e) => setOpen(e.currentTarget.open)}>
      <summary>{count === 1 ? '1 Bild ließ sich nicht laden' : `${count} Bilder ließen sich nicht laden`} – anzeigen</summary>
      <p className="small muted">
        Kann der Server ein Format nicht lesen (z. B. RAW, TIFF, HEIC), nimmt die App das in RAW-Dateien eingebettete JPEG oder das
        Vorschaubild von Google. Hier stehen Bilder, bei denen auch das nicht ging, z. B. beschädigte Dateien. Abhilfe: das Bild als JPG in
        denselben Ordner in Google Drive legen (das alte entfernen); der nächste Abgleich nimmt es neu auf.
      </p>
      <p className="small muted">
        Videos zeigt die Galerie als MP4 oder WebM. Andere Formate (z. B. MOV, AVI) stehen hier als „nicht unterstützt“. Abhilfe: das Video
        als MP4 speichern und in denselben Ordner legen.
      </p>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      {open && !list && !error && <Loading />}
      {list && (
        <>
          <ul className="gallery-failed-list">
            {list.photos.map((p) => (
              <li key={p.id}>
                <strong>{p.name}</strong>
                <span className="small muted">
                  {' '}
                  {p.album || 'ohne Album'}
                  {p.intern && ' (intern)'}
                  {!p.unsupported && ` · ${p.attempts === 1 ? '1 Versuch' : `${p.attempts} Versuche`}`}
                </span>
                <br />
                <span className="small">{p.error || 'ohne Fehlermeldung'}</span>{' '}
                <a className="small" href={p.drive_url} target="_blank" rel="noopener noreferrer">
                  In Google Drive öffnen
                </a>
              </li>
            ))}
          </ul>
          {list.total > list.photos.length && (
            <p className="small muted">
              Gezeigt werden die ersten {list.photos.length} von {list.total}.
            </p>
          )}
        </>
      )}
      <button
        type="button"
        className="btn btn-secondary"
        disabled={disabled || retrying}
        onClick={() => {
          setRetrying(true);
          setError(null);
          onRetry()
            .catch((err: Error) => setError(err.message))
            .finally(() => setRetrying(false));
        }}
      >
        {retrying ? 'Wird erneut versucht …' : 'Alle erneut versuchen'}
      </button>
    </details>
  );
}

export function News({ settings, onSaved, onToast }: SectionProps) {
  const n = settings.news!;
  const [source, setSource] = useState<NewsSourceType>(n.source);
  const [url, setUrl] = useState(n.url);
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const unsaved = source !== n.source || (source !== 'none' && url.trim() !== n.url);

  async function test() {
    setTesting(true);
    setResult(null);
    setError(null);
    try {
      const r = await api.testNews();
      setResult(`${r.count} Beiträge gefunden.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setTesting(false);
    }
  }

  return (
    <Section
      id="news"
      title={newsTitle()}
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        if (source !== 'none' && !url.trim()) return setError('Bitte die Adresse angeben.');
        save({ news_source: source, news_url: source === 'none' ? '' : url.trim() });
      }}
    >
      <p className="muted small">Die Neuigkeiten kommen von einer vorhandenen Webseite der Kompanie.</p>
      <label className="field">
        <span>Quelle</span>
        <select value={source} onChange={(e) => setSource(e.target.value as NewsSourceType)}>
          <option value="none">Keine Neuigkeiten</option>
          <option value="wordpress">WordPress-Webseite</option>
          <option value="rss">RSS- oder Atom-Feed</option>
        </select>
      </label>
      {source !== 'none' && (
        <label className="field">
          <span>{source === 'wordpress' ? 'Adresse der Webseite' : 'Adresse des Feeds'}</span>
          <input
            type="url"
            inputMode="url"
            required
            placeholder={source === 'wordpress' ? 'https://www.beispiel.de' : 'https://www.beispiel.de/feed'}
            value={url}
            onChange={(e) => setUrl(e.target.value)}
          />
        </label>
      )}
      {n.source !== 'none' &&
        (unsaved ? (
          <p className="muted small">Erst speichern, dann testen.</p>
        ) : (
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? 'Prüft …' : 'Neuigkeiten testen'}
          </button>
        ))}
      {result && (
        <p className="small" role="status">
          {result}
        </p>
      )}
    </Section>
  );
}
