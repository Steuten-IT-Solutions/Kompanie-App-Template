import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api, type EncodedFile } from './api';
import { badgeSrc } from './RankBadge';

/**
 * Fotos der Mitglieder. Hochladen, ersetzen und entfernen nur, wer Mitglieder anlegen darf; sehen die Person selbst
 * (Konto) und wer die Mitglieder in der Verwaltung sieht. Der Server speichert 128, 480 und 1024 Pixel.
 */

/** Längere Kante beim Hochladen: genug für die große Fassung, und die Anfrage bleibt klein (Server-Grenzen). */
const UPLOAD_EDGE = 1024;
/** Fassung für die vergrößerte Ansicht (so groß wie hochgeladen); ältere Fotos haben sie nicht, dann die große. */
const ZOOM_PIXELS = 1024;
const UPLOAD_QUALITY = 0.9;

/** Darstellungsgrößen; geladen wird die passende Fassung (128 oder 480 Pixel, für hochauflösende Displays reichlich). */
export type AvatarSize = 'small' | 'medium' | 'large';
const PIXELS: Record<AvatarSize, number> = { small: 128, medium: 128, large: 480 };

/** Ein gewähltes, schon verkleinertes Foto: für die API und als Vorschau. */
export type PreparedPhoto = { file: EncodedFile; preview: string };

/**
 * Bild im Browser lesen und auf höchstens UPLOAD_EDGE Pixel als JPEG verkleinern (Ausrichtung wie angezeigt). Handyfotos
 * sind oft mehrere MB groß; so passt auch das Anlegen vieler Personen in die Grenzen des Servers. Wirft eine lesbare
 * Meldung, wenn die Datei kein Bild ist, das der Browser anzeigen kann.
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
    const scale = Math.min(1, UPLOAD_EDGE / Math.max(w0, h0));
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
    const preview = canvas.toDataURL('image/jpeg', UPLOAD_QUALITY);
    const name = `${file.name.replace(/\.[^.]*$/, '') || 'foto'}.jpg`;
    return { file: { name, data: preview.replace(/^data:[^,]*,/, '') }, preview };
  } finally {
    URL.revokeObjectURL(url);
  }
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
 * Foto vergrößert über der Seite (1024-Pixel-Fassung, scharf auch bei hoher Pixeldichte; ganzes Bild statt runder
 * Ausschnitt), damit sich neue Mitglieder die Gesichter einprägen können. Schließen mit ✕, Escape oder Tippen neben
 * das Bild. Im body, damit es nicht im Absatz der Dienstgrad-Zeile steht und deren Farbe erbt.
 */
function PhotoZoom({ url, name, onClose }: { url: string; name: string; onClose: () => void }) {
  const closeRef = useRef<HTMLButtonElement>(null);
  // Vor Datenbankversion 18 hochgeladene Fotos gibt es nicht in 1024 Pixeln (404): dann die 480er.
  const [fallback, setFallback] = useState(false);
  const src = url.startsWith('data:') ? url : badgeSrc(url, fallback ? PIXELS.large : ZOOM_PIXELS);
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
        <img src={src} alt={`Foto von ${name}`} decoding="async" onError={() => setFallback(true)} />
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
