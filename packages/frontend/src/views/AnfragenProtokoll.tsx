import { useState } from 'react';
import { api, type RequestKind, type RequestLogRow, type RequestLogSummary } from '../api';
import { confirmAsk } from '../ask';
import { formatDate } from '../format';
import { localeTag, t, tn } from '../i18n';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Anfragen-Protokoll (nur WordPress): Schalter für wenige Tage und Übersicht, welche PHP-Anfragen beim Webspace
 * ankommen, auch von Crawlern und Cronjobs. Für Gespräche mit dem Hoster über Antwortzeiten.
 */
export default function AnfragenProtokoll() {
  const { data, error, loading, reload, setData } = useAsync(() => api.requestLog(), [], 'admin/request-log');
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? ''} onRetry={reload} />;
  const s = data;

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setActionError(null);
    try {
      await action();
    } catch (err) {
      setActionError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const change = (on: boolean) =>
    run(async () => {
      await api.setRequestLog(on);
      setData(await api.requestLog());
    });

  async function clear() {
    if (!(await confirmAsk(t('Alle aufgezeichneten Anfragen löschen?')))) return;
    await run(async () => setData(await api.clearRequestLog()));
  }

  return (
    <section className="card settings-section stack" aria-labelledby="requestlog-title">
      <h3 id="requestlog-title">{t('Anfragen-Protokoll')}</h3>
      <label className="toggle">
        <input type="checkbox" checked={s.on} disabled={busy} onChange={(e) => void change(e.target.checked)} />
        <span>{tn('Anfragen aufzeichnen ({count} Tag)', 'Anfragen aufzeichnen ({count} Tage)', s.on_days)}</span>
      </label>
      <p className="small muted">
        {s.on && s.until
          ? t('Läuft bis {date} und schaltet sich dann selbst aus.', { date: dateTime(s.until) })
          : t(
              'Zeichnet auf, welche Anfragen beim Webspace ankommen, auch von Crawlern und Cronjobs. Hilft im Gespräch mit dem Hoster über Antwortzeiten.',
            )}{' '}
        {t(
          'Gespeichert werden Zeit, Adresse ohne Werte der Parameter, Dauer, Statuscode, angemeldet ja/nein, User-Agent und die gekürzte IP-Adresse. Nach {days} Tagen wird alles gelöscht.',
          { days: s.keep_days },
        )}
      </p>
      {s.total === 0 ? (
        <p className="small">{s.on ? t('Noch keine Anfragen aufgezeichnet.') : t('Keine Aufzeichnung vorhanden.')}</p>
      ) : (
        <Overview s={s} />
      )}
      {s.total > 0 && (
        <div className="button-row">
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={clear}>
            {t('Aufzeichnung löschen')}
          </button>
        </div>
      )}
      {actionError && (
        <p className="form-error" role="alert">
          {actionError}
        </p>
      )}
    </section>
  );
}

const KIND_TEXT: Record<RequestKind, string> = {
  'app-api': t('App-Schnittstelle'),
  app: t('App-Seite, Bilder und Videos'),
  cron: t('WP-Cron (wp-cron.php)'),
  admin: t('WordPress-Verwaltung (wp-admin)'),
  login: t('Anmeldung (wp-login.php)'),
  rest: t('Andere REST-Schnittstellen'),
  page: t('Webseite'),
  other: t('Andere PHP-Dateien'),
};

/** Vom Server vergebene Namen, die übersetzt werden; Namen von Crawlern bleiben, wie sie sind. */
const AGENT_TEXT: Record<string, string> = {
  Browser: t('Browser'),
  Unbekannt: t('Unbekannt'),
  'Ohne User-Agent': t('Ohne User-Agent'),
  'Anderer Crawler': t('Anderer Crawler'),
  'WordPress (Server selbst)': t('WordPress (Server selbst)'),
};

const agent = (name: string) => AGENT_TEXT[name] ?? name;

function dateTime(ts: number): string {
  return new Date(ts * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' });
}

const num = (n: number) => n.toLocaleString(localeTag());

const ms = (n: number) => t('{ms} ms', { ms: num(n) });

function Overview({ s }: { s: RequestLogSummary }) {
  const kinds = (Object.keys(KIND_TEXT) as RequestKind[]).filter((k) => s.kinds[k].count > 0);
  const perDay = s.days.map((d) => ({ date: d.date, count: kinds.reduce((sum, k) => sum + d[k], 0) }));
  return (
    <>
      <p className="small">
        {t('{count} Anfragen vom {from} bis {to}', { count: num(s.total), from: dateTime(s.from ?? 0), to: dateTime(s.to ?? 0) })}
      </p>
      <Bars
        title={t('Nach Art')}
        rows={kinds.map((k) => ({ key: k, label: KIND_TEXT[k], note: t('Ø {avg}', { avg: ms(s.kinds[k].avg) }), value: s.kinds[k].count }))}
      />
      <Bars title={t('Je Tag')} rows={perDay.map((d) => ({ key: d.date, label: formatDate(d.date), value: d.count }))} />
      <Bars
        title={t('Je Stunde')}
        rows={s.hours.map((n, h) => ({ key: String(h), label: t('{hour} Uhr', { hour: h }), value: n })).filter((r) => r.value > 0)}
      />
      <Rows title={t('Häufigste Adressen')} rows={s.paths} />
      <Rows title={t('Langsamste Adressen (ab 3 Aufrufen)')} rows={s.slowest} />
      <Bars
        title={t('Wer fragt an (User-Agent)')}
        rows={s.agents.map((a) => ({ key: a.name, label: agent(a.name), note: t('Ø {avg}', { avg: ms(a.avg) }), value: a.count }))}
      />
      {s.cron.length > 0 && (
        <>
          <h4 className="usage-subtitle">{t('Woher kommen die Aufrufe von wp-cron.php?')}</h4>
          <ul className="small">
            {s.cron.map((c) => (
              <li key={c.agent + c.ip}>
                {t('{agent} von {ip}: {count}×', { agent: agent(c.agent), ip: c.ip || '?', count: num(c.count) })}
              </li>
            ))}
          </ul>
        </>
      )}
    </>
  );
}

function Bars({ title, rows }: { title: string; rows: { key: string; label: string; note?: string; value: number }[] }) {
  if (rows.length === 0) return null;
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <>
      <h4 className="usage-subtitle">{title}</h4>
      <ul className="usage-bars">
        {rows.map((r) => (
          <li key={r.key}>
            <span className="usage-bar-label">
              {r.label}
              {r.note && <span className="small muted"> · {r.note}</span>}
            </span>
            <span className="usage-bar" aria-hidden="true">
              <span style={{ width: `${(r.value / max) * 100}%` }} />
            </span>
            <span className="usage-bar-value">{num(r.value)}</span>
          </li>
        ))}
      </ul>
    </>
  );
}

/** Adressen mit Anzahl, Dauer im Schnitt und höchstens, Anteil ohne Browser (Crawler, Cronjobs, Dienste). */
function Rows({ title, rows }: { title: string; rows: RequestLogRow[] }) {
  if (rows.length === 0) return null;
  return (
    <>
      <h4 className="usage-subtitle">{title}</h4>
      <ul className="error-log">
        {rows.map((r) => (
          <li key={r.name}>
            <code className="small">{r.name}</code>
            <br />
            <span className="small muted">
              {[
                r.kind ? KIND_TEXT[r.kind] : null,
                tn('{count} Aufruf', '{count} Aufrufe', r.count),
                t('Ø {avg}', { avg: ms(r.avg) }),
                r.max !== undefined ? t('höchstens {max}', { max: ms(r.max) }) : null,
                r.bots ? t('{count} ohne Browser', { count: num(r.bots) }) : null,
              ]
                .filter(Boolean)
                .join(' · ')}
            </span>
          </li>
        ))}
      </ul>
    </>
  );
}
