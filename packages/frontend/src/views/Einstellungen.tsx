import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import brand from '../generated/brand';
import {
  api,
  can,
  config,
  applyBirthdayUpcomingDays,
  applyFeatures,
  applyRegistration,
  registrationRoles,
  newsTitle,
  refreshRoles,
  type AdminSettings,
  type CalendarSourceChoice,
  type FailedPhoto,
  type GallerySourceType,
  type Feature,
  type FeatureInfo,
  type MailSettings,
  type NewsSourceType,
  type PushAdmin,
} from '../api';
import { Caps, EmailField, ErrorBox, Loading, useAsync } from '../ui';
import { normalizeEmail } from '../email';
import Neuerungen from './Neuerungen';
import NavigationEinstellen from './NavigationEinstellen';
import Rollen from './Rollen';
import Wartung from './Wartung';
import Nutzung from './Nutzung';
import GalleryAlbums from './GalerieAlben';
import { MaintenanceCard } from '../maintenance';
import { navigate } from '../router';
import { formatBytes, formatUntil } from '../format';
import { menuSources, prefetchOnTap, sources, usePrefetchMenu } from '../viewData';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';

const sec = brand.labels.sections;

/** Seiten der Einstellungen: Adresse (#/einstellungen/<id>), Titel und ein Satz für die Übersicht. */
/** Gruppen der Übersicht (Issue #348): Kacheln je Gruppe statt einer langen Liste. */
const GROUPS = ['App', 'Mitglieder und Zugang', 'Benachrichtigungen', 'Verbindungen', 'Termine und Kalender', 'Betrieb'] as const;

const PAGES = [
  { id: 'bereiche', group: 'App', title: 'Bereiche' },
  { id: 'navigation', group: 'App', title: 'Navigation' },
  { id: 'rechtliches', group: 'App', title: 'Impressum und Datenschutz' },
  { id: 'rollen', group: 'Mitglieder und Zugang', title: 'Rollen' },
  { id: 'benachrichtigungen', group: 'Benachrichtigungen', title: 'Benachrichtigungen' },
  { id: 'email', group: 'Verbindungen', title: 'E-Mail' },
  { id: 'google', group: 'Verbindungen', title: 'Google-Zugang' },
  { id: 'galerie', group: 'Verbindungen', title: sec.gallery },
  { id: 'news', group: 'Verbindungen', title: newsTitle() },
  { id: 'kalender', group: 'Termine und Kalender', title: 'Kalender' },
  { id: 'zustand', group: 'Betrieb', title: 'Zustand und Updates' },
  { id: 'datensicherung', group: 'Betrieb', title: 'Datensicherung' },
  { id: 'wartung', group: 'Betrieb', title: 'Wartungsmodus' },
  { id: 'nutzung', group: 'Betrieb', title: 'Nutzung' },
  { id: 'neuerungen', group: 'Betrieb', title: 'Was ist neu' },
] as const;

/** Frühere eigene Seiten, jetzt Abschnitt einer anderen (Links aus Handbuch, Hinweisen und Lesezeichen gehen weiter). */
const MOVED: Record<string, string> = { zugang: 'rollen', geburtstage: 'kalender' };

/**
 * Einstellungen für Admins (beide Varianten): eine Übersicht, jede Gruppe auf einer eigenen Seite. Geheime Werte zeigt
 * der Server nie an, nur ob sie gesetzt sind. Bei WordPress kommen die Neuigkeiten aus den Beiträgen der Website; dort
 * gibt es keine Seite dafür.
 */
export default function Einstellungen({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  // Rückkehr von Google landet auf #/einstellungen?google=1: gleich zur Seite Google-Zugang (sie liest ?google=1).
  const [fromGoogle] = useState(() => !path[0] && new URLSearchParams(window.location.search).has('google'));
  const moved = path[0] ? MOVED[path[0]] : undefined;
  useEffect(() => {
    if (fromGoogle) window.location.replace('#/einstellungen/google');
    else if (moved) window.location.replace(`#/einstellungen/${moved}`);
  }, [fromGoogle, moved]);
  const page = PAGES.find((p) => p.id === path[0]);
  if ((fromGoogle || moved) && !page) return <Loading shape="form" />;
  if (!page) return <Overview />;
  return (
    <div className="settings">
      <button type="button" className="btn btn-link back" onClick={() => navigate('einstellungen')}>
        ‹ Einstellungen
      </button>
      {page.id === 'bereiche' && <Bereiche onToast={onToast} />}
      {page.id === 'navigation' && <NavigationEinstellen onToast={onToast} />}
      {page.id === 'rollen' && (
        <>
          <Rollen onToast={onToast} />
          <RegistrationSection onToast={onToast} />
        </>
      )}
      {page.id === 'benachrichtigungen' && <PushSettings onToast={onToast} />}
      {page.id === 'email' && <MailSection onToast={onToast} />}
      {page.id === 'nutzung' && <Nutzung onToast={onToast} />}
      {page.id === 'zustand' && <Wartung part="zustand" onToast={onToast} />}
      {page.id === 'wartung' && <MaintenanceCard onToast={onToast} />}
      {page.id === 'datensicherung' && <Wartung part="datensicherung" onToast={onToast} />}
      {page.id === 'neuerungen' && <Neuerungen />}
      {(page.id === 'google' || page.id === 'kalender' || page.id === 'galerie' || page.id === 'news' || page.id === 'rechtliches') && (
        <Verbindung page={page.id} onToast={onToast} />
      )}
      {page.id === 'kalender' && config?.features.birthdays && <BirthdaySettingsSection onToast={onToast} />}
    </div>
  );
}

/**
 * Übersicht (Issue #348): Kacheln, nach Gruppen geordnet; Neuigkeiten nur, wenn der Server die Quelle einstellen lässt
 * (ältere WordPress-Server nicht), Nutzung nur mit dem Recht.
 */
function Overview() {
  // Erst nach dem Laden zeigen (beim nächsten Öffnen sofort aus dem Speicher): Sonst rückt „Neuigkeiten“ nachträglich
  // ein und schiebt die Kacheln darunter weg.
  const { data, loading } = useAsync(sources.settings.load, sources.settings.deps, sources.settings.key);
  const pages = PAGES.filter((p) => (p.id !== 'news' || !!data?.news) && (p.id !== 'nutzung' || can('view_activity')));
  usePrefetchMenu(
    'einstellungen',
    pages.map((p) => p.id),
  );
  if (loading && !data) return <Loading />;
  return (
    <div className="settings-overview">
      {GROUPS.map((g) => {
        const list = pages.filter((p) => p.group === g);
        if (list.length === 0) return null;
        return (
          <section key={g} className="settings-group" aria-label={g}>
            <h3 className="settings-group-title">{g}</h3>
            <ul className="settings-tiles">
              {list.map((p) => (
                <li key={p.id}>
                  <button
                    type="button"
                    className="card settings-link settings-tile"
                    onClick={() => navigate(`einstellungen/${p.id}`)}
                    {...prefetchOnTap(...menuSources('einstellungen', p.id))}
                  >
                    <span className="row-title">{p.title}</span>
                    <span className="settings-chevron" aria-hidden="true">
                      ›
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          </section>
        );
      })}
    </div>
  );
}

/** Google-Zugang, Kalender, Galerie, Neuigkeiten oder Rechtliches: alle aus den gemeinsamen Einstellungen (/admin/settings). */
function Verbindung({
  page,
  onToast,
}: {
  page: 'google' | 'kalender' | 'galerie' | 'news' | 'rechtliches';
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, reload, setData, revision } = useAsync(sources.settings.load, sources.settings.deps, sources.settings.key);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  // key: Die Formulare übernehmen ihre Felder einmal; weicht der frische Stand vom gespeicherten ab, neu aufbauen.
  const props = { settings: s, onSaved: setData, onToast };
  if (page === 'google') return <GoogleAccess key={revision} {...props} />;
  if (page === 'kalender') return <Calendar key={revision} {...props} />;
  if (page === 'galerie')
    return (
      <>
        <Gallery key={revision} {...props} />
        {s.gallery.source !== undefined && <GalleryAlbums onToast={onToast} />}
      </>
    );
  if (page === 'rechtliches') return <Legal key={revision} {...props} />;
  return s.news ? <News key={revision} {...props} /> : null;
}

const devicesText = (n: number): string => (n === 0 ? 'an kein Gerät' : n === 1 ? 'an 1 Gerät' : `an ${n} Geräte`);

/** Zuletzt verschickt je Art, dazu zuletzt vom Push-Dienst angenommen bzw. abgelehnt; nichts, solange noch nichts verschickt wurde. */
function PushLog({ log }: { log: PushAdmin['log'] }) {
  if (log.types.length === 0 && !log.accepted && !log.rejected) return null;
  return (
    <div className="push-log">
      <h4>Zuletzt verschickt</h4>
      {log.types.length > 0 && (
        <ul className="small muted">
          {log.types.map((t) => (
            <li key={t.type}>
              {t.label} – {formatUntil(t.at)}, {devicesText(t.devices)}
            </li>
          ))}
        </ul>
      )}
      {log.accepted && (
        <p className="small muted">
          Zuletzt vom Push-Dienst angenommen: {formatUntil(log.accepted.at)} (
          {log.accepted.count === 1 ? '1 Nachricht' : `${log.accepted.count} Nachrichten`}).
        </p>
      )}
      {log.rejected && (
        <p className="small muted">
          Zuletzt vom Push-Dienst abgelehnt: {formatUntil(log.rejected.at)} (
          {log.rejected.count === 1 ? '1 Nachricht' : `${log.rejected.count} Nachrichten`}, {log.rejected.reason}).
        </p>
      )}
    </div>
  );
}

/**
 * Benachrichtigungen: wie viele Geräte angemeldet sind und wann die Erinnerung an Termine am nächsten Tag kommt. Ob und ab
 * wann am Geburtstag benachrichtigt wird, steht bei den Geburtstagen (BirthdayNotify, Issue #348).
 */
function PushSettings({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.pushAdmin(), [], 'admin/push');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="form" />;
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
      <PushLog log={p.log} />
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

/**
 * Zugang beantragen (Issue #314): ein- und ausschalten, Rolle, die beim Freigeben vorgeschlagen ist, Link zum Weitergeben.
 * Ohne Mailversand geht es auch; dann bestätigt niemand seine Adresse, und das Ergebnis sieht die Person beim Anmelden.
 */
function RegistrationSection({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.registrationSettings(), [], 'admin/registration');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  const roles = registrationRoles();

  async function save(change: { enabled?: boolean; default_role?: string }) {
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.setRegistrationSettings(change);
      setData(next);
      applyRegistration(next.enabled);
      onToast('Gespeichert');
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-zugang">
      <h3>Zugang beantragen</h3>
      <label className="toggle">
        <input type="checkbox" checked={s.enabled} disabled={busy} onChange={(e) => save({ enabled: e.target.checked })} />
        <span>Personen können selbst einen Zugang beantragen</span>
      </label>
      {roles.length > 1 && (
        <label className="field">
          <span>Vorgeschlagene Rolle beim Freigeben</span>
          <select value={s.default_role} disabled={busy} onChange={(e) => save({ default_role: e.target.value })}>
            {roles.map((r) => (
              <option key={r.key} value={r.key}>
                {r.label}
              </option>
            ))}
          </select>
        </label>
      )}
      <p className="small muted">
        {s.email_confirmation
          ? 'Der Mailversand ist eingerichtet: Wer einen Zugang beantragt, bestätigt zuerst die E-Mail-Adresse und erfährt das Ergebnis per Mail.'
          : 'Ohne eingerichteten Mailversand (Einstellungen → E-Mail) wird die E-Mail-Adresse nicht geprüft. Das Ergebnis erfährt die Person, wenn sie sich anmeldet oder den Stand ihrer Anfrage prüft.'}
      </p>
      {s.enabled && (
        <div className="field">
          <span>Link zum Formular (zum Weitergeben)</span>
          <code className="settings-link-code">{s.link}</code>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() =>
              void navigator.clipboard?.writeText(s.link).then(
                () => onToast('Link kopiert'),
                () => onToast('Kopieren hat nicht geklappt'),
              )
            }
          >
            Link kopieren
          </button>
        </div>
      )}
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
    </section>
  );
}

/** Benachrichtigung am Geburtstag (Issue #348): bei den übrigen Einstellungen zu Geburtstagen statt unter Benachrichtigungen. */
function BirthdayNotify({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.pushAdmin(), [], 'admin/push');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="text" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const p = data!;
  if (!p.birthdays) return null;

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
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
    </>
  );
}

/** Geburtstage (Issue #1): wie viele Tage im Voraus die Karte „Anstehende Geburtstage“ bei den Terminen einen zeigt. */
function BirthdaySettingsSection({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.birthdaySettings(), [], 'admin/birthdays');
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (!config?.features.birthdays) return <p className="muted">Der Bereich {sec.birthdays} ist ausgeschaltet.</p>;
  if (loading && !data) return <Loading shape="form" />;
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
        <span>
          Anstehende <Caps text={sec.birthdays} /> anzeigen
        </span>
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
      <BirthdayNotify onToast={onToast} />
    </section>
  );
}

/**
 * E-Mail (Issue #172): Kompanieadresse (Absender) und SMTP des Mailservers, Verbindung prüfen, Testmail, Versand in
 * Portionen. Das Passwort zeigt der Server nie an; leer lassen behält es.
 */
function MailSection({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.mailSettings(), [], 'admin/mail');
  if (loading && !data) return <Loading shape="form" />;
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
        <input type="text" value={form.from_name} onChange={set('from_name')} placeholder={settings.from_name_default} />
      </label>
      <label className="field">
        <span>Mailserver (SMTP)</span>
        <input
          type="text"
          className="mail-host"
          value={form.host}
          onChange={set('host')}
          placeholder="smtp.beispiel.de"
          autoComplete="off"
        />
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
        <input type="text" value={form.user} onChange={set('user')} autoComplete="off" />
      </label>
      <label className="field">
        <span>Passwort{settings.password_set ? ' (gespeichert – leer lassen, um es zu behalten)' : ''}</span>
        <input type="password" value={form.password} onChange={set('password')} autoComplete="new-password" />
      </label>
      <details>
        <summary>Versand in Portionen</summary>
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
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const value = Object.fromEntries(list.map((f) => [f.key, f.enabled])) as Record<Feature, boolean>;

  async function toggle(key: Feature, on: boolean) {
    const name = list.find((f) => f.key === key)?.label ?? key;
    if (
      !on &&
      !(await confirmAsk(`„${name}“ wird für alle ausgeblendet. Die Daten bleiben erhalten und sind beim Wiedereinschalten wieder da.`))
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
  window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : '') + window.location.hash);
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
    if (
      !(await confirmAsk(
        'Verbindung zu Google trennen? Galerie und Kalender lesen danach nur noch über ein Dienstkonto, falls hinterlegt.',
      ))
    )
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
      {result && (
        <p className={result.ok ? 'small' : 'form-error small'} role={result.ok ? 'status' : 'alert'}>
          {result.ok ? '✓ ' : ''}
          {result.message}
        </p>
      )}
      <div className="field">
        <span id="google-redirect-label">Weiterleitungs-URI (im OAuth-Client bei Google eintragen)</span>
        {/* Kopieren als Symbol direkt neben dem Feld */}
        <div className="copy-field">
          <input
            type="text"
            readOnly
            aria-labelledby="google-redirect-label"
            value={g.redirect_uri}
            onFocus={(e) => e.currentTarget.select()}
          />
          <IconButton icon="copy" label="Adresse kopieren" onClick={() => void copy()} />
        </div>
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
        </form>
      )}
      {connectError && (
        <p className="form-error" role="alert">
          {connectError}
        </p>
      )}
      <details open={!!g.service_account_email && !g.connected}>
        <summary>Alternative: Dienstkonto mit JSON-Schlüssel</summary>
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
                onClick={async () => (await confirmAsk('Schlüssel des Dienstkontos entfernen?')) && save({ drive_service_account: '' })}
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

/** Bezeichnung je Quelle des internen Kalenders (Auswahl in den Einstellungen). */
const CALENDAR_SOURCE_LABELS: Record<CalendarSourceChoice, string> = {
  app: 'In der App pflegen',
  google: 'Google Kalender (Kalender-IDs)',
  ics: 'iCal-Adresse',
};

/** Vorauswahl: die gewählte Quelle; ohne Auswahl (Einrichtung von vorher) die, die gerade gilt, sonst die iCal-Adresse. */
function calendarChoice(c: AdminSettings['calendar']): CalendarSourceChoice {
  if (c.choice) return c.choice;
  if (c.source === 'google' || c.source === 'google_connection') return 'google';
  return 'ics';
}

export function Calendar({ settings, onSaved, onToast }: SectionProps) {
  const c = settings.calendar;
  // Ältere Server kennen keine Auswahl: dann wie bisher Kalender-IDs und iCal-Adresse zusammen.
  const selectable = c.choice !== undefined;
  const [choice, setChoice] = useState<CalendarSourceChoice>(calendarChoice(c));
  const [url, setUrl] = useState('');
  const [ids, setIds] = useState(c.calendar_ids);
  const [minutes, setMinutes] = useState(String(c.cache_minutes));
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave((s) => {
    // Knopf „Internen Kalender verwalten“ gleich zeigen bzw. ausblenden, ohne die App neu zu laden.
    if (config) config.calendarInApp = s.calendar.source === 'app' && !!config.features.calendar;
    onSaved(s);
  }, onToast);
  const access = googleAccessLabel(settings.google);
  const status = {
    app: '✓ Die Termine werden in der App gepflegt.',
    ics: '✓ Eingerichtet über die iCal-Adresse.',
    google: '✓ Eingerichtet über die Google-API (Kalender-IDs und API-Schlüssel).',
    google_connection: `✓ Eingerichtet über die Kalender-IDs und ${access || 'den Google-Zugang'}.`,
    none: 'Noch kein Kalender eingerichtet.',
  }[c.source];
  const showIds = !selectable || choice === 'google';
  const showUrl = !selectable || choice === 'ics';
  const external = !selectable || choice !== 'app';

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

  function submit(e: FormEvent) {
    e.preventDefault();
    // Die Grenzen prüfen der Browser (min/max am Feld) und genau noch einmal der Server.
    const cacheMinutes = Number(minutes);
    // Nur die Dauer geändert: auch ohne neue Adresse oder IDs speichern (Issue #177).
    const cache = external && cacheMinutes !== c.cache_minutes ? { calendar_cache_minutes: cacheMinutes } : {};
    if (!selectable) {
      const idsChanged = ids.trim() !== c.calendar_ids;
      if (!url.trim() && !idsChanged) {
        if ('calendar_cache_minutes' in cache) return save(cache);
        if (c.source !== 'none') return onToast('Nichts geändert – der Kalender ist schon eingerichtet.');
        return setError('Bitte die iCal-Adresse oder Kalender-IDs eintragen.');
      }
      return save({ ...(url.trim() ? { calendar_ics_url: url.trim() } : {}), calendar_ids: ids.trim(), ...cache }, () => setUrl(''));
    }
    const changed = choice !== calendarChoice(c);
    if (choice === 'app') {
      if (!changed) return onToast('Nichts geändert – die Termine werden schon in der App gepflegt.');
      return save({ calendar_source: 'app' });
    }
    // Nur die Dauer geändert, die Quelle noch ohne Angaben: nur die Dauer speichern.
    const onlyCache = 'calendar_cache_minutes' in cache && !changed && c.source === 'none';
    if (choice === 'google') {
      if (!ids.trim()) return onlyCache ? save(cache) : setError('Bitte die Kalender-IDs eintragen.');
      if (!changed && ids.trim() === c.calendar_ids && !('calendar_cache_minutes' in cache))
        return onToast('Nichts geändert – der Kalender ist schon eingerichtet.');
      return save({ calendar_source: 'google', calendar_ids: ids.trim(), ...cache });
    }
    if (!url.trim() && !c.ics_url_set) return onlyCache ? save(cache) : setError('Bitte die iCal-Adresse eintragen.');
    if (!changed && !url.trim() && !('calendar_cache_minutes' in cache))
      return onToast('Nichts geändert – der Kalender ist schon eingerichtet.');
    save({ calendar_source: 'ics', ...(url.trim() ? { calendar_ics_url: url.trim() } : {}), ...cache }, () => setUrl(''));
  }

  return (
    <Section id="kalender" title="Kalender für Mitglieder" busy={busy} error={error} onSubmit={submit}>
      <p className="small">{status}</p>
      {selectable && (
        <label className="field">
          <span>Quelle</span>
          <select value={choice} onChange={(e) => setChoice(e.target.value as CalendarSourceChoice)}>
            {(Object.keys(CALENDAR_SOURCE_LABELS) as CalendarSourceChoice[]).map((k) => (
              <option key={k} value={k}>
                {CALENDAR_SOURCE_LABELS[k]}
              </option>
            ))}
          </select>
          <span className="small muted">
            Der interne Kalender ist von den öffentlichen Terminen getrennt. Die Termine kommen aus genau einer Quelle; beim Wechsel bleiben
            die Angaben der anderen gespeichert.
          </span>
        </label>
      )}
      {selectable && choice === 'app' && (
        <p className="small">
          Termine legst du bei den Terminen über <strong>Internen Kalender verwalten</strong> an. Das darf, wer das Recht „Kalender pflegen“
          hat, z. B. über die Rolle „Kalender verwalten“.
          {c.source === 'app' && config?.features.calendar && (
            <>
              {' '}
              <button type="button" className="btn btn-link btn-inline" onClick={() => navigate('termine/kalender')}>
                Zu den internen Terminen
              </button>
            </>
          )}
        </p>
      )}
      {c.connection_needs_calendar_access && showIds && (
        <p className="small">
          Die Kalender-IDs <code>{c.calendar_ids}</code> sind eingetragen, aber die Verbindung mit Google darf den Kalender nicht lesen
          (beim Verbinden nicht erlaubt). Unter <GoogleLink /> die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den
          Kalender setzen{c.source === 'ics' ? '; bis dahin gilt die iCal-Adresse' : ''}.
        </p>
      )}
      {showIds && (
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
      )}
      {showUrl && (
        <label className="field">
          <span>{c.ics_url_set ? 'Neue iCal-Adresse (ersetzt die bisherige)' : selectable ? 'iCal-Adresse' : 'Geheime iCal-Adresse'}</span>
          <input
            type="url"
            inputMode="url"
            autoComplete="off"
            placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
          />
          <span className="small muted">
            Die Adresse eines Kalenders im iCal-Format, z. B. in Google Kalender: Einstellungen des Kalenders → Kalender integrieren →
            Geheime Adresse im iCal-Format. Sie wird nicht wieder angezeigt.
          </span>
        </label>
      )}
      {external && (
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
            So lange zeigt die App die zuletzt geladenen Termine, bevor sie neu nachfragt ({CACHE_MINUTES.min} bis {CACHE_MINUTES.max}{' '}
            Minuten). Kürzere Zeiten zeigen Änderungen schneller, bedeuten aber mehr Abrufe, und das Öffnen der Termine dauert dann öfter
            länger. Zusätzlich erneuert die App den Kalender stündlich im Hintergrund. „Kalender testen“ und Speichern laden sofort neu.
          </span>
        </label>
      )}
      {c.source !== 'none' && c.source !== 'app' && external && (
        <div className="button-row single">
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? 'Prüft …' : 'Kalender testen'}
          </button>
          <button
            type="button"
            className="btn btn-link"
            onClick={async () =>
              (await confirmAsk('Kalender wirklich entfernen?')) &&
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
  const [source, setSource] = useState<GallerySourceType>(g.source ?? 'drive');
  const [downloads, setDownloads] = useState(!!g.downloads);
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
      title={sec.gallery}
      busy={busy}
      error={error}
      onSubmit={async (e) => {
        e.preventDefault();
        if (
          source === 'server' &&
          (g.source ?? 'drive') === 'drive' &&
          !(await confirmAsk(
            'Nur noch der eigene Server? Alben und Bilder aus Google Drive verschwinden aus der App; hochgeladene Bilder bleiben.',
          ))
        )
          return;
        save(
          g.source === undefined
            ? { gallery_root: root.trim() }
            : {
                gallery_source: source,
                gallery_store: 'server',
                ...(source === 'drive' ? { gallery_root: root.trim() } : {}),
                ...(g.downloads !== undefined ? { gallery_downloads: downloads ? '1' : '0' } : {}),
              },
        );
      }}
    >
      {g.source !== undefined && (
        <>
          <label className="field">
            <span>Quelle</span>
            <select value={source} onChange={(e) => setSource(e.target.value as GallerySourceType)}>
              <option value="drive">Google Drive (dazu Alben der App)</option>
              <option value="server">Eigener Server (nur Alben der App)</option>
            </select>
          </label>
          <label className="field">
            <span>Ablageort für hochgeladene Bilder</span>
            <select value="server" disabled>
              <option value="server">Eigener Server</option>
            </select>
          </label>
          <p className="muted small">
            Hochgeladene Bilder belegen {formatBytes(g.media_bytes ?? 0)}. Die App setzt keine Grenze; behalte den Speicherplatz deines
            Webspace im Blick. Sie sind in der Sicherung enthalten.
          </p>
        </>
      )}
      {source === 'drive' && (
        <>
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
        </>
      )}
      {g.downloads !== undefined && (
        <label className="toggle feature-toggle">
          <input type="checkbox" checked={downloads} onChange={(e) => setDownloads(e.target.checked)} />
          <span>
            Knöpfe zum Herunterladen zeigen
            <span className="small muted feature-desc">
              Wer die {sec.gallery} sieht, kann dann jedes Bild und Video in der Großansicht und jedes Album als ZIP herunterladen (Bilder
              in Originalauflösung, Videos unverändert).
            </span>
          </span>
        </label>
      )}
      {source === 'drive' && g.status.error && <p className="form-error small">Letzter Abgleich: {g.status.error}</p>}
      {g.configured && source === 'drive' && (
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
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      {open && !list && !error && <Loading shape="text" />}
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
                {p.drive_url ? (
                  <a className="small" href={p.drive_url} target="_blank" rel="noopener noreferrer">
                    In Google Drive öffnen
                  </a>
                ) : (
                  <span className="small muted">hochgeladen</span>
                )}
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

/** Bezeichnung je Quelle der News (Auswahl in den Einstellungen). */
const NEWS_SOURCE_LABELS: Record<NewsSourceType, string> = {
  none: 'Keine Neuigkeiten',
  site: 'Beiträge dieser WordPress-Seite',
  wordpress: 'Andere WordPress-Webseite',
  rss: 'RSS- oder Atom-Feed',
  app: 'In der App schreiben',
};

export function News({ settings, onSaved, onToast }: SectionProps) {
  const n = settings.news!;
  // Ältere Server nennen keine Auswahl: dann wie bisher Webseite oder Feed.
  const choices: NewsSourceType[] = n.sources ?? ['none', 'wordpress', 'rss'];
  const [source, setSource] = useState<NewsSourceType>(n.source);
  const [url, setUrl] = useState(n.url);
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave((s) => {
    // Knöpfe zum Schreiben gleich zeigen bzw. ausblenden, ohne die App neu zu laden.
    if (config && s.news) config.newsInApp = s.news.source === 'app' && !!config.features.news;
    onSaved(s);
  }, onToast);
  const needsUrl = source === 'wordpress' || source === 'rss';
  const unsaved = source !== n.source || (needsUrl && url.trim() !== n.url);

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
        if (needsUrl && !url.trim()) return setError('Bitte die Adresse angeben.');
        save({ news_source: source, ...(needsUrl ? { news_url: url.trim() } : {}) });
      }}
    >
      <label className="field">
        <span>Quelle</span>
        <select value={source} onChange={(e) => setSource(e.target.value as NewsSourceType)}>
          {choices.map((c) => (
            <option key={c} value={c}>
              {NEWS_SOURCE_LABELS[c]}
            </option>
          ))}
        </select>
      </label>
      {needsUrl && (
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
      {(n.source === 'wordpress' || n.source === 'rss') &&
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

/**
 * Links auf Impressum und Datenschutzerklärung (z. B. die Seiten auf der Webseite der Kompanie). Die App zeigt sie allen,
 * auch ohne Anmeldung: auf der Anmeldeseite, im Konto und in der Hilfe. Ohne einen der Links ist die Statusseite gelb.
 */
export function Legal({ settings, onSaved, onToast }: SectionProps) {
  const saved = settings.legal ?? { imprint_url: '', privacy_url: '' };
  const [imprint, setImprint] = useState(saved.imprint_url);
  const [privacy, setPrivacy] = useState(saved.privacy_url);
  // Gleich sichtbar machen (Anmeldeseite, Konto, Hilfe), ohne die App neu zu laden.
  const { busy, error, save } = useSave((s) => {
    if (config && s.legal) config.legal = { imprint: s.legal.imprint_url || null, privacy: s.legal.privacy_url || null };
    onSaved(s);
  }, onToast);
  return (
    <Section
      id="rechtliches"
      title="Impressum und Datenschutz"
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        save({ legal_imprint_url: imprint.trim(), legal_privacy_url: privacy.trim() });
      }}
    >
      <label className="field">
        <span>Impressum</span>
        <input
          type="url"
          inputMode="url"
          placeholder="https://www.beispiel.de/impressum"
          value={imprint}
          onChange={(e) => setImprint(e.target.value)}
        />
      </label>
      <label className="field">
        <span>Datenschutzerklärung</span>
        <input
          type="url"
          inputMode="url"
          placeholder="https://www.beispiel.de/datenschutz"
          value={privacy}
          onChange={(e) => setPrivacy(e.target.value)}
        />
      </label>
    </Section>
  );
}
