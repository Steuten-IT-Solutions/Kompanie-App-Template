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
      { key: 'view_intern', label: '„Intern“: die Knöpfe unter „Intern“ gesammelt unter einem Knopf', short: 'Intern' },
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
    label: 'Intern',
    hint: 'Je Knopf unter „Intern“ (ohne „Intern“ stehen Mitglieder und Dokumente unten als eigene Knöpfe).',
    items: [
      { key: 'view_directory', label: sec.directory, short: sec.directory, features: ['directory'] },
      { key: 'view_offices', label: sec.offices, short: sec.offices, features: ['offices'] },
      { key: 'view_former', label: 'Ehemalige', short: 'Ehemalige', features: ['directory'] },
      { key: 'view_documents', label: sec.documents, short: sec.documents, features: ['documents'] },
      { key: 'view_board', label: `${sec.board} lesen`, short: sec.board, features: ['board'] },
      { key: 'view_ranks', label: sec.ranks, short: sec.ranks, features: ['ranks'] },
      { key: 'view_catalog', label: `${sec.points}katalog`, short: `${sec.points}katalog`, features: ['points'] },
    ],
  },
  {
    key: 'content',
    label: 'Inhalte verwalten',
    items: [
      { key: 'manage_events', label: `Öffentliche ${sec.events} pflegen`, short: `${sec.events} pflegen`, features: ['events'] },
      {
        key: 'manage_documents',
        label: `${sec.documents} pflegen und alle ${sec.documents} sehen`,
        short: `${sec.documents} pflegen`,
        features: ['documents'],
      },
      { key: 'post_board', label: `${sec.board}: dringende Nachrichten schreiben`, short: `${sec.board} schreiben`, features: ['board'] },
      {
        key: 'manage_polls',
        label: `${sec.polls}: anlegen, beenden und alle Ergebnisse sehen`,
        short: `${sec.polls} verwalten`,
        features: ['polls'],
      },
      { key: 'manage_lists', label: `${sec.lists}: zum Drucken und als CSV anlegen und bearbeiten`, short: sec.lists, features: ['lists'] },
      {
        key: 'send_mail',
        label: `${sec.mail}: an Rollen, Mitglieder und beliebige Adressen schreiben und versenden, Verlauf`,
        short: sec.mail,
        features: ['mail'],
      },
    ],
  },
  {
    key: 'company',
    label: 'Kompanie verwalten',
    items: [
      { key: 'manage_points', label: `${sec.points} vergeben, Katalog pflegen`, short: `${sec.points} vergeben`, features: ['points'] },
      {
        key: 'manage_penalties',
        label: `${sec.penalties} vergeben und als bezahlt markieren`,
        short: `${sec.penalties} vergeben`,
        features: ['penalties'],
      },
      {
        key: 'manage_ranks',
        label: `${sec.ranks} pflegen, befördern und degradieren`,
        short: `${sec.ranks} pflegen`,
        features: ['ranks'],
      },
      { key: 'manage_titles', label: `${sec.titles} pflegen und vergeben`, short: `${sec.titles} pflegen`, features: ['titles'] },
      { key: 'manage_attendance', label: `${sec.attendance}: Listen je Termin führen`, short: sec.attendance, features: ['attendance'] },
      {
        key: 'manage_offices',
        label: `${sec.offices}: Gruppen, Ämter und Amtszeiten pflegen, auch frühere`,
        short: `${sec.offices} pflegen`,
        features: ['offices'],
      },
      {
        key: 'manage_inventory',
        label: `${sec.inventory}: sehen und pflegen, umlagern, Verlauf`,
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
      },
      {
        key: 'manage_personal',
        label: 'Persönliche Angaben pflegen: Adresse, Handynummer, E-Mail-Adresse und Geburtsdatum ändern',
        short: 'Persönliches pflegen',
      },
      {
        key: 'create_users',
        label:
          'Mitglieder anlegen (auch mit ihren persönlichen Angaben) und Startpasswörter erzeugen (nicht für Personen mit mehr Rechten)',
        short: 'Mitglieder anlegen',
      },
      {
        key: 'promote_users',
        label: 'Rollen vergeben (nur Rollen mit eigenen Rechten, ohne diese beiden Rechte)',
        short: 'Rollen vergeben',
      },
    ],
  },
];

/** Alle Rechte der Gruppen in einer Liste. */
export const CAPABILITIES: CapabilityItem[] = CAPABILITY_GROUPS.flatMap((g) => g.items);

/** Sichtbar, wenn einer seiner Bereiche an ist. */
export const shown = (item: CapabilityItem, features: Partial<Record<Feature, boolean>> | undefined): boolean =>
  !item.features || item.features.some((f) => !!features?.[f]);
