/** Was die Hilfe über die Person und die App wissen muss, um die passende Seite zu wählen. */
export interface HelpContext {
  boardWriter: boolean;
  /** Recht der Person (wie can() in api.ts). */
  can: (cap: string) => boolean;
  /** Eingeschaltete Bereiche. */
  features: Partial<Record<string, boolean>>;
}

/**
 * Hilfeseite zur aktuellen Seite (Knopf „?“ oben): Adresse der App (#/<Ansicht>/<Unterseite>) → Kennung der Seite im
 * Handbuch (docs/handbuch/<kennung>.md). Jeder Bildschirm hat seine eigene Anleitung. Ohne passende Seite null; dann
 * öffnet die Hilfe ihr Verzeichnis. Darf die Person die Seite nicht sehen, zeigt die Hilfe ebenfalls das Verzeichnis.
 */
export function helpSlug(path: string[], ctx: HelpContext): string | null {
  const [view, sub] = path;
  const on = (f: string): boolean => !!ctx.features[f];
  const id = /^\d+$/.test(sub ?? '');
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
      return sub ? 'neuigkeiten-beitrag' : 'neuigkeiten';
    case 'galerie':
      return id ? 'galerie-album' : 'galerie';
    case 'dokumente':
      return sub === 'verwalten' ? 'dokumente-pflegen' : id ? 'dokument-ansehen' : 'dokumente';
    case 'mitglieder':
      // Ämter (Issue #112): eigener Abschnitt im Tab, ohne Mitgliederverzeichnis der ganze Tab
      return on('offices') && (sub === 'aemter' || !on('directory')) ? 'aemter' : 'mitglieder';
    case 'meins':
      return (
        {
          uebersicht: 'meins-uebersicht',
          punkte: 'meine-punkte',
          strafen: 'meine-strafen',
          dienstgrad: 'mein-dienstgrad',
          anwesenheit: 'meine-anwesenheit',
          konto: 'anmelden-und-konto',
        }[sub ?? (ctx.can('member') ? 'uebersicht' : 'konto')] ?? 'meins-uebersicht'
      );
    case 'verwaltung':
      return adminSlug(path.slice(1), ctx);
    case 'brett':
      return ctx.boardWriter ? 'schwarzes-brett-schreiben' : 'schwarzes-brett';
    case 'umfragen':
      return sub === 'verwalten' ? 'umfragen-verwalten' : id ? 'umfrage-beantworten' : 'umfragen';
    case 'inventar':
      return sub === 'verlauf' ? 'inventar-verlauf' : 'inventar';
    case 'konto':
      return 'anmelden-und-konto';
    case 'anmelden':
      return 'anmelden';
    case 'benachrichtigungen':
      return 'benachrichtigungen';
    case 'installieren':
      return 'app-installieren';
    case 'einrichtung':
      return 'admin-einrichtung';
    case 'einstellungen':
      return (
        {
          bereiche: 'admin-bereiche',
          rollen: 'admin-rollen-und-mitglieder',
          benachrichtigungen: 'admin-benachrichtigungen',
          email: 'admin-email',
          google: 'admin-google',
          galerie: 'admin-galerie',
          kalender: 'admin-kalender',
          news: 'admin-neuigkeiten',
          zustand: 'admin-zustand',
          datensicherung: 'admin-updates-und-sicherung',
          wartung: 'admin-wartungsmodus',
          neuerungen: 'admin-neuerungen',
        }[sub ?? ''] ?? 'admin-einstellungen'
      );
    case 'hilfe':
      return sub === 'melden' ? 'fehler-melden' : null;
    default:
      return null;
  }
}

/** Verwaltung: Startseite, ihre Bereiche, die Mitgliederliste und die Seiten eines Mitglieds
 * (verwaltung/mitglied/<id>/<seite>, bei Ehemaligen verwaltung/ehemalige/<id>/<seite>). */
function adminSlug([sub, arg, page]: string[], ctx: HelpContext): string | null {
  const tabs: Record<string, string> = {
    mitglieder: 'mitgliederliste',
    punkte: 'punkte-vergeben',
    katalog: 'punktekatalog',
    strafe: 'strafen-verwalten',
    dienstgrade: 'dienstgrade-verwalten',
    befoerderungen: 'befoerderungen',
    titel: 'titel-verwalten',
    aemter: 'aemter-verwalten',
    neu: 'mitglieder-anlegen',
    'neu-mehrere': 'mitglieder-mehrere-anlegen',
    import: 'mitglieder-import',
  };
  // „Einrichtungen“ sammelt nur Knöpfe der Startseite; ihre Anleitung steht dort.
  if (!sub || sub === 'einrichtungen') return 'verwaltung';
  if (tabs[sub]) return tabs[sub];
  if (sub === 'anwesenheit') return arg ? 'anwesenheitsliste' : 'anwesenheit-erfassen';
  if (sub === 'listen') return arg ? 'liste-bearbeiten' : 'listen';
  const former = sub === 'ehemalige';
  if (former && arg === 'neu') return 'ehemalige-anlegen';
  // Liste der Ehemaligen und ihr Import (die Seiten einer ehemaligen Person wie bei Mitgliedern, unten)
  if (former && !/^\d+$/.test(arg ?? '')) return 'ehemalige-mitglieder';
  if (sub === 'mitglied' || former) {
    const memberPages: Record<string, string> = {
      punkte: 'mitglied-punkte',
      strafen: 'mitglied-strafen',
      dienstgrad: 'mitglied-dienstgrad',
      titel: 'mitglied-titel',
      konto: 'rollen-vergeben',
      austritt: 'ehemalige-mitglieder',
    };
    if (page && memberPages[page]) return memberPages[page];
    if (page === 'geburtstag' && ctx.can('create_users') && ctx.features.birthdays) return 'geburtstage-pflegen';
    return former ? 'ehemalige-mitglieder' : 'mitglied-uebersicht';
  }
  return null;
}
