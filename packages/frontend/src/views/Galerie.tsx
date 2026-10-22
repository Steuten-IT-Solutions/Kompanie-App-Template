import { useCallback, useEffect, useRef, useState, type TouchEvent, type TouchList } from 'react';
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
              {p.intern && (
                <span className="photo-lock" aria-hidden="true">
                  🔒
                </span>
              )}
            </button>
          </li>
        ))}
      </ul>
      {open !== null && <Lightbox photos={photos} start={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

type Zoom = { scale: number; x: number; y: number };
const NO_ZOOM: Zoom = { scale: 1, x: 0, y: 0 };
const MAX_ZOOM = 4;

/** Abstand und Mittelpunkt zweier Finger. */
function pinch(t: TouchList): { dist: number; cx: number; cy: number } {
  const [a, b] = [t[0], t[1]];
  return {
    dist: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY),
    cx: (a.clientX + b.clientX) / 2,
    cy: (a.clientY + b.clientY) / 2,
  };
}

/**
 * Vollbild-Ansicht mit Wischen, Pfeiltasten und Escape. Zoomen mit zwei Fingern (bis 4-fach) oder doppeltem Tippen;
 * vergrößert verschiebt ein Finger das Bild statt zu blättern. Zuerst erscheint die 1600er-Fassung, die
 * Originalauflösung wird nachgeladen und ersetzt sie, sobald sie ganz da ist.
 */
function Lightbox({ photos, start, onClose }: { photos: GalleryPhoto[]; start: number; onClose: () => void }) {
  const [i, setI] = useState(start);
  const [zoom, setZoom] = useState<Zoom>(NO_ZOOM);
  const gesture = useRef<{ kind: 'swipe' | 'pan' | 'pinch'; x: number; y: number; start: Zoom; dist?: number } | null>(null);
  const lastTap = useRef(0);
  const closeRef = useRef<HTMLButtonElement>(null);
  // IDs der Bilder, deren Originalauflösung schon geladen ist (dann aus dem Speicher des Browsers).
  const [originals, setOriginals] = useState<ReadonlySet<number>>(() => new Set());
  const go = useCallback(
    (d: number) => {
      setZoom(NO_ZOOM);
      setI((n) => (n + d + photos.length) % photos.length);
    },
    [photos.length],
  );

  function onTouchStart(e: TouchEvent) {
    if (e.touches.length === 2) {
      const p = pinch(e.touches);
      gesture.current = { kind: 'pinch', x: p.cx, y: p.cy, start: zoom, dist: p.dist };
    } else if (e.touches.length === 1) {
      const t = e.touches[0];
      gesture.current = { kind: zoom.scale > 1 ? 'pan' : 'swipe', x: t.clientX, y: t.clientY, start: zoom };
    }
  }

  function onTouchMove(e: TouchEvent) {
    const g = gesture.current;
    if (!g) return;
    if (g.kind === 'pinch' && e.touches.length === 2 && g.dist) {
      const p = pinch(e.touches);
      const scale = Math.min(MAX_ZOOM, Math.max(1, g.start.scale * (p.dist / g.dist)));
      setZoom(scale === 1 ? NO_ZOOM : { scale, x: g.start.x + p.cx - g.x, y: g.start.y + p.cy - g.y });
    } else if (g.kind === 'pan' && e.touches.length === 1) {
      const t = e.touches[0];
      setZoom({ ...g.start, x: g.start.x + t.clientX - g.x, y: g.start.y + t.clientY - g.y });
    }
  }

  function onTouchEnd(e: TouchEvent) {
    const g = gesture.current;
    if (!g || e.touches.length > 0) return;
    gesture.current = null;
    const t = e.changedTouches[0];
    const moved = Math.hypot(t.clientX - g.x, t.clientY - g.y);
    if (g.kind === 'swipe' && Math.abs(t.clientX - g.x) > 40) return go(t.clientX < g.x ? 1 : -1);
    // Doppeltes Tippen: zweifach vergrößern bzw. zurück
    if (g.kind !== 'pinch' && moved < 10) {
      const now = Date.now();
      if (now - lastTap.current < 300) {
        setZoom(zoom.scale > 1 ? NO_ZOOM : { scale: 2, x: 0, y: 0 });
        lastTap.current = 0;
      } else lastTap.current = now;
    }
  }

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

  // Originalauflösung des gezeigten Bildes nachladen; fehlt sie (404) oder dauert es, bleibt die 1600er stehen.
  useEffect(() => {
    const cur = photos[i];
    if (!cur.ready || !cur.original || originals.has(cur.id)) return;
    const big = new Image();
    big.onload = () => setOriginals((s) => new Set(s).add(cur.id));
    big.src = cur.original;
    return () => {
      big.onload = null;
    };
  }, [i, photos, originals]);

  const p = photos[i];
  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label={`Bild ${i + 1} von ${photos.length}`}
      onClick={(e) => e.target === e.currentTarget && zoom.scale === 1 && onClose()}
      onTouchStart={onTouchStart}
      onTouchMove={onTouchMove}
      onTouchEnd={onTouchEnd}
    >
      <img
        key={p.id}
        src={p.original && originals.has(p.id) ? p.original : p.large}
        alt=""
        className={zoom.scale > 1 ? 'zoomed' : undefined}
        style={zoom.scale > 1 ? { transform: `translate(${zoom.x}px, ${zoom.y}px) scale(${zoom.scale})` } : undefined}
      />
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
