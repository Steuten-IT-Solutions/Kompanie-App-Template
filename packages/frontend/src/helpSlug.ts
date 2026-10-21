/** Was die Hilfe über die Person und die App wissen muss, um die passende Seite zu wählen. */
export interface HelpContext {
  boardWriter: boolean;
  meinsFirst?: string;
  /** Recht der Person (wie can() in api.ts). */
  can: (cap: string) => boolean;
  /** Eingeschaltete Bereiche. */
  features: Partial<Record<string, boolean>>;
}

/**
 * Hilfeseite zur aktuellen Seite (Knopf „?“ oben): Adresse der App (#/<Ansicht>/<Unterseite>) → Kennung der Seite im
 * Handbuch (docs/handbuch/<kennung>.md). Ohne passende Seite null; dann öffnet die Hilfe ihr Verzeichnis. Darf die
 * Person die Seite nicht sehen, zeigt die Hilfe ebenfalls das Verzeichnis.
 */
export function helpSlug(path: string[], ctx: HelpContext): string | null {
  const [view, sub] = path;
  const on = (f: string): boolean => !!ctx.features[f];
  switch (view) {
    case 'termine':
      if (sub === 'verwalten') return 'termine-pflegen';
      if (sub === 'geburtstage') return 'geburtstage';
      // Mit internem Kalender zeigt die Seite alle Termine (kalender), sonst nur die öffentlichen.
      return on('calendar') && ctx.can('calendar')
        ? 'kalender'
        : on('events')
          ? 'termine-und-kalender'
          : on('birthdays')
            ? 'geburtstage'
            : null;
    case 'news':
      return 'neuigkeiten';
    case 'galerie':
      return 'galerie';
    case 'dokumente':
      return sub === 'verwalten' ? 'dokumente-pflegen' : 'dokumente';
    case 'mitglieder':
      return 'mitglieder';
    case 'meins':
      return (
        { punkte: 'meine-punkte', strafen: 'meine-strafen', dienstgrad: 'mein-dienstgrad', anwesenheit: 'meine-anwesenheit' }[
          sub ?? ctx.meinsFirst ?? ''
        ] ?? (on('titles') ? 'meine-titel' : null)
      );
    case 'verwaltung':
      return adminSlug(path.slice(1), ctx);
    case 'brett':
      return ctx.boardWriter ? 'schwarzes-brett-schreiben' : 'schwarzes-brett';
    case 'umfragen':
      return sub === 'verwalten' ? 'umfragen-verwalten' : 'umfragen';
    case 'inventar':
      return 'inventar';
    case 'konto':
    case 'anmelden':
      return 'anmelden-und-konto';
    case 'benachrichtigungen':
      return 'benachrichtigungen';
    case 'installieren':
      return 'app-installieren';
    case 'einrichtung':
      return 'admin-einrichtung';
    case 'einstellungen':
      return (
        {
          bereiche: 'admin-einrichtung',
          news: 'admin-einrichtung',
          kalender: 'admin-einrichtung',
          galerie: 'admin-einrichtung',
          rollen: 'admin-rollen-und-mitglieder',
          google: 'admin-google',
          zustand: 'admin-zustand',
          wartung: 'admin-wartungsmodus',
          datensicherung: 'admin-updates-und-sicherung',
        }[sub ?? ''] ?? 'admin-einstellungen'
      );
    default:
      return null;
  }
}

/** Verwaltung: Reiter, Mitgliederliste und die Seiten eines Mitglieds (verwaltung/mitglied/<id>/<seite>). */
function adminSlug([sub, , page]: string[], ctx: HelpContext): string | null {
  const tabs: Record<string, string> = {
    punkte: 'punkte-vergeben',
    katalog: 'punkte-vergeben',
    strafe: 'strafen-verwalten',
    dienstgrade: 'dienstgrade-verwalten',
    befoerderungen: 'dienstgrade-verwalten',
    titel: 'titel-verwalten',
    anwesenheit: 'anwesenheit-erfassen',
  };
  if (sub && tabs[sub]) return tabs[sub];
  if (sub === 'neu' || sub === 'neu-mehrere') return 'mitglieder-anlegen';
  if (sub === 'mitglied') {
    const memberPages: Record<string, string> = {
      punkte: 'punkte-vergeben',
      strafen: 'strafen-verwalten',
      dienstgrad: 'dienstgrade-verwalten',
      titel: 'titel-verwalten',
    };
    if (page && memberPages[page]) return memberPages[page];
    if (page === 'geburtstag' && ctx.can('create_users')) return 'geburtstage-pflegen';
  }
  // Mitgliederliste (und Übersicht, Konto und Rolle eines Mitglieds): die Seite zur Aufgabe, die die Person hier hat.
  const byRight: [string, string, string | null][] = [
    ['create_users', 'mitglieder-anlegen', null],
    ['promote_users', 'rollen-vergeben', null],
    ['manage_points', 'punkte-vergeben', 'points'],
    ['manage_penalties', 'strafen-verwalten', 'penalties'],
    ['manage_ranks', 'dienstgrade-verwalten', 'ranks'],
    ['manage_titles', 'titel-verwalten', 'titles'],
    ['manage_attendance', 'anwesenheit-erfassen', 'attendance'],
  ];
  return byRight.find(([cap, , feature]) => ctx.can(cap) && (feature === null || !!ctx.features[feature]))?.[1] ?? null;
}
