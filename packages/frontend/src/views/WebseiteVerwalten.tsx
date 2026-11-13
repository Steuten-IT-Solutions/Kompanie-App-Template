import { useEffect, useState } from 'react';
import { api, type WebsiteSettings } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Verwaltung → Einrichtungen → Webseite (Issue #273, manage_website): Einstiegsdatei im Ordner über der App anlegen und
 * eine Seite, die für alle sichtbar ist, als Startseite wählen. Die Webseite selbst rendert der Server.
 */
export function WebsiteAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.website(), [], 'website');
  const [home, setHome] = useState<string>('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (data) setHome(data.home_page_id === null ? '' : String(data.home_page_id));
  }, [data]);

  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (loading && !data) return <Loading shape="form" />;
  if (!data) return null;
  const site = data;

  async function run(action: () => Promise<WebsiteSettings>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      setData(await action());
      onToast(msg);
    } catch (e) {
      setFormError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const entry = site.entry;
  const canInstall = entry.state === 'missing' || entry.state === 'outdated';
  const homeChanged = home !== (site.home_page_id === null ? '' : String(site.home_page_id));

  return (
    <div className="stack">
      <section className="card stack">
        <h3>Adresse</h3>
        <p>{entry.message}</p>
        {canInstall && (
          <button className="btn" disabled={busy} onClick={() => void run(() => api.installWebsiteEntry(), 'Einstiegsdatei angelegt')}>
            Einstiegsdatei anlegen
          </button>
        )}
        {entry.state === 'not_writable' && (
          <>
            <p className="muted small">Inhalt der Datei index.php im Ordner über „{entry.folder}“:</p>
            <pre className="small payload">{entry.content}</pre>
          </>
        )}
        {entry.state === 'installed' && site.url && (
          <a className="btn btn-secondary" href={site.url} target="_blank" rel="noopener noreferrer">
            Webseite öffnen
          </a>
        )}
      </section>

      <section className="card stack">
        <h3>Startseite</h3>
        {!site.pages_enabled ? (
          <p className="muted">Die Webseite zeigt eure Seiten. Bitte zuerst unter Einstellungen → Bereiche die Seiten einschalten.</p>
        ) : site.pages.length === 0 ? (
          <p className="muted">
            Es gibt noch keine Seite, die für alle sichtbar ist. Bitte unter Verwaltung → Seiten eine anlegen und bei „Für wen?“ „Alle, auch
            ohne Anmeldung“ wählen.
          </p>
        ) : (
          <form
            className="stack"
            onSubmit={(e) => {
              e.preventDefault();
              void run(() => api.updateWebsite({ home_page_id: home === '' ? null : Number(home) }), 'Startseite gespeichert');
            }}
          >
            <label className="field">
              <span>Seite</span>
              <select value={home} onChange={(e) => setHome(e.target.value)}>
                <option value="">Keine</option>
                {site.pages.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.title}
                  </option>
                ))}
              </select>
            </label>
            <p className="muted small">
              Die Webseite zeigt die Seite so, wie Gäste sie in der App sehen. Blöcke nur für Mitglieder fehlen dort.
            </p>
            <button className="btn" type="submit" disabled={busy || !homeChanged}>
              Speichern
            </button>
          </form>
        )}
        {site.home_page_missing && (
          <p className="form-error">
            Die gewählte Startseite gibt es nicht mehr oder sie ist nicht mehr für alle sichtbar. Bitte eine andere wählen.
          </p>
        )}
      </section>

      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
    </div>
  );
}
