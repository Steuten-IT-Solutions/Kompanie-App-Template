import { useEffect, useState } from 'react';
import { type NewsItem } from '../api';
import { newsDate } from '../format';
import GuestPushButton from '../GuestPushButton';
import { navigate } from '../router';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

// Mit DOMPurify (ca. 12 kB gzip): erst laden, wenn jemand einen Beitrag öffnet.
const NewsDetail = lazyView(() => import('./NewsArtikel'));

export default function News({ id }: { id?: string }) {
  return id ? <NewsDetail id={id} /> : <NewsList />;
}

function NewsList() {
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<NewsItem[]>([]);
  const s = sources.news(page);
  const { data, error, loading, reload } = useAsync(s.load, s.deps, s.key);

  useEffect(() => {
    if (data) setItems((prev) => (page === 1 ? data.items : [...prev, ...data.items]));
  }, [data, page]);

  if (loading && items.length === 0) return <Loading shape="news" />;
  if (error && items.length === 0) return <ErrorBox message={error} onRetry={reload} />;
  const notify = <GuestPushButton label="Bei neuen Beiträgen benachrichtigen" />;
  if (items.length === 0)
    return (
      <>
        <Empty>Noch keine Neuigkeiten.</Empty>
        {notify}
      </>
    );

  return (
    <div>
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
          {loading ? 'Lädt …' : 'Ältere Beiträge'}
        </button>
      )}
      {notify}
    </div>
  );
}
