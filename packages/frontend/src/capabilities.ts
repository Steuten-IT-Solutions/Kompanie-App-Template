import type { Capability } from './api';
import brand from './generated/brand';

const sec = brand.labels.sections;

/**
 * Rechte, die sich einer Rolle oder einer einzelnen Person geben lassen (Einstellungen bleiben den Admins
 * vorbehalten), mit den Texten für Einstellungen → Rollen und die Rechte eines Mitglieds.
 */
export const CAPABILITIES: { key: Capability; label: string }[] = [
  { key: 'member', label: 'Mitgliederbereich: eigene Punkte und Strafen, interner Kalender, interne Galerie' },
  { key: 'calendar', label: 'Interner Kalender (ohne Mitgliederbereich, z. B. für Anwärter)' },
  { key: 'manage_points', label: `${sec.points} vergeben, Katalog pflegen` },
  { key: 'manage_penalties', label: `${sec.penalties} vergeben und als bezahlt markieren` },
  { key: 'manage_ranks', label: `${sec.ranks} pflegen, befördern und degradieren` },
  { key: 'manage_titles', label: `${sec.titles} pflegen und vergeben` },
  { key: 'manage_attendance', label: `${sec.attendance}: Listen je Termin führen` },
  { key: 'manage_events', label: 'Öffentliche Termine pflegen' },
  { key: 'manage_documents', label: 'Dokumente pflegen und alle Dokumente sehen' },
  { key: 'post_board', label: `${sec.board}: dringende Nachrichten schreiben` },
  { key: 'manage_polls', label: `${sec.polls}: anlegen, beenden und alle Ergebnisse sehen` },
  { key: 'manage_inventory', label: `${sec.inventory}: sehen und pflegen, umlagern, Verlauf` },
  { key: 'create_users', label: 'Mitglieder anlegen und Startpasswörter erzeugen (nicht für Personen mit mehr Rechten)' },
  { key: 'promote_users', label: 'Rollen vergeben (nur Rollen mit eigenen Rechten, ohne diese beiden Rechte)' },
];
