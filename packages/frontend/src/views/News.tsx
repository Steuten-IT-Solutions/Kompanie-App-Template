import DOMPurify from 'dompurify';
import { useEffect, useState } from 'react';
import { api, type NewsItem } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const dateFmt = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });

/** Datum eines Beitrags; leer, wenn die Quelle keines oder ein unlesbares liefert. */
function newsDate(iso: string): string {
  const t = iso ? Date.parse(iso) : NaN;
  return Number.isNaN(t) ? '' : dateFmt.format(new Date(t));
}

/**
 * Inhalte fremder Webseiten und Feeds: ohne Skripte (Standard von DOMPurify), zusätzlich ohne
 * Stile und Formulare, damit ein Beitrag die App nicht umgestalten oder Eingaben abfragen kann.
 */
function cleanHtml(html: string): string {
  const clean = DOMPurify.sanitize(html, {
    ADD_ATTR: ['target'],
    FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base'],
    FORBID_ATTR: ['style', 'class', 'id'],
    RETURN_DOM_FRAGMENT: true,
  });
  clean.querySelectorAll('a[target]').forEach((a) => a.setAttribute('rel', 'noopener noreferrer'));
  const div = document.createElement('div');
  div.appendChild(clean);
  return div.innerHTML;
}

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
    </div>
  );
}

function NewsDetail({ id }: { id: string }) {
  const { data, error, loading, reload } = useAsync(() => api.newsItem(id), [id]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const n = data!;
  const html = cleanHtml(n.content ?? '');

  return (
    <article className="news-article">
      <button className="btn btn-link back" onClick={() => navigate('news')}>
        ‹ Alle Neuigkeiten
      </button>
      {n.image && <img className="news-hero" src={n.image} alt="" />}
      {newsDate(n.date) && <div className="news-date">{newsDate(n.date)}</div>}
      <h2 className="news-headline">{n.title}</h2>
      <div className="news-content" dangerouslySetInnerHTML={{ __html: html }} />
      <a className="btn btn-secondary btn-block" href={n.link} target="_blank" rel="noreferrer">
        Auf der Webseite öffnen
      </a>
    </article>
  );
}
