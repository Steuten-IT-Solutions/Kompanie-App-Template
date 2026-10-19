import DOMPurify from 'dompurify';
import { api } from '../api';
import { newsDate } from '../format';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';

/**
 * Inhalte fremder Webseiten und Feeds: ohne Skripte (Standard von DOMPurify), zusätzlich ohne
 * Stile und Formulare, damit ein Beitrag die App nicht umgestalten oder Eingaben abfragen kann.
 */
function cleanHtml(html: string): string {
  const clean = DOMPurify.sanitize(html, {
    ADD_ATTR: ['target'],
    FORBID_TAGS: [
      'style',
      'form',
      'input',
      'button',
      'textarea',
      'select',
      'option',
      'link',
      'meta',
      'base',
      'dialog',
      'details',
      'summary',
      'marquee',
    ],
    FORBID_ATTR: ['style', 'class', 'id'],
    RETURN_DOM_FRAGMENT: true,
  });
  clean.querySelectorAll('a[target]').forEach((a) => a.setAttribute('rel', 'noopener noreferrer'));
  const div = document.createElement('div');
  div.appendChild(clean);
  return div.innerHTML;
}

export default function NewsArtikel({ id }: { id: string }) {
  const { data, error, loading, reload } = useAsync(() => api.newsItem(id), [id], 'news-item');

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
