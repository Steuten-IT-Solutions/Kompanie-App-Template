import {
  useCallback,
  type CSSProperties,
  type PointerEvent as ReactPointerEvent,
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type TouchEvent,
  type TouchList,
  type ReactNode,
} from 'react';
import {
  albumEditable,
  api,
  can,
  config,
  gallerySubmissionsOpen,
  photoEditable,
  toLogin,
  type GalleryAlbum,
  type GalleryPhoto,
} from '../api';
import { downloadablePhotos } from '../downloadFiles';
import { downloadAlbum, downloadMedia } from '../galleryDownload';
import { afterBackSync, useBackClose } from '../backClose';
import { navigate, redirect } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { useSwipeDrag } from '../swipe';
import { prefetchAlbumOnTap, sources } from '../viewData';
import { confirmAsk, promptAsk } from '../ask';
import { dataUpdated } from '../dataCache';
import { Icon, IconButton } from '../icons';
import { photoUrl, shareLink } from '../share';
import brand from '../generated/brand';
import { t, tc, tn } from '../i18n';
import {
  AlbumEditSheet,
  MoveSheet,
  canManageGallery,
  deleteAlbumsQuestion,
  itemsLabel,
  useLongPress,
  useSelectionBack,
  type LongPressHandlers,
} from '../galleryManage';

type Toast = (msg: string) => void;

/** photoId: Bild oder Video aus der Adresse galerie/<Album>/bild/<Bild> (geteilter Link), öffnet groß. */
export default function Galerie({ albumId, photoId, onToast = () => {} }: { albumId?: number; photoId?: number; onToast?: Toast }) {
  return albumId ? <Album id={albumId} photoId={photoId} onToast={onToast} /> : <Overview onToast={onToast} />;
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

function Overview({ onToast }: { onToast: Toast }) {
  const { data, error, loading, reload } = useAsync(sources.gallery.load, sources.gallery.deps, sources.gallery.key);
  const ready = !!data;
  // Galerie verwalten (manage_gallery): Alben der App auswählen, dann ins andere Jahr setzen oder löschen
  const manage = canManageGallery();
  const [selecting, setSelecting] = useState(false);
  const [selected, setSelected] = useState<ReadonlySet<number>>(() => new Set());
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  // Langes Drücken auf ein Album der App beginnt das Auswählen (mit diesem Album)
  const press = useLongPress(manage, (id) => {
    setSelecting(true);
    setSelected((old) => toggle(old, id));
  });
  // Beenden ohne Knopf: „Zurück“, Escape oder wenn nichts mehr gewählt ist
  useSelectionBack(selecting, () => {
    setSelecting(false);
    setSelected(new Set());
    setActionError(null);
  });
  useEffect(() => {
    if (selecting && selected.size === 0) {
      setSelecting(false);
      setActionError(null);
    }
  }, [selecting, selected]);
  useLayoutEffect(() => {
    if (!ready || overviewScroll === null) return;
    window.scrollTo(0, overviewScroll);
    overviewScroll = null;
  }, [ready]);
  if (loading && !data) return <Loading shape="grid" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const years = data?.years ?? [];
  const chosen = years.flatMap((y) => y.albums.map((a) => ({ ...a, year: y.year }))).filter((a) => selected.has(a.id));

  /** Auswählen beenden (sein Eintrag im Verlauf verschwindet von selbst, backClose.ts). */
  function stopSelecting() {
    setSelecting(false);
    setSelected(new Set());
    setActionError(null);
  }

  /** Für alle gewählten Alben nacheinander; bricht beim ersten Fehler ab und meldet, wie weit es kam. */
  async function each(fn: (id: number) => Promise<unknown>, doneMsg: (n: number) => string) {
    setBusy(true);
    setActionError(null);
    let done = 0;
    try {
      for (const a of chosen) {
        await fn(a.id);
        done++;
      }
      onToast(doneMsg(done));
      stopSelecting();
    } catch (e) {
      const message = (e as Error).message;
      setActionError(done > 0 ? t('{count} erledigt, dann: {message}', { count: done, message }) : message);
    } finally {
      setBusy(false);
      if (done > 0) {
        dataUpdated();
        reload();
      }
    }
  }

  async function changeYear() {
    const raw = await promptAsk(
      tn('In welches Jahr sollen das Album?', 'In welches Jahr sollen die {count} Alben?', chosen.length),
      chosen[0]?.year ?? '',
      {
        title: t('Jahr ändern'),
        okLabel: t('Ändern'),
      },
    );
    if (raw === null) return;
    const year = Number(raw.trim());
    if (!Number.isInteger(year)) {
      setActionError(t('Bitte ein Jahr als Zahl angeben, z. B. 2026.'));
      return;
    }
    await each(
      (id) => api.updateGalleryAlbum(id, { year }),
      (n) => tn('Album ins Jahr {year} gesetzt', '{count} Alben ins Jahr {year} gesetzt', n, { year }),
    );
  }

  async function removeAlbums() {
    if (!(await confirmAsk(deleteAlbumsQuestion(chosen)))) return;
    await each(
      (id) => api.deleteGalleryAlbum(id),
      (n) => tn('Album gelöscht', '{count} Alben gelöscht', n),
    );
  }

  // Einreichungen (Issue #286), nur mit Haken „Einreichen erlauben“ (Einstellungen → Galerie); Verwalten (manage_gallery)
  const submit = can('submit_gallery') && gallerySubmissionsOpen();
  const actions =
    submit || can('view_submissions') || manage ? (
      <div className="button-row gallery-actions">
        {submit && <IconButton icon="submit" label={t('Bilder und Videos einreichen')} onClick={() => navigate('galerie/einreichen')} />}
        {can('view_submissions') && (
          <IconButton
            icon="review"
            label={can('review_gallery') ? t('Einreichungen prüfen') : t('Einreichungen ansehen')}
            onClick={() => navigate('galerie/pruefen')}
          />
        )}
        {manage && <IconButton icon="albumAdd" label={t('Alben anlegen und hochladen')} onClick={() => navigate('galerie/verwalten')} />}
      </div>
    ) : null;
  const selectBar = selecting && (
    <div className="gallery-select-bar" role="toolbar" aria-label={t('Gewählte Alben')}>
      <span className="small">
        {chosen.length === 0
          ? t('Tippe auf Alben aus der App, um sie zu wählen.')
          : tn('{count} Album gewählt', '{count} Alben gewählt', chosen.length)}
      </span>
      <div className="button-row">
        <IconButton icon="year" label={t('Jahr ändern')} disabled={busy || chosen.length === 0} onClick={() => void changeYear()} />
        <IconButton
          icon="delete"
          label={t('Löschen')}
          variant="danger"
          disabled={busy || chosen.length === 0}
          onClick={() => void removeAlbums()}
        />
      </div>
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
    </div>
  );
  if (years.length === 0)
    return (
      <>
        {actions}
        <Empty>{t('Noch keine Bilder.')}</Empty>
      </>
    );

  return (
    <div className={manage ? 'gallery-manage' : undefined}>
      {actions}
      {selectBar}
      {years.map((y) => (
        <section key={y.year}>
          <h2 className="section-title">{y.year}</h2>
          <ul className="album-grid">
            {y.albums.map((a) => (
              <li key={a.id}>
                <button
                  className={selected.has(a.id) ? 'album-card selected' : 'album-card'}
                  aria-pressed={selecting ? selected.has(a.id) : undefined}
                  disabled={selecting && !albumEditable(a)}
                  title={selecting && !albumEditable(a) ? t('Aus Google Drive: in Drive ändern') : undefined}
                  onClick={() => {
                    if (selecting) {
                      setSelected((old) => toggle(old, a.id));
                      return;
                    }
                    overviewScroll = window.scrollY;
                    navigate(`galerie/${a.id}`);
                  }}
                  {...(selecting ? {} : prefetchAlbumOnTap(a.id))}
                  {...withPrefetch(press(a.id, albumEditable(a)), selecting ? undefined : prefetchAlbumOnTap(a.id).onPointerDown)}
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
                    {a.intern_count > 0 && ` · ${t('{count} intern', { count: a.intern_count })}`}
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

/** Langes Drücken und Vorladen beim Antippen zusammen (beide brauchen onPointerDown). */
function withPrefetch(press: LongPressHandlers, prefetch?: () => void): LongPressHandlers {
  if (!prefetch) return press;
  return {
    ...press,
    onPointerDown: (e) => {
      prefetch();
      press.onPointerDown?.(e);
    },
  };
}

/** Menge mit oder ohne id. */
function toggle(set: ReadonlySet<number>, id: number): ReadonlySet<number> {
  const next = new Set(set);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  return next;
}

/** „3 Bilder · 1 Video“ (wie der Shortcode auf der Webseite, Shortcodes::mediaCount). */
export function mediaCount(count: number, videos: number): string {
  const images = count - videos;
  const parts: string[] = [];
  if (images > 0 || videos === 0) parts.push(tn('{count} Bild', '{count} Bilder', images));
  if (videos > 0) parts.push(tn('{count} Video', '{count} Videos', videos));
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

type AlbumSheet = { kind: 'move'; ids: number[]; top: boolean; done?: (ok: boolean) => void } | { kind: 'edit' } | null;

function Album({ id, photoId, onToast }: { id: number; photoId?: number; onToast: Toast }) {
  const s = sources.album(id);
  const { data, error, loading, reload } = useAsync(s.load, s.deps, s.key);
  const [open, setOpen] = useState<number | null>(null);
  const tiles = useRef<(HTMLButtonElement | null)[]>([]);
  const [retry, setRetry] = useState(0);
  // Hochgeladene Bilder und Videos intern oder öffentlich stellen (Großansicht, Auswahl)
  const manage = canManageGallery();
  const canIntern = manage || can('review_gallery');
  // Auswählen, verschieben, löschen (manage_gallery)
  const [selecting, setSelecting] = useState(false);
  const [selected, setSelected] = useState<ReadonlySet<number>>(() => new Set());
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [sheet, setSheet] = useState<AlbumSheet>(null);
  // Langes Drücken auf ein hochgeladenes Bild oder Video beginnt das Auswählen (mit diesem)
  const press = useLongPress(manage, (photoId) => {
    setSelecting(true);
    setSelected((old) => toggle(old, photoId));
  });
  // Beenden ohne Knopf: „Zurück“, Escape oder wenn nichts mehr gewählt ist
  useSelectionBack(selecting, () => {
    setSelecting(false);
    setSelected(new Set());
    setActionError(null);
  });
  useEffect(() => {
    if (selecting && selected.size === 0) {
      setSelecting(false);
      setActionError(null);
    }
  }, [selecting, selected]);
  // Alles herausgenommen: zurück zur Übersicht (das leere Album gibt es für die Galerie nicht mehr)
  const [emptied, setEmptied] = useState(false);
  // Geändert, während die Großansicht offen war: erst nach dem Schließen neu laden (sonst fehlte ihr das Bild)
  const [stale, setStale] = useState(false);
  useEffect(() => {
    if (open !== null) return;
    // Erst wenn die Einträge von Großansicht, Fenster und Auswählen aus dem Verlauf sind, ersetzt die Übersicht den des
    // Albums: „Zurück“ führt nicht ins leere Album.
    if (emptied) afterBackSync(() => redirect('galerie'));
    else if (stale) {
      setStale(false);
      setRetry(0);
      reload();
    }
  }, [emptied, stale, open, reload]);
  // Geteilter Link auf ein Bild (galerie/<Album>/bild/<Bild>): einmal groß öffnen. Die Adresse wird dabei zu der des
  // Albums (ersetzt, nicht neu im Verlauf), damit die Großansicht ihren Eintrag dahinter legt und „Zurück“ ins Album führt.
  // Fehlt das Bild (gelöscht, verschoben oder nur für Mitglieder), bleibt die Adresse: Nach der Anmeldung geht es dorthin.
  // Erst wenn die neue Adresse angekommen ist (photoId fehlt dann), öffnet die Großansicht: Der Wechsel der Adresse löst
  // ein popstate aus, das eine schon offene Ebene sonst gleich wieder schlösse (backClose.ts).
  const [missing, setMissing] = useState<number | null>(null);
  const [linkedIndex, setLinkedIndex] = useState<number | null>(null);
  const linked = useRef<number | null>(null);
  useEffect(() => {
    if (!data || photoId === undefined || linked.current === photoId) return;
    linked.current = photoId;
    const index = data.photos.findIndex((p) => p.id === photoId);
    if (index < 0) {
      setMissing(photoId);
      return;
    }
    setMissing(null);
    setLinkedIndex(index);
    redirect(`galerie/${id}`);
  }, [data, photoId, id]);
  useEffect(() => {
    if (linkedIndex === null || photoId !== undefined) return;
    setLinkedIndex(null);
    setOpen(linkedIndex);
  }, [linkedIndex, photoId]);
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
  const uploads = photos.filter(photoEditable);
  const chosen = uploads.filter((p) => selected.has(p.id));

  /** Auswählen beenden (sein Eintrag im Verlauf verschwindet von selbst, backClose.ts). */
  function stopSelecting() {
    setSelecting(false);
    setSelected(new Set());
    setActionError(null);
  }

  /** Nach Verschieben oder Löschen: neu laden oder, wenn nichts mehr drin ist, zur Übersicht. */
  function removed(n: number) {
    dataUpdated();
    const empty = n >= photos.length;
    stopSelecting();
    if (empty) setEmptied(true);
    else setStale(true);
  }

  async function run(fn: () => Promise<unknown>): Promise<boolean> {
    setBusy(true);
    setActionError(null);
    try {
      await fn();
      return true;
    } catch (e) {
      setActionError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function deleteItems(ids: number[]): Promise<boolean> {
    if (!(await confirmAsk(t('{items} löschen? Das lässt sich nicht rückgängig machen.', { items: itemsLabel(ids.length) })))) return false;
    if (!(await run(() => api.deleteGalleryPhotos(ids)))) return false;
    onToast(tn('Gelöscht', '{count} gelöscht', ids.length));
    removed(ids.length);
    return true;
  }

  async function setIntern(intern: boolean) {
    let done = 0;
    const ok = await run(async () => {
      for (const p of chosen) {
        if (p.intern !== intern) await api.setGalleryPhotoIntern(p.id, intern);
        done++;
      }
    });
    if (done > 0) {
      dataUpdated();
      setRetry(0);
      reload();
    }
    if (ok) {
      onToast(intern ? t('{items} nur für Mitglieder', { items: itemsLabel(done) }) : t('{items} öffentlich', { items: itemsLabel(done) }));
      stopSelecting();
    }
  }

  // Auswählen beginnt mit langem Drücken auf ein Bild (useLongPress), ohne eigenen Knopf. Einreichen direkt in dieses
  // Album (vorgewählt), wie oben in der Übersicht nur mit Haken „Einreichen erlauben“; Bearbeiten nur für Alben der App.
  const submit = can('submit_gallery') && gallerySubmissionsOpen();
  const edit = manage && albumEditable(album);
  const tools = (
    <AlbumActions
      album={album}
      hidden={selecting}
      submit={
        submit && (
          <IconButton
            icon="submit"
            label={t('Bilder und Videos in dieses Album einreichen')}
            onClick={() => navigate(`galerie/einreichen/${album.id}`)}
          />
        )
      }
      edit={edit && <IconButton icon="edit" label={t('Album bearbeiten')} onClick={() => setSheet({ kind: 'edit' })} />}
    />
  );
  const selectBar = selecting && (
    <div className="gallery-select-bar" role="toolbar" aria-label={t('Gewählte Bilder und Videos')}>
      <span className="small">
        {chosen.length === 0
          ? t('Tippe auf Bilder und Videos, um sie zu wählen.')
          : t('{items} gewählt', { items: itemsLabel(chosen.length) })}
      </span>
      <div className="button-row">
        <IconButton
          icon="selectAll"
          label={t('Alle wählen')}
          disabled={busy || chosen.length === uploads.length}
          onClick={() => setSelected(new Set(uploads.map((p) => p.id)))}
        />
        <IconButton
          icon="move"
          label={t('Verschieben')}
          disabled={busy || chosen.length === 0}
          onClick={() => setSheet({ kind: 'move', ids: chosen.map((p) => p.id), top: false })}
        />
        <IconButton
          icon="intern"
          label={t('Nur für Mitglieder')}
          disabled={busy || chosen.length === 0}
          onClick={() => void setIntern(true)}
        />
        <IconButton icon="public" label={t('Öffentlich')} disabled={busy || chosen.length === 0} onClick={() => void setIntern(false)} />
        <IconButton
          icon="delete"
          label={t('Löschen')}
          variant="danger"
          disabled={busy || chosen.length === 0}
          onClick={() => void deleteItems(chosen.map((p) => p.id))}
        />
      </div>
      {uploads.length < photos.length && <p className="small muted">{t('Bilder aus Google Drive verschiebst und löschst du in Drive.')}</p>}
      {actionError && (
        <p className="form-error small" role="alert">
          {actionError}
        </p>
      )}
    </div>
  );

  return (
    <div className={manage ? 'gallery-manage' : undefined}>
      <button className="btn btn-link back" onClick={() => navigate('galerie')}>
        {t('‹ Alle Alben')}
      </button>
      <h2 className="member-name">
        {album.title} <span className="muted album-year">{album.year}</span>
      </h2>
      {missing !== null && (
        <div className="card login-first" role="status">
          <p>
            {config?.user
              ? t('Das verlinkte Bild gibt es in diesem Album nicht mehr, oder du darfst es nicht sehen.')
              : t('Das verlinkte Bild gibt es in diesem Album nicht mehr, oder es ist nur für angemeldete Mitglieder.')}
          </p>
          {!config?.user && (
            <button className="btn btn-block" onClick={toLogin}>
              {t('Anmelden')}
            </button>
          )}
        </div>
      )}
      {tools}
      {selectBar}
      <ul className={selecting ? 'photo-grid selecting' : 'photo-grid'}>
        {photos.map((p, i) => (
          <li key={p.id}>
            <button
              ref={(el) => {
                tiles.current[i] = el;
              }}
              className={`${isVideo(p) ? 'photo photo-video' : 'photo'}${selected.has(p.id) ? ' selected' : ''}`}
              onClick={() => (selecting ? setSelected((old) => toggle(old, p.id)) : setOpen(i))}
              {...press(p.id, photoEditable(p))}
              disabled={selecting && !photoEditable(p)}
              aria-pressed={selecting ? selected.has(p.id) : undefined}
              data-intern={p.intern ? '' : undefined}
              aria-label={`${
                selecting
                  ? isVideo(p)
                    ? t('Video {number} wählen', { number: i + 1 })
                    : t('Bild {number} wählen', { number: i + 1 })
                  : isVideo(p)
                    ? t('Video {number} öffnen', { number: i + 1 })
                    : t('Bild {number} öffnen', { number: i + 1 })
              }${p.intern ? ` ${t('(nur für Mitglieder)')}` : ''}`}
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
              {selecting && photoEditable(p) && (
                <span className="photo-check" aria-hidden="true">
                  {selected.has(p.id) ? '✓' : ''}
                </span>
              )}
            </button>
          </li>
        ))}
      </ul>
      {open !== null && photos.length > 0 && (
        <Lightbox
          photos={photos}
          start={open}
          onClose={close}
          downloads={!!album.downloads}
          onShare={(p) =>
            void shareLink(photoUrl(config?.appUrl ?? window.location.href, album.id, p.id), `${brand.shortName}: ${album.title}`, onToast)
          }
          onIntern={
            canIntern
              ? async (p, intern) => {
                  await api.setGalleryPhotoIntern(p.id, intern);
                  dataUpdated();
                  setRetry(0);
                  reload();
                }
              : undefined
          }
          coverId={album.cover_id ?? null}
          onCover={
            manage
              ? async (p, cover) => {
                  await api.setGalleryAlbumCover(album.id, cover ? p.id : null);
                  dataUpdated();
                  onToast(cover ? t('Titelbild gewählt') : t('Titelbild wieder automatisch'));
                  reload();
                }
              : undefined
          }
          onMove={manage ? (p) => new Promise<boolean>((done) => setSheet({ kind: 'move', ids: [p.id], top: true, done })) : undefined}
          onDelete={manage ? (p) => deleteItems([p.id]) : undefined}
        />
      )}
      {sheet?.kind === 'move' && (
        <MoveSheet
          ids={sheet.ids}
          currentAlbum={album.id}
          top={sheet.top}
          onClose={() => {
            sheet.done?.(false);
            setSheet(null);
          }}
          onMoved={(_, title) => {
            sheet.done?.(true);
            setSheet(null);
            onToast(t('{items} nach „{title}“ verschoben', { items: itemsLabel(sheet.ids.length), title }));
            removed(sheet.ids.length);
          }}
        />
      )}
      {sheet?.kind === 'edit' && (
        <AlbumEditSheet
          album={album}
          count={photos.length}
          onClose={() => setSheet(null)}
          onSaved={() => {
            setSheet(null);
            onToast(t('Album gespeichert'));
            reload();
          }}
          onDeleted={() => {
            setSheet(null);
            onToast(t('Album gelöscht'));
            navigate('galerie');
          }}
        />
      )}
    </div>
  );
}

/**
 * Knöpfe über den Bildern eines Albums: Einreichen, daneben das ganze Album als ZIP (nur mit „Knöpfe zum Herunterladen
 * zeigen“ in Einstellungen → Galerie), dann Bearbeiten. Die Bilder lädt der Browser einzeln und packt sie selbst
 * (galleryDownload.ts), Videos eingeschlossen. Beim Auswählen sind die Knöpfe verborgen; ein laufendes Herunterladen
 * geht dabei weiter und zeigt seinen Stand.
 */
function AlbumActions({ album, hidden, submit, edit }: { album: GalleryAlbum; hidden: boolean; submit: ReactNode; edit: ReactNode }) {
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const abort = useRef<AbortController | null>(null);
  useEffect(() => () => abort.current?.abort(), []);
  const list = album.downloads ? downloadablePhotos(album.photos) : [];
  const count = list.length;
  const videos = list.filter(isVideo).length;
  if (!submit && !edit && count === 0) return null;
  const label = t('Album herunterladen ({media}, ZIP)', { media: mediaCount(count, videos) });

  async function start() {
    const ctrl = new AbortController();
    abort.current = ctrl;
    setError(null);
    setResult(null);
    try {
      const r = await downloadAlbum(album, (done, total) => setProgress({ done, total }), ctrl.signal);
      setResult(
        r.missing > 0
          ? `${tn('{count} Datei gespeichert.', '{count} Dateien gespeichert.', r.saved)} ${t(
              '{count} ließen sich gerade nicht laden (noch nicht fertig aufbereitet?).',
              { count: r.missing },
            )}`
          : tn('{count} Datei gespeichert.', '{count} Dateien gespeichert.', r.saved),
      );
    } catch (e) {
      if ((e as Error).name !== 'AbortError') setError((e as Error).message);
    } finally {
      abort.current = null;
      setProgress(null);
    }
  }

  return (
    <div className="album-download">
      {!hidden && (
        <div className="button-row gallery-actions">
          {submit}
          {count > 0 && (
            <button
              type="button"
              className="icon-btn icon-btn-secondary album-download-btn"
              onClick={start}
              disabled={progress !== null}
              aria-label={label}
              title={label}
            >
              {progress ? <span className="img-loading album-download-busy" aria-hidden="true" /> : <Icon name="download" />}
            </button>
          )}
          {edit}
        </div>
      )}
      {progress && (
        <div className="album-download-progress">
          <p className="small" role="status">
            {tc('Herunterladen', 'Lädt {current} von {total} …', { current: progress.done, total: progress.total })}
          </p>
          <button type="button" className="btn btn-link" onClick={() => abort.current?.abort()}>
            {t('Abbrechen')}
          </button>
        </div>
      )}
      {result && (
        <p className="small" role="status">
          {result}
        </p>
      )}
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

/** Pfeil nach unten auf einer Linie (Herunterladen). */
function DownloadIcon() {
  return (
    <svg
      viewBox="0 0 24 24"
      width="22"
      height="22"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M12 4v11M7 10.5l5 5 5-5M5 20h14" />
    </svg>
  );
}

type Zoom = { scale: number; x: number; y: number };
const NO_ZOOM: Zoom = { scale: 1, x: 0, y: 0 };
const MAX_ZOOM = 4;
/** Tippen auf ein Video: kürzer als TAP_TIME Millisekunden und weniger als TAP_DISTANCE Pixel bewegt. */
const TAP_TIME = 600;
const TAP_DISTANCE = 10;

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
 * (ohne Zoom; auf dem Bild des Videos wird geblättert, in den Leisten des Players bedient der Finger das Video).
 */
function Lightbox({
  photos,
  start,
  onClose,
  downloads = false,
  onShare,
  onIntern,
  coverId = null,
  onCover,
  onMove,
  onDelete,
}: {
  photos: GalleryPhoto[];
  start: number;
  onClose: (last: number) => void;
  downloads?: boolean;
  /** Link auf das gezeigte Bild oder Video verschicken (Teilen-Menü des Geräts, sonst kopieren); öffnet es in der App */
  onShare?: (p: GalleryPhoto) => void;
  /** Umstellen intern/öffentlich für hochgeladene Bilder und Videos (manage_gallery oder review_gallery) */
  onIntern?: (p: GalleryPhoto, intern: boolean) => Promise<void>;
  /** Gewähltes Titelbild des Albums (null: automatisch) */
  coverId?: number | null;
  /** Bild als Titelbild der Übersicht wählen (true) oder zurück auf automatisch (false), auch aus Drive (manage_gallery) */
  onCover?: (p: GalleryPhoto, cover: boolean) => Promise<void>;
  /** Hochgeladenes Bild oder Video verschieben bzw. löschen (manage_gallery); true, wenn es geschehen ist (dann schließt die Großansicht) */
  onMove?: (p: GalleryPhoto) => Promise<boolean>;
  onDelete?: (p: GalleryPhoto) => Promise<boolean>;
}) {
  const [i, setI] = useState(start);
  const current = useRef(i);
  current.current = i;
  // Zurück (Taste, Geste des Geräts) schließt die Großansicht statt das Album (backClose.ts). Schließen mit ✕ oder Escape
  // nimmt ihren Eintrag im Verlauf wieder weg.
  const closing = useRef(false);
  const close = useCallback(() => {
    if (closing.current) return;
    closing.current = true;
    onClose(current.current);
  }, [onClose]);
  useBackClose(true, close);
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
  const bar = useRef<HTMLDivElement>(null);
  // Platz zwischen den Knöpfen oben (✕) und der Leiste unten (Anzahl, Knöpfe): Unvergrößert passt das Bild genau
  // dazwischen, statt unter den Knöpfen zu liegen. Die Leiste unten wächst mit ihren Knöpfen (auch zweizeilig).
  useLayoutEffect(() => {
    const box = root.current;
    const close = closeRef.current;
    const count = bar.current;
    if (!box || !close || !count) return;
    const fit = () => {
      const top = close.offsetTop + close.offsetHeight + 8;
      const bottom = box.clientHeight - count.offsetTop + 8;
      box.style.setProperty('--lb-top', `${top}px`);
      box.style.setProperty('--lb-bottom', `${bottom}px`);
      box.style.setProperty('--lb-space', `${Math.max(120, box.clientHeight - top - bottom)}px`);
    };
    fit();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(fit);
    observer.observe(box);
    observer.observe(count);
    return () => observer.disconnect();
  }, []);
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
    if (g.kind !== 'pinch' && moved < 10 && !isVideo(p)) {
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
    if (!cur || isVideo(cur) || !cur.ready || !cur.original || originals.has(cur.id)) return;
    const big = new Image();
    big.onload = () => setOriginals((s) => new Set(s).add(cur.id));
    big.src = cur.original;
    return () => {
      big.onload = null;
    };
  }, [i, photos, originals]);

  // Kommen frische Daten mit weniger Bildern (z. B. inzwischen gelöscht oder nicht mehr sichtbar), schließt die
  // Großansicht, statt auf ein fehlendes Bild zu zeigen.
  const p = photos[Math.min(i, photos.length - 1)];
  useEffect(() => {
    if (i >= photos.length) close();
  }, [i, photos.length, close]);
  // Herunterladen des gezeigten Bilds oder Videos (Knopf oben rechts, mit „Knöpfe zum Herunterladen zeigen“). Videos laden
  // in Stücken; solange zeigt die Ansicht, wie weit es ist.
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [switching, setSwitching] = useState(false);
  const [saveProgress, setSaveProgress] = useState<string | null>(null);
  useEffect(() => setSaveError(null), [i]);
  const save = async () => {
    setSaving(true);
    setSaveError(null);
    try {
      await downloadMedia(p, i, (loaded, total) =>
        setSaveProgress(t('Video wird geladen … {percent} %', { percent: Math.floor((loaded / total) * 100) })),
      );
    } catch (e) {
      setSaveError((e as Error).message);
    } finally {
      setSaving(false);
      setSaveProgress(null);
    }
  };
  const videoRef = useRef<HTMLVideoElement>(null);
  const togglePlay = () => {
    const v = videoRef.current;
    if (!v) return;
    if (v.paused) void v.play().catch(() => {});
    else v.pause();
  };
  // Tippen selbst erkennen statt über click: Gleich nach einem Wisch liefert der Browser für das erste Tippen kein click
  // (in Chromium nachgestellt), dann startete das Video erst beim zweiten.
  const videoTap = useRef<{ id: number; x: number; y: number; t: number } | null>(null);
  const onVideoPointerDown = (e: ReactPointerEvent) => {
    videoTap.current = { id: e.pointerId, x: e.clientX, y: e.clientY, t: e.timeStamp };
  };
  const onVideoPointerUp = (e: ReactPointerEvent) => {
    const tap = videoTap.current;
    videoTap.current = null;
    if (!tap || tap.id !== e.pointerId || e.timeStamp - tap.t > TAP_TIME) return;
    if (Math.hypot(e.clientX - tap.x, e.clientY - tap.y) < TAP_DISTANCE) togglePlay();
  };
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
      aria-label={t('Bild {number} von {count}', { number: i + 1, count: photos.length })}
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
            <p className="lb-error">{t('Dieses Video kann dein Browser nicht abspielen.')}</p>
          ) : (
            // key: Beim Blättern hält das alte Video an, das neue lädt von vorn. preload auto: Es lädt schon beim Öffnen,
            // nicht erst beim Tippen auf Abspielen (nur ein Hinweis an den Browser, er darf z. B. zum Datensparen weniger laden).
            <div className="lb-video" key={p.id}>
              <video
                ref={videoRef}
                src={p.video}
                poster={p.large || undefined}
                controls
                playsInline
                preload="auto"
                onError={() => setUnplayable(p.id)}
              />
              {/* Fläche über dem Bild des Videos (nicht über den Leisten oben und unten): Der Player des Browsers gibt
                  das Aufsetzen des Fingers nicht an die Seite weiter (so in Chromium), hier kommt es zum Wischen an. Tippen spielt ab bzw. hält an. */}
              <div
                className="lb-video-hit"
                aria-hidden="true"
                onPointerDown={onVideoPointerDown}
                onPointerUp={onVideoPointerUp}
                onPointerCancel={() => (videoTap.current = null)}
              />
            </div>
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
      <button ref={closeRef} className="lb-btn lb-close" onClick={close} aria-label={t('Schließen')}>
        ✕
      </button>
      {downloads && (
        <button
          className="lb-btn lb-download"
          onClick={save}
          disabled={saving}
          aria-label={isVideo(p) ? t('Video herunterladen') : t('Bild herunterladen')}
          title={isVideo(p) ? t('Video herunterladen') : t('Bild herunterladen')}
        >
          {saving ? <span className="img-loading lb-download-busy" aria-hidden="true" /> : <DownloadIcon />}
        </button>
      )}
      {/* Oben neben dem Herunterladen (ohne dieses direkt neben ✕) */}
      {onShare && (
        <button
          className="lb-btn lb-share"
          onClick={() => onShare(p)}
          aria-label={isVideo(p) ? t('Link zu diesem Video teilen') : t('Link zu diesem Bild teilen')}
          title={isVideo(p) ? t('Link zu diesem Video teilen') : t('Link zu diesem Bild teilen')}
        >
          <Icon name="share" />
        </button>
      )}
      {saveProgress && (
        <p className="lb-save-error" role="status">
          {saveProgress}
        </p>
      )}
      {saveError && (
        <p className="lb-save-error" role="alert">
          {saveError}
        </p>
      )}
      {many && (
        <>
          <button className="lb-btn lb-prev" onClick={() => swipe.slide(-1)} aria-label={t('Vorheriges')}>
            ‹
          </button>
          <button className="lb-btn lb-next" onClick={() => swipe.slide(1)} aria-label={t('Nächstes')}>
            ›
          </button>
        </>
      )}
      {/* Anzahl in eigener Zeile, die Knöpfe zum Verwalten darunter */}
      <div className="lb-count" ref={bar}>
        <span className="lb-count-text">
          {i + 1} / {photos.length}
          {p.intern && ` · ${t('intern')}`}
          {coverId === p.id && ` · ${t('Titelbild')}`}
        </span>
        {onCover && !isVideo(p) && (
          <IconButton
            icon="cover"
            label={coverId === p.id ? t('Titelbild wieder automatisch wählen') : t('Als Titelbild des Albums verwenden')}
            variant="lb"
            className={coverId === p.id ? 'lb-intern lb-active' : 'lb-intern'}
            disabled={switching}
            onClick={async () => {
              setSwitching(true);
              setSaveError(null);
              try {
                await onCover(p, coverId !== p.id);
              } catch (e) {
                setSaveError((e as Error).message);
              } finally {
                setSwitching(false);
              }
            }}
          />
        )}
        {onIntern && photoEditable(p) && (
          <IconButton
            icon={p.intern ? 'public' : 'intern'}
            label={p.intern ? t('Öffentlich zeigen') : t('Nur für Mitglieder')}
            variant="lb"
            className="lb-intern"
            disabled={switching}
            onClick={async () => {
              setSwitching(true);
              setSaveError(null);
              try {
                await onIntern(p, !p.intern);
              } catch (e) {
                setSaveError((e as Error).message);
              } finally {
                setSwitching(false);
              }
            }}
          />
        )}
        {onMove && photoEditable(p) && (
          <IconButton
            icon="move"
            label={t('Verschieben')}
            variant="lb"
            className="lb-intern"
            disabled={switching}
            onClick={async () => {
              if (await onMove(p)) close();
            }}
          />
        )}
        {onDelete && photoEditable(p) && (
          <IconButton
            icon="delete"
            label={t('Löschen')}
            variant="lb-danger"
            className="lb-intern"
            disabled={switching}
            onClick={async () => {
              if (await onDelete(p)) close();
            }}
          />
        )}
      </div>
    </div>
  );
}
