import { useState } from 'react';
import { api, applyMaintenance, config, toLogin, type MaintenanceState } from './api';
import brand from './generated/brand';
import logoUrl from './generated/logo-small.webp';
import { ErrorBox, Loading, useAsync } from './ui';

/** Höchstlänge der Begründung (wie MaintenanceService::REASON_MAX im Kern). */
export const REASON_MAX = 500;

/**
 * Wartungsseite für alle außer Admins (Issue #42). Offline gespeicherte Daten bleiben im Speicher, die Seite hat
 * aber Vorrang. „Erneut versuchen“ fragt den Server; ist die Wartung vorbei, lädt die App neu.
 */
export function MaintenancePage({ reason }: { reason: string }) {
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  async function retry() {
    setBusy(true);
    setNote(null);
    try {
      const c = await api.config();
      if (!c.maintenance?.on) {
        window.location.reload();
        return;
      }
      applyMaintenance(c.maintenance);
      setNote('Die Wartung läuft noch.');
    } catch {
      setNote('Keine Verbindung. Bitte später erneut versuchen.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="content maintenance-page">
      <img src={logoUrl} alt="" className="maintenance-logo" />
      <h1>{brand.name}</h1>
      <p>Die App wird gerade gewartet. Bitte versuche es später noch einmal.</p>
      {reason && <p className="maintenance-reason">{reason}</p>}
      <button className="btn btn-block" onClick={retry} disabled={busy}>
        {busy ? 'Prüft …' : 'Erneut versuchen'}
      </button>
      {note && (
        <p className="small" role="status">
          {note}
        </p>
      )}
      {!config?.user && (
        <button className="btn btn-link" onClick={toLogin}>
          Anmelden (Admins)
        </button>
      )}
    </main>
  );
}

/** Rotes Banner für Admins auf jeder Seite, solange der Wartungsmodus an ist. */
export function MaintenanceBanner({ reason, onToast }: { reason: string; onToast: (msg: string) => void }) {
  const [busy, setBusy] = useState(false);
  async function end() {
    setBusy(true);
    try {
      applyMaintenance(await api.setMaintenance(false));
      onToast('Wartungsmodus beendet.');
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="maintenance-banner" role="alert">
      <div>
        <strong>Wartungsmodus aktiv – nur Admins können die App nutzen.</strong>
        {reason && <div className="maintenance-reason">{reason}</div>}
      </div>
      <button className="btn btn-small" onClick={end} disabled={busy}>
        Beenden
      </button>
    </div>
  );
}

/** Schalter unter Einstellungen → Wartungsmodus: ein- und ausschalten, Begründung bearbeiten, Protokoll. */
export function MaintenanceCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.maintenance(), [], 'admin/maintenance');
  const [reason, setReason] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const m = data!;
  const text = reason ?? m.reason;

  async function save(on: boolean, message: string) {
    if (on && !m.on && !window.confirm('Alle außer Admins können die App dann nicht mehr nutzen. Wartungsmodus einschalten?')) return;
    setBusy(true);
    setSaveError(null);
    try {
      const next: MaintenanceState = await api.setMaintenance(on, on ? text : undefined);
      setData(next);
      setReason(null);
      applyMaintenance(next);
      onToast(message);
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section" aria-labelledby="maintenance-title">
      <h3 id="maintenance-title">Wartungsmodus</h3>
      <p className="muted small">
        Solange er an ist, sehen alle außer Admins nur eine Wartungsseite, z. B. während einer Datenübernahme. Offline gespeicherte Daten
        bleiben auf den Handys. Hintergrundaufgaben außer Updates pausieren; Benachrichtigungen gehen nach dem Ende raus.
      </p>
      <label className="field">
        <span>Begründung (optional, erscheint auf der Wartungsseite)</span>
        <textarea rows={3} maxLength={REASON_MAX} value={text} onChange={(e) => setReason(e.target.value)} />
        <span className="muted small">
          {text.length}/{REASON_MAX} Zeichen
        </span>
      </label>
      {saveError && <p className="form-error small">{saveError}</p>}
      <div className="button-row">
        {m.on ? (
          <>
            <button className="btn btn-secondary" disabled={busy || reason === null} onClick={() => save(true, 'Begründung gespeichert.')}>
              Begründung speichern
            </button>
            <button className="btn" disabled={busy} onClick={() => save(false, 'Wartungsmodus beendet.')}>
              Wartungsmodus beenden
            </button>
          </>
        ) : (
          <button className="btn btn-danger" disabled={busy} onClick={() => save(true, 'Wartungsmodus ist an.')}>
            Wartungsmodus einschalten
          </button>
        )}
      </div>
      {m.log.length > 0 && (
        <details className="small">
          <summary>Verlauf</summary>
          <ul>
            {m.log.map((e, i) => (
              <li key={i}>
                {new Date(e.at * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' })}: {e.by_name}{' '}
                {e.on ? 'ein bzw. Begründung geändert' : 'aus'}
              </li>
            ))}
          </ul>
        </details>
      )}
    </section>
  );
}
