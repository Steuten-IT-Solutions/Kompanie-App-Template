import { useState } from 'react';
import { albumEditable, api, type GalleryAdminAlbum } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';
import { confirmAsk } from '../ask';
import { InternToggle } from '../galleryIntern';
import { VIDEO_ACCEPT, countFiles, isVideoFile, uploadAlbumVideo } from '../videoUpload';
import { t } from '../i18n';
import { TranslationsHint } from '../language';

/**
 * Alben der App und hochgeladene Bilder (Issue #285), unter Einstellungen → Galerie: Album anlegen (nur in der App),
 * Bilder und Videos (Issue #350) in jedes Album hochladen, auch in eines aus Google Drive, hochgeladene löschen. Alben
 * aus Drive ändert man hier nur mit Drive als Ablage (editable), sonst in Drive.
 */
export default function GalleryAlbums({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(api.galleryAlbums, []);
  const [title, setTitle] = useState('');
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);

  async function create() {
    setBusy(true);
    setFormError(null);
    try {
      const a = await api.createGalleryAlbum({ title: title.trim(), year: Number(year) });
      setTitle('');
      onToast(t('Album angelegt'));
      reload();
      setOpen(a.id);
    } catch (e) {
      setFormError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card settings-section gallery-albums" id="einstellungen-galerie-alben">
      <h3>{t('Alben und eigene Bilder')}</h3>
      {loading && !data && <Loading shape="text" />}
      {error && <ErrorBox message={error} onRetry={reload} />}
      {data && data.albums.length === 0 && <p className="muted small">{t('Noch keine Alben.')}</p>}
      {data && data.albums.length > 0 && (
        <ul className="gallery-album-list">
          {data.albums.map((a) => (
            <li key={a.id} className="gallery-album-item">
              <button
                type="button"
                className="gallery-album-head"
                aria-expanded={open === a.id}
                onClick={() => setOpen(open === a.id ? null : a.id)}
              >
                <strong>
                  {a.year} · {a.title}
                </strong>
                <span className="small muted">
                  {' '}
                  {a.source === 'app' ? t('nur in der App') : t('aus Google Drive')} · {countLabel(a)}
                </span>
              </button>
              {open === a.id && <AlbumUploads album={a} onToast={onToast} onChanged={reload} onDeleted={() => setOpen(null)} />}
            </li>
          ))}
        </ul>
      )}
      <form
        className="form gallery-album-new"
        onSubmit={(e) => {
          e.preventDefault();
          void create();
        }}
      >
        <h4>{t('Neues Album in der App')}</h4>
        <label className="field">
          <span>{t('Titel')}</span>
          <input type="text" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <TranslationsHint />
        <label className="field">
          <span>{t('Jahr')}</span>
          <input type="number" min={1800} value={year} onChange={(e) => setYear(e.target.value)} required />
        </label>
        {formError && (
          <p className="form-error small" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-secondary" type="submit" disabled={busy}>
          {t('Album anlegen')}
        </button>
      </form>
    </section>
  );
}

function countLabel(a: GalleryAdminAlbum): string {
  const parts = [];
  if (a.source === 'drive') parts.push(t('{count} aus Drive', { count: a.drive_photos }));
  parts.push(t('{count} hochgeladen', { count: a.uploads }));
  return parts.join(', ');
}

function AlbumUploads({
  album,
  onToast,
  onChanged,
  onDeleted,
}: {
  album: GalleryAdminAlbum;
  onToast: (msg: string) => void;
  onChanged: () => void;
  onDeleted: () => void;
}) {
  const { data, error, loading, reload, setData } = useAsync(() => api.galleryAdminAlbum(album.id), [album.id]);
  const [intern, setIntern] = useState(false);
  const [progress, setProgress] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [title, setTitle] = useState(album.title);
  const [year, setYear] = useState(album.year);

  async function upload(files: File[]) {
    setBusy(true);
    setActionError(null);
    let done = 0;
    try {
      for (const f of files) {
        const label = files.length > 1 ? t('Lädt {current} von {total} …', { current: done + 1, total: files.length }) : t('Lädt …');
        setProgress(label);
        if (isVideoFile(f)) {
          setData(
            await uploadAlbumVideo(f, {
              albumId: album.id,
              intern,
              onProgress: (share) => setProgress(`${label} ${Math.floor(share * 100)} %`),
            }),
          );
        } else {
          setData(await api.uploadGalleryPhoto(album.id, f, intern));
        }
        done++;
      }
      onToast(t('{files} hochgeladen', { files: countFiles(files) }));
    } catch (e) {
      const message = (e as Error).message;
      setActionError(done > 0 ? t('{count} hochgeladen, dann: {message}', { count: done, message }) : message);
    } finally {
      setProgress(null);
      setBusy(false);
      onChanged();
    }
  }

  async function run(fn: () => Promise<unknown>, msg: string) {
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
    }
  }

  return (
    <div className="gallery-album-body">
      {loading && !data && <Loading shape="text" />}
      {error && <ErrorBox message={error} onRetry={reload} />}
      <label className="toggle">
        <input type="checkbox" checked={intern} onChange={(e) => setIntern(e.target.checked)} />
        <span>{t('Nur für Mitglieder (intern)')}</span>
      </label>
      <label className="btn btn-secondary">
        {busy && progress ? progress : t('Bilder oder Videos hochladen')}
        <input
          type="file"
          accept={`.jpg,.jpeg,.png,.webp,${VIDEO_ACCEPT}`}
          multiple
          hidden
          aria-label={t('Bilder oder Videos in {album} hochladen', { album: album.title })}
          disabled={busy}
          onChange={(e) => {
            const files = Array.from(e.target.files ?? []);
            e.target.value = '';
            if (files.length > 0) void upload(files);
          }}
        />
      </label>
      <p className="small muted">{t('Bilder als JPG, PNG oder WebP, Videos als MP4 oder WebM bis 1 GB.')}</p>
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
      {data && data.uploads.length > 0 && (
        <ul className="gallery-upload-list">
          {data.uploads.map((p) => (
            <li key={p.id} className="gallery-upload">
              {p.thumb ? (
                <img src={p.thumb} alt={p.name} loading="lazy" />
              ) : (
                <span className="gallery-upload-video" role="img" aria-label={p.name}>
                  ▶
                </span>
              )}
              <span className="small">
                {p.name}
                {p.status === 'error' && ` · ${p.error || t('ließ sich nicht aufbereiten')}`}
              </span>
              <InternToggle
                checked={p.intern}
                name={p.name}
                disabled={busy}
                onChange={async (next) => {
                  const ok = await run(
                    () => api.setGalleryPhotoIntern(p.id, next),
                    next ? t('Jetzt nur für Mitglieder') : t('Jetzt öffentlich'),
                  );
                  if (ok) {
                    reload();
                    onChanged();
                  }
                  return ok;
                }}
              />
              <button
                type="button"
                className="btn btn-small btn-danger"
                disabled={busy}
                onClick={async () => {
                  const video = p.type === 'video';
                  const question = video ? t('Video „{name}“ löschen?', { name: p.name }) : t('Bild „{name}“ löschen?', { name: p.name });
                  if (!(await confirmAsk(question))) return;
                  if (await run(() => api.deleteGalleryPhoto(p.id), video ? t('Video gelöscht') : t('Bild gelöscht'))) {
                    reload();
                    onChanged();
                  }
                }}
              >
                {t('Löschen')}
              </button>
            </li>
          ))}
        </ul>
      )}
      {albumEditable(album) && (
        <form
          className="form gallery-album-edit"
          onSubmit={async (e) => {
            e.preventDefault();
            if (await run(() => api.updateGalleryAlbum(album.id, { title: title.trim(), year: Number(year) }), t('Gespeichert')))
              onChanged();
          }}
        >
          <label className="field">
            <span>{t('Titel des Albums')}</span>
            <input type="text" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} required />
          </label>
          <TranslationsHint />
          <label className="field">
            <span>{t('Jahr des Albums')}</span>
            <input type="number" min={1800} value={year} onChange={(e) => setYear(e.target.value)} required />
          </label>
          <div className="button-row">
            <button className="btn btn-small btn-secondary" type="submit" disabled={busy}>
              {t('Album speichern')}
            </button>
            <button
              type="button"
              className="btn btn-small btn-danger"
              disabled={busy}
              onClick={async () => {
                if (!(await confirmAsk(t('Album „{title}“ mit allen Bildern darin löschen?', { title: album.title })))) return;
                if (await run(() => api.deleteGalleryAlbum(album.id), t('Album gelöscht'))) {
                  onDeleted();
                  onChanged();
                }
              }}
            >
              {t('Album löschen')}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
