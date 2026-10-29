import { useEffect, useRef, useState, type ChangeEvent } from 'react';
import {
  api,
  ApiError,
  config,
  inBackground,
  type AdminStatus,
  type BackupDriveStatus,
  type BackupEncryptionStatus,
  type ErrorLogEntry,
  type UpdateProgress,
  type UpdateStatus,
  type UpdateStep,
} from '../api';
import { filePayload, isEncryptedFile } from '../backupFile';
import brand from '../generated/brand';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Wartung für Admins: Ampel (Status), Updates mit Zurückrollen, Statusmeldungen (nur mit
 * Zustimmung), Fehlerprotokoll und Datensicherung. Gilt für beide Varianten.
 */
/** Seiten „Zustand und Updates“ und „Datensicherung“ der Einstellungen. */
export default function Wartung({ part, onToast }: { part: 'zustand' | 'datensicherung'; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.status(), [], 'admin/status');
  if (part === 'datensicherung') return <DatensicherungSeite onToast={onToast} onChanged={reload} />;
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  return (
    <>
      <StatusCard status={s} />
      {/* key: nach dem Neuladen mit dem neuen Stand neu aufbauen */}
      <Updates key={JSON.stringify([s.updates, s.statusReports.enabled])} status={s} onChanged={reload} onToast={onToast} />
      <Fehlerprotokoll key={JSON.stringify(s.errorLog)} status={s} onToast={onToast} />
    </>
  );
}

const LEVEL_TEXT = { ok: 'in Ordnung', warn: 'bitte ansehen', error: 'Problem', off: 'nicht eingerichtet' } as const;

function StatusCard({ status }: { status: AdminStatus }) {
  return (
    <section className="card settings-section" aria-labelledby="status-title">
      <h3 id="status-title">Zustand der App</h3>
      <ul className="status-list">
        {status.items.map((i) => (
          <li key={i.key} className={`status-item status-${i.level}`}>
            <span className="status-dot" role="img" aria-label={LEVEL_TEXT[i.level]} />
            <span>
              <strong>{i.label}</strong>
              <br />
              <span className="small">{i.text}</span>
            </span>
          </li>
        ))}
      </ul>
      {status.support && (
        <p className="muted small">
          Hilfe: <a href={supportHref(status.support)}>{status.support}</a>
        </p>
      )}
    </section>
  );
}

/** E-Mail-Adresse oder Webadresse; alles andere nur als Text. */
function supportHref(s: string): string | undefined {
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s)) return `mailto:${s}`;
  if (/^https:\/\//i.test(s)) return s;
  return undefined;
}

function date(ts: number | null | undefined): string {
  return ts ? new Date(ts * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '–';
}

const STEP_TEXT: Record<UpdateStep, string> = {
  download: 'Paket wird geladen',
  backup: 'Daten werden gesichert',
  drive: 'Sicherung wird in Google Drive abgelegt',
  install: 'Neue Version wird eingespielt',
};

/** Abfrage des laufenden Updates alle … ms; nach höchstens … ms aufgeben (das Zeitlimit von PHP sind 5 Minuten). */
const POLL_MS = 3000;
const WAIT_MAX_MS = 20 * 60 * 1000;

/**
 * Antwort auf das Einspielen ging verloren, das Update läuft aber auf dem Server weiter: keine Verbindung (Netzwechsel),
 * Zeitlimit des Hosters vor PHP (Gateway-Fehler ohne Code der App) oder es läuft schon eines (z. B. nach erneutem Klick).
 */
function stillRunning(err: unknown): boolean {
  return (
    err instanceof ApiError &&
    (err.code === 'update_running' || err.status === 0 || (!err.code && [502, 503, 504, 520, 522, 524].includes(err.status)))
  );
}

function mb(bytes: number): string {
  return (bytes / 1024 / 1024).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}

/**
 * Anzeige je Schritt, innerhalb von Sicherung und Hochladen nach Bytes. Die Größe der Sicherung steht erst am Ende fest
 * (geschätzt aus der letzten): Der Balken bleibt dann vor dem Ende des Schritts stehen, bis der nächste beginnt.
 */
function UpdateProgressBar({ progress }: { progress: UpdateProgress | null }) {
  const index = progress ? progress.steps.indexOf(progress.step) : -1;
  const steps = progress?.steps.length ?? 0;
  const { done = null, total = null, estimate = false } = progress ?? {};
  const part = done !== null && total ? Math.min(done / total, estimate ? 0.95 : 1) : 0;
  const bytes = done === null ? '' : total ? ` (${mb(done)} von ${estimate ? 'ca. ' : ''}${mb(total)} MB)` : ` (${mb(done)} MB)`;
  return (
    <div className="small" role="status" aria-live="polite">
      {index >= 0 ? (
        <>
          <progress className="progress" value={index + part} max={steps} aria-label={`Update: Schritt ${index + 1} von ${steps}`} />
          <p className="muted">
            Schritt {index + 1} von {steps}: {STEP_TEXT[progress!.step]}
            {bytes} … Bitte die Seite offen lassen; das kann einige Minuten dauern.
          </p>
        </>
      ) : (
        <>
          <progress className="progress" aria-label="Update läuft" />
          <p className="muted">Das Update läuft … Bitte die Seite offen lassen; das kann einige Minuten dauern.</p>
        </>
      )}
    </div>
  );
}

function Updates({ status, onChanged, onToast }: { status: AdminStatus; onChanged: () => void; onToast: (msg: string) => void }) {
  const [u, setU] = useState<UpdateStatus>(status.updates);
  const [reports, setReports] = useState(status.statusReports.enabled);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [older, setOlder] = useState('');
  const [showPayload, setShowPayload] = useState(false);
  /** Fortschritt des laufenden Updates; undefined: keins läuft */
  const [progress, setProgress] = useState<UpdateProgress | null | undefined>(status.updates.running ? status.updates.progress : undefined);
  const alive = useRef(true);
  useEffect(
    () => () => {
      alive.current = false;
    },
    [],
  );

  async function run(label: string, fn: () => Promise<void>) {
    setBusy(label);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(null);
    }
  }

  /**
   * Fragt den Stand ab, solange auf dem Server ein Update läuft, und zeigt den Fortschritt. Danach: eingespielt → App neu
   * laden; gescheitert → der Stand zeigt „Letztes Update … hat nicht geklappt“.
   *
   * @param version Ziel des Updates; null: nur auf ein schon laufendes warten (z. B. nach dem Neuladen der Seite)
   * @param done Promise der eigenen Anfrage; solange sie läuft, nur den Fortschritt zeigen
   */
  async function follow(version: string | null, done?: Promise<unknown>): Promise<void> {
    let finished = !done;
    let failure: unknown = null;
    done?.then(
      () => (finished = true),
      (err) => {
        finished = true;
        failure = err;
      },
    );
    const before = u.current;
    const until = Date.now() + WAIT_MAX_MS;
    setProgress((p) => p ?? null);
    try {
      for (;;) {
        await new Promise((r) => setTimeout(r, done && finished ? 0 : POLL_MS));
        if (!alive.current) return;
        if (finished && done) {
          // Antwort auf die eigene Anfrage: eingespielt, oder abgebrochen, während das Update weiterläuft.
          if (!failure) return reloadApp(version!);
          if (!stillRunning(failure)) throw failure;
          done = undefined;
          continue;
        }
        let next: UpdateStatus;
        try {
          next = await inBackground(() => api.updateStatus());
        } catch {
          if (Date.now() > until) throw new Error('Der Server antwortet nicht. Bitte in ein paar Minuten den Zustand neu laden.');
          continue; // kurz nicht erreichbar (z. B. während die Dateien getauscht werden)
        }
        if (next.running) {
          setProgress(next.progress);
          if (Date.now() > until) throw new Error('Das Update läuft noch. Bitte in ein paar Minuten den Zustand neu laden.');
          continue;
        }
        if (done) continue; // Anfrage läuft noch, Sperre noch nicht gesetzt oder schon frei: auf die Antwort warten
        setU(next);
        // Eingespielt (auch ein anderes, das schon lief, z. B. das automatische): die neue App laden.
        if (next.current !== before) return reloadApp(next.current);
        if (version && !(next.last && !next.last.ok && next.last.to === version)) {
          throw new Error(`Version ${version} wurde nicht eingespielt. Bitte den Zustand neu laden und erneut versuchen.`);
        }
        return; // gescheitert: steht unter „Letztes Update … hat nicht geklappt“
      }
    } finally {
      if (alive.current) setProgress(undefined);
    }
  }

  /** Ein Update läuft schon (Seite neu geladen, automatisches Update, anderer Admin): warten und Fortschritt zeigen. */
  useEffect(() => {
    if (status.updates.running) void run('wait', () => follow(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /** Nach einem Update läuft im Browser noch die alte App: neu laden. */
  function reloadApp(version: string) {
    onToast(`Version ${version} ist eingespielt.`);
    setTimeout(() => window.location.reload(), 1500);
  }

  /**
   * Scheitert nur die Sicherung in Google Drive vor dem Update (die auf dem Server ist da), fragt die App nach:
   * trotzdem einspielen oder abbrechen.
   */
  async function askWithoutDrive(version: string, fn: (withoutDrive: boolean) => Promise<unknown>) {
    try {
      await follow(version, fn(false));
    } catch (err) {
      if (
        !(err instanceof ApiError && err.code === 'drive_backup_failed') ||
        !window.confirm(`${err.message}\n\nTrotzdem aktualisieren?`)
      ) {
        throw err;
      }
      await follow(version, fn(true));
    }
  }

  const install = (version: string, major = false) =>
    run('install', async () => {
      await askWithoutDrive(version, (withoutDrive) => api.installUpdate(version, major, withoutDrive));
    });

  const olderVersions = u.versions.filter((v) => compare(v.version, u.current) < 0);

  return (
    <section className="card settings-section" aria-labelledby="updates-title">
      <h3 id="updates-title">Updates</h3>
      {!u.configured ? (
        <p className="small">
          Diese Installation bekommt keine Updates über die App (Version {u.current}
          {config?.variant === 'wordpress' ? ', z. B. von Hand hochgeladen' : ''}).
        </p>
      ) : (
        <>
          <p className="small">
            Installiert: <strong>{u.current}</strong>
            {u.channel === 'early' && ' (Vorab-Kanal)'} · zuletzt geprüft: {date(u.checked_at)} ·{' '}
            <a href="#/einstellungen/neuerungen">Was ist neu</a>
          </p>
          {u.withdrawn &&
            (u.fallback ? (
              <>
                <p className="form-error small">
                  Diese Version wurde zurückgezogen.{' '}
                  {u.autoAllowed
                    ? `Version ${u.fallback} wird beim nächsten Abgleich automatisch eingespielt.`
                    : `Bitte auf Version ${u.fallback} wechseln.`}
                </p>
                <button type="button" className="btn btn-block" disabled={!!busy} onClick={() => install(u.fallback!)}>
                  {busy === 'install' ? 'Wird eingespielt …' : `Jetzt auf ${u.fallback} wechseln`}
                </button>
              </>
            ) : (
              <p className="form-error small">
                Diese Version wurde zurückgezogen, aber es gibt noch keine geprüfte Version zum Wechseln. Bitte bei den Betreuern der App
                melden.
              </p>
            ))}
          {u.held && (
            <div className="small" role="alert">
              <p className="form-error">
                Das automatische Update auf {u.held.version} wurde zurückgestellt ({date(u.held.at)}). {u.held.error}
              </p>
              <p className="muted">
                Trotzdem aktualisieren: Die Sicherung auf dem Server reicht. Abbrechen: Die App versucht es beim nächsten täglichen Abgleich
                wieder, mit Sicherung in Google Drive.
              </p>
              <div className="button-row">
                <button
                  type="button"
                  className="btn"
                  disabled={!!busy}
                  onClick={() =>
                    run('held', async () => {
                      await follow(u.held!.version, api.installHeldUpdate());
                    })
                  }
                >
                  {busy === 'held' ? 'Wird eingespielt …' : 'Trotzdem aktualisieren'}
                </button>
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={!!busy}
                  onClick={() => run('dismiss', async () => setU(await api.dismissHeldUpdate()))}
                >
                  Abbrechen
                </button>
              </div>
            </div>
          )}
          {progress !== undefined && <UpdateProgressBar progress={progress} />}
          {u.error && <p className="form-error small">Letzte Prüfung: {u.error}</p>}
          {u.last && !u.last.ok && u.last.to !== u.held?.version && (
            <p className="form-error small">
              Letztes Update auf {u.last.to} hat nicht geklappt: {u.last.error}
            </p>
          )}
          {u.available && u.latest ? (
            <>
              <p className="small">
                Neue Version: <strong>{u.latest}</strong>
                {u.skipped && ' – wird nach dem Zurückrollen nicht automatisch eingespielt, nur mit diesem Knopf.'}
              </p>
              {u.problems.map((p) => (
                <p key={p} className="form-error small">
                  {p}
                </p>
              ))}
              <button type="button" className="btn btn-block" disabled={!!busy || u.problems.length > 0} onClick={() => install(u.latest!)}>
                {busy === 'install' ? 'Wird eingespielt … (einige Minuten)' : `Jetzt auf ${u.latest} aktualisieren`}
              </button>
            </>
          ) : (
            !u.withdrawn && <p className="small">✓ Die App ist auf dem neuesten Stand.</p>
          )}
          {u.major && u.majorVersion && (
            <div className="small">
              <p>
                Neue Hauptversion <strong>{u.majorVersion}</strong> verfügbar. Sie wird nicht automatisch eingespielt; vorher bitte die
                Hinweise zur Version lesen. Vor dem Update werden die Daten automatisch gesichert. Zurück auf die bisherige Hauptversion
                geht es danach nicht per Klick, sondern nur mit unserer Hilfe.
              </p>
              <button
                type="button"
                className="btn btn-secondary"
                disabled={!!busy}
                onClick={() => window.confirm(`Neue Hauptversion ${u.majorVersion} jetzt einspielen?`) && install(u.majorVersion!, true)}
              >
                Hauptversion einspielen
              </button>
            </div>
          )}
          <div className="button-row">
            <button
              type="button"
              className="btn btn-secondary"
              disabled={!!busy}
              onClick={() => run('check', async () => setU(await api.checkUpdates()))}
            >
              {busy === 'check' ? 'Prüft …' : 'Nach Updates suchen'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={!!busy} onClick={onChanged}>
              Zustand neu laden
            </button>
          </div>
          <label className="toggle">
            <input
              type="checkbox"
              checked={u.auto}
              disabled={!!busy}
              onChange={(e) => run('auto', async () => setU(await api.updateOptions({ auto: e.target.checked })))}
            />
            <span>Updates automatisch einspielen (empfohlen)</span>
          </label>
          <label className="toggle">
            <input
              type="checkbox"
              checked={u.channel === 'early'}
              disabled={!!busy}
              onChange={(e) =>
                (!e.target.checked ||
                  window.confirm(
                    'Vorabversionen erhalten? Diese Installation bekommt dann jede neue Version, bevor sie für alle freigegeben ist – zum Testen, kann noch Fehler enthalten.',
                  )) &&
                run('channel', async () => setU(await api.updateOptions({ channel: e.target.checked ? 'early' : 'stable' })))
              }
            />
            <span>Vorabversionen erhalten (zum Testen)</span>
          </label>
          {u.channel === 'early' && (
            <p className="muted small">
              Diese Installation bekommt jede neue Version, sobald sie gebaut ist, nicht erst nach der Freigabe für alle. Ausschalten: Sie
              bleibt auf der jetzigen Version, bis eine neuere freigegeben ist.
            </p>
          )}
          {!u.autoAllowed && (
            <p className="muted small">
              Automatische Updates sind in dieser Installation gesperrt (z. B. in den Einstellungen von WordPress). Neue Versionen bitte
              hier von Hand einspielen.
            </p>
          )}
          {olderVersions.length > 0 && (
            <details className="small">
              <summary>Auf eine ältere Version zurück</summary>
              <p className="muted">
                Hilft, wenn nach einem Update etwas nicht mehr klappt. Die jetzige Version wird danach übersprungen, bis eine neuere
                erscheint.
              </p>
              <label className="field">
                <span>Version</span>
                <select value={older} onChange={(e) => setOlder(e.target.value)}>
                  <option value="">Bitte wählen</option>
                  {olderVersions.map((v) => (
                    <option key={v.version} value={v.version}>
                      {v.version} {v.published && `(${v.published})`}
                    </option>
                  ))}
                </select>
              </label>
              <button
                type="button"
                className="btn btn-danger"
                disabled={!!busy || !older}
                onClick={() =>
                  window.confirm(`Auf Version ${older} zurückgehen?`) &&
                  run('rollback', async () => {
                    await askWithoutDrive(older, (withoutDrive) => api.rollback(older, withoutDrive));
                  })
                }
              >
                {busy === 'rollback' ? 'Wird eingespielt …' : 'Zurückrollen'}
              </button>
            </details>
          )}
        </>
      )}
      {status.statusReports.available && (
        <>
          <label className="toggle">
            <input
              type="checkbox"
              checked={reports}
              disabled={!!busy}
              onChange={(e) => {
                const on = e.target.checked;
                run('reports', async () => {
                  await api.updateOptions({ status_reports: on });
                  setReports(on);
                });
              }}
            />
            <span>
              Statusmeldungen an die Betreuer der App senden: stündlich ein Lebenszeichen mit Nutzungszahlen (Zahl der Konten, aktive
              Konten, Personen je Bereich), schwere Fehler sofort, jeweils mit Fehlercode und Ursache (z. B. „Kalender: HTTP 404“), ohne
              Namen, Inhalte oder Fehlertexte
            </span>
          </label>
          {reports && (
            <p className="small muted status-last">
              Letzte Meldung:{' '}
              {status.statusReports.last
                ? `${date(status.statusReports.last.at)}${status.statusReports.last.ok ? '' : ' (nicht angekommen, wird beim nächsten Mal erneut versucht)'}`
                : 'noch keine'}
            </p>
          )}
          <button type="button" className="btn btn-link small" onClick={() => setShowPayload(!showPayload)}>
            {showPayload ? 'Inhalt ausblenden' : 'Was genau gesendet wird (Beispiel: Lebenszeichen)'}
          </button>
          {showPayload && <pre className="small payload">{JSON.stringify(status.statusReports.payload, null, 2)}</pre>}
        </>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

const CODE_TEXT: Record<string, string> = {
  UPDATE_FAILED: 'Update gescheitert',
  ROLLBACK: 'Zurückgerollt',
  UPDATE_CHECK: 'Update-Liste nicht abrufbar',
  CALENDAR_FETCH: 'Kalender nicht abrufbar',
  GALLERY_SYNC: 'Galerie-Abgleich gescheitert',
  TASK_FAILED: 'Hintergrundaufgabe abgebrochen',
  SERVER_ERROR: 'Fehler bei einer Anfrage',
  DB_ERROR: 'Datenbankfehler',
  PHP_FATAL: 'Schwerer PHP-Fehler',
  DISK_LOW: 'Wenig Speicherplatz',
  CHECK_ERROR: 'Ampel auf Rot',
};

/** Ein Eintrag als Text zum Weitergeben (Zeit, Code, Ursache, Anzahl, Meldung). */
function logLine(e: ErrorLogEntry): string {
  const when = e.count > 1 ? `${date(e.first)} bis ${date(e.at)}, ${e.count}×` : date(e.at);
  return `${when} ${e.code}${e.detail ? ` (${e.detail})` : ''}${e.message ? `: ${e.message}` : ''}`;
}

/**
 * Fehlerprotokoll der Installation mit den Fehlertexten. Es bleibt auf dem Server; zum Weitergeben an die
 * Betreuer lässt es sich kopieren.
 */
function Fehlerprotokoll({ status, onToast }: { status: AdminStatus; onToast: (msg: string) => void }) {
  const [log, setLog] = useState<ErrorLogEntry[]>(status.errorLog);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Ohne Zugriff auf die Zwischenablage (z. B. ohne HTTPS): Text zum Markieren zeigen.
  const [showText, setShowText] = useState(false);
  const text = [
    `${brand.name} ${status.updates.current} (${config?.variant ?? ''}), Fehlerprotokoll vom ${date(Date.now() / 1000)}`,
    ...log.map(logLine),
  ].join('\n');

  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      onToast('Fehlerprotokoll kopiert');
    } catch {
      setShowText(true);
    }
  }

  async function clear() {
    if (!window.confirm('Fehlerprotokoll leeren?')) return;
    setBusy(true);
    setError(null);
    try {
      setLog((await api.clearErrorLog()).errorLog);
      setShowText(false);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" aria-labelledby="errorlog-title">
      <h3 id="errorlog-title">Fehlerprotokoll</h3>
      <p className="small muted">
        Die letzten Fehler dieser Installation mit Fehlertext (höchstens 14 Tage). Das Protokoll bleibt auf eurem Server. Wenn die Betreuer
        der App bei einem Problem helfen sollen, kopiere es und schicke es ihnen
        {status.support ? (
          <>
            {' '}
            (<a href={supportHref(status.support)}>{status.support}</a>)
          </>
        ) : null}
        . Vorher kurz durchsehen: Fehlertexte können Namen enthalten.
      </p>
      {log.length === 0 ? (
        <p className="small">Keine Fehler in den letzten 14 Tagen.</p>
      ) : (
        <>
          <ul className="error-log">
            {log.map((e, i) => (
              <li key={i}>
                <span className="small muted">{e.count > 1 ? `${date(e.first)} bis ${date(e.at)} · ${e.count}×` : date(e.at)}</span>
                <br />
                <strong>{CODE_TEXT[e.code] ?? e.code}</strong>
                {e.detail && <span className="small muted"> ({e.detail})</span>}
                {e.message && <span className="small error-log-message">{e.message}</span>}
              </li>
            ))}
          </ul>
          <div className="button-row">
            <button type="button" className="btn" onClick={copy}>
              Kopieren
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={clear}>
              {busy ? 'Wird geleert …' : 'Leeren'}
            </button>
          </div>
          {showText && <textarea className="payload small" readOnly rows={8} value={text} aria-label="Fehlerprotokoll als Text" />}
        </>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

/** Vergleicht Versionen wie 1.2.3.4 (Teil für Teil als Zahl). */
export function compare(a: string, b: string): number {
  const pa = a.split('.').map(Number);
  const pb = b.split('.').map(Number);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (d !== 0) return d;
  }
  return 0;
}

/** Datensicherung mit Passwort für Sicherungen; das Passwort bestimmt Download und Google Drive. */
function DatensicherungSeite({ onToast, onChanged }: { onToast: (msg: string) => void; onChanged: () => void }) {
  const { data, error, loading, reload } = useAsync(() => api.backupEncryption(), [], 'admin/backup/encryption');
  const [enc, setEnc] = useState<BackupEncryptionStatus | null>(null);
  if (loading && !data) return <Loading />;
  const e = enc ?? data;
  // Ohne den Stand des Passworts bleiben Herunterladen (unverschlüsselt) und Wiederherstellen trotzdem möglich.
  return (
    <>
      {/* Ohne Stand (Fehler): ausdrücklich „unverschlüsselt“, nicht stillschweigend */}
      <Datensicherung onToast={onToast} onChanged={onChanged} encrypted={e ? e.available && e.configured : null} />
      {e ? (
        <Verschluesselung status={e} onChange={setEnc} onToast={onToast} />
      ) : (
        <ErrorBox message={error ?? 'Nicht geladen'} onRetry={reload} />
      )}
      {/* key: nach einer Änderung am Passwort den Zustand von Drive neu laden (pausiert oder nicht) */}
      <DriveSicherung key={String(e?.configured)} onToast={onToast} noSodium={e?.available === false} />
    </>
  );
}

function Datensicherung({
  onToast,
  onChanged,
  encrypted,
}: {
  onToast: (msg: string) => void;
  onChanged: () => void;
  /** null: Stand des Passworts unbekannt (nicht geladen) */
  encrypted: boolean | null;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Verschlüsselte Datei zum Wiederherstellen: wartet auf das Passwort für Sicherungen.
  const [pending, setPending] = useState<File | null>(null);
  const [passphrase, setPassphrase] = useState('');
  const fileInput = useRef<HTMLInputElement>(null);

  async function download(plain = false) {
    setError(null);
    // Als Datei über die eigene Adresse: Der Server schreibt die Sicherung Stück für Stück, weder er noch der Browser
    // braucht sie ganz im Arbeitsspeicher (Fotos und Dokumente machen sie schnell groß). Mit Passwort für Sicherungen
    // verschlüsselt, außer auf ausdrücklichen Wunsch.
    if (config?.backupUrl) {
      const a = document.createElement('a');
      const url = config.backupUrl;
      a.href = encrypted && !plain ? `${url}${url.includes('?') ? '&' : '?'}verschluesselt=1` : url;
      a.download = '';
      document.body.appendChild(a);
      a.click();
      a.remove();
      onToast('Sicherung wird heruntergeladen');
      // Die Ampel zählt die Sicherung, sobald sie ganz geschrieben ist.
      setTimeout(onChanged, 5000);
      return;
    }
    if (encrypted && !plain) {
      setError('Verschlüsselt herunterladen geht nur über die Adresse der App; bitte die Seite neu laden.');
      return;
    }
    setBusy(true);
    try {
      const data = await api.backup();
      const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `sicherung-${new Date().toISOString().slice(0, 10)}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 10000);
      onToast('Sicherung heruntergeladen');
      onChanged();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function restore(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (
      !window.confirm(
        `Alle Daten der App durch die Sicherung „${file.name}“ ersetzen? Das lässt sich nur mit einer anderen Sicherung rückgängig machen.`,
      )
    )
      return;
    setError(null);
    if (await isEncryptedFile(file)) {
      setPending(file);
      setPassphrase('');
      return;
    }
    await send(file, false);
  }

  async function send(file: File, encrypted: boolean, pass?: string) {
    setBusy(true);
    setError(null);
    try {
      await api.restoreBackup(await filePayload(file, encrypted), pass);
      setPending(null);
      onToast('Sicherung wiederhergestellt');
      setTimeout(() => window.location.reload(), 1500);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" aria-labelledby="backup-title">
      <h3 id="backup-title">Datensicherung</h3>
      <p className="muted small">
        Enthält Termine, Punkte, Kasse, Galerie-Verzeichnis, Dokumente, Abzeichen und Einstellungen, die Konten (Passwörter als Prüfwerte,
        aus denen sich einfache Passwörter erraten lassen) und Zugangsschlüssel. Die Datei bitte wie ein Passwort behandeln und nicht nur
        auf dem Server aufbewahren. Vor jedem Update sichert die App automatisch auf dem Server und, wenn die Sicherungen in Google Drive
        eingeschaltet sind, auch dort.
      </p>
      <div className="button-row">
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => void download()}>
          {encrypted
            ? 'Sicherung verschlüsselt herunterladen'
            : encrypted === null
              ? 'Sicherung unverschlüsselt herunterladen'
              : 'Sicherung herunterladen'}
        </button>
        {encrypted && (
          <button type="button" className="btn btn-link" disabled={busy} onClick={() => void download(true)}>
            unverschlüsselt herunterladen
          </button>
        )}
        <button type="button" className="btn btn-link" disabled={busy} onClick={() => fileInput.current?.click()}>
          Sicherung wiederherstellen …
        </button>
      </div>
      {encrypted && (
        <p className="muted small">
          Bricht das Herunterladen ab, ist die Datei unvollständig (die App erkennt das beim Wiederherstellen); der Grund steht unter
          Einstellungen → Zustand und Updates im Fehlerprotokoll.
        </p>
      )}
      <input
        ref={fileInput}
        type="file"
        accept="application/json,application/octet-stream,.json,.enc"
        hidden
        onChange={(e) => void restore(e)}
      />
      {pending && (
        <form
          className="form"
          onSubmit={(e) => {
            e.preventDefault();
            void send(pending, true, passphrase);
          }}
        >
          <p className="small">„{pending.name}“ ist verschlüsselt. Zum Wiederherstellen bitte das Passwort für Sicherungen eingeben.</p>
          <label className="field">
            <span>Passwort für Sicherungen</span>
            <input type="password" autoComplete="off" value={passphrase} onChange={(e) => setPassphrase(e.target.value)} required />
          </label>
          <div className="button-row">
            <button type="submit" className="btn btn-primary" disabled={busy || passphrase === ''}>
              {busy ? 'Stellt wieder her …' : 'Wiederherstellen'}
            </button>
            <button type="button" className="btn btn-link" disabled={busy} onClick={() => setPending(null)}>
              Abbrechen
            </button>
          </div>
        </form>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

/**
 * Passwort für Sicherungen: Sicherungen außerhalb des Servers (Download, Google Drive) sind damit verschlüsselt. Die App
 * speichert nur einen öffentlichen Schlüssel; ohne das Passwort lässt sich eine verschlüsselte Sicherung nicht
 * wiederherstellen, auch nicht von den Betreuern.
 */
function Verschluesselung({
  status,
  onChange,
  onToast,
}: {
  status: BackupEncryptionStatus;
  onChange: (s: BackupEncryptionStatus) => void;
  onToast: (msg: string) => void;
}) {
  const [mode, setMode] = useState<'view' | 'set' | 'check'>('view');
  const [pass, setPass] = useState('');
  const [repeat, setRepeat] = useState('');
  const [kept, setKept] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function open(next: 'view' | 'set' | 'check') {
    setMode(next);
    setPass('');
    setRepeat('');
    setKept(false);
    setError(null);
  }

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" aria-labelledby="backup-enc-title">
      <h3 id="backup-enc-title">Passwort für Sicherungen</h3>
      <p className="muted small">
        Mit diesem Passwort sind heruntergeladene Sicherungen und die Sicherungen in Google Drive verschlüsselt: Wer die Datei findet, kann
        sie ohne das Passwort nicht lesen. Die App speichert das Passwort nicht.{' '}
        <strong>
          Ohne das Passwort lässt sich eine verschlüsselte Sicherung nicht wiederherstellen, auch nicht von den Betreuern der App.
        </strong>{' '}
        Bitte an einem sicheren Ort aufbewahren (z. B. im Passwort-Manager oder ausgedruckt im Tresor). Die Sicherungen vor Updates auf dem
        Server bleiben unverschlüsselt.
      </p>
      {!status.available ? (
        <p className="small">Auf diesem Server fehlt PHP die Erweiterung sodium; bitte beim Hoster nachfragen.</p>
      ) : status.configured && mode === 'view' ? (
        <>
          <p className="small">
            Eingerichtet{status.set_at ? ` am ${date(status.set_at)}` : ''}. Fingerabdruck: <code>{status.fingerprint}</code>
          </p>
          {!status.verified && (
            <p className="small">
              Bitte das Passwort einmal mit „Passwort prüfen“ bestätigen. Erst danach löscht die App ältere Sicherungen in Google Drive
              (auch die unverschlüsselten); bis dahin bleiben alle.
            </p>
          )}
          <div className="button-row">
            <button type="button" className="btn btn-secondary" onClick={() => open('check')}>
              Passwort prüfen
            </button>
            <button type="button" className="btn btn-link" onClick={() => open('set')}>
              Ändern
            </button>
            <button
              type="button"
              className="btn btn-link"
              disabled={busy}
              onClick={() => {
                if (
                  window.confirm(
                    'Passwort für Sicherungen entfernen? Neue Sicherungen sind dann unverschlüsselt, und die Sicherungen in Google Drive pausieren. Ältere verschlüsselte Sicherungen brauchen weiter das bisherige Passwort.',
                  )
                )
                  void run(async () => {
                    onChange(await api.removeBackupEncryption());
                    onToast('Passwort für Sicherungen entfernt');
                  });
              }}
            >
              Entfernen
            </button>
          </div>
        </>
      ) : mode === 'check' ? (
        <form
          className="form"
          onSubmit={(e) => {
            e.preventDefault();
            void run(async () => {
              const { ok } = await api.verifyBackupEncryption(pass);
              if (ok) {
                onToast('Das Passwort für Sicherungen stimmt');
                onChange(await api.backupEncryption()); // jetzt geprüft: Hinweis weg
                open('view');
              } else setError('Das Passwort stimmt nicht.');
            });
          }}
        >
          <label className="field">
            <span>Passwort für Sicherungen</span>
            <input type="password" autoComplete="off" value={pass} onChange={(e) => setPass(e.target.value)} required />
          </label>
          <div className="button-row">
            <button type="submit" className="btn btn-primary" disabled={busy || pass === ''}>
              Prüfen
            </button>
            <button type="button" className="btn btn-link" onClick={() => open('view')}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : mode === 'set' || !status.configured ? (
        <form
          className="form"
          onSubmit={(e) => {
            e.preventDefault();
            void run(async () => {
              onChange(await api.setBackupEncryption(pass, repeat));
              onToast('Passwort für Sicherungen eingerichtet');
              open('view');
            });
          }}
        >
          {status.configured && (
            <p className="small">
              Das neue Passwort gilt für neue Sicherungen. Ältere verschlüsselte Sicherungen brauchen weiter das bisherige Passwort.
            </p>
          )}
          <label className="field">
            <span>Neues Passwort für Sicherungen (mind. {status.min_length} Zeichen)</span>
            <input
              type="password"
              autoComplete="new-password"
              minLength={status.min_length}
              value={pass}
              onChange={(e) => setPass(e.target.value)}
              required
            />
          </label>
          <label className="field">
            <span>Passwort wiederholen</span>
            <input type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} required />
          </label>
          <label className="toggle">
            <input type="checkbox" checked={kept} onChange={(e) => setKept(e.target.checked)} />
            <span>Ich habe das Passwort sicher aufbewahrt. Ohne es lässt sich keine verschlüsselte Sicherung wiederherstellen.</span>
          </label>
          <div className="button-row">
            <button
              type="submit"
              className="btn btn-primary"
              // Zeichen wie auf dem Server (mb_strlen), nicht UTF-16-Einheiten
              disabled={busy || !kept || [...pass].length < status.min_length || pass !== repeat}
            >
              {busy ? 'Richtet ein …' : 'Passwort einrichten'}
            </button>
            {status.configured && (
              <button type="button" className="btn btn-link" onClick={() => open('view')}>
                Abbrechen
              </button>
            )}
          </div>
        </form>
      ) : null}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}

/** Sicherungen automatisch in Google Drive (wöchentlich, die letzten acht bleiben). */
function DriveSicherung({ onToast, noSodium }: { onToast: (msg: string) => void; noSodium: boolean }) {
  const { data, error, loading, reload } = useAsync(() => api.backupDrive(), [], 'admin/backup/drive');
  const [state, setState] = useState<BackupDriveStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const d = state ?? data!;

  async function run(fn: () => Promise<BackupDriveStatus>, done?: string) {
    setBusy(true);
    setActionError(null);
    try {
      setState(await fn());
      if (done) onToast(done);
    } catch (err) {
      setActionError((err as Error).message);
      // Der Fehler steht auch im Zustand (z. B. beim Hochladen); neu laden zeigt ihn.
      setState(await api.backupDrive().catch(() => d));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" aria-labelledby="backup-drive-title">
      <h3 id="backup-drive-title">Sicherungen in Google Drive</h3>
      <p className="muted small">
        Damit nach dem Verlust des Servers eine aktuelle Sicherung woanders liegt: Die App legt einmal pro{' '}
        {d.every_days === 7 ? 'Woche' : `${d.every_days} Tage`} eine Sicherung in einem eigenen Ordner im verbundenen Google-Konto ab und
        behält die letzten {d.keep}. Die Sicherungen gehen nur verschlüsselt mit dem Passwort für Sicherungen dorthin; den Ordner trotzdem
        bitte nicht freigeben.
      </p>
      {d.needs_passphrase && (
        <p className={d.enabled ? 'form-error' : 'small'} role={d.enabled ? 'alert' : undefined}>
          {d.enabled ? 'Pausiert: ' : ''}
          {noSodium
            ? 'Zum Verschlüsseln fehlt PHP auf diesem Server die Erweiterung sodium; bitte beim Hoster nachfragen.'
            : 'Bitte zuerst oben ein Passwort für Sicherungen einrichten.'}
        </p>
      )}
      {!d.available ? (
        <p className="small">
          Dafür braucht die App eine Verbindung über „Mit Google verbinden“ (<a href="#/einstellungen/google">Google-Zugang</a>).
        </p>
      ) : !d.access ? (
        <p className="small">
          Die Verbindung zu Google erlaubt noch nicht, Dateien anzulegen. Bitte unter <a href="#/einstellungen/google">Google-Zugang</a> die
          Verbindung trennen und neu mit Google verbinden; dabei das Häkchen für eigene Dateien in Google Drive setzen.
        </p>
      ) : null}
      {(d.available && d.access) || d.enabled ? (
        <label className="toggle">
          <input
            type="checkbox"
            checked={d.enabled}
            disabled={busy || (!d.enabled && !(d.available && d.access && !d.needs_passphrase))}
            onChange={(e) => {
              const on = e.target.checked;
              void run(
                () => api.setBackupDrive(on),
                on ? 'Sicherungen in Google Drive eingeschaltet' : 'Sicherungen in Google Drive ausgeschaltet',
              );
            }}
          />
          <span>Wöchentlich in Google Drive sichern{d.email ? ` (${d.email})` : ''}</span>
        </label>
      ) : null}
      {d.enabled && (
        <p className="small">
          Letzte Sicherung in Google Drive: {date(d.last_ok)}
          {d.folder_url && (
            <>
              {' · '}
              <a href={d.folder_url} target="_blank" rel="noopener noreferrer">
                Ordner öffnen
              </a>
            </>
          )}
        </p>
      )}
      {d.enabled && d.error && (
        <p className="form-error" role="alert">
          Letzter Versuch ({date(d.last_try)}) fehlgeschlagen: {d.error}
        </p>
      )}
      {d.enabled && d.access && !d.needs_passphrase && (
        <div className="button-row">
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy}
            onClick={() => void run(() => api.uploadBackupDrive(), 'Sicherung in Google Drive abgelegt')}
          >
            {busy ? 'Lädt hoch …' : 'Jetzt in Google Drive sichern'}
          </button>
        </div>
      )}
      {actionError && !(d.enabled && d.error === actionError) && (
        <p className="form-error" role="alert">
          {actionError}
        </p>
      )}
    </section>
  );
}
