import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import brand from '../generated/brand';
import { t, tn, type Locale } from '../i18n';
import { EditLanguageSwitch, Flag, LANGUAGE_NAMES } from '../language';
import {
  api,
  can,
  config,
  applyBirthdayUpcomingDays,
  applyEventKinds,
  applyEventResponses,
  applyFeatures,
  eventKinds,
  eventKindsHidden,
  applyGallerySubmissions,
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
import { TileArrange, TileGrid } from '../tiles';

const sec = brand.labels.sections;

/**
 * Übersetzter Satz mit einem Element (Knopf, Hervorhebung) an der Stelle des Platzhalters, z. B.
 * withPart(t('Unter {link} trennen.'), <GoogleLink />): So bleibt der Satz als Ganzes übersetzbar.
 */
function withPart(text: string, part: ReactNode): ReactNode {
  const [before, after = ''] = text.split(/\{\w+\}/);
  return (
    <>
      {before}
      {part}
      {after}
    </>
  );
}

/** Seiten der Einstellungen: Adresse (#/einstellungen/<id>), Titel und ein Satz für die Übersicht. */
/** Gruppen der Übersicht (Issue #348): Kacheln je Gruppe statt einer langen Liste. */
const GROUPS = ['App', 'Mitglieder und Zugang', 'Benachrichtigungen', 'Verbindungen', 'Termine und Kalender', 'Betrieb'] as const;

/** Kennung je Gruppe für die persönliche Anordnung der Kacheln (Seite einstellungen/<Kennung>); nie ändern. */
const GROUP_KEYS: Record<(typeof GROUPS)[number], string> = {
  App: 'app',
  'Mitglieder und Zugang': 'mitglieder',
  Benachrichtigungen: 'benachrichtigungen',
  Verbindungen: 'verbindungen',
  'Termine und Kalender': 'termine',
  Betrieb: 'betrieb',
};

/** Überschriften der Gruppen in der Sprache der App. */
const GROUP_TITLES: Record<(typeof GROUPS)[number], string> = {
  App: t('App'),
  'Mitglieder und Zugang': t('Mitglieder und Zugang'),
  Benachrichtigungen: t('Benachrichtigungen'),
  Verbindungen: t('Verbindungen'),
  'Termine und Kalender': t('Termine und Kalender'),
  Betrieb: t('Betrieb'),
};

const PAGES = [
  { id: 'bereiche', group: 'App', title: t('Bereiche') },
  { id: 'navigation', group: 'App', title: t('Navigation') },
  { id: 'rechtliches', group: 'App', title: t('Impressum und Datenschutz') },
  { id: 'sprachen', group: 'App', title: t('Sprachen') },
  { id: 'rollen', group: 'Mitglieder und Zugang', title: t('Rollen') },
  { id: 'benachrichtigungen', group: 'Benachrichtigungen', title: t('Benachrichtigungen') },
  { id: 'email', group: 'Verbindungen', title: t('E-Mail') },
  { id: 'google', group: 'Verbindungen', title: t('Google-Zugang') },
  { id: 'galerie', group: 'Verbindungen', title: sec.gallery },
  { id: 'news', group: 'Verbindungen', title: newsTitle() },
  { id: 'kalender', group: 'Termine und Kalender', title: t('Kalender') },
  { id: 'zustand', group: 'Betrieb', title: t('Zustand und Updates') },
  { id: 'datensicherung', group: 'Betrieb', title: t('Datensicherung') },
  { id: 'wartung', group: 'Betrieb', title: t('Wartungsmodus') },
  { id: 'nutzung', group: 'Betrieb', title: t('Nutzung') },
  { id: 'neuerungen', group: 'Betrieb', title: t('Was ist neu') },
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
        ‹ {t('Einstellungen')}
      </button>
      {/* Seiten mit eigenen Namen je Sprache: oben die Wahl „Einrichten auf“ */}
      {(page.id === 'navigation' || page.id === 'rollen') && <EditLanguageSwitch />}
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
      {(page.id === 'google' ||
        page.id === 'kalender' ||
        page.id === 'galerie' ||
        page.id === 'news' ||
        page.id === 'rechtliches' ||
        page.id === 'sprachen') && <Verbindung page={page.id} onToast={onToast} />}
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
      <TileArrange pages={GROUPS.map((g) => `einstellungen/${GROUP_KEYS[g]}`)}>
        {GROUPS.map((g) => {
          const list = pages.filter((p) => p.group === g);
          if (list.length === 0) return null;
          return (
            <section key={g} className="settings-group" aria-label={GROUP_TITLES[g]}>
              <h3 className="settings-group-title">{GROUP_TITLES[g]}</h3>
              <TileGrid
                page={`einstellungen/${GROUP_KEYS[g]}`}
                className="settings-tiles"
                tiles={list.map((p) => ({
                  id: p.id,
                  label: p.title,
                  node: (
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
                  ),
                }))}
              />
            </section>
          );
        })}
      </TileArrange>
    </div>
  );
}

/** Google-Zugang, Kalender, Galerie, Neuigkeiten oder Rechtliches: alle aus den gemeinsamen Einstellungen (/admin/settings). */
function Verbindung({
  page,
  onToast,
}: {
  page: 'google' | 'kalender' | 'galerie' | 'news' | 'rechtliches' | 'sprachen';
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, reload, setData, revision } = useAsync(sources.settings.load, sources.settings.deps, sources.settings.key);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  // key: Die Formulare übernehmen ihre Felder einmal; weicht der frische Stand vom gespeicherten ab, neu aufbauen.
  const props = { settings: s, onSaved: setData, onToast };
  if (page === 'google') return <GoogleAccess key={revision} {...props} />;
  if (page === 'kalender')
    return (
      <>
        <Calendar key={revision} {...props} />
        <CalendarResponses key={`r${revision}`} {...props} />
        {config?.eventKinds !== undefined && <EventKinds onToast={onToast} />}
      </>
    );
  if (page === 'galerie')
    return (
      <>
        <Gallery key={revision} {...props} />
        {s.gallery.source !== undefined && <GalleryAlbums onToast={onToast} />}
      </>
    );
  if (page === 'rechtliches') return <Legal key={revision} {...props} />;
  if (page === 'sprachen') return <Languages key={revision} {...props} />;
  return s.news ? <News key={revision} {...props} /> : null;
}

const devicesText = (n: number): string => (n === 0 ? t('an kein Gerät') : tn('an {count} Gerät', 'an {count} Geräte', n));

/** Zuletzt verschickt je Art, dazu zuletzt vom Push-Dienst angenommen bzw. abgelehnt; nichts, solange noch nichts verschickt wurde. */
function PushLog({ log }: { log: PushAdmin['log'] }) {
  if (log.types.length === 0 && !log.accepted && !log.rejected) return null;
  return (
    <div className="push-log">
      <h4>{t('Zuletzt verschickt')}</h4>
      {log.types.length > 0 && (
        <ul className="small muted">
          {log.types.map((x) => (
            <li key={x.type}>
              {x.label} – {formatUntil(x.at)}, {devicesText(x.devices)}
            </li>
          ))}
        </ul>
      )}
      {log.accepted && (
        <p className="small muted">
          {t('Zuletzt vom Push-Dienst angenommen: {time} ({messages}).', {
            time: formatUntil(log.accepted.at),
            messages: tn('{count} Nachricht', '{count} Nachrichten', log.accepted.count),
          })}
        </p>
      )}
      {log.rejected && (
        <p className="small muted">
          {t('Zuletzt vom Push-Dienst abgelehnt: {time} ({messages}, {reason}).', {
            time: formatUntil(log.rejected.at),
            messages: tn('{count} Nachricht', '{count} Nachrichten', log.rejected.count),
            reason: log.rejected.reason,
          })}
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
      onToast(t('Gespeichert'));
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-benachrichtigungen">
      <h3>{t('Benachrichtigungen')}</h3>
      {p.supported ? (
        <p className="small muted">
          {t('{devices} angemeldet ({accounts}, {guests}).', {
            devices: tn('{count} Gerät', '{count} Geräte', p.devices),
            accounts: tn('{count} Konto', '{count} Konten', p.accounts),
            guests: tn('{count} Gast', '{count} Gäste', p.guests),
          })}
          {p.queued > 0 && ` ${tn('{count} Nachricht wartet auf den Versand.', '{count} Nachrichten warten auf den Versand.', p.queued)}`}
        </p>
      ) : (
        <p className="small" role="alert">
          {t(
            'Der Server kann keine Benachrichtigungen senden. Es fehlt: {missing}. Dein Hoster kann das in den PHP-Einstellungen einschalten.',
            {
              missing: p.missing.join(', '),
            },
          )}
        </p>
      )}
      <label className="field">
        <span>{t('Erinnerung an Termine am nächsten Tag ab')}</span>
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
        {t('Jede Person wählt auf ihrem Gerät selbst, worüber sie benachrichtigt wird (Meins → Konto → Benachrichtigungen).')}
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
      onToast(t('Gespeichert'));
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-zugang">
      <h3>{t('Zugang beantragen')}</h3>
      <label className="toggle">
        <input type="checkbox" checked={s.enabled} disabled={busy} onChange={(e) => save({ enabled: e.target.checked })} />
        <span>{t('Personen können selbst einen Zugang beantragen')}</span>
      </label>
      {roles.length > 1 && (
        <label className="field">
          <span>{t('Vorgeschlagene Rolle beim Freigeben')}</span>
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
          ? t(
              'Der Mailversand ist eingerichtet: Wer einen Zugang beantragt, bestätigt zuerst die E-Mail-Adresse und erfährt das Ergebnis per Mail.',
            )
          : t(
              'Ohne eingerichteten Mailversand (Einstellungen → E-Mail) wird die E-Mail-Adresse nicht geprüft. Das Ergebnis erfährt die Person, wenn sie sich anmeldet oder den Stand ihrer Anfrage prüft.',
            )}
      </p>
      {s.enabled && (
        <div className="field">
          <span>{t('Link zum Formular (zum Weitergeben)')}</span>
          <code className="settings-link-code">{s.link}</code>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() =>
              void navigator.clipboard?.writeText(s.link).then(
                () => onToast(t('Link kopiert')),
                () => onToast(t('Kopieren hat nicht geklappt')),
              )
            }
          >
            {t('Link kopieren')}
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
      onToast(t('Gespeichert'));
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
        {t('Am Geburtstag eines Mitglieds alle anderen benachrichtigen')}
      </label>
      {p.birthdays.enabled && (
        <label className="field">
          <span>{t('Benachrichtigung am Geburtstag ab')}</span>
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
  if (!config?.features.birthdays)
    return <p className="muted">{t('Der Bereich {section} ist ausgeschaltet.', { section: sec.birthdays })}</p>;
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  async function save(days: number) {
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.setBirthdayUpcomingDays(days);
      setData(next);
      applyBirthdayUpcomingDays(next.upcoming_days);
      onToast(t('Gespeichert'));
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
          <Caps text={t('Anstehende {section} anzeigen', { section: sec.birthdays })} />
        </span>
        <select value={data!.upcoming_days} disabled={busy} onChange={(e) => save(Number(e.target.value))}>
          {Array.from({ length: UPCOMING_DAYS_MAX + 1 }, (_, d) => (
            <option key={d} value={d}>
              {d === 0 ? t('nur am Tag selbst') : tn('{count} Tag vorher', '{count} Tage vorher', d)}
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
        onToast(t('Gespeichert'));
      },
    );
  }

  return (
    <form className="card settings-section form" id="einstellungen-email" onSubmit={save}>
      <h3>{t('E-Mail')}</h3>
      {settings.unavailable && (
        <p className="form-error" role="alert">
          {settings.unavailable}
        </p>
      )}
      <EmailField
        label={t('Kompanieadresse (Absender)')}
        className="mail-from"
        value={form.from}
        onChange={(from) => setForm({ ...form, from })}
        placeholder={t('info@beispiel.de')}
      />
      <label className="field">
        <span>{t('Absendername')}</span>
        <input type="text" value={form.from_name} onChange={set('from_name')} placeholder={settings.from_name_default} />
      </label>
      <label className="field">
        <span>{t('Mailserver (SMTP)')}</span>
        <input
          type="text"
          className="mail-host"
          value={form.host}
          onChange={set('host')}
          placeholder={t('smtp.beispiel.de')}
          autoComplete="off"
        />
      </label>
      <div className="field-row">
        <label className="field">
          <span>Port</span>
          <input type="number" min={1} max={65535} value={form.port} onChange={set('port')} />
        </label>
        <label className="field">
          <span>{t('Verschlüsselung')}</span>
          <select value={form.secure} onChange={(e) => setForm({ ...form, secure: e.target.value as MailSettings['secure'] })}>
            <option value="starttls">{t('STARTTLS (meist Port 587)')}</option>
            <option value="ssl">{t('SSL/TLS (meist Port 465)')}</option>
            <option value="none">{t('keine')}</option>
          </select>
        </label>
      </div>
      <label className="field">
        <span>{t('Benutzername')}</span>
        <input type="text" value={form.user} onChange={set('user')} autoComplete="off" />
      </label>
      <label className="field">
        <span>{settings.password_set ? t('Passwort (gespeichert – leer lassen, um es zu behalten)') : t('Passwort')}</span>
        <input type="password" value={form.password} onChange={set('password')} autoComplete="new-password" />
      </label>
      <details>
        <summary>{t('Versand in Portionen')}</summary>
        <div className="field-row">
          <label className="field">
            <span>{t('Mails je Portion')}</span>
            <input type="number" min={0} value={form.batch_size} onChange={set('batch_size')} />
          </label>
          <label className="field">
            <span>{t('Pause (Minuten)')}</span>
            <input type="number" min={0} value={form.batch_pause} onChange={set('batch_pause')} />
          </label>
        </div>
      </details>
      <button className="btn btn-block" type="submit" disabled={busy}>
        {t('Speichern')}
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
              {t('Verbindung prüfen')}
            </button>
          </div>
          <EmailField label={t('Testmail an (leer: deine eigene Adresse)')} value={testTo} onChange={setTestTo} />
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
            {t('Testmail senden')}
          </button>
        </>
      )}
      {message && (
        <p className={message.ok ? 'small mail-result' : 'form-error mail-result'} role={message.ok ? 'status' : 'alert'}>
          {message.text}
        </p>
      )}
      <p className="small muted">
        {settings.server_max_size
          ? maxSizeText(settings.server_max_size)
          : t('Der Mailserver hat noch keine Höchstgröße für Mails gemeldet.')}
        {settings.pending > 0 &&
          ` ${tn('{count} Mail wartet auf den Versand.', '{count} Mails warten auf den Versand.', settings.pending)}`}
      </p>
    </form>
  );
}

function maxSizeText(size: number | null): string {
  return size
    ? t('Der Mailserver nimmt Mails bis {size} an (Bilder eingerechnet).', { size: formatBytes(size) })
    : t('Der Mailserver meldet keine Höchstgröße.');
}

/** Volle Stunden 00:00 bis 23:00 für die Auswahl einer Uhrzeit. */
function HourOptions() {
  return (
    <>
      {Array.from({ length: 24 }, (_, h) => (
        <option key={h} value={h}>
          {t('{time} Uhr', { time: `${String(h).padStart(2, '0')}:00` })}
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
              {blocked && (
                <span className="small feature-desc">{t('Braucht {areas}.', { areas: missing.map(label).join(` ${t('und')} `) })}</span>
              )}
              {onSetup && value[f.key] && f.configured === false && f.setup && (
                <span className="small feature-desc">
                  {t('Noch nicht eingerichtet.')}{' '}
                  <button type="button" className="btn btn-link btn-inline" onClick={() => onSetup(f.setup!)}>
                    {t('Jetzt einrichten')}
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
      !(await confirmAsk(
        t('„{name}“ wird für alle ausgeblendet. Die Daten bleiben erhalten und sind beim Wiedereinschalten wieder da.', { name }),
      ))
    )
      return;
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.setFeatures({ [key]: on });
      setData(next);
      applyFeatures(next);
      await refreshRoles();
      onToast(on ? t('{name} eingeschaltet', { name }) : t('{name} ausgeschaltet', { name }));
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-bereiche">
      <h3>{t('Bereiche')}</h3>
      <p className="small muted">
        {t('Welche Bereiche die App für alle hat. Ausgeschaltete Bereiche verschwinden aus der App; ihre Daten bleiben erhalten.')}
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
        {busy ? t('Speichert …') : t('Speichern')}
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
      onToast(t('Gespeichert'));
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
    if (!clientId.trim()) return setConnectError(t('Bitte die Client-ID eintragen.'));
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
        t('Verbindung zu Google trennen? Galerie und Kalender lesen danach nur noch über ein Dienstkonto, falls hinterlegt.'),
      ))
    )
      return;
    setConnectError(null);
    try {
      onSaved(await api.googleDisconnect());
      onToast(t('Verbindung getrennt'));
    } catch (err) {
      setConnectError((err as Error).message);
    }
  }

  async function copy() {
    try {
      await navigator.clipboard.writeText(g.redirect_uri);
      onToast(t('Kopiert'));
    } catch {
      onToast(t('Bitte die Adresse markieren und kopieren.'));
    }
  }

  return (
    <section className="card settings-section" id="einstellungen-google">
      <h3>{t('Google-Zugang')}</h3>
      {result && (
        <p className={result.ok ? 'small' : 'form-error small'} role={result.ok ? 'status' : 'alert'}>
          {result.ok ? '✓ ' : ''}
          {result.message}
        </p>
      )}
      <div className="field">
        <span id="google-redirect-label">{t('Weiterleitungs-URI (im OAuth-Client bei Google eintragen)')}</span>
        {/* Kopieren als Symbol direkt neben dem Feld */}
        <div className="copy-field">
          <input
            type="text"
            readOnly
            aria-labelledby="google-redirect-label"
            value={g.redirect_uri}
            onFocus={(e) => e.currentTarget.select()}
          />
          <IconButton icon="copy" label={t('Adresse kopieren')} onClick={() => void copy()} />
        </div>
      </div>
      {g.connected ? (
        <div className="small">
          <p>
            ✓ {g.email ? withPart(t('Verbunden mit Google als {email}.'), <code>{g.email}</code>) : t('Verbunden mit Google.')}{' '}
            {g.calendar_access
              ? t('Es darf auch den Kalender lesen.')
              : t(
                  'Den Kalender darf es noch nicht lesen. Für einen privaten Kalender: Verbindung trennen und neu verbinden, dabei beide Häkchen setzen.',
                )}
            {g.write_access === false &&
              ` ${t('Google Drive darf es nur lesen. Damit Einreichungen und hochgeladene Bilder der Galerie in Drive landen: Verbindung trennen und neu verbinden, dabei das Häkchen für Google Drive setzen.')}`}
          </p>
          <div className="button-row">
            <button type="button" className="btn btn-link" onClick={disconnect}>
              {t('Verbindung trennen')}
            </button>
          </div>
        </div>
      ) : (
        <form className="form" onSubmit={connect}>
          <label className="field">
            <span>{t('Client-ID')}</span>
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
            <span>{t('Clientschlüssel (Client-Secret)')}</span>
            <input
              type="password"
              autoComplete="off"
              placeholder={g.client_secret_set && clientId.trim() === g.client_id ? t('gespeichert – leer lassen, um ihn zu behalten') : ''}
              value={secret}
              onChange={(e) => setSecret(e.target.value)}
            />
          </label>
          <button className="btn btn-block" type="submit" disabled={connecting}>
            {connecting ? t('Weiter zu Google …') : t('Speichern und mit Google verbinden')}
          </button>
        </form>
      )}
      {connectError && (
        <p className="form-error" role="alert">
          {connectError}
        </p>
      )}
      <details open={!!g.service_account_email && !g.connected}>
        <summary>{t('Alternative: Dienstkonto mit JSON-Schlüssel')}</summary>
        {g.service_account_email && (
          <div className="small">
            {t('Dienstkonto:')} <code>{g.service_account_email}</code>
            {g.connected ? ` ${t('(wird nicht genutzt, solange die Verbindung mit Google besteht)')}` : ''}
            <div className="button-row">
              <button type="button" className="btn btn-link" onClick={() => setReplaceKey(!replaceKey)}>
                {replaceKey ? t('Schlüssel behalten') : t('Schlüssel ersetzen')}
              </button>
              <button
                type="button"
                className="btn btn-link"
                onClick={async () => (await confirmAsk(t('Schlüssel des Dienstkontos entfernen?'))) && save({ drive_service_account: '' })}
              >
                {t('Schlüssel entfernen')}
              </button>
            </div>
          </div>
        )}
        {showKeyField && (
          <>
            <label className="field">
              <span>{g.service_account_email ? t('Neuer Schlüssel des Dienstkontos (JSON)') : t('Schlüssel des Dienstkontos (JSON)')}</span>
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
                  : setError(t('Bitte den Inhalt der JSON-Datei einfügen.'))
              }
            >
              {busy ? t('Speichert …') : t('Schlüssel speichern')}
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
  if (g.connected) return g.email ? t('die Verbindung mit Google ({email})', { email: g.email }) : t('die Verbindung mit Google');
  if (g.service_account_email) return t('das Dienstkonto {email}', { email: g.service_account_email });
  return '';
}

/** Verweis auf den Abschnitt Google-Zugang (in den Einstellungen und im Assistenten darüber). */
function GoogleLink() {
  return (
    <button type="button" className="btn btn-link btn-inline" onClick={() => scrollToSetup('google')}>
      {t('Google-Zugang')}
    </button>
  );
}

/** Zwischenspeicher des Kalenders in Minuten (Grenzen wie SettingsService::update auf dem Server). */
const CACHE_MINUTES = { min: 1, max: 1440 };

/** Bezeichnung je Quelle des internen Kalenders (Auswahl in den Einstellungen). */
const CALENDAR_SOURCE_LABELS: Record<CalendarSourceChoice, string> = {
  app: t('In der App pflegen'),
  google: t('Google Kalender (Kalender-IDs)'),
  ics: t('iCal-Adresse'),
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
    app: t('✓ Die Termine werden in der App gepflegt.'),
    ics: t('✓ Eingerichtet über die iCal-Adresse.'),
    google: t('✓ Eingerichtet über die Google-API (Kalender-IDs und API-Schlüssel).'),
    google_connection: t('✓ Eingerichtet über die Kalender-IDs und {access}.', { access: access || t('den Google-Zugang') }),
    none: t('Noch kein Kalender eingerichtet.'),
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
          ? t('Verbindung klappt, aber es gibt keine kommenden Termine.')
          : tn('{count} Termin gefunden, z. B. „{title}“.', '{count} Termine gefunden, z. B. „{title}“.', r.count, {
              title: r.sample[0].title,
            }),
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
        if (c.source !== 'none') return onToast(t('Nichts geändert – der Kalender ist schon eingerichtet.'));
        return setError(t('Bitte die iCal-Adresse oder Kalender-IDs eintragen.'));
      }
      return save({ ...(url.trim() ? { calendar_ics_url: url.trim() } : {}), calendar_ids: ids.trim(), ...cache }, () => setUrl(''));
    }
    const changed = choice !== calendarChoice(c);
    if (choice === 'app') {
      if (!changed) return onToast(t('Nichts geändert – die Termine werden schon in der App gepflegt.'));
      return save({ calendar_source: 'app' });
    }
    // Nur die Dauer geändert, die Quelle noch ohne Angaben: nur die Dauer speichern.
    const onlyCache = 'calendar_cache_minutes' in cache && !changed && c.source === 'none';
    if (choice === 'google') {
      if (!ids.trim()) return onlyCache ? save(cache) : setError(t('Bitte die Kalender-IDs eintragen.'));
      if (!changed && ids.trim() === c.calendar_ids && !('calendar_cache_minutes' in cache))
        return onToast(t('Nichts geändert – der Kalender ist schon eingerichtet.'));
      return save({ calendar_source: 'google', calendar_ids: ids.trim(), ...cache });
    }
    if (!url.trim() && !c.ics_url_set) return onlyCache ? save(cache) : setError(t('Bitte die iCal-Adresse eintragen.'));
    if (!changed && !url.trim() && !('calendar_cache_minutes' in cache))
      return onToast(t('Nichts geändert – der Kalender ist schon eingerichtet.'));
    save({ calendar_source: 'ics', ...(url.trim() ? { calendar_ics_url: url.trim() } : {}), ...cache }, () => setUrl(''));
  }

  return (
    <Section id="kalender" title={t('Kalender für Mitglieder')} busy={busy} error={error} onSubmit={submit}>
      <p className="small">{status}</p>
      {selectable && (
        <label className="field">
          <span>{t('Quelle')}</span>
          <select value={choice} onChange={(e) => setChoice(e.target.value as CalendarSourceChoice)}>
            {(Object.keys(CALENDAR_SOURCE_LABELS) as CalendarSourceChoice[]).map((k) => (
              <option key={k} value={k}>
                {CALENDAR_SOURCE_LABELS[k]}
              </option>
            ))}
          </select>
          <span className="small muted">
            {t(
              'Der interne Kalender ist von den öffentlichen Terminen getrennt. Die Termine kommen aus genau einer Quelle; beim Wechsel bleiben die Angaben der anderen gespeichert.',
            )}
          </span>
        </label>
      )}
      {selectable && choice === 'app' && (
        <p className="small">
          {withPart(t('Termine legst du bei den Terminen über {button} an.'), <strong>{t('Internen Kalender verwalten')}</strong>)}{' '}
          {t('Das darf, wer das Recht „Kalender pflegen“ hat, z. B. über die Rolle „Kalender verwalten“.')}
          {c.source === 'app' && config?.features.calendar && (
            <>
              {' '}
              <button type="button" className="btn btn-link btn-inline" onClick={() => navigate('termine/kalender')}>
                {t('Zu den internen Terminen')}
              </button>
            </>
          )}
        </p>
      )}
      {c.connection_needs_calendar_access && showIds && (
        <p className="small">
          {withPart(
            t(
              'Die Kalender-IDs {ids} sind eingetragen, aber die Verbindung mit Google darf den Kalender nicht lesen (beim Verbinden nicht erlaubt).',
            ),
            <code>{c.calendar_ids}</code>,
          )}{' '}
          {withPart(
            c.source === 'ics'
              ? t(
                  'Unter {link} die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den Kalender setzen; bis dahin gilt die iCal-Adresse.',
                )
              : t('Unter {link} die Verbindung trennen und neu verbinden, dabei auch das Häkchen für den Kalender setzen.'),
            <GoogleLink />,
          )}
        </p>
      )}
      {showIds && (
        <label className="field">
          <span>{t('Google-Kalender-IDs')}</span>
          <input
            type="text"
            autoComplete="off"
            spellCheck={false}
            placeholder="abc123@group.calendar.google.com"
            value={ids}
            onChange={(e) => setIds(e.target.value)}
          />
          <span className="small muted">
            {t('In Google Kalender: Einstellungen des Kalenders → Kalender integrieren → Kalender-ID. Mehrere durch Komma trennen.')}
            {!access && ` ${t('Dafür zuerst den Google-Zugang einrichten.')}`}
          </span>
        </label>
      )}
      {showUrl && (
        <label className="field">
          <span>
            {c.ics_url_set ? t('Neue iCal-Adresse (ersetzt die bisherige)') : selectable ? t('iCal-Adresse') : t('Geheime iCal-Adresse')}
          </span>
          <input
            type="url"
            inputMode="url"
            autoComplete="off"
            placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
          />
          <span className="small muted">
            {t(
              'Die Adresse eines Kalenders im iCal-Format, z. B. in Google Kalender: Einstellungen des Kalenders → Kalender integrieren → Geheime Adresse im iCal-Format. Sie wird nicht wieder angezeigt.',
            )}
          </span>
        </label>
      )}
      {external && (
        <label className="field">
          <span>{t('Aktualisieren alle … Minuten')}</span>
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
            {t(
              'So lange zeigt die App die zuletzt geladenen Termine, bevor sie neu nachfragt ({min} bis {max} Minuten). Kürzere Zeiten zeigen Änderungen schneller, bedeuten aber mehr Abrufe, und das Öffnen der Termine dauert dann öfter länger. Zusätzlich erneuert die App den Kalender stündlich im Hintergrund. „Kalender testen“ und Speichern laden sofort neu.',
              { min: CACHE_MINUTES.min, max: CACHE_MINUTES.max },
            )}
          </span>
        </label>
      )}
      {c.source !== 'none' && c.source !== 'app' && external && (
        <div className="button-row single">
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? t('Prüft …') : t('Kalender testen')}
          </button>
          <button
            type="button"
            className="btn btn-link"
            onClick={async () =>
              (await confirmAsk(t('Kalender wirklich entfernen?'))) &&
              save({ calendar_ics_url: '', calendar_ids: '', google_api_key: '' }, () => setIds(''))
            }
          >
            {t('Entfernen')}
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

/**
 * Zu- und Absagen bei Terminen des internen Kalenders: erst einschalten (Vorgabe aus), dann die Erinnerung an/aus und
 * ihr Vorlauf in Tagen. Ältere Server kennen den Schalter nicht (dann nur die Erinnerung).
 */
function CalendarResponses({ settings, onSaved, onToast }: SectionProps) {
  const c = settings.calendar;
  const switchable = c.responses !== undefined;
  const [enabled, setEnabled] = useState(c.responses ?? true);
  const [on, setOn] = useState(c.response_reminder ?? true);
  const [days, setDays] = useState(String(c.response_reminder_days ?? 3));
  const { busy, error, save } = useSave(onSaved, onToast);
  // Gespeicherten Schalter übernehmen: Knöpfe am Termin, Anmeldeschluss beim Pflegen
  useEffect(() => {
    if (c.responses !== undefined) applyEventResponses(c.responses);
  }, [c.responses]);
  if (c.response_reminder === undefined) return null;

  function submit(e: FormEvent) {
    e.preventDefault();
    const n = Number(days);
    const same = on === c.response_reminder && n === c.response_reminder_days;
    if ((!switchable || enabled === c.responses) && (same || !enabled)) return onToast(t('Nichts geändert.'));
    save({
      ...(switchable ? { calendar_responses: enabled ? '1' : '0' } : {}),
      ...(enabled ? { calendar_response_reminder: on ? '1' : '0', ...(on ? { calendar_response_reminder_days: n } : {}) } : {}),
    });
  }

  return (
    <Section id="zu-und-absagen" title={t('Zu- und Absagen')} busy={busy} error={error} onSubmit={submit}>
      <p className="small muted">
        {t(
          'Bei jedem Termin des internen Kalenders sagen alle mit dem Recht „zu- und absagen“ zu oder ab. Eine Absage schlägt in der Anwesenheit „entschuldigt“ vor. Wer sich noch nicht gemeldet hat, bekommt auf Wunsch eine Benachrichtigung (nur auf Geräten mit eingeschalteten Benachrichtigungen, keine E-Mail).',
        )}
      </p>
      {switchable && (
        <label className="toggle feature-toggle">
          <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
          <span>
            {t('Zu- und Absagen bei Terminen')}
            <span className="small muted feature-desc">
              {t(
                'Ausgeschaltet gibt es keine Knöpfe am Termin, keine Erinnerung und keinen Vorschlag in der Anwesenheit. Bisherige Rückmeldungen bleiben gespeichert.',
              )}
            </span>
          </span>
        </label>
      )}
      {enabled && (
        <label className="toggle feature-toggle">
          <input type="checkbox" checked={on} onChange={(e) => setOn(e.target.checked)} />
          <span>
            {t('An Zu- und Absage erinnern')}
            <span className="small muted feature-desc">{t('Nur an alle, die bei dem Termin noch nicht zu- oder abgesagt haben.')}</span>
          </span>
        </label>
      )}
      {enabled && on && (
        <label className="field">
          <span>{t('Erinnerung … Tage vor dem Termin')}</span>
          <input
            type="number"
            inputMode="numeric"
            min={1}
            max={30}
            step={1}
            required
            value={days}
            onChange={(e) => setDays(e.target.value)}
          />
          <span className="small muted">
            {t(
              'Zur Uhrzeit der Erinnerung unter Benachrichtigungen. Hat ein Termin einen Anmeldeschluss, kommt die Erinnerung am Tag davor.',
            )}
          </span>
        </label>
      )}
    </Section>
  );
}

const KIND_MAX = 40;
const KINDS_MAX = 30;

/**
 * Terminarten (z. B. Versammlung): zur Auswahl am Termin und an Anwesenheitslisten, etwa als Voraussetzung für die
 * Aufnahme. Gespeichert wird die Bezeichnung; entfernte Arten bleiben an Terminen und Listen stehen. Je Art ein Haken, ob
 * sie als Schild neben dem Titel des Termins steht (Vorgabe: ja).
 */
function EventKinds({ onToast }: { onToast: (msg: string) => void }) {
  const saved = eventKinds();
  const savedHidden = eventKindsHidden();
  const toRows = (kinds: string[], hidden: string[]) =>
    kinds.length > 0 ? kinds.map((name) => ({ name, tag: !hidden.includes(name) })) : [{ name: '', tag: true }];
  const [rows, setRows] = useState(() => toRows(saved, savedHidden));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const update = (i: number, change: Partial<{ name: string; tag: boolean }>) =>
    setRows(rows.map((x, j) => (j === i ? { ...x, ...change } : x)));

  async function submit(e: FormEvent) {
    e.preventDefault();
    const filled = rows.map((r) => ({ ...r, name: r.name.trim() })).filter((r) => r.name !== '');
    const list = filled.map((r) => r.name);
    const hidden = filled.filter((r) => !r.tag).map((r) => r.name);
    const lower = list.map((n) => n.toLowerCase());
    const twice = list.find((_, i) => lower.indexOf(lower[i]) !== i);
    if (twice) return setError(t('Die Terminart „{kind}“ steht doppelt in der Liste.', { kind: twice }));
    if (list.join('\n') === saved.join('\n') && hidden.join('\n') === savedHidden.join('\n')) return onToast(t('Nichts geändert.'));
    setBusy(true);
    setError(null);
    try {
      const r = await api.setEventKinds(list, hidden);
      applyEventKinds(r.kinds, r.hidden ?? hidden);
      setRows(toRows(r.kinds, r.hidden ?? hidden));
      onToast(t('Gespeichert'));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Section id="terminarten" title={t('Terminarten')} busy={busy} error={error} onSubmit={submit}>
      <p className="small muted">
        {t(
          'Zum Beispiel Versammlung oder Arbeitsdienst. Wer Termine pflegt, wählt die Art beim Termin, auch bei Terminen aus Google oder iCal. Anwesenheitslisten übernehmen sie. Steht der Name einer Art im Titel, schlägt die App sie vor.',
        )}
      </p>
      <div className="field poll-options-edit">
        <span>{t('Terminarten')}</span>
        {rows.map((r, i) => (
          <div className="event-kind-edit" key={i}>
            <div className="poll-option-edit">
              <input
                type="text"
                aria-label={t('Terminart {n}', { n: i + 1 })}
                value={r.name}
                maxLength={KIND_MAX}
                onChange={(e) => update(i, { name: e.target.value })}
              />
              {(rows.length > 1 || r.name !== '') && (
                <button
                  type="button"
                  className="btn btn-link"
                  aria-label={t('Terminart {n} entfernen', { n: i + 1 })}
                  onClick={() => setRows(rows.length > 1 ? rows.filter((_, j) => j !== i) : [{ name: '', tag: true }])}
                >
                  ×
                </button>
              )}
            </div>
            <label className="toggle">
              <input
                type="checkbox"
                aria-label={t('Terminart {n} als Schild am Termin zeigen', { n: i + 1 })}
                checked={r.tag}
                onChange={(e) => update(i, { tag: e.target.checked })}
              />
              <span>{t('Als Schild am Termin zeigen')}</span>
            </label>
          </div>
        ))}
        {rows.length < KINDS_MAX && (
          <button type="button" className="btn btn-link poll-add-option" onClick={() => setRows([...rows, { name: '', tag: true }])}>
            + {t('Terminart')}
          </button>
        )}
      </div>
      <p className="small muted">
        {t(
          'Ohne Schild bleibt die Art am Termin gespeichert und zählt weiter, etwa für die Aufnahme; sie steht dann nur nicht neben dem Titel. Termine und Listen behalten ihre Art, auch wenn du sie hier entfernst.',
        )}
      </p>
    </Section>
  );
}

/** Wo hochgeladene Bilder und Einreichungen landen: eigener Server oder Google Drive (Drive als Quelle, voller Zugriff). */
function GalleryStore({ g }: { g: AdminSettings['gallery'] }) {
  const d = g.drive;
  const onServer = formatBytes(g.media_bytes ?? 0);
  if (g.store === 'drive' && d) {
    return (
      <div className="small">
        <p>
          <strong>{t('Ablageort:')}</strong>{' '}
          {t(
            'Google Drive. Hochgeladene Bilder und Videos kommen in den Ordner ihres Albums. Offene Einreichungen liegen im Ordner „Einreichungen“ in der Ablage des verbundenen Google-Kontos und kommen bei der Freigabe ins Album.',
          )}
        </p>
        {d.waiting > 0 && (
          <p className="muted">
            {tn(
              '{count} Datei wartet noch auf dem Server und kommt im Hintergrund nach Drive (belegt {size}).',
              '{count} Dateien warten noch auf dem Server und kommen im Hintergrund nach Drive (belegt {size}).',
              d.waiting,
              { size: onServer },
            )}{' '}
            {t('Alben, die nur in der App angelegt sind, bleiben vorerst auf dem Server.')}
          </p>
        )}
        {d.error && (
          <p className="form-error" role="alert">
            {t('Letzter Versuch, Dateien nach Drive zu bringen: {error}', { error: d.error })}
          </p>
        )}
      </div>
    );
  }
  return (
    <div className="small">
      <p>
        <strong>{t('Ablageort:')}</strong>{' '}
        {t(
          'eigener Server. Hochgeladene Bilder belegen {size}. Die App setzt keine Grenze; behalte den Speicherplatz deines Webspace im Blick. Sie sind in der Sicherung enthalten.',
          { size: onServer },
        )}
      </p>
      {d?.needs_reconnect && (
        <p>
          {withPart(
            t(
              'Damit hochgeladene Bilder und Einreichungen in Google Drive landen, braucht die App vollen Zugriff auf Drive: unter {link} die Verbindung trennen und neu mit Google verbinden, dabei das Häkchen für Google Drive setzen. Mit einem Dienstkonto geht das nicht, es hat in Drive keinen eigenen Speicherplatz.',
            ),
            <GoogleLink />,
          )}
        </p>
      )}
    </div>
  );
}

export function Gallery({ settings, onSaved, onToast }: SectionProps) {
  const g = settings.gallery;
  const [root, setRoot] = useState(g.root);
  const [source, setSource] = useState<GallerySourceType>(g.source ?? 'drive');
  const [downloads, setDownloads] = useState(!!g.downloads);
  const [submissions, setSubmissions] = useState(!!g.submissions);
  // Gespeicherten Haken „Einreichen erlauben“ übernehmen: Knopf „einreichen“ in der Galerie
  useEffect(() => {
    if (g.submissions !== undefined) applyGallerySubmissions(g.submissions);
  }, [g.submissions]);
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
      setProgress(
        t('{albums} Alben, {photos} Bilder und Videos gefunden – {pending} werden noch aufbereitet …', {
          albums: r.albums,
          photos: r.photos,
          pending: r.pending,
        }),
      );
      for (let round = 0; r.pending > 0 && r.processed > 0 && round < 50; round++) {
        r = await api.syncGallery(true);
        setProgress(
          t('{albums} Alben, {photos} Bilder und Videos – noch {pending} …', { albums: r.albums, photos: r.photos, pending: r.pending }),
        );
      }
      const parts = [t('{albums} Alben, {photos} Bilder und Videos.', { albums: r.albums, photos: r.photos })];
      if (r.pending > 0) parts.push(t('{count} Bilder werden stündlich automatisch weiter aufbereitet.', { count: r.pending }));
      if (r.failed > 0) parts.push(t('{count} Bilder ließen sich nicht laden (Liste unten).', { count: r.failed }));
      const details = parts.join(' ');
      setProgress(r.pending > 0 || r.failed > 0 ? t('Abgeglichen: {details}', { details }) : t('Fertig: {details}', { details }));
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
            t('Nur noch der eigene Server? Alben und Bilder aus Google Drive verschwinden aus der App; hochgeladene Bilder bleiben.'),
          ))
        )
          return;
        save(
          g.source === undefined
            ? { gallery_root: root.trim() }
            : {
                gallery_source: source,
                ...(source === 'drive' ? { gallery_root: root.trim() } : {}),
                ...(g.downloads !== undefined ? { gallery_downloads: downloads ? '1' : '0' } : {}),
                ...(g.submissions !== undefined ? { gallery_submissions: submissions ? '1' : '0' } : {}),
              },
        );
      }}
    >
      {g.source !== undefined && (
        <>
          <label className="field">
            <span>{t('Quelle')}</span>
            <select value={source} onChange={(e) => setSource(e.target.value as GallerySourceType)}>
              <option value="drive">{t('Google Drive (dazu Alben der App)')}</option>
              <option value="server">{t('Eigener Server (nur Alben der App)')}</option>
            </select>
          </label>
          <GalleryStore g={g} />
        </>
      )}
      {source === 'drive' && (
        <>
          <p className="small">
            {access
              ? withPart(t('Gelesen wird über {access} ({link}).', { access }), <GoogleLink />)
              : withPart(
                  t('Noch kein Zugang zu Google: zuerst unter {link} mit Google verbinden oder ein Dienstkonto hinterlegen.'),
                  <GoogleLink />,
                )}
          </p>
          <label className="field">
            <span>{t('Link zum Galerie-Ordner')}</span>
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
            {t('Knöpfe zum Herunterladen zeigen')}
            <span className="small muted feature-desc">
              {t(
                'Wer die {section} sieht, kann dann jedes Bild und Video in der Großansicht und jedes Album als ZIP herunterladen (Bilder in Originalauflösung, Videos unverändert).',
                { section: sec.gallery },
              )}
            </span>
          </span>
        </label>
      )}
      {g.submissions !== undefined && (
        <label className="toggle feature-toggle">
          <input type="checkbox" checked={submissions} onChange={(e) => setSubmissions(e.target.checked)} />
          <span>
            {t('Einreichen von Bildern und Videos durch Mitglieder erlauben')}
            <span className="small muted feature-desc">
              {t(
                'Wer das Recht „Bilder und Videos einreichen“ hat, kann dann Bilder und Videos für die {section} einreichen. Sie erscheinen erst, wenn jemand sie unter „Einreichungen prüfen“ freigibt.',
                { section: sec.gallery },
              )}
            </span>
          </span>
        </label>
      )}
      {source === 'drive' && g.status.error && (
        <p className="form-error small">{t('Letzter Abgleich: {error}', { error: g.status.error })}</p>
      )}
      {g.configured && source === 'drive' && (
        <button type="button" className="btn btn-secondary" onClick={sync} disabled={syncing}>
          {syncing ? t('Gleicht ab …') : t('Jetzt abgleichen')}
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
      <summary>{tn('{count} Bild ließ sich nicht laden – anzeigen', '{count} Bilder ließen sich nicht laden – anzeigen', count)}</summary>
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
                  {p.album || t('ohne Album')}
                  {p.intern && ` (${t('intern')})`}
                  {!p.unsupported && ` · ${tn('{count} Versuch', '{count} Versuche', p.attempts)}`}
                </span>
                <br />
                <span className="small">{p.error || t('ohne Fehlermeldung')}</span>{' '}
                {p.drive_url ? (
                  <a className="small" href={p.drive_url} target="_blank" rel="noopener noreferrer">
                    {t('In Google Drive öffnen')}
                  </a>
                ) : (
                  <span className="small muted">{t('hochgeladen')}</span>
                )}
              </li>
            ))}
          </ul>
          {list.total > list.photos.length && (
            <p className="small muted">
              {t('Gezeigt werden die ersten {count} von {total}.', { count: list.photos.length, total: list.total })}
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
        {retrying ? t('Wird erneut versucht …') : t('Alle erneut versuchen')}
      </button>
    </details>
  );
}

/** Bezeichnung je Quelle der News (Auswahl in den Einstellungen). */
const NEWS_SOURCE_LABELS: Record<NewsSourceType, string> = {
  none: t('Keine Neuigkeiten'),
  site: t('Beiträge dieser WordPress-Seite'),
  wordpress: t('Andere WordPress-Webseite'),
  rss: t('RSS- oder Atom-Feed'),
  app: t('In der App schreiben'),
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
    // Adresse des Editors von WordPress gilt nur mit den Beiträgen der Seite; nach einem Wechsel dorthin bringt sie das nächste Laden.
    if (config && s.news && s.news.source !== 'site') config.newsEditorUrl = null;
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
      setResult(tn('{count} Beitrag gefunden.', '{count} Beiträge gefunden.', r.count));
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
        if (needsUrl && !url.trim()) return setError(t('Bitte die Adresse angeben.'));
        save({ news_source: source, ...(needsUrl ? { news_url: url.trim() } : {}) });
      }}
    >
      <label className="field">
        <span>{t('Quelle')}</span>
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
          <span>{source === 'wordpress' ? t('Adresse der Webseite') : t('Adresse des Feeds')}</span>
          <input
            type="url"
            inputMode="url"
            required
            placeholder={source === 'wordpress' ? t('https://www.beispiel.de') : t('https://www.beispiel.de/feed')}
            value={url}
            onChange={(e) => setUrl(e.target.value)}
          />
        </label>
      )}
      {(n.source === 'wordpress' || n.source === 'rss') &&
        (unsaved ? (
          <p className="muted small">{t('Erst speichern, dann testen.')}</p>
        ) : (
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? t('Prüft …') : t('Neuigkeiten testen')}
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
 * Sprachen der App: Deutsch ist immer an; weitere schaltet der Admin hier ein. Erst dann erscheint die Flagge in der
 * Kopfzeile und die Wahl unter Meins → Konto. Namen und Inhalte bleiben, wo nichts anderes eingetragen ist, deutsch.
 */
export function Languages({ settings, onSaved, onToast }: SectionProps) {
  const saved = settings.languages ?? { enabled: ['de'], available: ['de'] };
  const [enabled, setEnabled] = useState<Locale[]>(saved.enabled);
  const { busy, error, save } = useSave((s) => {
    if (config && s.languages) config.languages = s.languages.enabled;
    onSaved(s);
  }, onToast);
  const others = saved.available.filter((l) => l !== 'de');
  return (
    <Section
      id="sprachen"
      title={t('Sprachen')}
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        save({ languages: enabled.join(',') });
      }}
    >
      <p className="small muted">
        {t(
          'Deutsch ist immer eingeschaltet. Mit einer weiteren Sprache erscheint oben eine Flagge zum Wechseln; jede Person wählt ihre Sprache selbst. Mails und Benachrichtigungen kommen in der Sprache, die jemand gewählt hat.',
        )}
      </p>
      <label className="toggle">
        <input type="checkbox" checked disabled />
        <span lang="de">
          <Flag lang="de" /> {LANGUAGE_NAMES.de}
        </span>
      </label>
      {others.map((l) => (
        <label key={l} className="toggle">
          <input
            type="checkbox"
            checked={enabled.includes(l)}
            onChange={(e) => setEnabled(e.target.checked ? [...enabled, l] : enabled.filter((x) => x !== l))}
          />
          <span lang={l}>
            <Flag lang={l} /> {LANGUAGE_NAMES[l]}
          </span>
        </label>
      ))}
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
      title={t('Impressum und Datenschutz')}
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        save({ legal_imprint_url: imprint.trim(), legal_privacy_url: privacy.trim() });
      }}
    >
      <label className="field">
        <span>{t('Impressum')}</span>
        <input
          type="url"
          inputMode="url"
          placeholder={t('https://www.beispiel.de/impressum')}
          value={imprint}
          onChange={(e) => setImprint(e.target.value)}
        />
      </label>
      <label className="field">
        <span>{t('Datenschutzerklärung')}</span>
        <input
          type="url"
          inputMode="url"
          placeholder={t('https://www.beispiel.de/datenschutz')}
          value={privacy}
          onChange={(e) => setPrivacy(e.target.value)}
        />
      </label>
    </Section>
  );
}
