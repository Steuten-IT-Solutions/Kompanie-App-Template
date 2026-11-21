import { useState } from 'react';
import { api, applyOpenReports, can, type ReportStatus, type ReviewReport } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';
import { localeTag, t } from '../i18n';

/** Zeitpunkt einer Meldung (Unix-Zeit) in Ortszeit */
export const reportTime = (unix: number): string =>
  new Date(unix * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' });

export const REPORT_STATUS: Record<ReportStatus, string> = {
  open: t('offen'),
  closed: t('geschlossen'),
  forwarded: t('an die Betreuer weitergeleitet'),
};

/**
 * Verwaltung → Fehlermeldungen (review_reports): Meldungen aus „Fehler melden“ landen zuerst hier. Die Admins schließen
 * sie mit Begründung (z. B. wenn sie das Problem selbst lösen konnten) oder leiten sie an die Betreuer der App weiter;
 * erst dann wird daraus ein Issue. Offene oben, darunter die erledigten der letzten 30 Tage.
 */
export function Meldungen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.reviewReports(), [], 'reports-review');
  if (loading && !data) return <Loading shape="text" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const open = list.reports.filter((r) => r.status === 'open');
  const done = list.reports.filter((r) => r.status !== 'open');
  const update = (next: ReviewReport, msg: string) => {
    const reports = list.reports.map((r) => (r.id === next.id ? next : r));
    setData({ ...list, reports });
    applyOpenReports(reports.filter((r) => r.status === 'open').length);
    onToast(msg);
  };
  return (
    <div className="reports-review">
      <h2 className="section-title">{t('Fehlermeldungen')}</h2>
      {can('review_reports') && !list.forward && (
        <p className="muted small">
          {t(
            'In dieser App ist kein Empfänger für Fehlermeldungen eingerichtet. Ihr könnt Meldungen lesen und schließen, aber nicht an die Betreuer der App weiterleiten.',
          )}
        </p>
      )}
      {open.length === 0 ? (
        <p className="muted">{t('Zurzeit ist keine Meldung offen.')}</p>
      ) : (
        <ul className="card-list">
          {open.map((r) => (
            <li key={r.id}>
              <ReportCard report={r} forward={list.forward} onDone={update} />
            </li>
          ))}
        </ul>
      )}
      {done.length > 0 && (
        <>
          <h3 className="section-title">{t('Erledigt')}</h3>
          <ul className="card-list">
            {done.map((r) => (
              <li key={r.id}>
                <ReportCard report={r} forward={list.forward} onDone={update} />
              </li>
            ))}
          </ul>
        </>
      )}
      <p className="muted small">
        {t(
          'Erledigte Meldungen werden nach 30 Tagen gelöscht. Wer angemeldet gemeldet hat, sieht unter ? → Fehler melden, ob die Meldung weitergeleitet ist, und eure Begründung beim Schließen.',
        )}
      </p>
    </div>
  );
}

function ReportCard({
  report: r,
  forward,
  onDone,
}: {
  report: ReviewReport;
  forward: boolean;
  onDone: (next: ReviewReport, msg: string) => void;
}) {
  const [closing, setClosing] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [shot, setShot] = useState<string | null>(null);
  const isOpen = r.status === 'open';

  async function run(action: () => Promise<[ReviewReport, string]>) {
    setBusy(true);
    setError(null);
    try {
      const [next, msg] = await action();
      onDone(next, msg);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function showShot() {
    setBusy(true);
    setError(null);
    try {
      const s = await api.reportScreenshot(r.id);
      setShot(`data:${s.type};base64,${s.data}`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const close = () => {
    if (reason.trim() === '') return setError(t('Bitte schreibe kurz, warum die Meldung geschlossen wird.'));
    return run(async () => [await api.closeReport(r.id, reason.trim()), t('Meldung geschlossen')]);
  };
  const send = () => {
    if (!window.confirm(t('Meldung an die Betreuer der App weiterleiten? Dort wird daraus ein Eintrag zur Bearbeitung.'))) return;
    return run(async () => [await api.forwardReport(r.id), t('Meldung weitergeleitet')]);
  };

  return (
    <div className="card report-review">
      <div className="row-title">{r.name ?? t('Ohne Anmeldung')}</div>
      <div className="row-sub">
        {reportTime(r.created)} · {r.role}
        {!isOpen &&
          ` · ${r.reviewed_by ? t('{status} von {name}', { status: REPORT_STATUS[r.status], name: r.reviewed_by }) : REPORT_STATUS[r.status]}`}
      </div>
      <p className="report-text">{r.description}</p>
      {r.expected && (
        <p className="report-text">
          <span className="muted small">{t('Erwartet:')} </span>
          {r.expected}
        </p>
      )}
      {r.reason && (
        <p className="report-text">
          <span className="muted small">{t('Begründung:')} </span>
          {r.reason}
        </p>
      )}
      <details className="report-meta">
        <summary className="small">{t('Weitere Angaben')}</summary>
        <dl>
          <dt>{t('Seite in der App')}</dt>
          <dd>{r.page || '–'}</dd>
          <dt>{t('App-Version')}</dt>
          <dd>
            {r.version} ({r.variant === 'wordpress' ? 'WordPress' : t('eigenständig')})
          </dd>
          <dt>{t('Gerät und Browser')}</dt>
          <dd>{r.user_agent || '–'}</dd>
          <dt>{t('Letzte Fehlermeldung der App')}</dt>
          <dd>{r.last_error ?? t('keine')}</dd>
          {r.name && (
            <>
              <dt>{t('Name beim Weiterleiten')}</dt>
              <dd>{r.share_name ? t('geht mit') : t('geht nicht mit')}</dd>
            </>
          )}
        </dl>
      </details>
      {isOpen && r.screenshot && !shot && (
        <button type="button" className="btn btn-link" disabled={busy} onClick={showShot}>
          {t('Bildschirmfoto anzeigen')}
        </button>
      )}
      {shot && <img className="report-shot-full" src={shot} alt={t('Bildschirmfoto zur Meldung')} />}
      {isOpen && closing && (
        <label className="field">
          <span>{t('Begründung (Pflicht, sieht die meldende Person)')}</span>
          <textarea rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {/* Nur sehen (view_reports): ohne Schließen und Weiterleiten */}
      {isOpen &&
        can('review_reports') &&
        (closing ? (
          <div className="row-actions">
            <button type="button" className="btn" disabled={busy} onClick={close}>
              {t('Schließen')}
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setClosing(false)}>
              {t('Abbrechen')}
            </button>
          </div>
        ) : (
          <div className="row-actions">
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setClosing(true)}>
              {t('Schließen …')}
            </button>
            {forward && (
              <button type="button" className="btn" disabled={busy} onClick={send}>
                {busy ? t('Leitet weiter …') : t('An die Betreuer weiterleiten')}
              </button>
            )}
          </div>
        ))}
    </div>
  );
}
