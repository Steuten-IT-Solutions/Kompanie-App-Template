import type { ReactNode } from 'react';
import type { DirectoryEntry } from './api';
import { ZoomableAvatar } from './memberPhoto';
import { RankStack } from './RankBadge';
import { TitleBadges } from './TitleBadges';

/**
 * Zeile der Mitgliederliste: Foto (vergrößerbar), Name mit Titeln, rechts der Dienstgrad. Mit onOpen öffnet die ganze
 * Zeile die Seite der Person; das Foto liegt darüber und vergrößert weiterhin. Auch in der Liste auf der Seite einer
 * Kompanie, damit beide gleich aussehen.
 */
export function MemberRow({
  m,
  name = m.name,
  onOpen,
  children,
}: {
  m: Pick<DirectoryEntry, 'id' | 'name'> &
    Partial<Pick<DirectoryEntry, 'photo_url' | 'titles' | 'candidate_role' | 'rank_title' | 'rank_badge_url'>>;
  /** Angezeigter Name, z. B. „Nachname, Vorname“ bei Sortierung nach Nachname */
  name?: string;
  onOpen?: () => void;
  /** Zusätzliche Zeilen unter dem Namen */
  children?: ReactNode;
}) {
  const title = (
    <>
      {name} <TitleBadges titles={m.titles} role={m.candidate_role ?? undefined} />
    </>
  );
  return (
    <li className={`card row member-row${onOpen ? ' member-row-link' : ''}`}>
      <ZoomableAvatar url={m.photo_url ?? null} name={m.name} size="medium" />
      <div className="row-main">
        {onOpen ? (
          <button type="button" className="row-title member-row-open" onClick={onOpen}>
            {title}
          </button>
        ) : (
          <div className="row-title">{title}</div>
        )}
        {children}
      </div>
      {/* Dienstgrad rechts, gegenüber dem Foto; ohne Dienstgrad bleibt die Spalte leer (Text in allen Zeilen mittig). */}
      {m.rank_title !== undefined && (
        <div className="member-row-rank">{m.rank_title && <RankStack url={m.rank_badge_url} title={m.rank_title} />}</div>
      )}
    </li>
  );
}
