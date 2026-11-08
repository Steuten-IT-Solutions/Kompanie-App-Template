import DOMPurify from 'dompurify';
import { useState } from 'react';
import { api, config } from '../api';
import { formatShortTime, newsDate } from '../format';
import BlocksView from '../blocks/BlocksView';
import RichTextView from '../richtext/RichTextView';
import { navigate } from '../router';
import { ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { canWriteNews } from './News';
import { confirmAsk } from '../ask';

const NewsSchreiben = lazyView(() => import('./NewsSchreiben'));

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

export default function NewsArtikel({ id, onToast }: { id: string; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.newsItem(id), [id], 'news-item');
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  if (loading && !data) return <Loading shape="detail" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const n = data!;
  // In der App geschrieben: formatierter Text wie auf den eigenen Seiten (der Server hat ihn schon bereinigt).
  const inApp = !!config?.newsInApp;
  const back = (
    <button className="btn btn-link back" onClick={() => navigate('news')}>
      ‹ Alle Neuigkeiten
    </button>
  );

  if (editing)
    return (
      <div className="news-article">
        {back}
        <NewsSchreiben
          item={n}
          onToast={onToast}
          onDone={(saved) => {
            setEditing(false);
            if (saved) reload();
          }}
        />
      </div>
    );

  async function remove() {
    if (!(await confirmAsk(`Beitrag „${n.title}“ samt seinen Bildern löschen?`))) return;
    setBusy(true);
    setActionError(null);
    try {
      await api.deleteNews(n.id);
      onToast('Beitrag gelöscht');
      navigate('news');
    } catch (err) {
      setActionError((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <article className="news-article">
      {back}
      {inApp
        ? n.cover && <img className="news-hero" src={n.cover} alt="" />
        : n.image && <img className="news-hero" src={n.image} alt="" />}
      {newsDate(n.date) && <div className="news-date">{newsDate(n.date)}</div>}
      <h2 className="news-headline">{n.title}</h2>
      {inApp ? (
        <div className="news-content">
          {n.blocks ? <BlocksView blocks={n.blocks} manager={canWriteNews()} /> : n.content ? <RichTextView html={n.content} /> : null}
        </div>
      ) : (
        <div className="news-content" dangerouslySetInnerHTML={{ __html: cleanHtml(n.content ?? '') }} />
      )}
      {n.link && (
        <a className="btn btn-secondary btn-block" href={n.link} target="_blank" rel="noreferrer">
          Auf der Webseite öffnen
        </a>
      )}
      {inApp && canWriteNews() && (
        <>
          {n.updated_at && (
            <p className="muted small">
              Zuletzt geändert {formatShortTime(n.updated_at)}
              {n.updated_by_name ? ` von ${n.updated_by_name}` : ''}
            </p>
          )}
          {actionError && (
            <p className="form-error" role="alert">
              {actionError}
            </p>
          )}
          <div className="button-row">
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setEditing(true)}>
              Bearbeiten
            </button>
            <button type="button" className="btn btn-danger" disabled={busy} onClick={remove}>
              Löschen
            </button>
          </div>
        </>
      )}
    </article>
  );
}
