import { useState, type FormEvent, type ReactNode } from 'react';
import { api, applyFeatures, refreshRoles, type AdminSettings, type Feature, type FeatureInfo, type NewsSourceType } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';
import Rollen from './Rollen';
import Wartung from './Wartung';

/**
 * Einstellungen für Admins (beide Varianten): Bereiche, Rollen, Zustand, Updates und Datensicherung, dann Kalender,
 * Galerie und Neuigkeiten. Geheime Werte zeigt der Server nie an, nur ob sie gesetzt sind.
 * Bei WordPress kommen die Neuigkeiten aus den Beiträgen der Website; dort gibt es keinen Abschnitt dafür.
 */
export default function Einstellungen({ onToast }: { onToast: (msg: string) => void }) {
  // Nach dem Speichern von Kalender, Galerie oder News: „eingerichtet?“ der Bereiche neu laden.
  const [setupSaved, setSetupSaved] = useState(0);
  // Nach dem Schalten von Bereichen: Standardrollen neu laden.
  const [featuresSaved, setFeaturesSaved] = useState(0);
  return (
    <div className="settings">
      <Bereiche onToast={onToast} refresh={setupSaved} onSaved={() => setFeaturesSaved((n) => n + 1)} />
      <Rollen onToast={onToast} refresh={featuresSaved} />
      <Wartung onToast={onToast} />
      <PushSettings onToast={onToast} />
      <Verbindungen onToast={onToast} onSetupSaved={() => setSetupSaved((n) => n + 1)} />
    </div>
  );
}

function Verbindungen({ onToast, onSetupSaved }: { onToast: (msg: string) => void; onSetupSaved: () => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.settings(), []);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  const onSaved = (n: AdminSettings) => {
    setData(n);
    onSetupSaved();
  };
  return (
    <>
      <Calendar settings={s} onSaved={onSaved} onToast={onToast} />
      <Gallery settings={s} onSaved={onSaved} onToast={onToast} />
      {s.news && <News settings={s} onSaved={onSaved} onToast={onToast} />}
    </>
  );
}

/** Benachrichtigungen: wie viele Geräte angemeldet sind und wann die Erinnerung an Termine am nächsten Tag kommt. */
function PushSettings({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.pushAdmin(), []);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const p = data!;

  async function setHour(hour: number) {
    setBusy(true);
    setSaveError(null);
    try {
      setData(await api.setPushReminderHour(hour));
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
        <select value={p.reminderHour} disabled={busy} onChange={(e) => setHour(Number(e.target.value))}>
          {Array.from({ length: 24 }, (_, h) => (
            <option key={h} value={h}>
              {String(h).padStart(2, '0')}:00 Uhr
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
        Jede Person wählt auf ihrem Gerät selbst, worüber sie benachrichtigt wird (Mein Konto → Benachrichtigungen).
      </p>
    </section>
  );
}

/** Scrollt zum Abschnitt eines Bereichs (Kalender, Galerie, News), z. B. aus „noch nicht eingerichtet“. */
function scrollToSetup(setup: string) {
  document.getElementById(`einstellungen-${setup}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
function Bereiche({ onToast, refresh, onSaved }: { onToast: (msg: string) => void; refresh: number; onSaved: () => void }) {
  const { data, error, loading, reload, setData } = useAsync(
    () =>
      api.features().then((list) => {
        applyFeatures(list);
        return list;
      }),
    [refresh],
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
      onSaved();
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

export function Calendar({ settings, onSaved, onToast }: SectionProps) {
  const [url, setUrl] = useState('');
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const c = settings.calendar;
  const status = {
    ics: '✓ Eingerichtet über die geheime iCal-Adresse.',
    google: '✓ Eingerichtet über die Google-API (Kalender-IDs und API-Schlüssel).',
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
        if (!url.trim()) return setError('Bitte zuerst die iCal-Adresse einfügen.');
        save({ calendar_ics_url: url.trim() }, () => setUrl(''));
      }}
    >
      <p className="muted small">
        In Google Kalender unter <em>Einstellungen → Kalender auswählen → Kalender integrieren</em> die{' '}
        <strong>geheime Adresse im iCal-Format</strong> kopieren und hier einfügen. Sie wird nicht wieder angezeigt.
      </p>
      <p className="small">{status}</p>
      <label className="field">
        <span>{c.source === 'none' ? 'Geheime iCal-Adresse' : 'Neue iCal-Adresse (ersetzt die bisherige Einrichtung)'}</span>
        <input
          type="url"
          inputMode="url"
          autoComplete="off"
          placeholder="https://calendar.google.com/calendar/ical/…/basic.ics"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
        />
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
              window.confirm('Kalender wirklich entfernen?') && save({ calendar_ics_url: '', calendar_ids: '', google_api_key: '' })
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
  const [key, setKey] = useState('');
  const [replaceKey, setReplaceKey] = useState(false);
  const [progress, setProgress] = useState<string | null>(null);
  const [syncing, setSyncing] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const showKeyField = !g.service_account_email || replaceKey;

  async function sync() {
    setSyncing(true);
    setError(null);
    try {
      // Erst abgleichen, dann so lange aufbereiten, bis nichts mehr aussteht.
      let r = await api.syncGallery(false);
      setProgress(`${r.albums} Alben, ${r.photos} Bilder gefunden – ${r.pending} Bilder werden noch aufbereitet …`);
      for (let round = 0; r.pending > 0 && r.processed > 0 && round < 50; round++) {
        r = await api.syncGallery(true);
        setProgress(`${r.albums} Alben, ${r.photos} Bilder – noch ${r.pending} Bilder …`);
      }
      const parts = [`${r.albums} Alben, ${r.photos} Bilder.`];
      if (r.pending > 0) parts.push(`${r.pending} Bilder werden stündlich automatisch weiter aufbereitet.`);
      if (r.failed > 0) parts.push(`${r.failed} Bilder ließen sich nicht laden; sie werden beim nächsten Abgleich erneut versucht.`);
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
      title="Galerie aus Google Drive"
      busy={busy}
      error={error}
      onSubmit={(e) => {
        e.preventDefault();
        save({ gallery_root: root.trim(), ...(key.trim() ? { drive_service_account: key.trim() } : {}) }, () => {
          setKey('');
          setReplaceKey(false);
        });
      }}
    >
      <p className="muted small">
        Den Galerie-Ordner in Google Drive für das Dienstkonto freigeben (Betrachter) und hier den Link zum Ordner eintragen. Darin je Jahr
        ein Ordner, darin je Album ein Ordner; Bilder im Unterordner <em>intern</em> sehen nur Mitglieder.
      </p>
      {g.service_account_email && (
        <div className="small">
          Dienstkonto: <code>{g.service_account_email}</code>
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
      )}
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
    </Section>
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
      title="Neuigkeiten"
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
