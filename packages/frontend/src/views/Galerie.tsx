import { useCallback, useEffect, useRef, useState } from 'react';
import { api, type GalleryPhoto } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

export default function Galerie({ albumId }: { albumId?: number }) {
  return albumId ? <Album id={albumId} /> : <Overview />;
}

function Overview() {
  const { data, error, loading, reload } = useAsync(api.gallery, [], 'galerie');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const years = data?.years ?? [];
  if (years.length === 0) return <Empty>Noch keine Bilder in der Galerie.</Empty>;

  return (
    <div>
      {years.map((y) => (
        <section key={y.year}>
          <h2 className="section-title">{y.year}</h2>
          <ul className="album-grid">
            {y.albums.map((a) => (
              <li key={a.id}>
                <button className="album-card" onClick={() => navigate(`galerie/${a.id}`)}>
                  {a.cover ? <img src={a.cover} alt="" loading="lazy" /> : <span className="album-placeholder" />}
                  <span className="album-title">{a.title}</span>
                  <span className="album-count">
                    {a.count} {a.count === 1 ? 'Bild' : 'Bilder'}
                    {a.intern_count > 0 && ` · ${a.intern_count} intern`}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

/** Noch nicht aufbereitete Bilder: so oft neu laden (alle 10 s, wie Retry-After), danach erst beim nächsten Öffnen. */
const PENDING_RETRIES = 60;
const PENDING_INTERVAL = 10000;

/** Adresse eines Platzhalters mit Zähler, damit der Browser das Bild neu holt statt aus dem Speicher. */
function retryUrl(url: string, n: number): string {
  return n === 0 ? url : `${url}${url.includes('?') ? '&' : '?'}v=${n}`;
}

function Album({ id }: { id: number }) {
  const { data, error, loading, reload } = useAsync(() => api.galleryAlbum(id), [id], 'galerie-album');
  const [open, setOpen] = useState<number | null>(null);
  const [retry, setRetry] = useState(0);
  const pending = !!data?.photos.some((p) => !p.ready);

  // Bilder entstehen beim Abruf (höchstens wenige gleichzeitig) oder im Hintergrund: nachsehen, bis alle fertig sind.
  useEffect(() => {
    if (!pending || retry >= PENDING_RETRIES) return;
    const t = setTimeout(() => {
      setRetry((n) => n + 1);
      reload();
    }, PENDING_INTERVAL);
    return () => clearTimeout(t);
  }, [pending, retry, reload]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const album = data!;
  const photos = album.photos.map((p) => (p.ready ? p : { ...p, thumb: retryUrl(p.thumb, retry), large: retryUrl(p.large, retry) }));

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('galerie')}>
        ‹ Alle Alben
      </button>
      <h2 className="member-name">
        {album.title} <span className="muted album-year">{album.year}</span>
      </h2>
      <ul className="photo-grid">
        {photos.map((p, i) => (
          <li key={p.id}>
            <button
              className="photo"
              onClick={() => setOpen(i)}
              data-intern={p.intern ? '' : undefined}
              aria-label={`Bild ${i + 1} öffnen${p.intern ? ' (nur für Mitglieder)' : ''}`}
            >
              <img src={p.thumb} alt="" loading="lazy" />
            </button>
          </li>
        ))}
      </ul>
      {open !== null && <Lightbox photos={photos} start={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

/** Vollbild-Ansicht mit Wischen, Pfeiltasten und Escape. */
function Lightbox({ photos, start, onClose }: { photos: GalleryPhoto[]; start: number; onClose: () => void }) {
  const [i, setI] = useState(start);
  const touchX = useRef<number | null>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const go = useCallback((d: number) => setI((n) => (n + d + photos.length) % photos.length), [photos.length]);

  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'ArrowLeft') go(-1);
      if (e.key === 'ArrowRight') go(1);
    };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
    };
  }, [go, onClose]);

  // Vorheriges und nächstes Bild schon laden, damit das Wischen ohne Wartezeit klappt.
  useEffect(() => {
    if (photos.length < 2) return;
    for (const n of [i + 1, i - 1 + photos.length]) new Image().src = photos[n % photos.length].large;
  }, [i, photos]);

  const p = photos[i];
  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label={`Bild ${i + 1} von ${photos.length}`}
      onClick={(e) => e.target === e.currentTarget && onClose()}
      onTouchStart={(e) => (touchX.current = e.touches[0].clientX)}
      onTouchEnd={(e) => {
        if (touchX.current === null) return;
        const dx = e.changedTouches[0].clientX - touchX.current;
        if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
        touchX.current = null;
      }}
    >
      <img key={p.id} src={p.large} alt="" />
      <button ref={closeRef} className="lb-btn lb-close" onClick={onClose} aria-label="Schließen">
        ✕
      </button>
      {photos.length > 1 && (
        <>
          <button className="lb-btn lb-prev" onClick={() => go(-1)} aria-label="Vorheriges Bild">
            ‹
          </button>
          <button className="lb-btn lb-next" onClick={() => go(1)} aria-label="Nächstes Bild">
            ›
          </button>
        </>
      )}
      <div className="lb-count">
        {i + 1} / {photos.length}
        {p.intern && ' · intern'}
      </div>
    </div>
  );
}
