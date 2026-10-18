import { useState } from 'react';
import { api, config, type AdminSettings } from '../api';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';
import { Calendar, Gallery, News } from './Einstellungen';

type Step = 'start' | 'kalender' | 'galerie' | 'news' | 'fertig';

/**
 * Einrichtungsassistent beim ersten Öffnen durch einen Admin: Kalender, Galerie und Neuigkeiten
 * Schritt für Schritt (jeder Schritt lässt sich überspringen), am Ende Statusmeldungen (nur mit
 * Zustimmung). Alles lässt sich später unter Einstellungen ändern.
 */
export default function Einrichtung({ onToast }: { onToast: (msg: string) => void }) {
  const settings = useAsync(() => api.settings(), []);
  const status = useAsync(() => api.status(), []);
  const [index, setIndex] = useState(0);
  const [reports, setReports] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if ((settings.loading && !settings.data) || (status.loading && !status.data)) return <Loading />;
  if (settings.error) return <ErrorBox message={settings.error} onRetry={settings.reload} />;
  if (status.error) return <ErrorBox message={status.error} onRetry={status.reload} />;
  const s = settings.data!;
  const f = config?.features;

  const steps: Step[] = ['start'];
  if (f?.calendar) steps.push('kalender');
  if (f?.gallery) steps.push('galerie');
  if (f?.news && s.news) steps.push('news');
  steps.push('fertig');
  const step = steps[Math.min(index, steps.length - 1)];
  const next = () => setIndex(index + 1);
  const onSaved = (n: AdminSettings) => settings.setData(n);

  async function finish() {
    setBusy(true);
    setError(null);
    try {
      if (reports) await api.updateOptions({ status_reports: true });
      await api.setupDone();
      if (config) config.setupPending = false;
      onToast('Einrichtung abgeschlossen');
      navigate('verwaltung');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="settings">
      <p className="setup-steps small">
        Schritt {steps.indexOf(step) + 1} von {steps.length}
      </p>
      {step === 'start' && (
        <section className="card settings-section">
          <h3>Willkommen!</h3>
          <p>Die App läuft. In wenigen Schritten verbinden wir sie mit dem Kalender, den Fotos und den Neuigkeiten der Kompanie.</p>
          <p className="muted small">Jeder Schritt lässt sich überspringen und später unter Einstellungen nachholen.</p>
          <button type="button" className="btn btn-block" onClick={next}>
            Los geht's
          </button>
        </section>
      )}
      {step === 'kalender' && <Calendar settings={s} onSaved={onSaved} onToast={onToast} />}
      {step === 'galerie' && <Gallery settings={s} onSaved={onSaved} onToast={onToast} />}
      {step === 'news' && <News settings={s} onSaved={onSaved} onToast={onToast} />}
      {step === 'fertig' && (
        <section className="card settings-section">
          <h3>Fast fertig</h3>
          <p className="small">
            Updates kommen automatisch; vorher wird gesichert. Den Zustand der App, Updates und die Datensicherung findest du unter{' '}
            <strong>Konto → Einstellungen</strong>.
          </p>
          {status.data!.statusReports.available && (
            <label className="toggle">
              <input type="checkbox" checked={reports} onChange={(e) => setReports(e.target.checked)} />
              <span>
                Einmal täglich eine kurze Statusmeldung an die Betreuer der App senden (Version, PHP-Version, ob Updates und Galerie
                klappen; keine Namen oder Inhalte). So fallen Probleme früher auf.
              </span>
            </label>
          )}
          <p className="small">Als Nächstes legst du die Mitglieder an; sie bekommen von dir Benutzername und Startpasswort.</p>
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <button type="button" className="btn btn-block" disabled={busy} onClick={finish}>
            {busy ? 'Speichert …' : 'Einrichtung abschließen'}
          </button>
        </section>
      )}
      {step !== 'start' && step !== 'fertig' && (
        <div className="button-row">
          <button type="button" className="btn btn-secondary" onClick={() => setIndex(index - 1)}>
            Zurück
          </button>
          <button type="button" className="btn" onClick={next}>
            Weiter
          </button>
        </div>
      )}
    </div>
  );
}
