import { useState } from 'react';
import { api, applyMaintenance, config, toLogin, type MaintenanceState } from './api';
import brand from './generated/brand';
import logoUrl from './generated/logo-small.webp';
import { ErrorBox, Loading, useAsync } from './ui';
import { sources } from './viewData';
import { confirmAsk } from './ask';
import { localeTag, t } from './i18n';

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
      setNote(t('Die Wartung läuft noch.'));
    } catch {
      setNote(t('Keine Verbindung. Bitte später erneut versuchen.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="content maintenance-page">
      <img src={logoUrl} alt="" className="maintenance-logo" />
      <h1>{brand.name}</h1>
      <p>{t('Die App wird gerade gewartet. Bitte versuche es später noch einmal.')}</p>
      {reason && <p className="maintenance-reason">{reason}</p>}
      <button className="btn btn-block" onClick={retry} disabled={busy}>
        {busy ? t('Prüft …') : t('Erneut versuchen')}
      </button>
      {note && (
        <p className="small" role="status">
          {note}
        </p>
      )}
      {!config?.user && (
        <button className="btn btn-link" onClick={toLogin}>
          {t('Anmelden (Admins)')}
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
      onToast(t('Wartungsmodus beendet.'));
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="maintenance-banner" role="alert">
      <div>
        <strong>{t('Wartungsmodus aktiv – nur Admins können die App nutzen.')}</strong>
        {reason && <div className="maintenance-reason">{reason}</div>}
      </div>
      <button className="btn btn-small" onClick={end} disabled={busy}>
        {t('Beenden')}
      </button>
    </div>
  );
}

/** Schalter unter Einstellungen → Wartungsmodus: ein- und ausschalten, Begründung bearbeiten, Protokoll. */
export function MaintenanceCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.maintenance.load, sources.maintenance.deps, sources.maintenance.key);
  const [reason, setReason] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const m = data!;
  const text = reason ?? m.reason;

  async function save(on: boolean, message: string) {
    if (on && !m.on && !(await confirmAsk(t('Alle außer Admins können die App dann nicht mehr nutzen. Wartungsmodus einschalten?'))))
      return;
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
      <h3 id="maintenance-title">{t('Wartungsmodus')}</h3>
      <label className="field">
        <span>{t('Begründung (optional, erscheint auf der Wartungsseite)')}</span>
        <textarea rows={3} maxLength={REASON_MAX} value={text} onChange={(e) => setReason(e.target.value)} />
        <span className="muted small">{t('{count}/{max} Zeichen', { count: text.length, max: REASON_MAX })}</span>
      </label>
      {saveError && <p className="form-error small">{saveError}</p>}
      <div className="button-row">
        {m.on ? (
          <>
            <button
              className="btn btn-secondary"
              disabled={busy || reason === null}
              onClick={() => save(true, t('Begründung gespeichert.'))}
            >
              {t('Begründung speichern')}
            </button>
            <button className="btn" disabled={busy} onClick={() => save(false, t('Wartungsmodus beendet.'))}>
              {t('Wartungsmodus beenden')}
            </button>
          </>
        ) : (
          <button className="btn btn-danger" disabled={busy} onClick={() => save(true, t('Wartungsmodus ist an.'))}>
            {t('Wartungsmodus einschalten')}
          </button>
        )}
      </div>
      {m.log.length > 0 && (
        <details className="small">
          <summary>{t('Verlauf')}</summary>
          <ul>
            {m.log.map((e, i) => (
              <li key={i}>
                {((p) => (e.on ? t('{date}: {name} ein bzw. Begründung geändert', p) : t('{date}: {name} aus', p)))({
                  date: new Date(e.at * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' }),
                  name: e.by_name,
                })}
              </li>
            ))}
          </ul>
        </details>
      )}
    </section>
  );
}
