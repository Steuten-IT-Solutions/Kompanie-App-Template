import { Suspense, useState, type FormEvent } from 'react';
import { api, ApiError, type NewsItem } from '../api';
import { Loading, lazyView } from '../ui';

const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/** Grenze wie auf dem Server (NewsService::TITLE_MAX). */
const TITLE_MAX = 150;

/**
 * Beitrag der News in der App schreiben oder ändern (Quelle „In der App“, Recht manage_news): Titel und formatierter
 * Text mit Bildern; das erste Bild ist das Vorschaubild in der Liste. Beim Ändern schickt die App den geladenen Stand
 * mit; hat jemand dazwischen gespeichert, sagt der Server 409 conflict.
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
  const [body, setBody] = useState(item?.content ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const data = { title: title.trim(), body };
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
      <div className="field">
        <Suspense fallback={<Loading shape="text" chunk />}>
          <RichTextEditor value={body} onChange={setBody} label="Text" id={`news-${item?.id ?? 'neu'}-body`} />
        </Suspense>
      </div>
      <p className="muted small">Das erste Bild im Text erscheint auch in der Übersicht.</p>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || conflict}>
          {item ? 'Speichern' : 'Veröffentlichen'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => onDone(null)}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}
