import { useState } from 'react';
import { api, can, type ChangeEntry, type ChangeLog } from '../api';
import { CAPABILITIES } from '../capabilities';
import { actorText, csvRows, entryTitle, fieldText, groupEntries, groupTitle } from '../changeText';
import { formatDate, todayYmd } from '../format';
import { toCsv } from '../lists';
import { ErrorBox, Loading, Sheet, useAsync } from '../ui';
import { confirmAsk } from '../ask';
import { localeTag, t, tn } from '../i18n';

const time = (ts: number) => new Date(ts * 1000).toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
const dateTime = (ts: number) => new Date(ts * 1000).toLocaleString(localeTag(), { dateStyle: 'medium', timeStyle: 'short' });
/** Tag eines Eintrags (JJJJ-MM-TT, Ortszeit des Geräts) für die Gruppen der Liste */
const dayOf = (ts: number) => {
  const d = new Date(ts * 1000);
  const pad = (v: number) => String(v).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const capLabel = (key: string) => CAPABILITIES.find((c) => c.key === key)?.short ?? key;

/** 'YYYY-MM-DD' minus n Tage (lokal) */
function daysBefore(ymd: string, n: number): string {
  const [y, m, d] = ymd.split('-').map(Number);
  const date = new Date(y, m - 1, d - n);
  const pad = (v: number) => String(v).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Verwaltung → Änderungen (view_changes): wer wann was angelegt, geändert oder gelöscht hat, mit alten und neuen Werten.
 * Zeitraum, Person und Bereich wählbar, CSV-Datei; Werte persönlicher Angaben zeigt der Server nur mit view_personal.
 * Ein/aus und Frist stellen Admins (manage_settings) unten ein.
 */
export function Aenderungen({ onToast }: { onToast: (msg: string) => void }) {
  const [range, setRange] = useState(() => ({ from: daysBefore(todayYmd(), 29), to: todayYmd() }));
  const [actor, setActor] = useState(0);
  const [area, setArea] = useState('');
  const { data, error, loading, reload, setData } = useAsync(
    () => api.changes({ from: range.from, to: range.to, userId: actor || undefined, area: area || undefined }),
    [range.from, range.to, actor, area],
    'admin/changes',
  );
  const [open, setOpen] = useState<ChangeEntry[] | null>(null);
  if (loading && !data) return <Loading shape="list" />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const log = data!;
  // Personen zur Auswahl: wer im Zeitraum etwas geändert hat (die gewählte bleibt in der Liste)
  const actors = new Map<number, string>();
  // Ohne Konto (System, Kommandozeile) lässt sich nicht auswählen
  for (const e of log.entries) if (e.actor_id > 0) actors.set(e.actor_id, e.actor_name);
  return (
    <div className="changes stack">
      <h2 className="section-title">{t('Änderungen')}</h2>
      <p className="small muted">
        {t(
          'Wer wann was angelegt, geändert oder gelöscht hat. Tippe auf einen Eintrag für die alten und neuen Werte. Was auf einmal geändert wurde (z. B. Punkte für mehrere Mitglieder), steht in einer Zeile.',
        )}
      </p>
      {!log.enabled && (
        <p className="small">
          <strong>{t('Das Änderungsprotokoll ist ausgeschaltet.')}</strong> {t('Es wird nichts aufgezeichnet.')}
        </p>
      )}
      <section className="card settings-section" aria-labelledby="changes-filter-title">
        <h3 id="changes-filter-title">{t('Auswahl')}</h3>
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
        <label className="field">
          <span>{t('Wer')}</span>
          <select value={actor} onChange={(e) => setActor(Number(e.target.value))}>
            <option value={0}>{t('Alle')}</option>
            {[...actors.entries()]
              .sort((a, b) => a[1].localeCompare(b[1], 'de'))
              .map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
          </select>
        </label>
        <label className="field">
          <span>{t('Bereich')}</span>
          <select value={area} onChange={(e) => setArea(e.target.value)}>
            <option value="">{t('Alle')}</option>
            {log.areas.map((a) => (
              <option key={a.area} value={a.area}>
                {a.label}
              </option>
            ))}
          </select>
        </label>
        {error && (
          <p className="form-error small" role="alert">
            {error}
          </p>
        )}
        {log.entries.length > 0 && <CsvExport log={log} onToast={onToast} />}
      </section>
      <Entries log={log} onOpen={setOpen} />
      {can('manage_settings') && (
        <ChangeLogSettings log={log} onChanged={(s) => setData({ ...log, ...s })} onReload={reload} onToast={onToast} />
      )}
      {open && <Detail group={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

/** Einträge nach Tagen, neueste zuerst; Einträge einer Aktion (batch) als eine Zeile. */
function Entries({ log, onOpen }: { log: ChangeLog; onOpen: (group: ChangeEntry[]) => void }) {
  if (log.entries.length === 0) return <p className="muted">{t('Im Zeitraum wurde nichts geändert.')}</p>;
  const days: { day: string; groups: ChangeEntry[][] }[] = [];
  for (const g of groupEntries(log.entries)) {
    const day = dayOf(g[0].at);
    if (days[days.length - 1]?.day !== day) days.push({ day, groups: [] });
    days[days.length - 1].groups.push(g);
  }
  return (
    <>
      {days.map((d) => (
        <section key={d.day} aria-label={formatDate(d.day)}>
          <h3 className="section-title">{formatDate(d.day)}</h3>
          <ul className="card-list">
            {d.groups.map((g) => (
              <li key={g[0].id}>
                <button type="button" className="card row clickable settings-link" onClick={() => onOpen(g)}>
                  <span className="row-main">
                    <span className="row-title">{groupTitle(g)}</span>
                    <span className="row-sub">
                      {time(g[0].at)} · {actorText(g[0])} · {g[0].area_label}
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
      ))}
      {log.truncated && (
        <p className="small muted">
          {t('Nur die neuesten {count} Einträge. Wähle einen kürzeren Zeitraum.', { count: log.entries.length })}
        </p>
      )}
    </>
  );
}

/** Werte eines Eintrags */
function Fields({ entry: e }: { entry: ChangeEntry }) {
  if (e.changes.length === 0) return <p className="small">{t('Keine einzelnen Werte gespeichert.')}</p>;
  return (
    <dl className="change-fields">
      {e.changes.map((c) => (
        <div key={c.field} className="change-field">
          <dt>{c.field}</dt>
          <dd>{fieldText(c, capLabel)}</dd>
        </div>
      ))}
    </dl>
  );
}

/** Ein Eintrag mit seinen Werten; bei mehreren auf einmal jeder Eintrag mit Titel und Werten. */
function Detail({ group, onClose }: { group: ChangeEntry[]; onClose: () => void }) {
  const first = group[0];
  return (
    <Sheet title={groupTitle(group)} onClose={onClose}>
      <p className="small muted">
        {dateTime(first.at)} · {actorText(first)} · {first.area_label}
      </p>
      {group.length === 1 ? (
        <Fields entry={first} />
      ) : (
        <div className="stack">
          {group.map((e) => (
            <section key={e.id} className="change-entry" aria-label={entryTitle(e)}>
              <h4 className="change-entry-title">{entryTitle(e)}</h4>
              <Fields entry={e} />
            </section>
          ))}
        </div>
      )}
    </Sheet>
  );
}

/** Alle Einträge der Auswahl als CSV-Datei, eine Zeile je Feld. */
function CsvExport({ log, onToast }: { log: ChangeLog; onToast: (msg: string) => void }) {
  function download() {
    const header = [t('Zeit'), t('Wer'), t('Bereich'), t('Art'), t('Eintrag'), t('Aktion'), t('Feld'), t('Vorher'), t('Nachher')];
    const blob = new Blob([toCsv(header, csvRows(log.entries, dateTime, capLabel))], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `aenderungen-${log.from}-bis-${log.to}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    onToast(
      log.truncated
        ? t('CSV-Datei mit den neuesten {count} Einträgen heruntergeladen', { count: log.entries.length })
        : t('CSV-Datei heruntergeladen'),
    );
  }
  return (
    <>
      <div className="button-row">
        <button type="button" className="btn btn-secondary" onClick={download}>
          {t('Als CSV-Datei herunterladen')}
        </button>
      </div>
      <p className="small muted">{t('Enthält Namen und Werte. Die Datei nach Gebrauch bitte löschen.')}</p>
    </>
  );
}

/** Admins: Protokoll ein/aus (aus löscht alles) und Frist in Tagen. */
function ChangeLogSettings({
  log,
  onChanged,
  onReload,
  onToast,
}: {
  log: ChangeLog;
  onChanged: (s: Pick<ChangeLog, 'enabled' | 'keep_days'>) => void;
  onReload: () => void;
  onToast: (msg: string) => void;
}) {
  const [days, setDays] = useState(String(log.keep_days));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function save(data: { enabled?: boolean; keep_days?: number }, msg: string) {
    setBusy(true);
    setError(null);
    try {
      const s = await api.setChangeLogSettings(data);
      onChanged(s);
      setDays(String(s.keep_days));
      onToast(msg);
      onReload();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function toggle(on: boolean) {
    if (!on && !(await confirmAsk(t('Änderungen nicht mehr protokollieren? Alle bisherigen Einträge werden sofort gelöscht.')))) return;
    await save({ enabled: on }, on ? t('Änderungsprotokoll eingeschaltet') : t('Änderungsprotokoll ausgeschaltet'));
  }
  const n = Number(days);
  const valid = Number.isInteger(n) && n >= log.keep_min && n <= log.keep_max;
  return (
    <section className="card settings-section" aria-labelledby="changes-settings-title">
      <h3 id="changes-settings-title">{t('Einstellungen')}</h3>
      <label className="toggle">
        <input type="checkbox" checked={log.enabled} disabled={busy} onChange={(e) => void toggle(e.target.checked)} />
        <span>{t('Änderungen protokollieren (wer, wann, was, mit alten und neuen Werten)')}</span>
      </label>
      <form
        className="change-keep"
        onSubmit={(e) => {
          e.preventDefault();
          if (valid && n !== log.keep_days)
            void save({ keep_days: n }, tn('Einträge werden nach {count} Tag gelöscht', 'Einträge werden nach {count} Tagen gelöscht', n));
        }}
      >
        <label className="field">
          <span>{t('Einträge löschen nach (Tage, {min} bis {max})', { min: log.keep_min, max: log.keep_max })}</span>
          <input
            type="number"
            inputMode="numeric"
            min={log.keep_min}
            max={log.keep_max}
            value={days}
            onChange={(e) => setDays(e.target.value)}
          />
        </label>
        <button type="submit" className="btn btn-secondary" disabled={busy || !valid || n === log.keep_days}>
          {t('Frist speichern')}
        </button>
      </form>
      <p className="small muted">
        {t(
          'Ältere Einträge löscht die App jeden Tag, eine kürzere Frist sofort. Das Protokoll enthält persönliche Angaben und kommt nicht in die Datensicherung. Bitte nennt es in eurer Datenschutzerklärung.',
        )}
      </p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}
