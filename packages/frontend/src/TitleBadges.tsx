import type { TitleRef } from './api';

/** Titel als kleine Abzeichen, z. B. in „Meins“ und in der Mitgliederliste. */
export function TitleBadges({ titles }: { titles?: TitleRef[] }) {
  if (!titles || titles.length === 0) return null;
  return (
    <span className="title-badges">
      {titles.map((t) => (
        <span key={t.id} className="badge badge-title">
          {t.title}
        </span>
      ))}
    </span>
  );
}
