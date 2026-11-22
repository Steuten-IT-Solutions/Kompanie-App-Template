import { useState } from 'react';
import { api, can, type GallerySubmission } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, FileInput, Loading, useAsync } from '../ui';
import { confirmAsk, promptAsk } from '../ask';
import { IconButton } from '../icons';
import { InternToggle } from '../galleryIntern';
import { VIDEO_ACCEPT, countFiles, isVideoFile, submitVideo } from '../videoUpload';
import { t } from '../i18n';

/**
 * Bilder und Videos (Issue #350) für die Galerie einreichen (submit_gallery) und Einreichungen prüfen (review_gallery),
 * Issue #286. Unter Galerie → „Bilder und Videos einreichen“ bzw. „Einreichungen prüfen“. Aus einem Album heraus
 * (#/galerie/einreichen/<album>) ist dieses Album schon gewählt, und „‹ Zum Album“ führt dorthin zurück.
 */
export default function GalerieEinreichungen({
  mode,
  albumId,
  onToast,
}: {
  mode: 'einreichen' | 'pruefen';
  albumId?: number;
  onToast: (msg: string) => void;
}) {
  const fromAlbum = mode === 'einreichen' && albumId !== undefined;
  return (
    <div className="gallery-submissions">
      <button className="btn btn-link back" onClick={() => navigate(fromAlbum ? `galerie/${albumId}` : 'galerie')}>
        {fromAlbum ? t('‹ Zum Album') : t('‹ Alle Alben')}
      </button>
      {mode === 'einreichen' ? <Submit albumId={albumId} onToast={onToast} /> : <Review onToast={onToast} />}
    </div>
  );
}

const STATUS: Record<GallerySubmission['status'], string> = {
  pending: t('wartet auf Freigabe'),
  approved: t('freigegeben'),
  rejected: t('nicht freigegeben'),
};

function Submit({ albumId, onToast }: { albumId?: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.gallerySubmissions, []);
  const [chosen, setAlbum] = useState(albumId !== undefined ? String(albumId) : '');
  const [consent, setConsent] = useState(false);
  const [files, setFiles] = useState<File[]>([]);
  const [progress, setProgress] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  async function send() {
    setFormError(null);
    let done = 0;
    try {
      for (const f of files) {
        const step = { current: done + 1, total: files.length };
        setProgress(files.length > 1 ? t('Lädt {current} von {total} …', step) : t('Lädt …'));
        if (isVideoFile(f)) {
          await submitVideo(f, {
            albumId: Number(album),
            onProgress: (share) => {
              const percent = Math.floor(share * 100);
              setProgress(
                files.length > 1
                  ? t('Lädt {current} von {total} … {percent} %', { ...step, percent })
                  : t('Lädt … {percent} %', { percent }),
              );
            },
          });
        } else {
          await api.submitGalleryPhoto(Number(album), f);
        }
        done++;
      }
      onToast(t('{files} eingereicht', { files: countFiles(files) }));
      setFiles([]);
      setConsent(false);
    } catch (e) {
      const message = (e as Error).message;
      setFormError(done > 0 ? t('{count} eingereicht, dann: {error}', { count: done, error: message }) : message);
    } finally {
      setProgress(null);
      reload();
    }
  }

  if (loading && !data) return <Loading shape="text" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const albums = data?.albums ?? [];
  // Ein vorgewähltes Album, in das man nicht (mehr) einreichen kann (z. B. gelöscht), gilt als nicht gewählt.
  const album = albums.some((a) => String(a.id) === chosen) ? chosen : '';
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
        <h2>{t('Bilder und Videos einreichen')}</h2>
        {data?.enabled === false ? (
          <p className="muted small">{t('Das Einreichen ist zurzeit ausgeschaltet.')}</p>
        ) : albums.length === 0 ? (
          <p className="muted small">{t('Noch keine Alben, in die du einreichen kannst.')}</p>
        ) : (
          <>
            <label className="field">
              <span>{t('Album')}</span>
              <select value={album} onChange={(e) => setAlbum(e.target.value)} required>
                <option value="">{t('Bitte wählen …')}</option>
                {albums.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.year} · {a.title}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>{t('Bilder oder Videos')}</span>
              <FileInput
                accept={`.jpg,.jpeg,.png,.webp,${VIDEO_ACCEPT}`}
                multiple
                required
                disabled={busy}
                onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
              />
              <span className="small muted">{t('Bilder als JPG, PNG oder WebP, Videos als MP4 oder WebM bis 1 GB.')}</span>
            </label>
            <label className="toggle">
              <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} required />
              <span>{t('Ich habe die Rechte an den Bildern und Videos, und die erkennbaren Personen sind einverstanden.')}</span>
            </label>
            {formError && (
              <p className="form-error small" role="alert">
                {formError}
              </p>
            )}
            <button className="btn btn-primary" type="submit" disabled={busy || files.length === 0 || !consent || album === ''}>
              {progress ?? t('Einreichen')}
            </button>
          </>
        )}
      </form>
      <section className="card settings-section">
        <h3>{t('Deine Einreichungen')}</h3>
        {(data?.submissions ?? []).length === 0 ? (
          <p className="muted small">{t('Noch keine.')}</p>
        ) : (
          <ul className="gallery-upload-list">
            {data!.submissions.map((s) => (
              <li key={s.id} className="gallery-upload">
                <Preview s={s} />
                <span className="small">
                  {s.name} · {s.album} · <strong>{STATUS[s.status]}</strong>
                  {s.reason && `: ${s.reason}`}
                </span>
                {s.status === 'pending' && (
                  <button
                    type="button"
                    className="btn btn-small btn-secondary"
                    onClick={async () => {
                      if (!(await confirmAsk(t('Einreichung „{name}“ zurückziehen?', { name: s.name })))) return;
                      try {
                        await api.withdrawGallerySubmission(s.id);
                        onToast(t('Zurückgezogen'));
                      } catch (e) {
                        setFormError((e as Error).message);
                      }
                      reload();
                    }}
                  >
                    {t('Zurückziehen')}
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
  // Nur sehen (view_submissions): Einreichungen ansehen; freigeben, ablehnen, umstellen und entfernen mit review_gallery
  const editable = can('review_gallery');
  const { data, error, loading, reload } = useAsync(api.galleryReview, []);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  async function run(fn: () => Promise<unknown>, msg: string): Promise<boolean> {
    setBusy(true);
    setActionError(null);
    try {
      await fn();
      onToast(msg);
      return true;
    } catch (e) {
      setActionError((e as Error).message);
      return false;
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
      <h2>{editable ? t('Einreichungen prüfen') : t('Einreichungen')}</h2>
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
      {pending.length === 0 ? (
        <Empty>{t('Keine offenen Einreichungen.')}</Empty>
      ) : (
        <ul className="gallery-review-list">
          {pending.map((s) => (
            <li key={s.id} className="card gallery-review-item">
              {s.video ? (
                // Vor der Freigabe ansehen; Standbild als Vorschau
                <video src={s.video} poster={s.thumb ?? undefined} controls preload="none" playsInline aria-label={s.name} />
              ) : (
                <Preview s={s} />
              )}
              <p className="small">
                <strong>{s.name}</strong> {t('für {album}, von {name}', { album: s.album, name: s.user_name ?? '' })}
              </p>
              {editable && (
                <div className="button-row">
                  <button
                    type="button"
                    className="btn btn-small btn-primary"
                    disabled={busy}
                    onClick={() => void run(() => api.approveGallerySubmission(s.id, false), t('Freigegeben'))}
                  >
                    {t('Öffentlich freigeben')}
                  </button>
                  <button
                    type="button"
                    className="btn btn-small btn-secondary"
                    disabled={busy}
                    onClick={() => void run(() => api.approveGallerySubmission(s.id, true), t('Intern freigegeben'))}
                  >
                    {t('Nur für Mitglieder freigeben')}
                  </button>
                  <button
                    type="button"
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={async () => {
                      const reason = await promptAsk(t('Warum kommt „{name}“ nicht in die Galerie?', { name: s.name }));
                      if (reason === null) return;
                      void run(() => api.rejectGallerySubmission(s.id, reason), t('Abgelehnt'));
                    }}
                  >
                    {t('Ablehnen')}
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {approved.length > 0 && (
        <section className="card settings-section">
          <h3>{t('Zuletzt freigegeben')}</h3>
          <ul className="gallery-upload-list">
            {approved.map((s) => (
              <li key={s.id} className="gallery-upload gallery-approved">
                <Preview s={s} />
                <span className="small">
                  {s.name} · {s.album} · {t('von {name}', { name: s.user_name ?? '' })}
                </span>
                {editable && (
                  <div className="gallery-approved-actions">
                    {s.photo_id !== null && typeof s.intern === 'boolean' && (
                      <InternToggle
                        checked={s.intern}
                        name={s.name}
                        disabled={busy}
                        onChange={(next) =>
                          run(
                            () => api.setGalleryPhotoIntern(s.photo_id!, next),
                            next ? t('Jetzt nur für Mitglieder') : t('Jetzt öffentlich'),
                          )
                        }
                      />
                    )}
                    <IconButton
                      icon="delete"
                      label={t('Wieder entfernen')}
                      variant="danger"
                      disabled={busy}
                      onClick={async () => {
                        const question =
                          s.type === 'video'
                            ? t('Video „{name}“ wieder aus der Galerie entfernen?', { name: s.name })
                            : t('Bild „{name}“ wieder aus der Galerie entfernen?', { name: s.name });
                        if (!(await confirmAsk(question))) return;
                        void run(() => api.removeGallerySubmission(s.id), t('Entfernt'));
                      }}
                    />
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
    </>
  );
}

/** Vorschau einer Einreichung: Standbild bzw. Bild, bei einem Video mit ▶ darüber */
function Preview({ s }: { s: GallerySubmission }) {
  if (s.type !== 'video') return s.thumb ? <img src={s.thumb} alt={s.name} loading="lazy" /> : null;
  return (
    <span className="gallery-upload-video" role="img" aria-label={t('Video {name}', { name: s.name })}>
      {s.thumb && <img src={s.thumb} alt="" loading="lazy" />}
      <span aria-hidden="true">▶</span>
    </span>
  );
}
