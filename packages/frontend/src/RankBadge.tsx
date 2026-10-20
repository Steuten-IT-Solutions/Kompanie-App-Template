import { useEffect, useState } from 'react';

/** Darstellungsgrößen der Abzeichen; geladen wird die passende PNG-Fassung (für hochauflösende Displays doppelt). */
export type RankBadgeSize = 'small' | 'medium' | 'large';

const PIXELS: Record<RankBadgeSize, number> = { small: 64, medium: 128, large: 256 };

/** Adresse eines Abzeichens in einer Größe (64, 128 oder 256 Pixel); die API liefert sie mit s=128. */
export function badgeSrc(url: string, px: number): string {
  return /([?&])s=\d+/.test(url) ? url.replace(/([?&])s=\d+/, `$1s=${px}`) : `${url}${url.includes('?') ? '&' : '?'}s=${px}`;
}

/**
 * Abzeichen eines Dienstgrads (Issue #17). Ohne eigenen Hintergrund, Rahmen oder Farbfilter: Transparente Abzeichen
 * stehen direkt auf der Fläche dahinter (hell wie dunkel). Ohne Abzeichen (oder wenn es nicht lädt) nichts; die
 * Bezeichnung zeigt der Aufrufer wie bisher.
 */
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

/** Abzeichen und Bezeichnung nebeneinander; ohne Abzeichen nur die Bezeichnung. */
export function RankLabel({ url, title, size = 'small' }: { url?: string | null; title: string; size?: RankBadgeSize }) {
  if (!url) return <>{title}</>;
  return (
    <span className="rank-label">
      <RankBadge url={url} title={title} size={size} />
      <span>{title}</span>
    </span>
  );
}
