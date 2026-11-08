import { useState } from 'react';
import { api, type GalleryAdminAlbum } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';
import { confirmAsk } from '../ask';

/**
 * Alben der App und hochgeladene Bilder (Issue #285), unter Einstellungen → Galerie: Album anlegen (nur in der App),
 * Bilder in jedes Album hochladen, auch in eines aus Google Drive, hochgeladene Bilder löschen. Alben aus Drive ändert
 * man in Drive.
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
      onToast('Album angelegt');
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
      <h3>Alben und eigene Bilder</h3>
      {loading && !data && <Loading shape="text" />}
      {error && <ErrorBox message={error} onRetry={reload} />}
      {data && data.albums.length === 0 && <p className="muted small">Noch keine Alben.</p>}
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
                  {a.source === 'app' ? 'nur in der App' : 'aus Google Drive'} · {countLabel(a)}
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
        <h4>Neues Album in der App</h4>
        <label className="field">
          <span>Titel</span>
          <input type="text" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>Jahr</span>
          <input type="number" min={1800} value={year} onChange={(e) => setYear(e.target.value)} required />
        </label>
        {formError && (
          <p className="form-error small" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-secondary" type="submit" disabled={busy}>
          Album anlegen
        </button>
      </form>
    </section>
  );
}

function countLabel(a: GalleryAdminAlbum): string {
  const parts = [];
  if (a.source === 'drive') parts.push(`${a.drive_photos} aus Drive`);
  parts.push(a.uploads === 1 ? '1 hochgeladen' : `${a.uploads} hochgeladen`);
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
        setProgress(`Lädt ${done + 1} von ${files.length} …`);
        setData(await api.uploadGalleryPhoto(album.id, f, intern));
        done++;
      }
      onToast(done === 1 ? 'Bild hochgeladen' : `${done} Bilder hochgeladen`);
    } catch (e) {
      setActionError(`${done > 0 ? `${done} hochgeladen, dann: ` : ''}${(e as Error).message}`);
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
        <span>Nur für Mitglieder (intern)</span>
      </label>
      <label className="btn btn-secondary">
        {busy && progress ? progress : 'Bilder hochladen'}
        <input
          type="file"
          accept=".jpg,.jpeg,.png,.webp"
          multiple
          hidden
          aria-label={`Bilder in ${album.title} hochladen`}
          disabled={busy}
          onChange={(e) => {
            const files = Array.from(e.target.files ?? []);
            e.target.value = '';
            if (files.length > 0) void upload(files);
          }}
        />
      </label>
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
      {data && data.uploads.length > 0 && (
        <ul className="gallery-upload-list">
          {data.uploads.map((p) => (
            <li key={p.id} className="gallery-upload">
              <img src={p.thumb} alt={p.name} loading="lazy" />
              <span className="small">
                {p.name}
                {p.intern && ' (intern)'}
                {p.status === 'error' && ` · ${p.error || 'ließ sich nicht aufbereiten'}`}
              </span>
              <button
                type="button"
                className="btn btn-small btn-danger"
                disabled={busy}
                onClick={async () => {
                  if (!(await confirmAsk(`Bild „${p.name}“ löschen?`))) return;
                  if (await run(() => api.deleteGalleryPhoto(p.id), 'Bild gelöscht')) {
                    reload();
                    onChanged();
                  }
                }}
              >
                Löschen
              </button>
            </li>
          ))}
        </ul>
      )}
      {album.source === 'app' && (
        <form
          className="form gallery-album-edit"
          onSubmit={async (e) => {
            e.preventDefault();
            if (await run(() => api.updateGalleryAlbum(album.id, { title: title.trim(), year: Number(year) }), 'Gespeichert')) onChanged();
          }}
        >
          <label className="field">
            <span>Titel des Albums</span>
            <input type="text" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} required />
          </label>
          <label className="field">
            <span>Jahr des Albums</span>
            <input type="number" min={1800} value={year} onChange={(e) => setYear(e.target.value)} required />
          </label>
          <div className="button-row">
            <button className="btn btn-small btn-secondary" type="submit" disabled={busy}>
              Album speichern
            </button>
            <button
              type="button"
              className="btn btn-small btn-danger"
              disabled={busy}
              onClick={async () => {
                if (!(await confirmAsk(`Album „${album.title}“ mit allen Bildern darin löschen?`))) return;
                if (await run(() => api.deleteGalleryAlbum(album.id), 'Album gelöscht')) {
                  onDeleted();
                  onChanged();
                }
              }}
            >
              Album löschen
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
