import type { TitleRef } from './api';

/**
 * Titel als kleine Abzeichen, z. B. in „Meins“ und in der Mitgliederliste. `role` steht davor wie ein Titel: die
 * Rolle von Personen, die noch als Mitglied aufzunehmen sind (z. B. Anwärter), damit man sie in der Liste erkennt.
 */
export function TitleBadges({ titles, role }: { titles?: TitleRef[]; role?: string }) {
  if (!role && (!titles || titles.length === 0)) return null;
  return (
    <span className="title-badges">
      {role && <span className="badge badge-title badge-candidate">{role}</span>}
      {(titles ?? []).map((t) => (
        <span key={t.id} className="badge badge-title">
          {t.title}
        </span>
      ))}
    </span>
  );
}
