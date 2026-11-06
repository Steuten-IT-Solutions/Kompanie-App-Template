import { useState } from 'react';
import { api, type GallerySubmission } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

/**
 * Bilder für die Galerie einreichen (submit_gallery) und Einreichungen prüfen (review_gallery), Issue #286. Unter
 * Galerie → „Bilder einreichen“ bzw. „Einreichungen prüfen“.
 */
export default function GalerieEinreichungen({ mode, onToast }: { mode: 'einreichen' | 'pruefen'; onToast: (msg: string) => void }) {
  return (
    <div className="gallery-submissions">
      <button className="btn btn-link back" onClick={() => navigate('galerie')}>
        ‹ Alle Alben
      </button>
      {mode === 'einreichen' ? <Submit onToast={onToast} /> : <Review onToast={onToast} />}
    </div>
  );
}

const STATUS: Record<GallerySubmission['status'], string> = {
  pending: 'wartet auf Freigabe',
  approved: 'freigegeben',
  rejected: 'nicht freigegeben',
};

function Submit({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.gallerySubmissions, []);
  const [album, setAlbum] = useState('');
  const [consent, setConsent] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [progress, setProgress] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  async function send() {
    setFormError(null);
    let done = 0;
    try {
      for (const f of files) {
        setProgress(`Lädt ${done + 1} von ${files.length} …`);
        await api.submitGalleryPhoto(Number(album), f);
        done++;
      }
      onToast(done === 1 ? 'Bild eingereicht' : `${done} Bilder eingereicht`);
      setFiles([]);
      setConsent(false);
    } catch (e) {
      setFormError(`${done > 0 ? `${done} eingereicht, dann: ` : ''}${(e as Error).message}`);
    } finally {
      setProgress(null);
      reload();
    }
  }

  if (loading && !data) return <Loading shape="text" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const albums = data?.albums ?? [];
  const busy = progress !== null;

  return (
    <>
      <form
        className="card form gallery-submit"
        onSubmit={(e) => {
          e.preventDefault();
          void send();
        }}
      >
        <h2>Bilder einreichen</h2>
        <p className="muted small">
          Eingereichte Bilder erscheinen erst, wenn jemand sie freigibt. Der Server entfernt dabei Metadaten wie den Aufnahmeort.
        </p>
        {albums.length === 0 ? (
          <p className="muted small">Noch keine Alben, in die du einreichen kannst.</p>
        ) : (
          <>
            <label className="field">
              <span>Album</span>
              <select value={album} onChange={(e) => setAlbum(e.target.value)} required>
                <option value="">Bitte wählen …</option>
                {albums.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.year} · {a.title}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Bilder (JPG, PNG oder WebP)</span>
              <input
                type="file"
                accept=".jpg,.jpeg,.png,.webp"
                multiple
                required
                disabled={busy}
                onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
              />
            </label>
            <label className="toggle">
              <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} required />
              <span>Ich habe die Rechte an den Bildern, und die erkennbaren Personen sind einverstanden.</span>
            </label>
            {formError && (
              <p className="form-error small" role="alert">
                {formError}
              </p>
            )}
            <button className="btn btn-primary" type="submit" disabled={busy || files.length === 0 || !consent || album === ''}>
              {progress ?? 'Einreichen'}
            </button>
          </>
        )}
      </form>
      <section className="card">
        <h3>Deine Einreichungen</h3>
        {(data?.submissions ?? []).length === 0 ? (
          <p className="muted small">Noch keine.</p>
        ) : (
          <ul className="gallery-upload-list">
            {data!.submissions.map((s) => (
              <li key={s.id} className="gallery-upload">
                {s.thumb && <img src={s.thumb} alt={s.name} loading="lazy" />}
                <span className="small">
                  {s.name} · {s.album} · <strong>{STATUS[s.status]}</strong>
                  {s.reason && `: ${s.reason}`}
                </span>
                {s.status === 'pending' && (
                  <button
                    type="button"
                    className="btn btn-small btn-secondary"
                    onClick={async () => {
                      if (!window.confirm(`Einreichung „${s.name}“ zurückziehen?`)) return;
                      try {
                        await api.withdrawGallerySubmission(s.id);
                        onToast('Zurückgezogen');
                      } catch (e) {
                        setFormError((e as Error).message);
                      }
                      reload();
                    }}
                  >
                    Zurückziehen
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  );
}

function Review({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.galleryReview, []);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  async function run(fn: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setActionError(null);
    try {
      await fn();
      onToast(msg);
    } catch (e) {
      setActionError((e as Error).message);
    } finally {
      setBusy(false);
      reload();
    }
  }

  if (loading && !data) return <Loading shape="text" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const pending = data?.pending ?? [];
  const approved = data?.approved ?? [];

  return (
    <>
      <h2>Einreichungen prüfen</h2>
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
      {pending.length === 0 ? (
        <Empty>Keine offenen Einreichungen.</Empty>
      ) : (
        <ul className="gallery-review-list">
          {pending.map((s) => (
            <li key={s.id} className="card gallery-review-item">
              {s.thumb && <img src={s.thumb} alt={s.name} loading="lazy" />}
              <p className="small">
                <strong>{s.name}</strong> für {s.album}, von {s.user_name}
              </p>
              <div className="button-row">
                <button
                  type="button"
                  className="btn btn-small btn-primary"
                  disabled={busy}
                  onClick={() => void run(() => api.approveGallerySubmission(s.id, false), 'Freigegeben')}
                >
                  Öffentlich freigeben
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => void run(() => api.approveGallerySubmission(s.id, true), 'Intern freigegeben')}
                >
                  Nur für Mitglieder freigeben
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-danger"
                  disabled={busy}
                  onClick={() => {
                    const reason = window.prompt(`Warum kommt „${s.name}“ nicht in die Galerie?`);
                    if (reason === null) return;
                    void run(() => api.rejectGallerySubmission(s.id, reason), 'Abgelehnt');
                  }}
                >
                  Ablehnen
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {approved.length > 0 && (
        <section className="card">
          <h3>Zuletzt freigegeben</h3>
          <ul className="gallery-upload-list">
            {approved.map((s) => (
              <li key={s.id} className="gallery-upload">
                {s.thumb && <img src={s.thumb} alt={s.name} loading="lazy" />}
                <span className="small">
                  {s.name} · {s.album} · von {s.user_name}
                </span>
                <button
                  type="button"
                  className="btn btn-small btn-danger"
                  disabled={busy}
                  onClick={() => {
                    if (!window.confirm(`Bild „${s.name}“ wieder aus der Galerie entfernen?`)) return;
                    void run(() => api.removeGallerySubmission(s.id), 'Entfernt');
                  }}
                >
                  Wieder entfernen
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}
