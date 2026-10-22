import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api, type EncodedFile } from './api';
import { badgeSrc } from './RankBadge';

/**
 * Fotos der Mitglieder. Hochladen, ersetzen und entfernen nur, wer Mitglieder anlegen darf; sehen die Person selbst
 * (Konto) und wer die Mitglieder in der Verwaltung sieht. Der Server speichert 128, 480 und 1024 Pixel und, soweit
 * möglich, die Originalauflösung (bis 4096 Pixel).
 */

/** Längere Kante beim Hochladen: Originalauflösung bis zur Grenze des Servers (MemberPhotoService::MAX_EDGE). */
const UPLOAD_EDGE = 4096;
/**
 * Höchstens so groß wird das hochgeladene JPEG (sonst geringere Qualität, notfalls kleiner): Als Base64 rund 5,5 MB,
 * das passt in die üblichen Grenzen von PHP für eine Anfrage (post_max_size meist 8 MB) und unter die 5 MB des Servers.
 */
const UPLOAD_MAX_BYTES = 4 * 1024 * 1024;
const UPLOAD_QUALITIES = [0.9, 0.85, 0.8, 0.7];
/** Großansicht: erst diese Fassung (ältere Fotos haben sie nicht, dann die große) … */
const ZOOM_PIXELS = 1024;
/** … dann die Originalauflösung nachladen (fehlt sie, bleibt es bei der 1024er). */
const ORIGINAL_PIXELS = 4096;

/** Darstellungsgrößen; geladen wird die passende Fassung (128 oder 480 Pixel, für hochauflösende Displays reichlich). */
export type AvatarSize = 'small' | 'medium' | 'large';
const PIXELS: Record<AvatarSize, number> = { small: 128, medium: 128, large: 480 };

/** Ein gewähltes, schon verkleinertes Foto: für die API und als Vorschau. */
export type PreparedPhoto = { file: EncodedFile; preview: string };

/**
 * Bild im Browser lesen und als JPEG neu speichern: höchstens UPLOAD_EDGE Pixel (Ausrichtung wie angezeigt, ohne
 * Metadaten wie den GPS-Ort) und höchstens UPLOAD_MAX_BYTES. Jedes Foto geht in einer eigenen Anfrage. Wirft eine
 * lesbare Meldung, wenn die Datei kein Bild ist, das der Browser anzeigen kann.
 */
export async function preparePhoto(file: File): Promise<PreparedPhoto> {
  if (!file.type.startsWith('image/') && !/\.(jpe?g|png|webp|heic|heif)$/i.test(file.name)) {
    throw new Error('Bitte ein Foto auswählen (JPG, PNG oder WebP).');
  }
  const url = URL.createObjectURL(file);
  try {
    const img = new Image();
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error('Das Foto lässt sich nicht lesen. Bitte ein JPG, PNG oder WebP wählen.'));
      img.src = url;
    });
    const w0 = img.naturalWidth;
    const h0 = img.naturalHeight;
    if (w0 < 1 || h0 < 1) throw new Error('Das Foto lässt sich nicht lesen.');
    let preview = '';
    // Erst die Qualität senken, dann (selten, z. B. bei Panoramen) die Kantenlänge.
    for (let edge = UPLOAD_EDGE; preview === '' && edge >= 512; edge = Math.round(edge * 0.75)) {
      const scale = Math.min(1, edge / Math.max(w0, h0));
      const w = Math.max(1, Math.round(w0 * scale));
      const h = Math.max(1, Math.round(h0 * scale));
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      if (!ctx) throw new Error('Das Foto lässt sich auf diesem Gerät nicht verarbeiten.');
      // Transparente Flächen (PNG) weiß statt schwarz, wie auf dem Server.
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, w, h);
      ctx.drawImage(img, 0, 0, w, h);
      for (const quality of UPLOAD_QUALITIES) {
        const data = canvas.toDataURL('image/jpeg', quality);
        if (base64Bytes(data) <= UPLOAD_MAX_BYTES) {
          preview = data;
          break;
        }
      }
      canvas.width = 0; // Speicher des großen Canvas gleich freigeben (iOS begrenzt ihn)
    }
    if (preview === '') throw new Error('Das Foto ist zu groß. Bitte ein kleineres Foto wählen.');
    const name = `${file.name.replace(/\.[^.]*$/, '') || 'foto'}.jpg`;
    return { file: { name, data: preview.replace(/^data:[^,]*,/, '') }, preview };
  } finally {
    URL.revokeObjectURL(url);
  }
}

/** Größe der Datei hinter einer Data-URL mit Base64. */
function base64Bytes(dataUrl: string): number {
  const b64 = dataUrl.slice(dataUrl.indexOf(',') + 1);
  return Math.floor((b64.length * 3) / 4) - (b64.endsWith('==') ? 2 : b64.endsWith('=') ? 1 : 0);
}

/** Anfangsbuchstaben für Personen ohne Foto: „Max Mustermann“ → „MM“. */
export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);
  const first = words[0]?.[0] ?? '';
  const last = words.length > 1 ? (words[words.length - 1][0] ?? '') : '';
  return (first + last).toUpperCase() || '?';
}

/** Rundes Foto; ohne Foto (oder wenn es nicht lädt) die Anfangsbuchstaben. */
export function MemberAvatar({ url, name, size = 'small' }: { url?: string | null; name: string; size?: AvatarSize }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [url]);
  const src = url && !failed ? (url.startsWith('data:') ? url : badgeSrc(url, PIXELS[size])) : null;
  return (
    <span className={`member-avatar member-avatar-${size}`} aria-hidden={size === 'small' ? true : undefined}>
      {src ? (
        <img src={src} alt={size === 'small' ? '' : `Foto von ${name}`} loading="lazy" decoding="async" onError={() => setFailed(true)} />
      ) : (
        <span className="member-avatar-initials">{initials(name)}</span>
      )}
    </span>
  );
}

/**
 * Foto vergrößert über der Seite (erst die 1024-Pixel-Fassung, dann die Originalauflösung nachgeladen; ganzes Bild
 * statt runder Ausschnitt), damit sich neue Mitglieder die Gesichter einprägen können. Schließen mit ✕, Escape oder Tippen neben
 * das Bild. Im body, damit es nicht im Absatz der Dienstgrad-Zeile steht und deren Farbe erbt.
 */
function PhotoZoom({ url, name, onClose }: { url: string; name: string; onClose: () => void }) {
  const closeRef = useRef<HTMLButtonElement>(null);
  // Vor Datenbankversion 18 hochgeladene Fotos gibt es nicht in 1024 Pixeln (404): dann die 480er.
  const [fallback, setFallback] = useState(false);
  // Bis das Bild da ist, dreht sich die Ladeschnecke (die 1024er-Fassung braucht im Mobilnetz einen Moment).
  const [state, setState] = useState<'loading' | 'loaded' | 'failed'>('loading');
  const [original, setOriginal] = useState<string | null>(null);
  const src = url.startsWith('data:') ? url : (original ?? badgeSrc(url, fallback ? PIXELS.large : ZOOM_PIXELS));
  useEffect(() => {
    if (url.startsWith('data:')) return;
    // Originalauflösung im Hintergrund laden und erst tauschen, wenn sie ganz da ist (fehlt sie: 404, nichts tun).
    const big = new Image();
    big.onload = () => setOriginal(big.src);
    big.src = badgeSrc(url, ORIGINAL_PIXELS);
    return () => {
      big.onload = null;
      big.src = '';
    };
  }, [url]);
  useEffect(() => {
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
    };
  }, [onClose]);
  return createPortal(
    <div
      className="lightbox member-photo-zoom"
      role="dialog"
      aria-modal="true"
      aria-label={`Foto von ${name}`}
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <figure onClick={(e) => e.target === e.currentTarget && onClose()}>
        {state === 'loading' && (
          <span className="member-photo-zoom-loading" role="status" aria-label="Foto lädt">
            <span className="spinner" aria-hidden="true" />
          </span>
        )}
        {state === 'failed' ? (
          <p className="member-photo-zoom-error">Das Foto lässt sich gerade nicht laden.</p>
        ) : (
          <img
            src={src}
            alt={`Foto von ${name}`}
            decoding="async"
            hidden={state === 'loading'}
            onLoad={() => setState('loaded')}
            onError={() => (fallback || url.startsWith('data:') ? setState('failed') : setFallback(true))}
          />
        )}
        <figcaption>{name}</figcaption>
      </figure>
      <button ref={closeRef} type="button" className="lb-btn lb-close" onClick={onClose} aria-label="Schließen">
        ✕
      </button>
    </div>,
    document.body,
  );
}

/** Foto in den Mitgliedsdetails und der Mitgliederliste: Antippen vergrößert es; ohne Foto nur die Anfangsbuchstaben. */
export function ZoomableAvatar({ url, name, size = 'large' }: { url?: string | null; name: string; size?: AvatarSize }) {
  const [zoomed, setZoomed] = useState(false);
  const close = useCallback(() => setZoomed(false), []);
  if (!url) return <MemberAvatar url={url} name={name} size={size} />;
  return (
    <>
      <button type="button" className="member-photo-open" onClick={() => setZoomed(true)} aria-label={`Foto von ${name} vergrößern`}>
        <MemberAvatar url={url} name={name} size={size} />
      </button>
      {zoomed && <PhotoZoom url={url} name={name} onClose={close} />}
    </>
  );
}

/** Foto auswählen (Anlegen eines Mitglieds): Vorschau, Ändern und Entfernen; gespeichert wird erst mit dem Formular. */
export function PhotoPicker({
  name,
  value,
  onChange,
  onError,
}: {
  name: string;
  value: PreparedPhoto | null;
  onChange: (photo: PreparedPhoto | null) => void;
  onError: (message: string | null) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  async function choose(file: File | null) {
    if (input.current) input.current.value = '';
    if (!file) return;
    setBusy(true);
    try {
      onChange(await preparePhoto(file));
      onError(null);
    } catch (err) {
      onError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="photo-picker">
      <MemberAvatar url={value?.preview} name={name || '?'} size="large" />
      <div className="button-row">
        <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => input.current?.click()}>
          {busy ? 'Liest …' : value ? 'Anderes Foto' : 'Foto auswählen'}
        </button>
        {value && (
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => onChange(null)}>
            Kein Foto
          </button>
        )}
      </div>
      <input
        ref={input}
        type="file"
        accept="image/jpeg,image/png,image/webp,image/*"
        hidden
        aria-label="Foto-Datei"
        onChange={(e) => choose(e.target.files?.[0] ?? null)}
      />
    </div>
  );
}

/**
 * Foto in den Mitgliedsdetails: groß anzeigen; mit Recht hochladen, ersetzen und entfernen (sofort gespeichert).
 * Mit showAvatar={false} nur die Knöpfe, wenn das Foto schon an anderer Stelle steht.
 */
export function MemberPhotoPanel({
  id,
  name,
  url,
  editable,
  showAvatar = true,
  onChanged,
  onToast,
}: {
  id: number;
  name: string;
  url: string | null;
  editable: boolean;
  showAvatar?: boolean;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  async function upload(file: File | null) {
    if (input.current) input.current.value = '';
    if (!file) return;
    setBusy(true);
    try {
      const photo = await preparePhoto(file);
      await api.uploadMemberPhoto(id, photo.file);
      onToast(url ? 'Foto ersetzt' : 'Foto gespeichert');
      onChanged();
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!window.confirm(`Foto von ${name} entfernen?`)) return;
    setBusy(true);
    try {
      await api.removeMemberPhoto(id);
      onToast('Foto entfernt');
      onChanged();
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (!editable && (!url || !showAvatar)) return null;
  return (
    <div className="member-photo">
      {showAvatar && <MemberAvatar url={url} name={name} size="large" />}
      {editable && (
        <>
          <div className="button-row">
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => input.current?.click()}>
              {busy ? 'Speichert …' : url ? 'Foto ersetzen' : 'Foto hochladen'}
            </button>
            {url && (
              <button type="button" className="btn btn-danger" disabled={busy} onClick={remove}>
                Foto entfernen
              </button>
            )}
          </div>
          <input
            ref={input}
            type="file"
            accept="image/jpeg,image/png,image/webp,image/*"
            hidden
            aria-label="Foto-Datei"
            onChange={(e) => upload(e.target.files?.[0] ?? null)}
          />
        </>
      )}
    </div>
  );
}
