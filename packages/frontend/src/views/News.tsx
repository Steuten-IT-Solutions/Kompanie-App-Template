import DOMPurify from 'dompurify';
import { useEffect, useState } from 'react';
import { api, type NewsItem } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const dateFmt = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });

export default function News({ id }: { id?: string }) {
  return id ? <NewsDetail id={id} /> : <NewsList />;
}

function NewsList() {
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<NewsItem[]>([]);
  const { data, error, loading, reload } = useAsync(() => api.news(page), [page]);

  useEffect(() => {
    if (data) setItems((prev) => (page === 1 ? data.items : [...prev, ...data.items]));
  }, [data, page]);

  if (loading && items.length === 0) return <Loading />;
  if (error && items.length === 0) return <ErrorBox message={error} onRetry={reload} />;
  if (items.length === 0) return <Empty>Noch keine Neuigkeiten.</Empty>;

  return (
    <div>
      <ul className="card-list news-list">
        {items.map((n) => (
          <li key={n.id} className="card news-card clickable" onClick={() => navigate(`news/${n.id}`)}>
            {n.image && <img className="news-image" src={n.image} alt="" loading="lazy" />}
            <div className="news-body">
              <div className="news-date">{dateFmt.format(new Date(n.date))}</div>
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
    </div>
  );
}

function NewsDetail({ id }: { id: string }) {
  const { data, error, loading, reload } = useAsync(() => api.newsItem(id), [id]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const n = data!;
  // Inhalt stammt von der Webseite; zusätzlich bereinigen, bevor er eingefügt wird.
  const html = DOMPurify.sanitize(n.content ?? '', { ADD_ATTR: ['target'] });

  return (
    <article className="news-article">
      <button className="btn btn-link back" onClick={() => navigate('news')}>
        ‹ Alle Neuigkeiten
      </button>
      {n.image && <img className="news-hero" src={n.image} alt="" />}
      <div className="news-date">{dateFmt.format(new Date(n.date))}</div>
      <h2 className="news-headline">{n.title}</h2>
      <div className="news-content" dangerouslySetInnerHTML={{ __html: html }} />
      <a className="btn btn-secondary btn-block" href={n.link} target="_blank" rel="noreferrer">
        Auf der Webseite öffnen
      </a>
    </article>
  );
}
