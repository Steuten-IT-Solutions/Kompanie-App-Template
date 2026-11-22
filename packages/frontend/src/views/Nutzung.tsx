import { useState } from 'react';
import { api, type ActivityAction, type ActivityEntry, type ActivityOverview } from '../api';
import { formatDate, todayYmd } from '../format';
import { toCsv } from '../lists';
import { ErrorBox, Loading, Sheet, StatCard, useAsync } from '../ui';
import { confirmAsk } from '../ask';
import { localeTag, t, tn } from '../i18n';

const ACTION_TEXT: Record<ActivityAction, string> = {
  view: t('angesehen'),
  create: t('angelegt'),
  update: t('geändert'),
  delete: t('gelöscht'),
  vote: t('abgestimmt'),
  login: t('angemeldet'),
};

/** Ab so vielen Tagen ohne Nutzung gilt jemand als „lange nicht aktiv“. */
const INACTIVE_DAYS = 30;

const dateTime = (ts: number) => new Date(ts * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' });

/** 'YYYY-MM-DD' minus n Tage (lokal) */
function daysBefore(ymd: string, n: number): string {
  const [y, m, d] = ymd.split('-').map(Number);
  const date = new Date(y, m - 1, d - n);
  const pad = (v: number) => String(v).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Einstellungen → Nutzung (Issue #175, nur Admins mit view_activity): aktive Konten, Nutzung je Tag und Bereich, je Person
 * zuletzt aktiv und Verlauf, Zeitraum wählbar, CSV-Datei. Gespeichert sind nur Bereich, Aktion und Zeit.
 */
export default function Nutzung({ onToast }: { onToast: (msg: string) => void }) {
  const [range, setRange] = useState(() => ({ from: daysBefore(todayYmd(), 29), to: todayYmd() }));
  const { data, error, loading, reload, setData } = useAsync(
    () => api.activity(range.from, range.to),
    [range.from, range.to],
    'admin/activity',
  );
  const [person, setPerson] = useState<{ id: number; name: string } | null>(null);
  if (loading && !data) return <Loading shape="form" />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const o = data!;
  return (
    <>
      <section className="card settings-section" aria-labelledby="usage-title">
        <h3 id="usage-title">{t('Nutzung')}</h3>
        <div className="stats usage-stats">
          <StatCard label={t('Konten')} value={String(o.users)} />
          <StatCard label={t('Aktiv (24 Std.)')} value={String(o.active.d1)} />
          <StatCard label={t('Aktiv (7 Tage)')} value={String(o.active.d7)} />
          <StatCard label={t('Aktiv (30 Tage)')} value={String(o.active.d30)} />
        </div>
      </section>
      <section className="card settings-section" aria-labelledby="usage-range-title">
        <h3 id="usage-range-title">{t('Zeitraum')}</h3>
        <div className="usage-range">
          <label className="field">
            <span>{t('Von')}</span>
            <input
              type="date"
              value={range.from}
              max={range.to}
              onChange={(e) => e.target.value && setRange({ ...range, from: e.target.value })}
            />
          </label>
          <label className="field">
            <span>{t('Bis')}</span>
            <input
              type="date"
              value={range.to}
              min={range.from}
              onChange={(e) => e.target.value && setRange({ ...range, to: e.target.value })}
            />
          </label>
        </div>
        {error && (
          <p className="form-error small" role="alert">
            {error}
          </p>
        )}
        <Days overview={o} />
        <Areas overview={o} />
        {o.personal && <CsvExport from={o.from} to={o.to} onToast={onToast} />}
      </section>
      {o.personal && o.members && <Members members={o.members} onOpen={setPerson} />}
      <PersonalSwitch overview={o} onChanged={(personal) => setData({ ...o, personal, members: personal ? [] : null })} onReload={reload} />
      {person && <History person={person} from={o.from} to={o.to} onClose={() => setPerson(null)} />}
    </>
  );
}

/** Aktive Personen je Tag als Balken (Tage ohne Nutzung fehlen). */
function Days({ overview }: { overview: ActivityOverview }) {
  const max = Math.max(1, ...overview.days.map((d) => d.users));
  return (
    <>
      <h4 className="usage-subtitle">{t('Aktive Personen je Tag')}</h4>
      {overview.days.length === 0 ? (
        <p className="small muted">{t('Im Zeitraum hat niemand die App genutzt.')}</p>
      ) : (
        <ul className="usage-bars">
          {overview.days.map((d) => (
            <li key={d.day}>
              <span className="usage-bar-label">{formatDate(d.day)}</span>
              <span className="usage-bar" aria-hidden="true">
                <span style={{ width: `${(d.users / max) * 100}%` }} />
              </span>
              <span className="usage-bar-value">{d.users}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

function Areas({ overview }: { overview: ActivityOverview }) {
  const max = Math.max(1, ...overview.areas.map((a) => a.actions));
  return (
    <>
      <h4 className="usage-subtitle">{t('Nutzung je Bereich')}</h4>
      {overview.areas.length === 0 ? (
        <p className="small muted">{t('Noch keine Nutzung.')}</p>
      ) : (
        <ul className="usage-bars">
          {overview.areas.map((a) => (
            <li key={a.area}>
              <span className="usage-bar-label">
                {a.label}
                <span className="small muted"> · {tn('{count} Person in 30 Tagen', '{count} Personen in 30 Tagen', a.people)}</span>
              </span>
              <span className="usage-bar" aria-hidden="true">
                <span style={{ width: `${(a.actions / max) * 100}%` }} />
              </span>
              <span className="usage-bar-value">{a.actions}</span>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

/** Alle Einträge je Person im Zeitraum als CSV-Datei für Excel. */
function CsvExport({ from, to, onToast }: { from: string; to: string; onToast: (msg: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function download() {
    setBusy(true);
    setError(null);
    try {
      const res = await api.activityEntries(from, to);
      const rows = res.entries.map((e) => [dateTime(e.at), e.name, e.label, ACTION_TEXT[e.action] ?? e.action]);
      const blob = new Blob([toCsv([t('Zeit'), t('Name'), t('Bereich'), t('Aktion')], rows)], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `nutzung-${from}-bis-${to}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
      onToast(
        res.truncated
          ? t('CSV-Datei mit den neuesten {count} Einträgen heruntergeladen', { count: res.entries.length })
          : t('CSV-Datei heruntergeladen'),
      );
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <div className="button-row">
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={download}>
          {busy ? t('Wird erstellt …') : t('Als CSV-Datei herunterladen')}
        </button>
      </div>
      <p className="small muted">{t('Enthält Namen. Die Datei nach Gebrauch bitte löschen.')}</p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </>
  );
}

/**
 * Konten, die im Zeitraum aktiv waren, mit „zuletzt aktiv“; wer am längsten nicht aktiv war, zuerst. Der Schalter zeigt
 * stattdessen alle, die seit INACTIVE_DAYS Tagen nicht aktiv waren (unabhängig vom Zeitraum).
 */
function Members({
  members,
  onOpen,
}: {
  members: NonNullable<ActivityOverview['members']>;
  onOpen: (p: { id: number; name: string }) => void;
}) {
  const [onlyInactive, setOnlyInactive] = useState(false);
  const limit = Date.now() / 1000 - INACTIVE_DAYS * 86400;
  const inactive = (m: { last_active: number | null }) => m.last_active === null || m.last_active < limit;
  const sorted = [...members].sort((a, b) => (a.last_active ?? 0) - (b.last_active ?? 0) || a.name.localeCompare(b.name, 'de'));
  const shown = sorted.filter(onlyInactive ? inactive : (m) => m.actions > 0);
  return (
    <section className="card settings-section" aria-labelledby="usage-members-title">
      <h3 id="usage-members-title">{t('Mitglieder')}</h3>
      <label className="toggle">
        <input type="checkbox" checked={onlyInactive} onChange={(e) => setOnlyInactive(e.target.checked)} />
        <span>
          {t('Nur, wer seit {days} Tagen nicht aktiv war ({count})', { days: INACTIVE_DAYS, count: members.filter(inactive).length })}
        </span>
      </label>
      {shown.length === 0 && (
        <p className="small muted">{onlyInactive ? t('Alle waren in dieser Zeit aktiv.') : t('Im Zeitraum war niemand aktiv.')}</p>
      )}
      <ul className="card-list usage-members">
        {shown.map((m) => (
          <li key={m.id}>
            <button type="button" className="card row clickable settings-link" onClick={() => onOpen(m)}>
              <span className="row-main">
                <span className="row-title">{m.name}</span>
                <span className="row-sub">
                  {m.last_active
                    ? t('zuletzt aktiv: {time}', { time: dateTime(m.last_active) })
                    : t('nicht aktiv, seit die Erfassung läuft')}
                </span>
              </span>
              <span className="settings-chevron" aria-hidden="true">
                ›
              </span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** Verlauf einer Person im Zeitraum: Bereich, Aktion und Zeit. */
function History({ person, from, to, onClose }: { person: { id: number; name: string }; from: string; to: string; onClose: () => void }) {
  const { data, error, loading, reload } = useAsync(() => api.activityEntries(from, to, person.id), [person.id, from, to]);
  return (
    <Sheet title={person.name} onClose={onClose}>
      <p className="small muted">{t('{from} bis {to}', { from: formatDate(from), to: formatDate(to) })}</p>
      {loading && !data ? (
        <Loading shape="text" />
      ) : error ? (
        <ErrorBox message={error} onRetry={reload} />
      ) : data!.entries.length === 0 ? (
        <p className="small">{t('In diesem Zeitraum nicht aktiv.')}</p>
      ) : (
        <ul className="usage-history">
          {data!.entries.map((e: ActivityEntry, i) => (
            <li key={i}>
              <span className="small muted">{dateTime(e.at)}</span>{' '}
              {t('{area}: {action}', { area: e.label, action: ACTION_TEXT[e.action] ?? e.action })}
            </li>
          ))}
          {data!.truncated && <li className="small muted">{t('Nur die neuesten {count} Einträge.', { count: data!.entries.length })}</li>}
        </ul>
      )}
    </Sheet>
  );
}

/** Erfassung je Person: Standard an; aus löscht alle Einträge je Person, gezählt wird dann nur noch ohne Namen. */
function PersonalSwitch({
  overview,
  onChanged,
  onReload,
}: {
  overview: ActivityOverview;
  onChanged: (personal: boolean) => void;
  onReload: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function change(on: boolean) {
    if (
      !on &&
      !(await confirmAsk(
        t('Nutzung nicht mehr je Person erfassen? Alle bisherigen Einträge mit Namen werden sofort gelöscht, auch „zuletzt aktiv“.'),
      ))
    )
      return;
    setBusy(true);
    setError(null);
    try {
      onChanged((await api.setActivityPersonal(on)).personal);
      onReload();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="card settings-section" aria-labelledby="usage-personal-title">
      <h3 id="usage-personal-title">{t('Erfassung je Person')}</h3>
      <label className="toggle">
        <input type="checkbox" checked={overview.personal} disabled={busy} onChange={(e) => void change(e.target.checked)} />
        <span>{t('Nutzung je Person erfassen (wer, wann, welcher Bereich)')}</span>
      </label>
      <p className="small muted">
        {overview.personal
          ? t(
              'Einträge je Person werden nach {days} Tagen automatisch gelöscht, ebenso mit dem Konto. Bitte informiert eure Mitglieder darüber, z. B. in der Datenschutzerklärung.',
              { days: overview.keep_days },
            )
          : t('Ausgeschaltet: Die App zählt nur noch, wie viele Personen welchen Bereich nutzen, ohne Namen.')}
      </p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}
