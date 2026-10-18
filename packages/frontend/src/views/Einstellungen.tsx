import { useState, type FormEvent, type ReactNode } from 'react';
import { api, type AdminSettings, type NewsSourceType } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Einstellungen der eigenständigen Version (bei WordPress zeigt die App sie nicht; dort im WP-Admin):
 * Kalender, Galerie und Neuigkeiten. Geheime Werte zeigt der Server nie an, nur ob sie gesetzt sind.
 * (Die Endpunkte /admin/* gibt es auch im WordPress-Plugin; dort pflegt man sie aber im WP-Admin.)
 */
export default function Einstellungen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.settings(), []);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  return (
    <div className="settings">
      <Calendar settings={s} onSaved={setData} onToast={onToast} />
      <Gallery settings={s} onSaved={setData} onToast={onToast} />
      {s.news && <News settings={s} onSaved={setData} onToast={onToast} />}
    </div>
  );
}

type SectionProps = { settings: AdminSettings; onSaved: (s: AdminSettings) => void; onToast: (msg: string) => void };

function Section({ title, children, onSubmit, busy, error }: { title: string; children: ReactNode; onSubmit: (e: FormEvent) => void; busy: boolean; error: string | null }) {
  return (
    <form className="form card settings-section" onSubmit={onSubmit}>
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

function Calendar({ settings, onSaved, onToast }: SectionProps) {
  const [url, setUrl] = useState('');
  const [result, setResult] = useState<string | null>(null);
  const [testing, setTesting] = useState(false);
  const { busy, error, setError, save } = useSave(onSaved, onToast);
  const c = settings.calendar;
  const status = { ics: '✓ Eingerichtet über die geheime iCal-Adresse.', google: '✓ Eingerichtet über die Google-API (Kalender-IDs und API-Schlüssel).', none: 'Noch kein Kalender eingerichtet.' }[c.source];

  async function test() {
    setTesting(true);
    setResult(null);
    setError(null);
    try {
      const r = await api.testCalendar();
      setResult(r.count === 0 ? 'Verbindung klappt, aber es gibt keine kommenden Termine.' : `${r.count} Termine gefunden, z. B. „${r.sample[0].title}“.`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setTesting(false);
    }
  }

  return (
    <Section
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
        <input type="url" inputMode="url" autoComplete="off" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics" value={url} onChange={(e) => setUrl(e.target.value)} />
      </label>
      {c.source !== 'none' && (
        <div className="button-row">
          <button type="button" className="btn btn-secondary" onClick={test} disabled={testing}>
            {testing ? 'Prüft …' : 'Kalender testen'}
          </button>
          <button
            type="button"
            className="btn btn-link"
            onClick={() => window.confirm('Kalender wirklich entfernen?') && save({ calendar_ics_url: '', calendar_ids: '', google_api_key: '' })}
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

function Gallery({ settings, onSaved, onToast }: SectionProps) {
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
        Den Galerie-Ordner in Google Drive für das Dienstkonto freigeben (Betrachter) und hier den Link zum Ordner eintragen.
        Darin je Jahr ein Ordner, darin je Album ein Ordner; Bilder im Unterordner <em>intern</em> sehen nur Mitglieder.
      </p>
      {g.service_account_email && (
        <div className="small">
          Dienstkonto: <code>{g.service_account_email}</code>
          <div className="button-row">
            <button type="button" className="btn btn-link" onClick={() => setReplaceKey(!replaceKey)}>
              {replaceKey ? 'Schlüssel behalten' : 'Schlüssel ersetzen'}
            </button>
            <button type="button" className="btn btn-link" onClick={() => window.confirm('Schlüssel des Dienstkontos entfernen?') && save({ drive_service_account: '' })}>
              Schlüssel entfernen
            </button>
          </div>
        </div>
      )}
      {showKeyField && (
        <label className="field">
          <span>{g.service_account_email ? 'Neuer Schlüssel des Dienstkontos (JSON)' : 'Schlüssel des Dienstkontos (JSON)'}</span>
          <textarea rows={4} spellCheck={false} value={key} onChange={(e) => setKey(e.target.value)} placeholder='{"type": "service_account", …}' />
        </label>
      )}
      <label className="field">
        <span>Link zum Galerie-Ordner</span>
        <input type="text" autoComplete="off" placeholder="https://drive.google.com/drive/folders/…" value={root} onChange={(e) => setRoot(e.target.value)} />
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

function News({ settings, onSaved, onToast }: SectionProps) {
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
          <input type="url" inputMode="url" required placeholder={source === 'wordpress' ? 'https://www.beispiel.de' : 'https://www.beispiel.de/feed'} value={url} onChange={(e) => setUrl(e.target.value)} />
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
