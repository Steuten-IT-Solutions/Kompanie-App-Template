import { useEffect, useRef, useState } from 'react';
import { api, applyFeatures, config, refreshRoles, type AdminSettings, type Feature } from '../api';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';
import { Calendar, FeatureSwitches, Gallery, News } from './Einstellungen';

type Step = 'bereiche' | 'kalender' | 'galerie' | 'news' | 'fertig';

/**
 * Einrichtungsassistent beim ersten Öffnen durch einen Admin. Erster Schritt: die Bereiche wählen
 * (neue Installationen haben keine; der Schritt lässt sich nicht überspringen). Danach Kalender,
 * Galerie und Neuigkeiten, aber nur für die gewählten Bereiche (diese Schritte lassen sich
 * überspringen), am Ende Statusmeldungen (nur mit Zustimmung). Alles lässt sich später unter
 * Einstellungen ändern.
 */
export default function Einrichtung({ onToast }: { onToast: (msg: string) => void }) {
  const settings = useAsync(() => api.settings(), []);
  const status = useAsync(() => api.status(), []);
  const features = useAsync(() => api.features(), []);
  const [chosen, setChosen] = useState<Record<Feature, boolean> | null>(null);
  const [index, setIndex] = useState(0);
  const [reports, setReports] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const stepRef = useRef<HTMLParagraphElement>(null);
  // Beim Schrittwechsel den Fokus mitnehmen (Bildschirmleser, Tastatur).
  useEffect(() => {
    if (index > 0) stepRef.current?.focus();
  }, [index]);

  if ((settings.loading && !settings.data) || (features.loading && !features.data)) return <Loading />;
  if (settings.error) return <ErrorBox message={settings.error} onRetry={settings.reload} />;
  if (features.error) return <ErrorBox message={features.error} onRetry={features.reload} />;
  const s = settings.data!;
  const list = features.data!;
  const value = chosen ?? (Object.fromEntries(list.map((x) => [x.key, x.enabled])) as Record<Feature, boolean>);
  // Der Zustand wird nur für die Statusmeldungen gebraucht; fehlt er, geht es ohne diese Frage weiter.
  const reportsAvailable = !!status.data?.statusReports.available;
  // Folgeschritte nach der Auswahl (nicht nach „eingerichtet“: genau das passiert in diesen Schritten).
  const steps: Step[] = ['bereiche'];
  if (value.calendar) steps.push('kalender');
  if (value.gallery) steps.push('galerie');
  if (value.news && s.news) steps.push('news');
  steps.push('fertig');
  const step = steps[Math.min(index, steps.length - 1)];
  const next = () => setIndex(index + 1);
  const onSaved = (n: AdminSettings) => settings.setData(n);

  async function saveFeatures() {
    setBusy(true);
    setError(null);
    try {
      const saved = await api.setFeatures(value);
      features.setData(saved);
      setChosen(null);
      applyFeatures(saved);
      await refreshRoles();
      setIndex(1);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function finish() {
    setBusy(true);
    setError(null);
    try {
      if (reports) await api.updateOptions({ status_reports: true });
      await api.setupDone();
      if (config) config.setupPending = false;
      // Kalender, Galerie und News sind jetzt ggf. eingerichtet: Tabs der App neu bestimmen.
      applyFeatures(await api.features());
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
      <p className="setup-steps small" ref={stepRef} tabIndex={-1} aria-live="polite">
        Schritt {steps.indexOf(step) + 1} von {steps.length}
      </p>
      {step === 'bereiche' && (
        <section className="card settings-section">
          <h3>Willkommen!</h3>
          <p>
            Die App läuft. Wähle zuerst, welche Bereiche eure Kompanie nutzen möchte. Danach verbinden wir die gewählten Bereiche, z. B. mit
            dem Kalender oder den Fotos.
          </p>
          <FeatureSwitches list={list} value={value} disabled={busy} onChange={(key, on) => setChosen({ ...value, [key]: on })} />
          <p className="muted small">
            Die Auswahl lässt sich jederzeit unter Einstellungen → Bereiche ändern. Die folgenden Schritte lassen sich überspringen.
          </p>
          {!Object.values(value).some(Boolean) && <p className="small">Ohne Bereich zeigt die App nur die Installationsanleitung.</p>}
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <button type="button" className="btn btn-block" disabled={busy} onClick={saveFeatures}>
            {busy ? 'Speichert …' : 'Weiter'}
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
            Updates kommen automatisch; bei Datenbank-Änderungen werden die Daten vorher gesichert. Den Zustand der App, Updates und die
            Datensicherung findest du unter <strong>Konto → Einstellungen</strong>.
          </p>
          {reportsAvailable && (
            <label className="toggle">
              <input type="checkbox" checked={reports} onChange={(e) => setReports(e.target.checked)} />
              <span>
                Statusmeldungen an die Betreuer der App senden: stündlich ein Lebenszeichen (Version, PHP-Version, Ampel je Bereich,
                Fehlercodes), schwere Fehler sofort; keine Namen, Inhalte oder Fehlertexte. So fallen Probleme früher auf.
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
      {step !== 'bereiche' && step !== 'fertig' && (
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
