import { useEffect, useState } from 'react';
import { nameIn } from './names';

/**
 * Bezeichnung eines Dienstgrads in der Sprache der App („Einrichten je Sprache“): eigener Name dieser Sprache, sonst
 * die deutsche Bezeichnung. Ohne ID (ältere Server) die deutsche.
 */
export function rankName(id: number | null | undefined, german: string): string {
  return id === null || id === undefined ? german : nameIn('rank', String(id), german);
}

/** Darstellungsgrößen der Abzeichen; geladen wird die passende PNG-Fassung (für hochauflösende Displays doppelt). */
export type RankBadgeSize = 'small' | 'medium' | 'large';

const PIXELS: Record<RankBadgeSize, number> = { small: 64, medium: 128, large: 256 };

/** Adresse eines Abzeichens in einer Größe (64, 128 oder 256 Pixel); die API liefert sie mit s=128. */
export function badgeSrc(url: string, px: number): string {
  return /([?&])s=\d+/.test(url) ? url.replace(/([?&])s=\d+/, `$1s=${px}`) : `${url}${url.includes('?') ? '&' : '?'}s=${px}`;
}

/** Bild in den Speicher des Browsers laden; fertig, wenn es da ist oder nicht lädt (zum Vorladen nacheinander). */
export function preloadImage(src: string): Promise<void> {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = img.onerror = () => resolve();
    img.src = src;
  });
}

/**
 * Abzeichen eines Dienstgrads (Issue #17). Ohne eigenen Hintergrund, Rahmen oder Farbfilter: Transparente Abzeichen
 * stehen direkt auf der Fläche dahinter (hell wie dunkel). Ohne Abzeichen (oder wenn es nicht lädt) nichts; die
 * Bezeichnung zeigt der Aufrufer wie bisher.
 */
/** Abzeichen schon laden (z. B. des nächsten Mitglieds beim Wischen), damit es ohne Verzögerung erscheint. */
export function preloadBadge(url: string | null | undefined, size: RankBadgeSize = 'large'): Promise<void> {
  return url ? preloadImage(badgeSrc(url, PIXELS[size])) : Promise.resolve();
}

export function RankBadge({ url, title, size = 'small' }: { url?: string | null; title: string; size?: RankBadgeSize }) {
  const [failed, setFailed] = useState(false);
  useEffect(() => setFailed(false), [url]);
  if (!url || failed) return null;
  return (
    <img
      className={`rank-badge rank-badge-${size}`}
      src={badgeSrc(url, PIXELS[size])}
      alt={title}
      title={title}
      loading={size === 'large' ? 'eager' : 'lazy'}
      decoding="async"
      onError={() => setFailed(true)}
    />
  );
}

/**
 * Abzeichen und Bezeichnung nebeneinander; ohne Abzeichen nur die Bezeichnung. Mit `quoted` steht die Bezeichnung in
 * Anführungszeichen („Maat“), das Abzeichen davor (z. B. der nächste Dienstgrad in den Beförderungen).
 */
export function RankLabel({
  url,
  title,
  size = 'small',
  quoted = false,
}: {
  url?: string | null;
  title: string;
  size?: RankBadgeSize;
  quoted?: boolean;
}) {
  const text = quoted ? `„${title}“` : title;
  if (!url) return <>{text}</>;
  return (
    <span className="rank-label">
      <RankBadge url={url} title={title} size={size} />
      <span>{text}</span>
    </span>
  );
}

/** Abzeichen und darunter die Bezeichnung (Mitgliederliste); ohne Abzeichen nur die Bezeichnung. */
export function RankStack({ url, title }: { url?: string | null; title: string }) {
  return (
    <span className="rank-stack">
      <RankBadge url={url} title={title} size="medium" />
      <span>{title}</span>
    </span>
  );
}
