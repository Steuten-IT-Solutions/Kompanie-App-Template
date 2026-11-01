import { useCallback, type CSSProperties, useEffect, useLayoutEffect, useRef, useState, type TouchEvent, type TouchList } from 'react';
import { type GalleryPhoto } from '../api';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { useSwipeDrag } from '../swipe';
import { prefetchAlbumOnTap, sources } from '../viewData';

export default function Galerie({ albumId }: { albumId?: number }) {
  return albumId ? <Album id={albumId} /> : <Overview />;
}

/**
 * Bild mit Ladeschnecke, solange es lädt oder (busy) noch aufbereitet wird. Die Schnecke liegt als Geschwister über dem
 * Bild (der Container braucht position: relative) und erscheint erst nach kurzer Zeit (CSS), damit Bilder aus dem
 * Speicher nicht aufblinken. Ein Fehler beendet sie ebenfalls.
 */
function LoadingImage({
  src,
  busy = false,
  className,
  style,
  lazy = false,
}: {
  src: string;
  busy?: boolean;
  className?: string;
  style?: CSSProperties;
  lazy?: boolean;
}) {
  const [done, setDone] = useState<string | null>(null);
  // Schon fertig (z. B. aus dem Speicher, bevor React onLoad anhängen konnte)
  const ref = useCallback(
    (el: HTMLImageElement | null) => {
      if (el?.complete) setDone(src);
    },
    [src],
  );
  const finish = () => setDone(src);
  return (
    <>
      <img
        ref={ref}
        src={src}
        alt=""
        loading={lazy ? 'lazy' : undefined}
        className={className}
        style={style}
        onLoad={finish}
        onError={finish}
      />
      {(busy || done !== src) && <span className="img-loading" aria-hidden="true" data-testid="img-loading" />}
    </>
  );
}

/**
 * Scrollposition der Übersicht beim Öffnen eines Albums: Zurück (im Browser, per Wischen oder mit „‹ Alle Alben“) führt
 * wieder dorthin statt an den Anfang. Der Router scrollt bei jedem Wechsel nach oben; die Übersicht stellt die Stelle
 * wieder her, sobald ihre Alben dastehen (die Kacheln haben feste Seitenverhältnisse, die Höhe stimmt also schon vor den
 * Bildern).
 */
let overviewScroll: number | null = null;

function Overview() {
  const { data, error, loading, reload } = useAsync(sources.gallery.load, sources.gallery.deps, sources.gallery.key);
  const ready = !!data;
  useLayoutEffect(() => {
    if (!ready || overviewScroll === null) return;
    window.scrollTo(0, overviewScroll);
    overviewScroll = null;
  }, [ready]);
  if (loading && !data) return <Loading shape="grid" />;
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
                <button
                  className="album-card"
                  onClick={() => {
                    overviewScroll = window.scrollY;
                    navigate(`galerie/${a.id}`);
                  }}
                  {...prefetchAlbumOnTap(a.id)}
                >
                  {a.cover ? (
                    <span className="album-cover">
                      <LoadingImage src={a.cover} lazy />
                    </span>
                  ) : (
                    <span className="album-placeholder" />
                  )}
                  <span className="album-title">{a.title}</span>
                  <span className="album-count">
                    {mediaCount(a.count, a.video_count ?? 0)}
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

/** „3 Bilder · 1 Video“ (wie der Shortcode auf der Webseite, Shortcodes::mediaCount). */
export function mediaCount(count: number, videos: number): string {
  const images = count - videos;
  const parts: string[] = [];
  if (images > 0 || videos === 0) parts.push(`${images} ${images === 1 ? 'Bild' : 'Bilder'}`);
  if (videos > 0) parts.push(`${videos} ${videos === 1 ? 'Video' : 'Videos'}`);
  return parts.join(' · ');
}

/** Dauer eines Videos „1:02“. */
function duration(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function isVideo(p: GalleryPhoto): boolean {
  return p.type === 'video' && !!p.video;
}

/** Noch nicht aufbereitete Bilder: so oft neu laden (alle 10 s, wie Retry-After), danach erst beim nächsten Öffnen. */
const PENDING_RETRIES = 60;
const PENDING_INTERVAL = 10000;

/** Adresse eines Platzhalters mit Zähler, damit der Browser das Bild neu holt statt aus dem Speicher. */
function retryUrl(url: string, n: number): string {
  return n === 0 ? url : `${url}${url.includes('?') ? '&' : '?'}v=${n}`;
}

function Album({ id }: { id: number }) {
  const s = sources.album(id);
  const { data, error, loading, reload } = useAsync(s.load, s.deps, s.key);
  const [open, setOpen] = useState<number | null>(null);
  const tiles = useRef<(HTMLButtonElement | null)[]>([]);
  const [retry, setRetry] = useState(0);
  const pending = !!data?.photos.some((p) => !p.ready);
  // Solange die App noch nachsieht, drehen sich in den Platzhaltern die Ladeschnecken.
  const preparing = retry < PENDING_RETRIES;

  // Bilder entstehen beim Abruf (höchstens wenige gleichzeitig) oder im Hintergrund: nachsehen, bis alle fertig sind.
  useEffect(() => {
    if (!pending || retry >= PENDING_RETRIES) return;
    const t = setTimeout(() => {
      setRetry((n) => n + 1);
      reload();
    }, PENDING_INTERVAL);
    return () => clearTimeout(t);
  }, [pending, retry, reload]);

  // Nach dem Schließen der Großansicht steht das zuletzt gezeigte Bild im Raster im Blick (auch wenn weit geblättert
  // wurde) und hat den Fokus. Erst nach dem Abbau der Großansicht, die das Scrollen der Seite sperrt.
  const last = useRef<number | null>(null);
  const close = useCallback((i: number) => {
    last.current = i;
    setOpen(null);
  }, []);
  useEffect(() => {
    if (open !== null || last.current === null) return;
    const tile = tiles.current[last.current];
    last.current = null;
    tile?.scrollIntoView({ block: 'nearest' });
    tile?.focus({ preventScroll: true });
  }, [open]);

  if (loading && !data) return <Loading shape="detail" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const album = data!;
  const photos = album.photos.map((p) =>
    p.ready || !p.thumb ? p : { ...p, thumb: retryUrl(p.thumb, retry), large: p.large && retryUrl(p.large, retry) },
  );

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
              ref={(el) => {
                tiles.current[i] = el;
              }}
              className={isVideo(p) ? 'photo photo-video' : 'photo'}
              onClick={() => setOpen(i)}
              data-intern={p.intern ? '' : undefined}
              aria-label={`${isVideo(p) ? 'Video' : 'Bild'} ${i + 1} öffnen${p.intern ? ' (nur für Mitglieder)' : ''}`}
            >
              {p.thumb && <LoadingImage src={p.thumb} busy={!p.ready && preparing} lazy />}
              {isVideo(p) && (
                <>
                  <span className="photo-play" aria-hidden="true">
                    ▶
                  </span>
                  {!!p.duration && <span className="photo-duration">{duration(p.duration)}</span>}
                </>
              )}
              {p.intern && (
                <span className="photo-lock" aria-hidden="true">
                  🔒
                </span>
              )}
            </button>
          </li>
        ))}
      </ul>
      {open !== null && <Lightbox photos={photos} start={open} onClose={close} />}
    </div>
  );
}

function isLightboxEntry(): boolean {
  return !!(window.history.state as { lightbox?: boolean } | null)?.lightbox;
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
 * Vollbild-Ansicht mit Wischen, Pfeiltasten und Escape. Beim Wischen folgt das Bild dem Finger, daneben stehen das
 * vorherige und nächste, beim Loslassen gleitet es weiter oder zurück (wie in der Mitgliederansicht, swipe.ts). Zoomen mit zwei Fingern (bis 4-fach) oder doppeltem Tippen;
 * vergrößert verschiebt ein Finger das Bild statt zu blättern. Zuerst erscheint die 1600er-Fassung, die
 * Originalauflösung wird nachgeladen und ersetzt sie, sobald sie ganz da ist. Videos spielt der Player des Browsers ab
 * (ohne Zoom; Wischen auf dem Video bedient den Player, daneben und mit den Pfeilen wird geblättert).
 */
function Lightbox({ photos, start, onClose }: { photos: GalleryPhoto[]; start: number; onClose: (last: number) => void }) {
  const [i, setI] = useState(start);
  const current = useRef(i);
  current.current = i;
  // Zurück (Taste, Geste des Geräts) schließt die Großansicht statt das Album: Beim Öffnen kommt ein Eintrag mit derselben
  // Adresse in den Verlauf (kein hashchange, der Router merkt nichts). Schließen mit ✕ oder Escape nimmt ihn wieder weg.
  const closing = useRef(false);
  const close = useCallback(() => {
    if (closing.current) return;
    closing.current = true;
    if (isLightboxEntry()) window.history.back();
    else onClose(current.current);
  }, [onClose]);
  useEffect(() => {
    if (!isLightboxEntry()) window.history.pushState({ ...window.history.state, lightbox: true }, '');
    const onPop = () => {
      if (!isLightboxEntry()) onClose(current.current);
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [onClose]);
  const [zoom, setZoom] = useState<Zoom>(NO_ZOOM);
  const gesture = useRef<{ kind: 'tap' | 'pan' | 'pinch'; x: number; y: number; start: Zoom; dist?: number } | null>(null);
  const lastTap = useRef(0);
  const closeRef = useRef<HTMLButtonElement>(null);
  // IDs der Bilder, deren Originalauflösung schon geladen ist (dann aus dem Speicher des Browsers).
  const [originals, setOriginals] = useState<ReadonlySet<number>>(() => new Set());
  // Video, das der Browser nicht abspielen kann (z. B. MP4 mit HEVC)
  const [unplayable, setUnplayable] = useState<number | null>(null);
  const go = useCallback(
    (d: number) => {
      setZoom(NO_ZOOM);
      setI((n) => (n + d + photos.length) % photos.length);
    },
    [photos.length],
  );
  const root = useRef<HTMLDivElement>(null);
  const track = useRef<HTMLDivElement>(null);
  const many = photos.length > 1;
  // Vergrößert verschiebt ein Finger das Bild, statt zu blättern.
  const swipe = useSwipeDrag(root, track, { canPrev: many, canNext: many, onCommit: go, enabled: zoom.scale === 1, inPlace: true });
  const slide = useRef(swipe.slide);
  slide.current = swipe.slide;

  function onTouchStart(e: TouchEvent) {
    if (e.target instanceof HTMLVideoElement) {
      gesture.current = null;
      return;
    }
    if (e.touches.length === 2) {
      const p = pinch(e.touches);
      gesture.current = { kind: 'pinch', x: p.cx, y: p.cy, start: zoom, dist: p.dist };
    } else if (e.touches.length === 1) {
      const t = e.touches[0];
      // Unvergrößert blättert useSwipeDrag; hier zählt nur noch das Tippen.
      gesture.current = { kind: zoom.scale > 1 ? 'pan' : 'tap', x: t.clientX, y: t.clientY, start: zoom };
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
    // Doppeltes Tippen: zweifach vergrößern bzw. zurück (nicht bei Videos)
    if (g.kind !== 'pinch' && moved < 10 && !isVideo(photos[i])) {
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
      if (e.key === 'Escape') close();
      // Im Video (Fokus auf dem Player) spulen die Pfeile, statt zu blättern.
      if (e.target instanceof HTMLVideoElement) return;
      if (e.key === 'ArrowLeft') slide.current(-1);
      if (e.key === 'ArrowRight') slide.current(1);
    };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
    };
  }, [close]);

  // Vorheriges und nächstes Bild schon laden, damit das Wischen ohne Wartezeit klappt.
  useEffect(() => {
    if (photos.length < 2) return;
    for (const n of [i + 1, i - 1 + photos.length]) {
      const large = photos[n % photos.length].large;
      if (large) new Image().src = large;
    }
  }, [i, photos]);

  // Originalauflösung des gezeigten Bildes nachladen; fehlt sie (404) oder dauert es, bleibt die 1600er stehen.
  useEffect(() => {
    const cur = photos[i];
    if (isVideo(cur) || !cur.ready || !cur.original || originals.has(cur.id)) return;
    const big = new Image();
    big.onload = () => setOriginals((s) => new Set(s).add(cur.id));
    big.src = cur.original;
    return () => {
      big.onload = null;
    };
  }, [i, photos, originals]);

  const p = photos[i];
  // Nachbarn neben dem Bild, solange gewischt wird oder es weitergleitet; Videos mit ihrem Vorschaubild.
  const peek = (n: number, side: 'prev' | 'next') => {
    if (!swipe.peeking || !many) return null;
    const q = photos[(n + photos.length) % photos.length];
    return (
      <div className={`lb-peek lb-peek-${side}`} aria-hidden="true">
        {q.large && <LoadingImage key={q.id} src={q.large} />}
      </div>
    );
  };
  return (
    <div
      className="lightbox"
      role="dialog"
      aria-modal="true"
      aria-label={`Bild ${i + 1} von ${photos.length}`}
      ref={root}
      onClick={(e) => (e.target === e.currentTarget || e.target === track.current) && zoom.scale === 1 && close()}
      onTouchStart={onTouchStart}
      onTouchMove={onTouchMove}
      onTouchEnd={onTouchEnd}
    >
      <div className={`lb-track${swipe.peeking ? ' peeking' : ''}`} ref={track}>
        {peek(i - 1, 'prev')}
        {peek(i + 1, 'next')}
        {isVideo(p) ? (
          unplayable === p.id ? (
            <p className="lb-error">Dieses Video kann dein Browser nicht abspielen.</p>
          ) : (
            // key: Beim Blättern hält das alte Video an, das neue lädt von vorn.
            <video
              key={p.id}
              src={p.video}
              poster={p.large || undefined}
              controls
              playsInline
              preload="metadata"
              onError={() => setUnplayable(p.id)}
            />
          )
        ) : (
          <LoadingImage
            key={p.id}
            src={p.original && originals.has(p.id) ? p.original : p.large}
            className={zoom.scale > 1 ? 'zoomed' : undefined}
            style={zoom.scale > 1 ? { transform: `translate(${zoom.x}px, ${zoom.y}px) scale(${zoom.scale})` } : undefined}
          />
        )}
      </div>
      <button ref={closeRef} className="lb-btn lb-close" onClick={close} aria-label="Schließen">
        ✕
      </button>
      {many && (
        <>
          <button className="lb-btn lb-prev" onClick={() => swipe.slide(-1)} aria-label="Vorheriges">
            ‹
          </button>
          <button className="lb-btn lb-next" onClick={() => swipe.slide(1)} aria-label="Nächstes">
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
