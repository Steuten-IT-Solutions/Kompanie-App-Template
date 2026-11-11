import { useState } from 'react';
import { api, can } from './api';
import { confirmAsk } from './ask';
import { dataUpdated } from './dataCache';
import { ErrorBox, Loading, Sheet, useAsync } from './ui';

/**
 * Galerie direkt in der Galerie verwalten (Recht manage_gallery, Admins sowieso): hochgeladene Bilder und Videos
 * verschieben, löschen und intern oder öffentlich stellen, Alben der App umbenennen, ins andere Jahr setzen und
 * löschen, auch mehrere auf einmal. Bilder und Alben aus Google Drive ändert man in Drive.
 */
export function canManageGallery(): boolean {
  return can('manage_gallery') || can('manage_settings');
}

/** „3 Bilder und Videos“ für Rückfragen und Meldungen. */
export function itemsLabel(n: number): string {
  return n === 1 ? '1 Bild oder Video' : `${n} Bilder und Videos`;
}

/** Fenster: Album wählen, in das die gewählten Bilder und Videos kommen (alle Alben, auch aus Drive). */
export function MoveSheet({
  ids,
  currentAlbum,
  top = false,
  onClose,
  onMoved,
}: {
  ids: number[];
  currentAlbum: number;
  /** über der Großansicht */
  top?: boolean;
  onClose: () => void;
  onMoved: (albumId: number, title: string) => void;
}) {
  const { data, error, loading, reload } = useAsync(api.galleryMoveTargets, []);
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const [moveError, setMoveError] = useState<string | null>(null);
  const albums = (data?.albums ?? []).filter((a) => a.id !== currentAlbum);
  const years = [...new Set(albums.map((a) => a.year))].sort((a, b) => b.localeCompare(a, 'de', { numeric: true }));

  async function move() {
    const id = Number(target);
    const album = albums.find((a) => a.id === id);
    if (!album) return;
    setBusy(true);
    setMoveError(null);
    try {
      await api.moveGalleryPhotos(ids, id);
      dataUpdated();
      onMoved(id, album.title);
    } catch (e) {
      setMoveError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet title={`${itemsLabel(ids.length)} verschieben`} onClose={onClose} top={top}>
      {loading && !data && <Loading shape="text" />}
      {error && <ErrorBox message={error} onRetry={reload} />}
      {data && albums.length === 0 && <p className="muted small">Es gibt noch kein anderes Album. Lege es zuerst an.</p>}
      {data && albums.length > 0 && (
        <form
          className="form stack"
          onSubmit={(e) => {
            e.preventDefault();
            void move();
          }}
        >
          <label className="field">
            <span>In das Album</span>
            <select value={target} onChange={(e) => setTarget(e.target.value)} required>
              <option value="">Bitte wählen</option>
              {years.map((y) => (
                <optgroup key={y} label={y}>
                  {albums
                    .filter((a) => a.year === y)
                    .map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.title}
                        {a.source === 'drive' ? ' (aus Google Drive)' : ''}
                      </option>
                    ))}
                </optgroup>
              ))}
            </select>
          </label>
          {moveError && (
            <p className="form-error small" role="alert">
              {moveError}
            </p>
          )}
          <button className="btn btn-primary" type="submit" disabled={busy || target === ''}>
            Verschieben
          </button>
        </form>
      )}
    </Sheet>
  );
}

/** Fenster: Album der App umbenennen, ins andere Jahr setzen oder mit allem darin löschen. */
export function AlbumEditSheet({
  album,
  count,
  onClose,
  onSaved,
  onDeleted,
}: {
  album: { id: number; title: string; year: string };
  /** Bilder und Videos, die die Person darin sieht (für die Rückfrage beim Löschen) */
  count: number;
  onClose: () => void;
  onSaved: () => void;
  onDeleted: () => void;
}) {
  const [title, setTitle] = useState(album.title);
  const [year, setYear] = useState(album.year);
  const [busy, setBusy] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);

  async function run(fn: () => Promise<unknown>): Promise<boolean> {
    setBusy(true);
    setEditError(null);
    try {
      await fn();
      dataUpdated();
      return true;
    } catch (e) {
      setEditError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  return (
    <Sheet title="Album bearbeiten" onClose={onClose}>
      <form
        className="form stack"
        onSubmit={async (e) => {
          e.preventDefault();
          if (await run(() => api.updateGalleryAlbum(album.id, { title: title.trim(), year: Number(year) }))) onSaved();
        }}
      >
        <label className="field">
          <span>Titel</span>
          <input type="text" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>Jahr</span>
          <input type="number" min={1800} value={year} onChange={(e) => setYear(e.target.value)} required />
        </label>
        {editError && (
          <p className="form-error small" role="alert">
            {editError}
          </p>
        )}
        <div className="button-row">
          <button className="btn btn-primary" type="submit" disabled={busy}>
            Speichern
          </button>
          <button
            type="button"
            className="btn btn-danger"
            disabled={busy}
            onClick={async () => {
              if (!(await confirmAsk(deleteAlbumsQuestion([{ title: album.title, count }])))) return;
              if (await run(() => api.deleteGalleryAlbum(album.id))) onDeleted();
            }}
          >
            Album löschen
          </button>
        </div>
      </form>
    </Sheet>
  );
}

/** Rückfrage vor dem Löschen von Alben: nennt, wie viel mitgelöscht wird. */
export function deleteAlbumsQuestion(albums: { title: string; count: number }[]): string {
  const total = albums.reduce((n, a) => n + a.count, 0);
  const what = albums.length === 1 ? `Album „${albums[0].title}“` : `${albums.length} Alben`;
  return total === 0 ? `${what} löschen?` : `${what} löschen? Dabei werden ${itemsLabel(total)} mitgelöscht.`;
}
