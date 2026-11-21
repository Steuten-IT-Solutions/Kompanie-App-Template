import type { Capability, Feature } from './api';
import brand from './generated/brand';
import { t } from './i18n';

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
    label: t('Tabs und Navigation'),
    hint: t('Was unten in der Leiste steht. Gast gilt auch ohne Anmeldung.'),
    items: [
      { key: 'view_events', label: t('Öffentliche {events}', { events: sec.events }), short: sec.events, features: ['events'] },
      { key: 'view_news', label: sec.news, short: sec.news, features: ['news'] },
      {
        key: 'view_gallery',
        label: t('{gallery}: öffentliche Alben', { gallery: sec.gallery }),
        short: sec.gallery,
        features: ['gallery'],
      },
      { key: 'view_companies', label: sec.companies, short: sec.companies, features: ['companies'] },
      { key: 'view_sponsors', label: sec.sponsors, short: sec.sponsors, features: ['sponsors'] },
      {
        key: 'view_intern',
        label: t('„{intern}“: die Knöpfe darunter gesammelt unter einem Knopf', { intern: sec.intern }),
        short: sec.intern,
      },
    ],
  },
  {
    key: 'member',
    label: t('Mitgliederbereich'),
    hint: t('Eigene Daten gibt es nur für Personen, die zur Kompanie gehören.'),
    items: [
      {
        key: 'member',
        label: t('Gehört zur Kompanie: bekommt {points}, {penalties} und {ranks}, steht in Listen', {
          points: sec.points,
          penalties: sec.penalties,
          ranks: sec.ranks,
        }),
        short: t('Mitglied'),
      },
      {
        key: 'view_own_points',
        label: t('Eigene {points}', { points: sec.points }),
        short: t('eigene {points}', { points: sec.points }),
        features: ['points'],
      },
      {
        key: 'view_own_penalties',
        label: t('Eigene {penalties}', { penalties: sec.penalties }),
        short: t('eigene {penalties}', { penalties: sec.penalties }),
        features: ['penalties'],
      },
      {
        key: 'view_own_rank',
        label: t('Eigener {rank} mit Verlauf und eigene {titles}', { rank: brand.labels.rank, titles: sec.titles }),
        short: t('eigener {rank}', { rank: brand.labels.rank }),
        features: ['ranks', 'titles'],
      },
      {
        key: 'view_own_attendance',
        label: t('Eigene {attendance}', { attendance: sec.attendance }),
        short: t('eigene {attendance}', { attendance: sec.attendance }),
        features: ['attendance'],
      },
      { key: 'calendar', label: t('Interner Kalender'), short: t('Kalender'), features: ['calendar'] },
      {
        key: 'respond_calendar',
        label: t('Bei Terminen des internen Kalenders zu- und absagen'),
        short: t('zu- und absagen'),
        features: ['calendar'],
      },
      {
        key: 'view_gallery_internal',
        label: t('{gallery}: interne Bilder und Videos', { gallery: sec.gallery }),
        short: t('interne {gallery}', { gallery: sec.gallery }),
        features: ['gallery'],
      },
      {
        key: 'view_birthdays',
        label: t('{birthdays} der anderen sehen', { birthdays: sec.birthdays }),
        short: sec.birthdays,
        features: ['birthdays'],
      },
      { key: 'vote_polls', label: t('{polls}: teilnehmen', { polls: sec.polls }), short: sec.polls, features: ['polls'] },
    ],
  },
  {
    key: 'intern',
    label: sec.intern,
    hint: t(
      'Je Knopf unter „{intern}“ (in der Standardnavigation; ohne „{intern}“ stehen {directory} und {documents} unten als eigene Knöpfe).',
      { intern: sec.intern, directory: sec.directory, documents: sec.documents },
    ),
    items: [
      { key: 'view_directory', label: sec.directory, short: sec.directory, features: ['directory'] },
      { key: 'view_offices', label: sec.offices, short: sec.offices, features: ['offices'] },
      { key: 'view_former', label: t('Ehemalige'), short: t('Ehemalige'), features: ['directory'] },
      { key: 'view_documents', label: sec.documents, short: sec.documents, features: ['documents'] },
      { key: 'view_board', label: t('{board} lesen', { board: sec.board }), short: sec.board, features: ['board'] },
      { key: 'view_ranks', label: sec.ranks, short: sec.ranks, features: ['ranks'] },
      { key: 'view_catalog', label: sec.catalog, short: sec.catalog, features: ['points'] },
    ],
  },
  {
    key: 'content',
    label: t('Inhalte verwalten'),
    items: [
      {
        key: 'manage_events',
        label: t('Öffentliche {events} pflegen', { events: sec.events }),
        short: t('{events} pflegen', { events: sec.events }),
        features: ['events'],
      },
      {
        key: 'view_responses',
        label: t('Zu- und Absagen sehen: wer bei einem internen Termin zu- oder abgesagt hat, mit Hinweis'),
        short: t('Zu- und Absagen sehen'),
        area: t('Zu- und Absagen, interner Kalender'),
        features: ['calendar'],
      },
      {
        key: 'manage_calendar',
        label: t('Internen Kalender pflegen: Termine in der App anlegen, ändern und löschen (nur mit der Quelle „In der App“)'),
        short: t('Kalender pflegen'),
        features: ['calendar'],
      },
      {
        key: 'manage_news',
        label: t(
          '{news}: Beiträge schreiben, bearbeiten und löschen, in der App (Quelle „In der App“) oder im Editor von WordPress (Quelle „Beiträge dieser WordPress-Seite“)',
          { news: sec.news },
        ),
        short: t('{news} schreiben', { news: sec.news }),
        features: ['news'],
      },
      {
        key: 'view_restricted_documents',
        label: t('Eingeschränkte {documents} sehen, die nur für die Pflege bestimmt sind', { documents: sec.documents }),
        short: t('Eingeschränkte {documents} sehen', { documents: sec.documents }),
        area: t('Eingeschränkte {documents}', { documents: sec.documents }),
        features: ['documents'],
      },
      {
        key: 'manage_documents',
        label: t('{documents} hochladen, ändern und löschen', { documents: sec.documents }),
        short: t('{documents} pflegen', { documents: sec.documents }),
        features: ['documents'],
      },
      {
        key: 'view_board_history',
        label: t('{board}: alle Nachrichten sehen, auch für andere, mit Verlauf und Mailversand', { board: sec.board }),
        short: t('{board}-Verlauf sehen', { board: sec.board }),
        area: t('{board}: alle Nachrichten', { board: sec.board }),
        features: ['board'],
      },
      {
        key: 'post_board',
        label: t('{board}: dringende Nachrichten schreiben', { board: sec.board }),
        short: t('{board} schreiben', { board: sec.board }),
        features: ['board'],
      },
      {
        key: 'view_poll_results',
        label: t('{polls}: alle Ergebnisse sehen, auch laufender und verborgener', { polls: sec.polls }),
        short: t('{polls}-Ergebnisse sehen', { polls: sec.polls }),
        area: t('{polls}: alle Ergebnisse', { polls: sec.polls }),
        features: ['polls'],
      },
      {
        key: 'manage_polls',
        label: t('{polls}: anlegen, ändern, beenden und löschen', { polls: sec.polls }),
        short: t('{polls} verwalten', { polls: sec.polls }),
        features: ['polls'],
      },
      {
        key: 'view_lists',
        label: t('{lists} sehen, drucken und als CSV laden', { lists: sec.lists }),
        short: t('{lists} sehen', { lists: sec.lists }),
        features: ['lists'],
        area: sec.lists,
      },
      {
        key: 'manage_lists',
        label: t('{lists}: zum Drucken und als CSV anlegen, bearbeiten und löschen', { lists: sec.lists }),
        short: sec.lists,
        features: ['lists'],
      },
      {
        key: 'view_mail',
        label: t('{mail}: Verlauf der versendeten E-Mails mit Versandprotokoll sehen', { mail: sec.mail }),
        short: t('{mail} sehen', { mail: sec.mail }),
        area: sec.mail,
        features: ['mail'],
      },
      {
        key: 'send_mail',
        label: t('{mail}: an Rollen, Mitglieder und beliebige Adressen schreiben und versenden', { mail: sec.mail }),
        short: sec.mail,
        features: ['mail'],
      },
      {
        key: 'manage_sponsors',
        label: t('{sponsors}: anlegen, Logos hochladen und sortieren', { sponsors: sec.sponsors }),
        short: t('{sponsors} pflegen', { sponsors: sec.sponsors }),
        features: ['sponsors'],
      },
      {
        key: 'view_company_members',
        label: t('{companies}: sehen, welchen {companies} die Mitglieder zugeordnet sind', { companies: sec.companies }),
        short: t('Zuordnung zu {companies} sehen', { companies: sec.companies }),
        area: t('Zuordnung zu {companies}', { companies: sec.companies }),
        features: ['companies'],
      },
      {
        key: 'manage_companies',
        label: t('{companies}: anlegen, Logos hochladen, sortieren und Mitglieder zuordnen', { companies: sec.companies }),
        short: t('{companies} pflegen', { companies: sec.companies }),
        features: ['companies'],
      },
      {
        key: 'submit_gallery',
        label: t('{gallery}: Bilder und Videos einreichen, eigene Einreichungen sehen und zurückziehen', { gallery: sec.gallery }),
        short: t('Bilder und Videos einreichen'),
        features: ['gallery'],
      },
      {
        key: 'view_submissions',
        label: t('{gallery}: eingereichte Bilder und Videos ansehen, auch vor der Freigabe', { gallery: sec.gallery }),
        short: t('Einreichungen sehen'),
        area: t('Einreichungen der {gallery}', { gallery: sec.gallery }),
        features: ['gallery'],
      },
      {
        key: 'review_gallery',
        label: t('{gallery}: Einreichungen freigeben oder ablehnen, freigegebene wieder entfernen', { gallery: sec.gallery }),
        short: t('Einreichungen prüfen'),
        features: ['gallery'],
      },
      {
        key: 'manage_gallery',
        label: t('{gallery}: Alben anlegen, ändern und löschen, Bilder und Videos hochladen, verschieben, löschen und intern stellen', {
          gallery: sec.gallery,
        }),
        short: t('{gallery} verwalten', { gallery: sec.gallery }),
        features: ['gallery'],
      },
      {
        key: 'manage_pages',
        label: t('{pages}: eigene Seiten anlegen, bearbeiten, sortieren und löschen (sieht alle Seiten)', { pages: sec.pages }),
        short: t('{pages} pflegen', { pages: sec.pages }),
        features: ['pages'],
      },
      {
        key: 'manage_website',
        label: t('Webseite: Startseite wählen und die Einstiegsdatei anlegen'),
        short: t('Webseite pflegen'),
        features: ['website'],
      },
    ],
  },
  {
    key: 'company',
    label: t('Kompanie verwalten'),
    items: [
      {
        key: 'view_points',
        label: t('{points} aller Mitglieder sehen', { points: sec.points }),
        short: t('{points} sehen', { points: sec.points }),
        area: sec.points,
        features: ['points'],
      },
      {
        key: 'manage_points',
        label: t('{points} vergeben, Katalog pflegen', { points: sec.points }),
        short: t('{points} vergeben', { points: sec.points }),
        features: ['points'],
      },
      {
        key: 'view_penalties',
        label: t('{penalties} aller Mitglieder sehen, auch die offenen', { penalties: sec.penalties }),
        short: t('{penalties} sehen', { penalties: sec.penalties }),
        area: sec.penalties,
        features: ['penalties'],
      },
      {
        key: 'manage_penalties',
        label: t('{penalties} vergeben und als bezahlt markieren', { penalties: sec.penalties }),
        short: t('{penalties} vergeben', { penalties: sec.penalties }),
        features: ['penalties'],
      },
      {
        key: 'view_promotions',
        label: t('{rank} der Mitglieder mit Verlauf und anstehende Beförderungen sehen', { rank: brand.labels.rank }),
        short: t('Beförderungen sehen'),
        area: t('{ranks} und Beförderungen', { ranks: sec.ranks }),
        features: ['ranks'],
      },
      {
        key: 'manage_ranks',
        label: t('{ranks} pflegen, befördern und degradieren', { ranks: sec.ranks }),
        short: t('{ranks} pflegen', { ranks: sec.ranks }),
        features: ['ranks'],
      },
      {
        key: 'view_titles',
        label: t('{titles} sehen und wer welchen hat', { titles: sec.titles }),
        short: t('{titles} sehen', { titles: sec.titles }),
        area: sec.titles,
        features: ['titles'],
      },
      {
        key: 'manage_titles',
        label: t('{titles} pflegen und vergeben', { titles: sec.titles }),
        short: t('{titles} pflegen', { titles: sec.titles }),
        features: ['titles'],
      },
      {
        key: 'view_attendance',
        label: t('{attendance}: Listen je Termin und die {attendance} der Mitglieder sehen', { attendance: sec.attendance }),
        short: t('{attendance} sehen', { attendance: sec.attendance }),
        area: sec.attendance,
        features: ['attendance'],
      },
      {
        key: 'manage_attendance',
        label: t('{attendance}: Listen je Termin führen', { attendance: sec.attendance }),
        short: sec.attendance,
        features: ['attendance'],
      },
      {
        key: 'manage_offices',
        label: t('{offices}: Gruppen, Ämter und Amtszeiten pflegen, auch frühere', { offices: sec.offices }),
        short: t('{offices} pflegen', { offices: sec.offices }),
        features: ['offices'],
      },
      {
        key: 'view_inventory',
        label: t('{inventory} mit Lagerorten und Verlauf sehen', { inventory: sec.inventory }),
        short: t('{inventory} sehen', { inventory: sec.inventory }),
        area: sec.inventory,
        features: ['inventory'],
      },
      {
        key: 'manage_inventory',
        label: t('{inventory}: anlegen, ändern, umlagern und löschen', { inventory: sec.inventory }),
        short: sec.inventory,
        features: ['inventory'],
      },
    ],
  },
  {
    key: 'users',
    label: t('Mitglieder und Rollen'),
    items: [
      {
        key: 'view_personal',
        label: t('Persönliche Angaben sehen: Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum der Mitglieder'),
        short: t('Persönliches sehen'),
        area: t('Persönliche Angaben'),
      },
      {
        key: 'manage_personal',
        label: t('Persönliche Angaben pflegen: Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum ändern'),
        short: t('Persönliches pflegen'),
      },
      {
        key: 'view_accounts',
        label: t(
          'Konten sehen: wer ohne Zugang ist, Benutzername und E-Mail-Adresse, Eintritt, Rollen und einzelne Rechte, Anfragen auf einen Zugang und Aufnahmen (ohne zu ändern; nur Admins vergeben es)',
        ),
        short: t('Konten sehen'),
        area: t('Konten und Mitglieder anlegen'),
      },
      {
        key: 'create_users',
        label: t(
          'Mitglieder anlegen (auch mit ihren persönlichen Angaben) und Startpasswörter erzeugen (nicht für Personen mit mehr Rechten)',
        ),
        short: t('Mitglieder anlegen'),
      },
      {
        key: 'manage_former',
        label: t(
          'Ehemalige verwalten: Liste der Ehemaligen, Ehemalige anlegen und importieren, Mitglieder als ehemalig eintragen und wieder aufnehmen (persönliche Angaben nur mit den Rechten dazu)',
        ),
        short: t('Ehemalige verwalten'),
      },
      {
        key: 'view_passive',
        label: t(
          'Passive sehen: Liste der Passiven mit Betreuer und Beiträgen (Adresse, Telefon, E-Mail und Geburtsdatum nur mit den Rechten für persönliche Angaben)',
        ),
        short: t('Passive sehen'),
        area: t('Passive'),
        features: ['passive'],
      },
      {
        key: 'manage_passive',
        label: t('Passive verwalten: anlegen, Namen und Betreuer ändern, Beiträge eintragen, löschen'),
        short: t('Passive verwalten'),
        features: ['passive'],
      },
      {
        key: 'promote_users',
        label: t(
          'Rollen vergeben (nur Rollen mit eigenen Rechten, ohne die Rechte Konten sehen, Mitglieder anlegen, Ehemalige verwalten und Rollen vergeben)',
        ),
        short: t('Rollen vergeben'),
      },
      {
        key: 'view_reports',
        label: t('Fehlermeldungen der Mitglieder mit Bildschirmfoto sehen'),
        short: t('Fehlermeldungen sehen'),
        area: t('Fehlermeldungen'),
      },
      {
        key: 'review_reports',
        label: t('Fehlermeldungen der Mitglieder mit Begründung schließen oder an die Betreuer der App weiterleiten'),
        short: t('Fehlermeldungen bearbeiten'),
      },
      {
        key: 'view_changes',
        label: t(
          'Änderungsprotokoll ansehen: wer wann was angelegt, geändert oder gelöscht hat, mit alten und neuen Werten (persönliche Angaben darin nur mit „Persönliches sehen“)',
        ),
        short: t('Änderungen ansehen'),
      },
    ],
  },
];

/** Alle Rechte der Gruppen in einer Liste. */
export const CAPABILITIES: CapabilityItem[] = CAPABILITY_GROUPS.flatMap((g) => g.items);

/** Sichtbar, wenn einer seiner Bereiche an ist. */
export const shown = (item: CapabilityItem, features: Partial<Record<Feature, boolean>> | undefined): boolean =>
  !item.features || item.features.some((f) => !!features?.[f]);
