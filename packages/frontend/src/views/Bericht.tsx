import { useState } from 'react';
import { api } from '../api';
import { todayYmd } from '../format';
import brand from '../generated/brand';
import logoUrl from '../generated/logo-small.webp';
import { Icon } from '../icons';
import { previewRole } from '../preview';
import { rangeLabel, reportPresets, type ReportRange } from '../report';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

/**
 * Verwaltung → Bericht: die Daten mehrerer Bereiche für einen Zeitraum als PDF-Datei. Zur Auswahl stehen nur die
 * Bereiche, für die die Person die Rechte hat (prüft der Server, GET /admin/report/sections); Bereiche ohne Zeitbezug
 * (z. B. Mitglieder) zeigen den Stand von heute. Das PDF entsteht im Browser (reportPdf.ts, erst beim Erstellen geladen).
 */
export function Bericht({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.reportSections(), [], 'admin/report/sections');
  const presets = reportPresets(todayYmd());
  const [range, setRange] = useState<ReportRange>(presets[0].range);
  /** Abgewählte Bereiche (neue Bereiche sind so von selbst gewählt) */
  const [off, setOff] = useState<Set<string>>(() => new Set());
  const [busy, setBusy] = useState(false);
  const [fail, setFail] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="list" />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const sections = data ?? [];
  const chosen = sections.filter((s) => !off.has(s.key));
  const valid = range.from !== '' && range.to !== '' && range.from <= range.to;
  const preview = previewRole() !== null;

  function toggle(key: string, on: boolean) {
    const next = new Set(off);
    if (on) next.delete(key);
    else next.add(key);
    setOff(next);
  }

  async function create() {
    setBusy(true);
    setFail(null);
    try {
      const report = await api.report({ ...range, sections: chosen.map((s) => s.key) });
      const { downloadReportPdf } = await import('../reportPdf');
      await downloadReportPdf(report, { company: brand.name, color: brand.themeColor, logoUrl });
      onToast('Bericht heruntergeladen');
    } catch (e) {
      setFail(`Der Bericht ließ sich nicht erstellen: ${(e as Error).message}`);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="report stack">
      <h2 className="section-title">Bericht</h2>
      <p className="small muted">
        Die Daten mehrerer Bereiche für einen Zeitraum als PDF-Datei, z. B. für die Jahreshauptversammlung. Du siehst nur die Bereiche, die
        du verwalten darfst.
      </p>
      {sections.length === 0 ? (
        <Empty>Für einen Bericht fehlen dir die Rechte, oder die Bereiche sind ausgeschaltet.</Empty>
      ) : (
        <>
          <section className="card settings-section stack" aria-labelledby="report-range-title">
            <h3 id="report-range-title">Zeitraum</h3>
            <div className="segmented small" role="group" aria-label="Schnellwahl">
              {presets.map((p) => {
                const active = p.range.from === range.from && p.range.to === range.to;
                return (
                  <button
                    key={p.id}
                    type="button"
                    className={active ? 'active' : ''}
                    aria-pressed={active}
                    onClick={() => setRange(p.range)}
                  >
                    {p.label}
                  </button>
                );
              })}
            </div>
            <div className="usage-range">
              <label className="field">
                <span>Von</span>
                <input type="date" value={range.from} max={range.to} onChange={(e) => setRange({ ...range, from: e.target.value })} />
              </label>
              <label className="field">
                <span>Bis</span>
                <input type="date" value={range.to} min={range.from} onChange={(e) => setRange({ ...range, to: e.target.value })} />
              </label>
            </div>
            {!valid && (
              <p className="form-error small" role="alert">
                Bitte einen Zeitraum wählen, der Beginn vor dem Ende.
              </p>
            )}
          </section>
          <section className="card settings-section stack" aria-labelledby="report-sections-title">
            <h3 id="report-sections-title">Bereiche</h3>
            <div className="toggle-grid">
              {sections.map((s) => (
                <label key={s.key} className="toggle">
                  <input type="checkbox" checked={!off.has(s.key)} onChange={(e) => toggle(s.key, e.target.checked)} />
                  <span>
                    {s.title}
                    {!s.dated && <span className="muted"> (Stand heute)</span>}
                  </span>
                </label>
              ))}
            </div>
          </section>
          {fail && (
            <p className="form-error small" role="alert">
              {fail}
            </p>
          )}
          {preview && <p className="small muted">In der Vorschau gibt es keinen Bericht.</p>}
          <div className="icon-row">
            <button
              type="button"
              className="btn btn-icon-text"
              disabled={busy || !valid || chosen.length === 0 || preview}
              onClick={() => void create()}
              aria-label={busy ? 'Bericht wird erstellt' : `PDF für ${valid ? rangeLabel(range) : 'den Zeitraum'} erstellen`}
              title="PDF erstellen"
            >
              <Icon name="download" />
              {busy ? 'PDF …' : 'PDF'}
            </button>
          </div>
          <p className="small muted">
            Der Bericht kann persönliche Daten enthalten. Gib ihn nur an Personen weiter, die sie sehen dürfen, und lösche ihn, wenn du ihn
            nicht mehr brauchst. Wer wann einen Bericht erstellt, steht im Änderungsprotokoll.
          </p>
        </>
      )}
    </div>
  );
}
