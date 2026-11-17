import type { Capability, Feature } from './api';
import brand from './generated/brand';

export * from './capabilityRules';

const sec = brand.labels.sections;

/** Ein Recht in Einstellungen → Rollen und bei den Rechten eines Mitglieds. */
export interface CapabilityItem {
  key: Capability;
  label: string;
  /** Kurz für die Zusammenfassung in der Rollenliste */
  short: string;
  /** Nur sichtbar, wenn einer dieser Bereiche an ist (sonst immer) */
  features?: Feature[];
  /**
   * Bei einem Leserecht, dem in der Liste sein Schreibrecht folgt (READ_OF): Name des Bereichs für die gemeinsame Zeile
   * mit den Haken „Lesen“ und „Schreiben“ (Entscheidung vom 17.11.2026).
   */
  area?: string;
}

export interface CapabilityGroup {
  key: string;
  label: string;
  hint?: string;
  items: CapabilityItem[];
}

/**
 * Rechte, die sich einer Rolle oder einer einzelnen Person geben lassen (Einstellungen bleiben den Admins vorbehalten),
 * in Gruppen und in der Reihenfolge von Roles::GRANTABLE im Kern. Jedes Recht gilt einzeln; was eines voraussetzt, steht
 * in REQUIRES.
 */
export const CAPABILITY_GROUPS: CapabilityGroup[] = [
  {
    key: 'tabs',
    label: 'Tabs und Navigation',
    hint: 'Was unten in der Leiste steht. Gast gilt auch ohne Anmeldung.',
    items: [
      { key: 'view_events', label: `Öffentliche ${sec.events}`, short: sec.events, features: ['events'] },
      { key: 'view_news', label: sec.news, short: sec.news, features: ['news'] },
      { key: 'view_gallery', label: `${sec.gallery}: öffentliche Alben`, short: sec.gallery, features: ['gallery'] },
      { key: 'view_companies', label: sec.companies, short: sec.companies, features: ['companies'] },
      { key: 'view_sponsors', label: sec.sponsors, short: sec.sponsors, features: ['sponsors'] },
      { key: 'view_intern', label: `„${sec.intern}“: die Knöpfe darunter gesammelt unter einem Knopf`, short: sec.intern },
    ],
  },
  {
    key: 'member',
    label: 'Mitgliederbereich',
    hint: 'Eigene Daten gibt es nur für Personen, die zur Kompanie gehören.',
    items: [
      {
        key: 'member',
        label: `Gehört zur Kompanie: bekommt ${sec.points}, ${sec.penalties} und ${sec.ranks}, steht in Listen`,
        short: 'Mitglied',
      },
      { key: 'view_own_points', label: `Eigene ${sec.points}`, short: `eigene ${sec.points}`, features: ['points'] },
      { key: 'view_own_penalties', label: `Eigene ${sec.penalties}`, short: `eigene ${sec.penalties}`, features: ['penalties'] },
      {
        key: 'view_own_rank',
        label: `Eigener ${brand.labels.rank} mit Verlauf und eigene ${sec.titles}`,
        short: `eigener ${brand.labels.rank}`,
        features: ['ranks', 'titles'],
      },
      { key: 'view_own_attendance', label: `Eigene ${sec.attendance}`, short: `eigene ${sec.attendance}`, features: ['attendance'] },
      { key: 'calendar', label: 'Interner Kalender', short: 'Kalender', features: ['calendar'] },
      {
        key: 'respond_calendar',
        label: 'Bei Terminen des internen Kalenders zu- und absagen',
        short: 'zu- und absagen',
        features: ['calendar'],
      },
      {
        key: 'view_gallery_internal',
        label: `${sec.gallery}: interne Bilder und Videos`,
        short: `interne ${sec.gallery}`,
        features: ['gallery'],
      },
      { key: 'view_birthdays', label: `${sec.birthdays} der anderen sehen`, short: sec.birthdays, features: ['birthdays'] },
      { key: 'vote_polls', label: `${sec.polls}: teilnehmen`, short: sec.polls, features: ['polls'] },
    ],
  },
  {
    key: 'intern',
    label: sec.intern,
    hint: `Je Knopf unter „${sec.intern}“ (in der Standardnavigation; ohne „${sec.intern}“ stehen ${sec.directory} und ${sec.documents} unten als eigene Knöpfe).`,
    items: [
      { key: 'view_directory', label: sec.directory, short: sec.directory, features: ['directory'] },
      { key: 'view_offices', label: sec.offices, short: sec.offices, features: ['offices'] },
      { key: 'view_former', label: 'Ehemalige', short: 'Ehemalige', features: ['directory'] },
      { key: 'view_documents', label: sec.documents, short: sec.documents, features: ['documents'] },
      { key: 'view_board', label: `${sec.board} lesen`, short: sec.board, features: ['board'] },
      { key: 'view_ranks', label: sec.ranks, short: sec.ranks, features: ['ranks'] },
      { key: 'view_catalog', label: sec.catalog, short: sec.catalog, features: ['points'] },
    ],
  },
  {
    key: 'content',
    label: 'Inhalte verwalten',
    items: [
      { key: 'manage_events', label: `Öffentliche ${sec.events} pflegen`, short: `${sec.events} pflegen`, features: ['events'] },
      {
        key: 'view_responses',
        label: 'Zu- und Absagen sehen: wer bei einem internen Termin zu- oder abgesagt hat, mit Hinweis',
        short: 'Zu- und Absagen sehen',
        area: 'Zu- und Absagen, interner Kalender',
        features: ['calendar'],
      },
      {
        key: 'manage_calendar',
        label: 'Internen Kalender pflegen: Termine in der App anlegen, ändern und löschen (nur mit der Quelle „In der App“)',
        short: 'Kalender pflegen',
        features: ['calendar'],
      },
      {
        key: 'manage_news',
        label: `${sec.news}: Beiträge schreiben, bearbeiten und löschen, in der App (Quelle „In der App“) oder im Editor von WordPress (Quelle „Beiträge dieser WordPress-Seite“)`,
        short: `${sec.news} schreiben`,
        features: ['news'],
      },
      {
        key: 'view_restricted_documents',
        label: `Eingeschränkte ${sec.documents} sehen, die nur für die Pflege bestimmt sind`,
        short: `Eingeschränkte ${sec.documents} sehen`,
        area: `Eingeschränkte ${sec.documents}`,
        features: ['documents'],
      },
      {
        key: 'manage_documents',
        label: `${sec.documents} hochladen, ändern und löschen`,
        short: `${sec.documents} pflegen`,
        features: ['documents'],
      },
      {
        key: 'view_board_history',
        label: `${sec.board}: alle Nachrichten sehen, auch für andere, mit Verlauf und Mailversand`,
        short: `${sec.board}-Verlauf sehen`,
        area: `${sec.board}: alle Nachrichten`,
        features: ['board'],
      },
      { key: 'post_board', label: `${sec.board}: dringende Nachrichten schreiben`, short: `${sec.board} schreiben`, features: ['board'] },
      {
        key: 'view_poll_results',
        label: `${sec.polls}: alle Ergebnisse sehen, auch laufender und verborgener`,
        short: `${sec.polls}-Ergebnisse sehen`,
        area: `${sec.polls}: alle Ergebnisse`,
        features: ['polls'],
      },
      {
        key: 'manage_polls',
        label: `${sec.polls}: anlegen, ändern, beenden und löschen`,
        short: `${sec.polls} verwalten`,
        features: ['polls'],
      },
      {
        key: 'view_lists',
        label: `${sec.lists} sehen, drucken und als CSV laden`,
        short: `${sec.lists} sehen`,
        features: ['lists'],
        area: sec.lists,
      },
      {
        key: 'manage_lists',
        label: `${sec.lists}: zum Drucken und als CSV anlegen, bearbeiten und löschen`,
        short: sec.lists,
        features: ['lists'],
      },
      {
        key: 'view_mail',
        label: `${sec.mail}: Verlauf der versendeten E-Mails mit Versandprotokoll sehen`,
        short: `${sec.mail} sehen`,
        area: sec.mail,
        features: ['mail'],
      },
      {
        key: 'send_mail',
        label: `${sec.mail}: an Rollen, Mitglieder und beliebige Adressen schreiben und versenden`,
        short: sec.mail,
        features: ['mail'],
      },
      {
        key: 'manage_sponsors',
        label: `${sec.sponsors}: anlegen, Logos hochladen und sortieren`,
        short: `${sec.sponsors} pflegen`,
        features: ['sponsors'],
      },
      {
        key: 'view_company_members',
        label: `${sec.companies}: sehen, welchen ${sec.companies} die Mitglieder zugeordnet sind`,
        short: `Zuordnung zu ${sec.companies} sehen`,
        area: `Zuordnung zu ${sec.companies}`,
        features: ['companies'],
      },
      {
        key: 'manage_companies',
        label: `${sec.companies}: anlegen, Logos hochladen, sortieren und Mitglieder zuordnen`,
        short: `${sec.companies} pflegen`,
        features: ['companies'],
      },
      {
        key: 'submit_gallery',
        label: `${sec.gallery}: Bilder und Videos einreichen, eigene Einreichungen sehen und zurückziehen`,
        short: 'Bilder und Videos einreichen',
        features: ['gallery'],
      },
      {
        key: 'view_submissions',
        label: `${sec.gallery}: eingereichte Bilder und Videos ansehen, auch vor der Freigabe`,
        short: 'Einreichungen sehen',
        area: `Einreichungen der ${sec.gallery}`,
        features: ['gallery'],
      },
      {
        key: 'review_gallery',
        label: `${sec.gallery}: Einreichungen freigeben oder ablehnen, freigegebene wieder entfernen`,
        short: 'Einreichungen prüfen',
        features: ['gallery'],
      },
      {
        key: 'manage_gallery',
        label: `${sec.gallery}: Alben anlegen, ändern und löschen, Bilder und Videos hochladen, verschieben, löschen und intern stellen`,
        short: `${sec.gallery} verwalten`,
        features: ['gallery'],
      },
      {
        key: 'manage_pages',
        label: `${sec.pages}: eigene Seiten anlegen, bearbeiten, sortieren und löschen (sieht alle Seiten)`,
        short: `${sec.pages} pflegen`,
        features: ['pages'],
      },
      {
        key: 'manage_website',
        label: 'Webseite: Startseite wählen und die Einstiegsdatei anlegen',
        short: 'Webseite pflegen',
        features: ['website'],
      },
    ],
  },
  {
    key: 'company',
    label: 'Kompanie verwalten',
    items: [
      {
        key: 'view_points',
        label: `${sec.points} aller Mitglieder sehen`,
        short: `${sec.points} sehen`,
        area: sec.points,
        features: ['points'],
      },
      { key: 'manage_points', label: `${sec.points} vergeben, Katalog pflegen`, short: `${sec.points} vergeben`, features: ['points'] },
      {
        key: 'view_penalties',
        label: `${sec.penalties} aller Mitglieder sehen, auch die offenen`,
        short: `${sec.penalties} sehen`,
        area: sec.penalties,
        features: ['penalties'],
      },
      {
        key: 'manage_penalties',
        label: `${sec.penalties} vergeben und als bezahlt markieren`,
        short: `${sec.penalties} vergeben`,
        features: ['penalties'],
      },
      {
        key: 'view_promotions',
        label: `${brand.labels.rank} der Mitglieder mit Verlauf und anstehende Beförderungen sehen`,
        short: 'Beförderungen sehen',
        area: `${sec.ranks} und Beförderungen`,
        features: ['ranks'],
      },
      {
        key: 'manage_ranks',
        label: `${sec.ranks} pflegen, befördern und degradieren`,
        short: `${sec.ranks} pflegen`,
        features: ['ranks'],
      },
      {
        key: 'view_titles',
        label: `${sec.titles} sehen und wer welchen hat`,
        short: `${sec.titles} sehen`,
        area: sec.titles,
        features: ['titles'],
      },
      { key: 'manage_titles', label: `${sec.titles} pflegen und vergeben`, short: `${sec.titles} pflegen`, features: ['titles'] },
      {
        key: 'view_attendance',
        label: `${sec.attendance}: Listen je Termin und die ${sec.attendance} der Mitglieder sehen`,
        short: `${sec.attendance} sehen`,
        area: sec.attendance,
        features: ['attendance'],
      },
      { key: 'manage_attendance', label: `${sec.attendance}: Listen je Termin führen`, short: sec.attendance, features: ['attendance'] },
      {
        key: 'manage_offices',
        label: `${sec.offices}: Gruppen, Ämter und Amtszeiten pflegen, auch frühere`,
        short: `${sec.offices} pflegen`,
        features: ['offices'],
      },
      {
        key: 'view_inventory',
        label: `${sec.inventory} mit Lagerorten und Verlauf sehen`,
        short: `${sec.inventory} sehen`,
        area: sec.inventory,
        features: ['inventory'],
      },
      {
        key: 'manage_inventory',
        label: `${sec.inventory}: anlegen, ändern, umlagern und löschen`,
        short: sec.inventory,
        features: ['inventory'],
      },
    ],
  },
  {
    key: 'users',
    label: 'Mitglieder und Rollen',
    items: [
      {
        key: 'view_personal',
        label: 'Persönliche Angaben sehen: Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum der Mitglieder',
        short: 'Persönliches sehen',
        area: 'Persönliche Angaben',
      },
      {
        key: 'manage_personal',
        label: 'Persönliche Angaben pflegen: Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum ändern',
        short: 'Persönliches pflegen',
      },
      {
        key: 'view_accounts',
        label:
          'Konten sehen: wer ohne Zugang ist, Benutzername und E-Mail-Adresse, Eintritt, Rollen und einzelne Rechte, Anfragen auf einen Zugang und Aufnahmen (ohne zu ändern; nur Admins vergeben es)',
        short: 'Konten sehen',
        area: 'Konten und Mitglieder anlegen',
      },
      {
        key: 'create_users',
        label:
          'Mitglieder anlegen (auch mit ihren persönlichen Angaben) und Startpasswörter erzeugen (nicht für Personen mit mehr Rechten)',
        short: 'Mitglieder anlegen',
      },
      {
        key: 'manage_former',
        label:
          'Ehemalige verwalten: Liste der Ehemaligen, Ehemalige anlegen und importieren, Mitglieder als ehemalig eintragen und wieder aufnehmen (persönliche Angaben nur mit den Rechten dazu)',
        short: 'Ehemalige verwalten',
      },
      {
        key: 'view_passive',
        label:
          'Passive sehen: Liste der Passiven mit Betreuer und Beiträgen (Adresse, Telefon, E-Mail und Geburtsdatum nur mit den Rechten für persönliche Angaben)',
        short: 'Passive sehen',
        area: 'Passive',
        features: ['passive'],
      },
      {
        key: 'manage_passive',
        label: 'Passive verwalten: anlegen, Namen und Betreuer ändern, Beiträge eintragen, löschen',
        short: 'Passive verwalten',
        features: ['passive'],
      },
      {
        key: 'promote_users',
        label:
          'Rollen vergeben (nur Rollen mit eigenen Rechten, ohne die Rechte Konten sehen, Mitglieder anlegen, Ehemalige verwalten und Rollen vergeben)',
        short: 'Rollen vergeben',
      },
      {
        key: 'view_reports',
        label: 'Fehlermeldungen der Mitglieder mit Bildschirmfoto sehen',
        short: 'Fehlermeldungen sehen',
        area: 'Fehlermeldungen',
      },
      {
        key: 'review_reports',
        label: 'Fehlermeldungen der Mitglieder mit Begründung schließen oder an die Betreuer der App weiterleiten',
        short: 'Fehlermeldungen bearbeiten',
      },
      {
        key: 'view_changes',
        label:
          'Änderungsprotokoll ansehen: wer wann was angelegt, geändert oder gelöscht hat, mit alten und neuen Werten (persönliche Angaben darin nur mit „Persönliches sehen“)',
        short: 'Änderungen ansehen',
      },
    ],
  },
];

/** Alle Rechte der Gruppen in einer Liste. */
export const CAPABILITIES: CapabilityItem[] = CAPABILITY_GROUPS.flatMap((g) => g.items);

/** Sichtbar, wenn einer seiner Bereiche an ist. */
export const shown = (item: CapabilityItem, features: Partial<Record<Feature, boolean>> | undefined): boolean =>
  !item.features || item.features.some((f) => !!features?.[f]);
