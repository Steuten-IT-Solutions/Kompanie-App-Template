import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { api, config, encodeFile } from '../api';
import { formatBytes } from '../format';
import { lastError, reportOrigin } from '../lastError';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';

const MAX_TEXT = 2000;
const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/** Knopf „Fehler melden“ auf der Hilfeseite und im Hilfe-Verzeichnis; nur, wenn die App Meldungen annimmt. */
export function ReportButton() {
  if (!config?.reports) return null;
  return (
    <button className="btn btn-secondary btn-block hilfe-melden" onClick={() => navigate('hilfe/melden')}>
      Fehler melden
    </button>
  );
}

/**
 * „Fehler melden“ (Issue #64): Beschreibung, Erwartung, Bildschirmfoto; vor dem Absenden steht da, was automatisch
 * mitgeht. Die Meldung geht an die Betreuer der App, nicht an die Kompanie. Erst wenn der Server sie gespeichert hat,
 * heißt es „angekommen“.
 */
export default function FehlerMelden() {
  const form = useAsync(() => api.reportForm(), []);
  const opened = useRef(Date.now());
  const [description, setDescription] = useState('');
  const [expected, setExpected] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [includeName, setIncludeName] = useState(false);
  const [consent, setConsent] = useState(false);
  const [website, setWebsite] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  // Zum Zeitpunkt des Öffnens festgehalten: genau das geht mit.
  const page = useMemo(() => reportOrigin() ?? '#/hilfe', []);
  const appError = useMemo(() => lastError(), []);
  const [now, setNow] = useState(() => new Date());
  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);
  const user = config?.user ?? null;

  useEffect(() => () => void (preview && URL.revokeObjectURL(preview)), [preview]);
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 30_000);
    return () => clearInterval(t);
  }, []);

  if (!config?.reports) {
    return <ErrorBox message="Fehlermeldungen sind in dieser App nicht eingerichtet." />;
  }
  if (form.loading && !form.data) return <Loading />;
  if (form.error || !form.data)
    return <ErrorBox message={form.error ?? 'Das Formular konnte nicht geladen werden.'} onRetry={form.reload} />;
  const f = form.data;

  if (done) {
    return (
      <div className="card report-done" role="status">
        <h2 className="section-title">Danke, deine Meldung ist angekommen</h2>
        <p>Die Betreuer der App schauen sie sich an. Eine Antwort bekommst du darüber nicht.</p>
        <button className="btn btn-block" onClick={() => navigate('hilfe')}>
          Zur Hilfe
        </button>
      </div>
    );
  }

  function choose(next: File | null) {
    setError(null);
    if (next && !TYPES.includes(next.type)) return setError('Als Bildschirmfoto gehen nur PNG-, JPEG- oder WebP-Bilder.');
    if (next && next.size > f.maxBytes) return setError(`Das Bildschirmfoto darf höchstens ${formatBytes(f.maxBytes)} groß sein.`);
    setFile(next);
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (description.trim() === '') return setError('Bitte beschreibe kurz, was nicht klappt.');
    if (!consent) return setError('Bitte bestätige unten, dass die Meldung an die Betreuer der App gehen darf.');
    setBusy(true);
    try {
      // Zeitfalle des Servers: Wer sehr schnell ist, wartet den Rest ab.
      const wait = f.minSeconds * 1000 + 300 - (Date.now() - opened.current);
      if (wait > 0) await new Promise((r) => setTimeout(r, wait));
      const shot = file ? await encodeFile(file) : null;
      await api.sendReport({
        token: f.token,
        consent,
        description,
        expected,
        page,
        userAgent: navigator.userAgent,
        lastError: appError?.message ?? null,
        includeName: !!user && includeName,
        screenshot: shot && file ? { type: file.type, data: shot.data } : null,
        website,
      });
      setDone(true);
      window.scrollTo(0, 0);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card report-form" onSubmit={submit} noValidate>
      <button type="button" className="btn btn-link back" onClick={() => navigate('hilfe')}>
        ‹ Alle Hilfeseiten
      </button>
      <h2 className="news-headline">Fehler melden</h2>
      <p className="muted small">
        Etwas klappt nicht? Beschreibe kurz, was passiert ist. Die Meldung geht an die Betreuer der App, nicht an die Verwaltung eurer
        Kompanie.
      </p>
      <label className="field">
        <span>Was klappt nicht? (Pflicht)</span>
        <textarea rows={5} maxLength={MAX_TEXT} required value={description} onChange={(e) => setDescription(e.target.value)} />
        <span className="muted small board-counter">
          {description.length}/{MAX_TEXT} Zeichen
        </span>
      </label>
      <label className="field">
        <span>Was hast du erwartet? (freiwillig)</span>
        <textarea rows={3} maxLength={MAX_TEXT} value={expected} onChange={(e) => setExpected(e.target.value)} />
      </label>
      <div className="field">
        <span>Bildschirmfoto (freiwillig)</span>
        {file && preview ? (
          <div className="report-shot">
            <img src={preview} alt="Gewähltes Bildschirmfoto" />
            <div>
              <div className="small">
                {file.name} · {formatBytes(file.size)}
              </div>
              <button type="button" className="btn btn-link" onClick={() => setFile(null)}>
                Entfernen
              </button>
            </div>
          </div>
        ) : (
          <input type="file" accept={TYPES.join(',')} aria-label="Bildschirmfoto" onChange={(e) => choose(e.target.files?.[0] ?? null)} />
        )}
        <span className="muted small">PNG, JPEG oder WebP, höchstens {formatBytes(f.maxBytes)}.</span>
      </div>
      {/* Gegen Programme: Menschen sehen dieses Feld nicht und lassen es leer. */}
      <label className="report-hp" aria-hidden="true">
        Webseite
        <input type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
      </label>
      {user && (
        <label className="toggle">
          <input type="checkbox" checked={includeName} onChange={(e) => setIncludeName(e.target.checked)} />
          <span>Meinen Namen mitschicken</span>
        </label>
      )}

      <section className="report-details" aria-label="Automatisch mitgeschickt">
        <h3>Das geht automatisch mit</h3>
        <dl>
          <dt>Seite in der App</dt>
          <dd>{page}</dd>
          <dt>App-Version</dt>
          <dd>{f.details.version}</dd>
          <dt>Gerät und Browser</dt>
          <dd>{navigator.userAgent}</dd>
          <dt>Rolle</dt>
          <dd>{f.details.role}</dd>
          {user && includeName && (
            <>
              <dt>Name</dt>
              <dd>{user.name}</dd>
            </>
          )}
          <dt>Uhrzeit</dt>
          <dd>{now.toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' })}</dd>
          <dt>Letzte Fehlermeldung der App</dt>
          <dd>{appError?.message ?? 'keine'}</dd>
        </dl>
      </section>

      <label className="toggle report-consent">
        <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
        <span>
          Ich bin einverstanden, dass diese Meldung mit den oben gezeigten Angaben{file ? ' und dem Bildschirmfoto' : ''} an die Betreuer
          der App (Steuten IT Solutions) geht und dort in einem privaten GitHub-Repository gespeichert wird.
        </span>
      </label>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Wird gesendet …' : 'Meldung senden'}
      </button>
    </form>
  );
}
