import { useRef, useState, type ChangeEvent } from 'react';
import { api, config, type AdminStatus, type UpdateStatus } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Wartung für Admins: Ampel (Status), Updates mit Zurückrollen, Statusmeldungen (nur mit
 * Zustimmung) und Datensicherung. Gilt für beide Varianten.
 */
/** Seiten „Zustand und Updates“ und „Datensicherung“ der Einstellungen. */
export default function Wartung({ part, onToast }: { part: 'zustand' | 'datensicherung'; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.status(), []);
  if (part === 'datensicherung') return <Datensicherung onToast={onToast} onChanged={reload} />;
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const s = data!;
  return (
    <>
      <StatusCard status={s} />
      {/* key: nach dem Neuladen mit dem neuen Stand neu aufbauen */}
      <Updates key={JSON.stringify([s.updates, s.statusReports.enabled])} status={s} onChanged={reload} onToast={onToast} />
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

function Updates({ status, onChanged, onToast }: { status: AdminStatus; onChanged: () => void; onToast: (msg: string) => void }) {
  const [u, setU] = useState<UpdateStatus>(status.updates);
  const [reports, setReports] = useState(status.statusReports.enabled);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [older, setOlder] = useState('');
  const [showPayload, setShowPayload] = useState(false);

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

  /** Nach einem Update läuft im Browser noch die alte App: neu laden. */
  function reloadApp(version: string) {
    onToast(`Version ${version} ist eingespielt.`);
    setTimeout(() => window.location.reload(), 1500);
  }

  const install = (version: string, major = false) =>
    run('install', async () => {
      await api.installUpdate(version, major);
      reloadApp(version);
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
            {u.channel === 'early' && ' (Vorab-Kanal)'} · zuletzt geprüft: {date(u.checked_at)}
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
          {u.error && <p className="form-error small">Letzte Prüfung: {u.error}</p>}
          {u.last && !u.last.ok && (
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
                {busy === 'install' ? 'Wird eingespielt … (bis zu 2 Minuten)' : `Jetzt auf ${u.latest} aktualisieren`}
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
                    await api.rollback(older);
                    reloadApp(older);
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
              Statusmeldungen an die Betreuer der App senden: stündlich ein Lebenszeichen, schwere Fehler sofort (ohne Namen, Inhalte oder
              Fehlertexte)
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

function Datensicherung({ onToast, onChanged }: { onToast: (msg: string) => void; onChanged: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  async function download() {
    setBusy(true);
    setError(null);
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
    setBusy(true);
    setError(null);
    try {
      await api.restoreBackup(await file.text());
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
        Enthält Termine, Punkte, Kasse, Galerie-Verzeichnis und Einstellungen
        {config?.variant === 'standalone'
          ? ', die Konten (Passwörter als Prüfwerte, aus denen sich einfache Passwörter erraten lassen)'
          : ''}{' '}
        und Zugangsschlüssel. Die Datei bitte wie ein Passwort behandeln. Vor Updates mit Datenbank-Änderungen sichert die App automatisch.
        {config?.features.documents && ' Die Dateien der Dokumente sind nicht enthalten (nur ihre Angaben); sie bleiben auf dem Server.'}
      </p>
      <div className="button-row">
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={download}>
          Sicherung herunterladen
        </button>
        <button type="button" className="btn btn-link" disabled={busy} onClick={() => fileInput.current?.click()}>
          Sicherung wiederherstellen …
        </button>
      </div>
      <input ref={fileInput} type="file" accept="application/json,.json" hidden onChange={restore} />
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}
