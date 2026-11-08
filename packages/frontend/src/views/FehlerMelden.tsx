import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { api, config, encodeFile } from '../api';
import { REPORT_STATUS, reportTime } from './Meldungen';
import { formatBytes } from '../format';
import { lastError, reportOrigin } from '../lastError';
import { navigate } from '../router';
import { ErrorBox, FileInput, Loading, useAsync } from '../ui';

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

/** Eigene Meldungen mit Stand und Begründung (nur angemeldet); unter dem Formular und nach dem Absenden. */
function MyReports({ refresh }: { refresh: number }) {
  const { data } = useAsync(() => api.myReports(), [refresh], 'reports-mine');
  if (!data || data.length === 0) return null;
  return (
    <section className="card my-reports" aria-label="Deine Meldungen">
      <h3 className="section-title">Deine Meldungen</h3>
      <ul>
        {data.map((r) => (
          <li key={r.id}>
            <div className="row-sub">
              {reportTime(r.created)} · <strong>{REPORT_STATUS[r.status]}</strong>
            </div>
            <div className="report-text">{r.description.length > 140 ? `${r.description.slice(0, 140)} …` : r.description}</div>
            {r.reason && (
              <div className="report-text">
                <span className="muted small">Antwort der Admins: </span>
                {r.reason}
              </div>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * „Fehler melden“ (Issue #64): Beschreibung, Erwartung, Bildschirmfoto; vor dem Absenden steht da, was automatisch
 * mitgeht. Die Meldung geht zuerst an die Admins der App; sie schließen sie mit Begründung oder leiten sie an die
 * Betreuer der App weiter. Erst wenn der Server sie gespeichert hat, heißt es „angekommen“.
 */
export default function FehlerMelden() {
  const form = useAsync(() => api.reportForm(), [], 'report-form');
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
  const [sent, setSent] = useState(0);
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
  const mine = user ? <MyReports refresh={sent} /> : null;
  if (form.loading && !form.data) return <Loading shape="form" />;
  if (form.error || !form.data)
    return <ErrorBox message={form.error ?? 'Das Formular konnte nicht geladen werden.'} onRetry={form.reload} />;
  const f = form.data;

  if (done) {
    return (
      <>
        <div className="card report-done" role="status">
          <h2 className="section-title">Danke, deine Meldung ist angekommen</h2>
          <p>
            Die Admins eurer App schauen sie sich an
            {f.forward !== false ? ' und leiten sie bei Bedarf an die Betreuer der App weiter' : ''}.
            {user
              ? ' Was daraus wird, siehst du hier unter „Deine Meldungen“; dazu bekommst du eine Benachrichtigung, wenn du sie eingeschaltet hast.'
              : ' Ohne Anmeldung bekommst du darüber keine Antwort.'}
          </p>
          <button className="btn btn-block" onClick={() => navigate('hilfe')}>
            Zur Hilfe
          </button>
        </div>
        {mine}
      </>
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
    if (!consent) return setError('Bitte bestätige unten, dass die Meldung an die Admins gehen darf.');
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
      setSent((n) => n + 1);
      window.scrollTo(0, 0);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <form className="form card report-form" onSubmit={submit} noValidate>
        <button type="button" className="btn btn-link back" onClick={() => navigate('hilfe')}>
          ‹ Alle Hilfeseiten
        </button>
        <h2 className="news-headline">Fehler melden</h2>
        <p className="muted small">
          Etwas klappt nicht? Beschreibe kurz, was passiert ist. Die Meldung geht zuerst an die Admins eurer App. Oft können sie gleich
          helfen{f.forward !== false ? '; sonst leiten sie die Meldung an die Betreuer der App weiter' : ''}.
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
            <FileInput accept={TYPES.join(',')} aria-label="Bildschirmfoto" onChange={(e) => choose(e.target.files?.[0] ?? null)} />
          )}
          <span className="muted small">PNG, JPEG oder WebP, höchstens {formatBytes(f.maxBytes)}.</span>
        </div>
        {/* Gegen Programme: Menschen sehen dieses Feld nicht und lassen es leer. */}
        <label className="report-hp" aria-hidden="true">
          Webseite
          <input type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
        </label>
        {user && f.forward !== false && (
          <label className="toggle">
            <input type="checkbox" checked={includeName} onChange={(e) => setIncludeName(e.target.checked)} />
            <span>Meinen Namen beim Weiterleiten an die Betreuer mitschicken</span>
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
            {user && (
              <>
                <dt>Name</dt>
                <dd>
                  {user.name}{' '}
                  <span className="muted small">
                    {f.forward === false
                      ? '(sehen die Admins)'
                      : includeName
                        ? '(sehen die Admins, geht beim Weiterleiten mit)'
                        : '(sehen nur die Admins)'}
                  </span>
                </dd>
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
            Ich bin einverstanden, dass diese Meldung mit den oben gezeigten Angaben{file ? ' und dem Bildschirmfoto' : ''} an die Admins
            dieser App geht
            {f.forward !== false
              ? ' und dass sie sie an die Betreuer der App (Steuten IT Solutions) weiterleiten dürfen, die sie in einem privaten GitHub-Repository speichern.'
              : '.'}
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
      {mine}
    </>
  );
}
