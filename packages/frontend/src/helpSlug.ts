/**
 * Hilfeseite zur aktuellen Seite (Knopf „?“ oben): Adresse der App (#/<Ansicht>/<Unterseite>) → Kennung der Seite im
 * Handbuch (docs/handbuch/<kennung>.md). Ohne passende Seite null; dann öffnet die Hilfe ihr Verzeichnis. Darf die
 * Person die Seite nicht sehen, zeigt die Hilfe ebenfalls das Verzeichnis.
 */
export function helpSlug(path: string[], ctx: { boardWriter: boolean; meinsFirst?: string }): string | null {
  const [view, sub] = path;
  switch (view) {
    case 'termine':
      return sub === 'verwalten' ? 'termine-pflegen' : sub === 'geburtstage' ? 'geburtstage' : 'termine-und-kalender';
    case 'dokumente':
      return sub === 'verwalten' ? 'dokumente-pflegen' : 'dokumente';
    case 'meins':
      return (
        { punkte: 'meine-punkte', strafen: 'meine-strafen', dienstgrad: 'mein-dienstgrad', anwesenheit: 'meine-anwesenheit' }[
          sub ?? ctx.meinsFirst ?? ''
        ] ?? null
      );
    case 'verwaltung':
      return (
        {
          punkte: 'punkte-vergeben',
          katalog: 'punkte-vergeben',
          strafe: 'strafen-verwalten',
          dienstgrade: 'dienstgrade-verwalten',
          befoerderungen: 'dienstgrade-verwalten',
          titel: 'titel-verwalten',
          anwesenheit: 'anwesenheit-erfassen',
        }[sub ?? ''] ?? 'mitglieder-anlegen'
      );
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
          rollen: 'admin-rollen-und-mitglieder',
          google: 'admin-google',
          kalender: 'admin-google',
          galerie: 'admin-google',
          benachrichtigungen: 'benachrichtigungen',
          zustand: 'admin-zustand',
          wartung: 'admin-wartungsmodus',
          datensicherung: 'admin-updates-und-sicherung',
        }[sub ?? ''] ?? null
      );
    default:
      return null;
  }
}
