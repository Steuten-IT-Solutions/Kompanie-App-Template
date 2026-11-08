import { Suspense, useState, type FormEvent } from 'react';
import { api, ApiError, type NewsItem } from '../api';
import { Loading, lazyView } from '../ui';
import { blocksInput, draftsFrom, newBlock, type BlockDraft } from '../blocks/blockDrafts';

const BlocksEditor = lazyView(() => import('../blocks/BlocksEditor'));

/** Grenze wie auf dem Server (NewsService::TITLE_MAX). */
const TITLE_MAX = 150;

/**
 * Beitrag der News in der App schreiben oder ändern (Quelle „In der App“, Recht manage_news): Titel und Inhalt aus
 * Blöcken (Text mit Bildern, Countdown …) und ein Titelbild (Issue #351); ohne Titelbild ist das erste Bild in einem
 * Textblock für alle das Vorschaubild der Liste. Beim Ändern schickt die App den geladenen Stand mit; hat jemand dazwischen gespeichert, sagt der Server 409 conflict.
 */
export default function NewsSchreiben({
  item,
  onDone,
  onToast,
}: {
  /** Beitrag zum Ändern (mit content und updated_at); ohne: neuer Beitrag */
  item?: NewsItem;
  onDone: (saved: NewsItem | null) => void;
  onToast: (msg: string) => void;
}) {
  const [title, setTitle] = useState(item?.title ?? '');
  const [blocks, setBlocks] = useState<BlockDraft[]>(() => (item ? draftsFrom(item.blocks, item.content ?? '') : [newBlock('text')]));
  const [cover, setCover] = useState<{ uid: string | null; url: string | null }>({
    uid: item?.cover_uid ?? null,
    url: item?.cover ?? null,
  });
  const [uploading, setUploading] = useState(false);
  const [coverError, setCoverError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  async function upload(file: File | null) {
    if (!file) return;
    setUploading(true);
    setCoverError(null);
    try {
      const img = await api.uploadRichImage(file);
      setCover({ uid: img.uid, url: img.url });
    } catch (e) {
      setCoverError((e as Error).message);
    } finally {
      setUploading(false);
    }
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const data = { title: title.trim(), blocks: blocksInput(blocks), cover: cover.uid };
      const saved = item ? await api.updateNews(item.id, { ...data, updated_at: item.updated_at ?? null }) : await api.createNews(data);
      onToast(item ? 'Gespeichert' : 'Beitrag veröffentlicht');
      onDone(saved);
    } catch (err) {
      setConflict(err instanceof ApiError && err.code === 'conflict');
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card news-editor" onSubmit={save}>
      <h3>{item ? 'Beitrag bearbeiten' : 'Neuer Beitrag'}</h3>
      <label className="field">
        <span>Titel</span>
        <input type="text" maxLength={TITLE_MAX} value={title} onChange={(e) => setTitle(e.target.value)} required autoFocus />
      </label>
      <div className="field news-cover-field">
        <span>Titelbild (optional)</span>
        {cover.url && (
          <div className="card news-cover-preview">
            <img className="news-image" src={cover.url} alt="Titelbild" />
          </div>
        )}
        <div className="button-row">
          <label className="btn btn-small btn-secondary">
            {uploading ? 'Lädt …' : cover.url ? 'Bild ersetzen' : 'Bild wählen'}
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              hidden
              aria-label="Titelbild"
              disabled={uploading}
              onChange={(e) => {
                void upload(e.target.files?.[0] ?? null);
                e.target.value = '';
              }}
            />
          </label>
          {cover.url && (
            <button type="button" className="btn btn-small btn-secondary" onClick={() => setCover({ uid: null, url: null })}>
              Bild entfernen
            </button>
          )}
        </div>
        {coverError && (
          <p className="form-error" role="alert">
            {coverError}
          </p>
        )}
      </div>
      <div className="field">
        <span>Inhalt</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksEditor value={blocks} onChange={setBlocks} idPrefix={`news-${item?.id ?? 'neu'}`} />
        </Suspense>
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || conflict || uploading}>
          {item ? 'Speichern' : 'Veröffentlichen'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => onDone(null)}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}
