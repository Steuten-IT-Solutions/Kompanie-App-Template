import { useEffect, useState } from 'react';
import { can, config, type NewsItem } from '../api';
import { newsDate } from '../format';
import GuestPushButton from '../GuestPushButton';
import { navigate } from '../router';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { t } from '../i18n';

// Mit DOMPurify (ca. 12 kB gzip): erst laden, wenn jemand einen Beitrag öffnet.
const NewsDetail = lazyView(() => import('./NewsArtikel'));
// Editor mit Tiptap: nur für die Pflege der News in der App
const NewsSchreiben = lazyView(() => import('./NewsSchreiben'));

/** Darf die Person hier News schreiben (Quelle „In der App“ und manage_news)? */
export const canWriteNews = (): boolean => !!config?.newsInApp && can('manage_news');

/** Adresse des Editors von WordPress für einen neuen Beitrag (Quelle „Beiträge dieser WordPress-Seite“, manage_news). */
// Wechselt ein Admin in den Einstellungen auf „In der App“, gilt sofort das Formular der App (newsInApp), nicht die
// beim Laden mitgeschickte Adresse.
export const newsEditorUrl = (): string | null => (config?.newsInApp ? null : (config?.newsEditorUrl ?? null));

/**
 * Den Editor von WordPress in einem eigenen Fenster öffnen (Browser bzw. Custom Tab); kommt man in die App zurück,
 * lädt sie die Beiträge neu, damit der gespeicherte Beitrag gleich zu sehen ist.
 */
export function openNewsEditor(url: string, onBack: () => void): void {
  window.open(url, '_blank', 'noopener');
  const back = () => {
    if (document.visibilityState !== 'visible') return;
    document.removeEventListener('visibilitychange', back);
    onBack();
  };
  document.addEventListener('visibilitychange', back);
}

type Toast = (msg: string) => void;

export default function News({ id, onToast = () => {} }: { id?: string; onToast?: Toast }) {
  return id ? <NewsDetail id={id} onToast={onToast} /> : <NewsList onToast={onToast} />;
}

function NewsList({ onToast }: { onToast: Toast }) {
  const [page, setPage] = useState(1);
  const [writing, setWriting] = useState(false);
  const [items, setItems] = useState<NewsItem[]>([]);
  const s = sources.news(page);
  const { data, error, loading, reload } = useAsync(s.load, s.deps, s.key);

  useEffect(() => {
    if (data) setItems((prev) => (page === 1 ? data.items : [...prev, ...data.items]));
  }, [data, page]);

  if (loading && items.length === 0) return <Loading shape="news" />;
  if (error && items.length === 0) return <ErrorBox message={error} onRetry={reload} />;
  const notify = <GuestPushButton label={t('Bei neuen Beiträgen benachrichtigen')} />;
  const editor = newsEditorUrl();
  const write = editor ? (
    <p>
      <button
        type="button"
        className="btn"
        onClick={() =>
          openNewsEditor(editor, () => {
            setPage(1);
            reload();
          })
        }
      >
        {t('Neuer Beitrag')}
      </button>
    </p>
  ) : canWriteNews() ? (
    writing ? (
      <NewsSchreiben
        onToast={onToast}
        onDone={(saved) => {
          setWriting(false);
          if (saved) navigate(`news/${saved.id}`);
        }}
      />
    ) : (
      <p>
        <button type="button" className="btn" onClick={() => setWriting(true)}>
          {t('Neuer Beitrag')}
        </button>
      </p>
    )
  ) : null;
  if (items.length === 0)
    return (
      <>
        {write}
        {!writing && <Empty>{t('Noch keine Beiträge.')}</Empty>}
        {notify}
      </>
    );

  return (
    <div>
      {write}
      <ul className="card-list news-list">
        {items.map((n) => (
          <li key={n.id} className="card news-card clickable" onClick={() => navigate(`news/${n.id}`)}>
            {n.image && <img className="news-image" src={n.image} alt="" loading="lazy" />}
            <div className="news-body">
              {newsDate(n.date) && <div className="news-date">{newsDate(n.date)}</div>}
              <h2 className="news-title">{n.title}</h2>
              <p className="news-excerpt">{n.excerpt}</p>
            </div>
          </li>
        ))}
      </ul>
      {data && page < data.totalPages && (
        <button className="btn btn-secondary btn-block more" disabled={loading} onClick={() => setPage((p) => p + 1)}>
          {loading ? t('Lädt …') : t('Ältere Beiträge')}
        </button>
      )}
      {notify}
    </div>
  );
}
