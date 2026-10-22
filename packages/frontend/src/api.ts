// Zugriff auf die API der Kompanie-App (siehe api/openapi.yaml), unabhängig davon,
// ob sie von WordPress oder der eigenständigen Version kommt.

import { dataCache } from './dataCache';
import { PREVIEW_HEADER, previewRole, stopPreview } from './preview';
import brand from './generated/brand';
import { noteError } from './lastError';

/** Schlüssel einer Rolle: guest, candidate, member, organizer, manager, admin oder vom Admin angelegt (r1, r2, …). */
export type Level = string;
export type Capability =
  | 'member'
  | 'calendar'
  | 'manage_points'
  | 'manage_penalties'
  | 'manage_ranks'
  | 'manage_titles'
  | 'manage_attendance'
  | 'manage_events'
  | 'manage_documents'
  | 'post_board'
  | 'manage_polls'
  | 'manage_inventory'
  | 'manage_offices'
  | 'create_users'
  | 'promote_users'
  | 'manage_settings';
export type Feature =
  | 'events'
  | 'calendar'
  | 'news'
  | 'gallery'
  | 'points'
  | 'penalties'
  | 'ranks'
  | 'titles'
  | 'attendance'
  | 'documents'
  | 'board'
  | 'polls'
  | 'birthdays'
  | 'inventory'
  | 'directory'
  | 'offices';

export interface AppUser {
  id: number;
  name: string;
  login: string;
  /** Grundrolle */
  level: Level;
  /** Zusatzrollen (Issue #66); fehlt bei älteren Servern */
  extra_roles?: Level[];
  /** Alle Rechte zusammen (Grundrolle und Zusatzrollen) */
  capabilities: Capability[];
  /** Eigenes Foto (128 Pixel), null ohne; fehlt bei älteren Servern */
  photo_url?: string | null;
}

export interface AppConfig {
  apiBase: string;
  appUrl: string;
  swUrl: string;
  siteUrl: string | null;
  variant: 'wordpress' | 'standalone';
  version: string;
  csrf: { header: string; token: string; refreshUrl: string };
  auth: { mode: 'form' | 'redirect'; loginUrl: string | null; logoutUrl: string | null };
  features: Record<Feature, boolean>;
  /** Handbuch auf der Webseite der Kompanie (GitHub Pages); null ohne Update-Adresse */
  handbookUrl?: string | null;
  /** „Fehler melden“ möglich (Issue #64): Die App hat einen Empfänger für Fehlermeldungen */
  reports?: boolean;
  /** Rollen der Installation (Einstellungen → Rollen): Bezeichnung und Punktefaktor */
  roles?: RoleInfo[];
  /** null = Gast ohne Anmeldung. */
  user: AppUser | null;
  /** Nur für Admins: Die Einrichtung ist noch nicht abgeschlossen (Assistent anbieten). */
  setupPending?: boolean;
  /** Wartungsmodus (Issue #42): an, Begründung (Klartext), seit (Unix-Zeit) */
  maintenance?: MaintenanceInfo;
  /** Vorschau „App als Rolle ansehen“ (Issue #63): vom Server bestätigt; role ist der Schlüssel oder @public */
  preview?: { role: string; label: string } | null;
}

export interface MaintenanceInfo {
  on: boolean;
  reason: string;
  since: number;
}

export interface MaintenanceState extends MaintenanceInfo {
  log: { at: number; by: number; by_name: string; on: boolean; reason: string }[];
}

declare global {
  interface Window {
    KOMPANIE_CONFIG?: AppConfig;
  }
}

export const config: AppConfig | undefined = window.KOMPANIE_CONFIG;

/** Bereich mit Schalter für die Ersteinrichtung und die Einstellungen der Admins. */
export interface FeatureInfo {
  key: Feature;
  label: string;
  description: string;
  enabled: boolean;
  requires: Feature[];
  /** null = braucht keine Einrichtung */
  configured: boolean | null;
  /** Abschnitt der Einstellungen bzw. Schritt der Ersteinrichtung, z. B. „kalender“ */
  setup: string | null;
}

export const CONFIG_CHANGED = 'kompanie-config-changed';

/**
 * Übernimmt geänderte Bereiche in die geladene Konfiguration (wie /config: an und eingerichtet)
 * und sagt der App, dass sie Tabs und Ansichten neu bestimmen soll.
 */
export function applyFeatures(list: FeatureInfo[]): void {
  if (!config) return;
  for (const f of list) config.features[f.key] = f.enabled && f.configured !== false;
  window.dispatchEvent(new Event(CONFIG_CHANGED));
}

/** Übernimmt den Wartungsmodus in die geladene Konfiguration (nach dem Schalten oder einer 503 `maintenance`). */
export function applyMaintenance(m: MaintenanceInfo): void {
  if (!config) return;
  config.maintenance = { on: m.on, reason: m.reason, since: m.since };
  window.dispatchEvent(new Event(CONFIG_CHANGED));
}

export interface RoleInfo {
  key: Level;
  label: string;
  points_factor: number;
  /** Als Zusatzrolle vergebbar (eigene Rollen, nicht Gast, Anwärter, Mitglied, Admin; Issue #66) */
  extra?: boolean;
}

/** Für Einstellungen → Rollen (nur Admins). */
export interface RoleAdmin extends RoleInfo {
  capabilities: Capability[];
  /** Personen mit dieser Rolle, als Grundrolle oder als Zusatzrolle */
  users: number;
  /** Davon als Zusatzrolle */
  extra_users?: number;
  system: boolean;
  deletable: boolean;
}

export type RoleInput = { label?: string; capabilities?: Capability[]; points_factor?: number | string };

export const roleInfo = (level: Level): RoleInfo | undefined => config?.roles?.find((r) => r.key === level);

export const levelLabel = (level: Level): string =>
  roleInfo(level)?.label ?? (brand.labels.roles as Record<string, string | undefined>)[level] ?? level;

/** Überschrift für die Neuigkeiten: Standardbezeichnung „News“ ausgeschrieben, eigene Bezeichnungen unverändert. */
export const newsTitle = (): string => (brand.labels.sections.news === 'News' ? 'Neuigkeiten' : brand.labels.sections.news);

/** Punktefaktor der Rolle (1 ohne Angabe). */
export const roleFactor = (level: Level): number => roleInfo(level)?.points_factor ?? 1;

/** Punktefaktor einer Person: der kleinste ihrer Rollen, Grundrolle und Zusatzrollen (wie der Server, Issue #66). */
export const personFactor = (p: { level: Level; extra_roles?: Level[] }): number =>
  Math.min(roleFactor(p.level), ...(p.extra_roles ?? []).map(roleFactor));

/** Alle Rollen einer Person zum Anzeigen: Grundrolle, dahinter die Zusatzrollen. */
export const roleLabels = (p: { level: Level; extra_roles?: Level[] }): string =>
  [p.level, ...(p.extra_roles ?? [])].map(levelLabel).join(', ');

/** Nach dem Schalten von Bereichen: Standardrollen können dazugekommen oder weggefallen sein. */
export async function refreshRoles(): Promise<RoleAdmin[] | null> {
  try {
    const list = await api.roles();
    applyRoles(list);
    return list;
  } catch {
    return null; // z. B. offline; beim nächsten Laden stimmt es wieder
  }
}

/** Übernimmt geänderte Rollen in die geladene Konfiguration (Bezeichnungen überall in der App). */
export function applyRoles(list: RoleAdmin[]): void {
  if (!config) return;
  config.roles = list.map(({ key, label, points_factor, system }) => ({ key, label, points_factor, extra: !system }));
  window.dispatchEvent(new Event(CONFIG_CHANGED));
}

/** Darf der angemeldete Benutzer das? */
export const can = (cap: Capability): boolean => !!config?.user?.capabilities.includes(cap);

export const isMember = (): boolean => can('member');

export interface Me {
  id: number;
  name: string;
  level: Level;
  extra_roles?: Level[];
  points: number;
  open_cents: number;
  /** Eigener Dienstgrad (mit Bereich Dienstgrade) */
  rank?: RankRef | null;
  /** Eigene Titel (mit Bereich Titel), in der Reihenfolge der Titelliste */
  titles?: TitleRef[];
}

export interface PointEntry {
  id: number;
  user_id: number;
  /** zählt: gebuchte Punkte × Faktor der Rolle */
  points: number;
  base_points?: number;
  factor?: number;
  reason: string;
  catalog_id: number | null;
  date: string;
  created_by_name: string | null;
}

export interface Penalty {
  id: number;
  user_id: number;
  amount_cents: number;
  reason: string;
  date: string;
  status: 'open' | 'paid';
  paid_at: string | null;
  created_by_name: string | null;
}

export interface CalendarEvent {
  id: string;
  title: string;
  start: string;
  end: string;
  all_day: boolean;
  location: string;
  description: string;
}

export interface CatalogItem {
  id: number;
  title: string;
  points: number;
  active: boolean;
  sort: number;
}

export interface PublicEvent extends Omit<CalendarEvent, 'id'> {
  id: number;
  start_date: string;
  start_time: string | null;
  end_date: string | null;
  end_time: string | null;
  url: string;
}

export interface EventInput {
  title: string;
  start_date: string;
  start_time: string;
  end_date: string;
  end_time: string;
  location: string;
  description: string;
  url: string;
}

export interface NewsItem {
  id: string;
  title: string;
  date: string;
  excerpt: string;
  image: string | null;
  link: string;
  content?: string;
}

export interface GalleryAlbumSummary {
  id: number;
  title: string;
  count: number;
  intern_count: number;
  cover: string | null;
}

export interface GalleryYear {
  year: string;
  albums: GalleryAlbumSummary[];
}

export interface GalleryPhoto {
  id: number;
  thumb: string;
  large: string;
  width: number;
  height: number;
  intern: boolean;
  name: string;
  /** false: noch nicht aufbereitet; die Adressen erzeugen das Bild oder liefern vorerst einen Platzhalter */
  ready: boolean;
}

export interface GalleryAlbum {
  id: number;
  title: string;
  year: string;
  photos: GalleryPhoto[];
}

export interface MemberSummary {
  id: number;
  /** Anzeigename „Vorname Nachname“ */
  name: string;
  /** Vor- und Nachname (Nachname kann leer sein); fehlen bei einem Server vor der Trennung */
  first_name?: string;
  last_name?: string;
  /** Grundrolle */
  level: Level;
  /** Zusatzrollen (Issue #66), in der Reihenfolge der Rollen */
  extra_roles?: Level[];
  /** Mit Mitgliederbereich; false bei Anwärtern, Rollen nur mit Kalender und Gästen */
  member: boolean;
  /** Gruppe im Zähler wie in der Anwesenheit (Einstellungen → Rollen); null bei Gästen, fehlt bei älteren Servern */
  group?: Level | null;
  /** Dienstgrad (nur mit Bereich Dienstgrade) */
  rank_title?: string | null;
  /** Abzeichen des Dienstgrads (wie rank_title) */
  rank_badge_url?: string | null;
  /** Titel (nur mit Bereich und Recht „Titel verwalten“) */
  titles?: TitleRef[];
  /** Foto (128 Pixel; s=480 für die große Fassung), null ohne */
  photo_url?: string | null;
  /** null: Punkte bzw. Strafen sieht nur, wer sie vergeben darf */
  points: number | null;
  open_cents: number | null;
}

/** Eintrag der Mitgliederliste (Bereich directory, öffentlich): nur Name, Foto, Dienstgrad, Titel. */
export interface DirectoryEntry {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  /** Foto (128 Pixel), null ohne */
  photo_url: string | null;
  /** Dienstgrad (fehlt ohne Bereich Dienstgrade) */
  rank_title?: string | null;
  rank_badge_url?: string | null;
  /** Titel (fehlen ohne Bereich Titel) */
  titles?: TitleRef[];
}

export interface MemberDetail extends MemberSummary {
  point_list: PointEntry[];
  penalties: Penalty[];
  /** Dienstgrad und Verlauf (nur mit Bereich und Recht „Dienstgrade verwalten“) */
  rank?: RankStatus;
  rank_history?: RankHistoryEntry[];
  /** Geburtstag mit Jahr und Schaltern (nur mit Bereich Geburtstage; null ohne Eintrag) */
  birthday?: Birthday | null;
  /** Ausnahmen bei den Rechten; null für Admins, Gäste, sich selbst und wer sie nicht ändern darf */
  rights?: MemberRights | null;
  /** Ob man das Foto hochladen, ersetzen und entfernen darf (create_users und dieses Konto verwalten) */
  photo_editable?: boolean;
  /** Ehemalig: Grund, Eintritt, Ausscheiden; null, wenn die Person nicht ehemalig ist (fehlt bei älteren Servern) */
  former?: FormerInfo | null;
}

/** Grund des Ausscheidens */
export type FormerReason = 'left' | 'deceased' | 'other';

/** Angaben zu einem ehemaligen Mitglied. Daten als JJJJ-MM-TT, nur das Jahr (JJJJ) oder leer. */
export interface FormerInfo {
  reason: FormerReason;
  joined_on: string;
  /** Gründungsmitglied */
  founder: boolean;
  left_on: string;
  /** Grundrolle vor dem Ausscheiden (für „Wieder aufnehmen“) */
  previous_level: Level;
}

/** Eintrag der Liste „Ehemalige“ (create_users). */
export interface FormerMember extends FormerInfo {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  photo_url: string | null;
  /** null: nur wer Punkte bzw. Strafen vergibt */
  points: number | null;
  open_cents: number | null;
}

export interface FormerInput {
  reason: FormerReason;
  joined_on: string;
  founder: boolean;
  left_on: string;
}

/** Zeile der Vorschau beim Import der Ehemaligen */
export interface FormerImportRow {
  line: number;
  name: string;
  first_name: string;
  last_name: string;
  reason?: FormerReason;
  joined_on?: string;
  founder?: boolean;
  left_on?: string;
  /** Konto mit genau diesem Namen gibt es schon: wird übersprungen */
  existing?: { id: number; name: string; former: boolean };
  error?: string;
}

export interface FormerImportResult {
  rows: FormerImportRow[];
  new: number;
  existing: number;
  /** Einträge in Amtszeiten (nur freier Name), die mit den Konten verknüpft werden bzw. wurden */
  offices_linked?: number;
  applied: boolean;
}

/** Rechte einer Person: die ihrer Rolle und Ausnahmen davon. */
export interface MemberRights {
  /** Rechte der Rolle (mit dem im Mitgliederbereich enthaltenen Kalender) */
  role: Capability[];
  /** zusätzlich zur Rolle gegeben */
  grant: Capability[];
  /** trotz der Rolle entzogen */
  revoke: Capability[];
  /** Rechte, die man hier ändern darf */
  editable: Capability[];
}

/** Geburtstag mit Jahr und Schaltern: nur der eigene und in den Mitgliedsdetails (Issue #1). */
export interface Birthday {
  month: number;
  day: number;
  year: number | null;
  /** Anderen Mitgliedern zeigen */
  visible: boolean;
  /** Alter anzeigen */
  show_age: boolean;
}

/** Eintrag im Inventar (Issue #6): ein Ort je Eintrag. */
export interface InventoryItem {
  id: number;
  title: string;
  quantity: number;
  unit: string | null;
  location: string;
  category: string | null;
  note: string | null;
  created_by_name: string | null;
  created_at: string;
  updated_by_name: string | null;
  updated_at: string;
}

/** Felder zum Anlegen und Ändern; Mengen auch als Text mit Komma. */
export interface InventoryInput {
  title?: string;
  quantity?: number | string;
  location?: string;
  unit?: string;
  category?: string;
  note?: string;
  reason?: string;
}

export type InventoryChange = { old: string | number | null; new: string | number | null };

export interface InventoryMove {
  from: string;
  to: string;
  quantity: number;
  source_id: number;
  target_id: number;
  source_removed?: boolean;
}

export interface InventoryLogEntry {
  id: number;
  item_id: number;
  action: 'create' | 'update' | 'move' | 'delete';
  changes: Partial<Record<'title' | 'quantity' | 'unit' | 'location' | 'category' | 'note', InventoryChange>> & { move?: InventoryMove };
  reason: string | null;
  title: string;
  unit: string | null;
  location: string;
  user_id: number;
  user_name: string | null;
  created_at: string;
}

/** Sichtbarer Geburtstag eines Mitglieds (GET /birthdays), nie mit Geburtsjahr. */
export interface BirthdayEntry {
  user_id: number;
  name: string;
  month: number;
  day: number;
  /** Tag des nächsten Geburtstags (29.02. in Jahren ohne Schalttag: 28.02.) */
  date: string;
  days_until: number;
  today: boolean;
  /** Alter an diesem Geburtstag, nur wenn freigegeben */
  age: number | null;
}

export interface BirthdayInput {
  month: number;
  day: number;
  year: number | null;
  visible?: boolean;
  show_age?: boolean;
}

export interface Rank {
  id: number;
  title: string;
  sort: number;
  /** Punkteschwelle (null = ohne); wirkt nur mit dem Bereich Punkte, Mitglieder sehen sie nur dann */
  min_points: number | null;
  active: boolean;
  /** Abzeichen (Issue #17): Adresse mit s=128, siehe badgeSrc(); null ohne */
  badge_url: string | null;
  /** Wie viele Mitglieder ihn haben (nur in der Pflege) */
  assigned?: number;
  /** Format des Abzeichens (nur in der Pflege) */
  badge_type?: 'png' | 'jpg' | 'webp' | 'svg' | null;
  /** Es gibt eine PNG-Fassung für Benachrichtigungen (nur in der Pflege) */
  badge_push?: boolean;
}

/** Nachricht am Schwarzen Brett (Issue #5). */
export interface BoardMessage {
  kind: 'message';
  id: number;
  /** Reiner Text mit Zeilenumbrüchen, nie HTML */
  text: string;
  /** null, wenn unbekannt; „gelöschtes Konto“, wenn das Konto gelöscht wurde */
  author_name: string | null;
  created_at: string;
  updated_at: string;
  /** Genau 24 Stunden nach created_at; danach blendet die App den Eintrag aus, auch offline */
  expires_at: string;
}

/** Laufende Umfrage am Schwarzen Brett (Issue #4), nur für Mitglieder. */
export interface BoardPoll {
  kind: 'poll';
  id: number;
  title: string;
  type: PollType;
  ends_at: string | null;
  /** wie ends_at; null = läuft, bis sie beendet wird */
  expires_at: string | null;
  has_voted: boolean;
  created_at: string;
  updated_at: string;
}

/** Eintrag am Schwarzen Brett; unbekannte Arten überspringt die App. */
export type BoardItem = BoardMessage | BoardPoll;

export type PollType = 'single' | 'multiple' | 'text';
export type PollResults = 'after_vote' | 'after_end' | 'managers';

/** Umfrage (Issue #4); Ergebnisse nur, soweit sie für dieses Konto sichtbar sind. */
export interface Poll {
  id: number;
  title: string;
  description: string;
  type: PollType;
  anonymous: boolean;
  results: PollResults;
  status: 'running' | 'ended';
  ends_at: string | null;
  closed_at: string | null;
  created_at: string;
  updated_at: string;
  created_by_name: string | null;
  options: { id: number; title: string }[];
  /** Es gibt Antworten: Art, Möglichkeiten, Anonymität und Sichtbarkeit sind fest */
  locked: boolean;
  has_voted: boolean;
  my_answer: { option_ids: number[]; text: string | null } | null;
  results_visible: boolean;
  results_data: PollResultData | null;
}

export interface PollResultData {
  voters: number;
  options?: { id: number; title: string; count: number; percent: number; names?: string[] }[];
  texts?: { text: string; name?: string }[];
}

export interface PollInput {
  title?: string;
  description?: string;
  type?: PollType;
  options?: string[];
  anonymous?: boolean;
  results?: PollResults;
  /** „JJJJ-MM-TTTHH:MM“ in der Zeitzone der Kompanie; leer = ohne Ende */
  ends_at?: string | null;
}

export type DocVisibility = 'members' | 'managers';

export interface DocCategory {
  id: number;
  title: string;
  sort: number;
}

export interface DocumentInfo {
  id: number;
  title: string;
  category_id: number | null;
  /** Stand (JJJJ-MM-TT) */
  date: string;
  visibility: DocVisibility;
  ext: string;
  /** pdf und image zeigt die App an, file wird nur heruntergeladen */
  kind: 'pdf' | 'image' | 'file';
  mime: string;
  size: number;
  version: string;
  /** Auslieferung mit Prüfung der Anmeldung; wechselt mit jeder Fassung */
  url: string;
  updated_at: string;
}

export interface DocumentMeta {
  title: string;
  category_id: number | null;
  date: string;
  visibility: DocVisibility;
}

export interface DocumentsResponse {
  categories: DocCategory[];
  documents: DocumentInfo[];
  /** Höchstgröße einer Datei (nur für wer Dokumente pflegt) */
  max_bytes?: number;
}

/** Datei für die API: Name und Inhalt als Base64. */
export type EncodedFile = { name: string; data: string };

/** Datei als { name, data (Base64) } für die API (beide Adapter nehmen nur JSON an). */
export async function encodeFile(file: File): Promise<EncodedFile> {
  const data = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    // Nur der Base64-Teil (ohne „data:…;base64,“): spart auf dem Server eine Kopie der Datei.
    reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
    reader.onerror = () => reject(new Error('Die Datei konnte nicht gelesen werden.'));
    reader.readAsDataURL(file);
  });
  return { name: file.name, data };
}

export type AttendanceStatus = 'present' | 'excused' | 'absent';
export const ATTENDANCE_LABELS: Record<AttendanceStatus, string> = { present: 'anwesend', excused: 'entschuldigt', absent: 'fehlt' };
export type AttendanceCounts = Record<AttendanceStatus, number>;

/** „3 anwesend · 1 entschuldigt · 0 fehlt“ */
export const countsText = (c: AttendanceCounts): string =>
  (['present', 'excused', 'absent'] as AttendanceStatus[]).map((s) => `${c[s]} ${ATTENDANCE_LABELS[s]}`).join(' · ');

export interface AttendanceSummary {
  id: number;
  source: 'calendar' | 'free';
  title: string;
  date: string;
  counts: AttendanceCounts;
}

/** Termin aus dem internen Kalender zur Auswahl (letzte Wochen bis heute) */
export interface AttendanceEvent {
  key: string;
  title: string;
  date: string;
  start: string;
  end: string;
  all_day: boolean;
  list_id: number | null;
}

export interface AttendancePerson {
  id: number;
  name: string;
  role: Level;
  /** Rolle, als die die Person im Zähler zählt */
  group: Level;
  /** Mit Mitgliederbereich: Punkte und Strafen möglich */
  member: boolean;
  status: AttendanceStatus | null;
  /** Hat für diese Liste schon Punkte */
  booked: boolean;
}

export interface AttendanceDetail extends AttendanceSummary {
  event_key: string | null;
  updated_by_name: string | null;
  updated_at: string;
  people: AttendancePerson[];
  counts_by_group: Record<AttendanceStatus, { group: Level; label: string; count: number }[]>;
}

export type AttendanceInput = (
  | { id: number; title?: string; date?: string }
  | { source: 'calendar'; event_key: string }
  | { source: 'free'; title: string; date: string }
) & { entries?: Record<number, AttendanceStatus | null> };

export interface MyAttendance {
  entries: { list_id: number; title: string; date: string; status: AttendanceStatus; updated_by_name: string | null }[];
  counts: AttendanceCounts;
}

export interface AttendanceGroup {
  key: Level;
  label: string;
  /** null: eigene Gruppe */
  counts_as: Level | null;
}

export interface Title {
  id: number;
  title: string;
  sort: number;
  /** Deaktivierte werden nicht mehr neu vergeben; wer sie hat, behält sie. */
  active: boolean;
  /** Wie vielen Mitgliedern zugewiesen */
  assigned: number;
  /** Amt, das den Titel über die laufenden Amtszeiten vergibt (Issue #112); null ohne, fehlt bei älteren Servern */
  office?: { id: number; title: string } | null;
}

export interface TitleRef {
  id: number;
  title: string;
}

/** Ämter (Issue #112): Gruppe der Anzeige */
export interface OfficeGroup {
  id: number;
  title: string;
}

/** Inhaber einer Amtszeit: user_id und Foto nur bei Personen der Mitgliederliste (bzw. für die Pflege) */
export interface OfficeHolder {
  name: string;
  user_id?: number | null;
  photo_url?: string | null;
}

export type OfficeTermKind = 'period' | 'point';

export interface OfficeTerm {
  id: number;
  /** period: Zeitraum (ohne Ende bis heute), point: ein einzelnes Jahr */
  kind: OfficeTermKind;
  start_year: number;
  end_year: number | null;
  note: string;
  /** Zeitraum ohne Ende */
  current: boolean;
  holders: OfficeHolder[];
}

export interface Office {
  id: number;
  group_id: number | null;
  title: string;
  /** Laufende zuerst, dann nach Beginn absteigend */
  terms: OfficeTerm[];
  /** Nur für die Pflege: verknüpfter Titel */
  title_id?: number | null;
  linked_title?: string | null;
}

export interface OfficesOverview {
  groups: OfficeGroup[];
  offices: Office[];
}

export interface OfficeTermInput {
  kind: OfficeTermKind;
  start_year: number;
  end_year: number | null;
  note: string;
  holders: ({ user_id: number } | { name: string })[];
}

export interface OfficeImportRow {
  line: number;
  group?: string;
  office?: string;
  kind?: OfficeTermKind;
  start_year?: number;
  end_year?: number | null;
  note?: string;
  holders?: { name: string; user_id: number | null }[];
  duplicate?: boolean;
  error?: string;
}

export interface OfficeImportResult {
  rows: OfficeImportRow[];
  groups_new: number;
  offices_new: number;
  terms_new: number;
  applied: boolean;
}

export interface RankRef {
  id: number;
  title: string;
  /** Abzeichen (Issue #17): Adresse mit s=128, siehe badgeSrc(); null ohne */
  badge_url: string | null;
}

/** Stand eines Mitglieds; die Felder nach rank nur mit dem Bereich Punkte. */
export interface RankStatus {
  rank: RankRef | null;
  points?: number;
  next_rank?: (RankRef & { min_points: number | null }) | null;
  points_to_next?: number | null;
  reachable_rank?: RankRef | null;
  threshold_reached_since?: string | null;
  below_current_threshold?: boolean;
}

export interface RankHistoryEntry {
  from: string | null;
  to: string | null;
  /** Heutiges Abzeichen des neuen Dienstgrads */
  to_badge_url?: string | null;
  kind: 'promotion' | 'demotion' | 'initial';
  points_at_change: number | null;
  reason: string;
  changed_by_name: string | null;
  changed_at: string;
}

export interface Promotion extends Required<Omit<RankStatus, 'rank'>> {
  user_id: number;
  name: string;
  rank: RankRef | null;
}

export interface CreatedUser {
  id: number;
  name: string;
  first_name?: string;
  last_name?: string;
  username: string;
  email: string;
  level: Level;
  /** Konto angelegt, aber Anfangspunkte, Dienstgrad, Geburtstag bzw. Foto nicht übernommen */
  warning?: string;
  /** Foto, wenn eines mitgeschickt wurde */
  photo_url?: string | null;
  /** Startpasswort – steht nur in dieser einen Antwort. */
  password: string;
}

/** Ergebnis je Person beim Anlegen mehrerer (Issue #24): angelegt oder mit Grund nicht. */
export type BulkResult = CreatedUser | { name: string; username: string; error: string };

/** Vor- und Nachname; nur der Vorname ist Pflicht. */
export interface PersonName {
  first_name: string;
  last_name: string;
}

export interface NewPerson extends PersonName {
  username?: string;
  email?: string;
  initial_points?: number;
  initial_points_date?: string;
  rank_id?: number;
  /** JJJJ-MM-TT oder ohne Jahr MM-TT */
  birthday?: string;
  /** Foto (JPG, PNG oder WebP als Base64) */
  photo?: EncodedFile;
}

export interface AccountProfile extends PersonName {
  name: string;
  login: string;
  email: string;
  /** WordPress: Benutzernamen lassen sich nicht ändern */
  can_change_login: boolean;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code = '',
  ) {
    super(message);
  }
}

let csrfToken = config?.csrf.token ?? '';

/** Zur Anmeldung: Weiterleitung (WordPress) oder Anmeldeformular in der App. */
export function toLogin(): void {
  if (config?.auth.mode === 'redirect' && config.auth.loginUrl) {
    window.location.href = config.auth.loginUrl;
  } else {
    window.location.hash = '/anmelden';
  }
}

function sessionExpired(): never {
  toLogin();
  throw new ApiError('Sitzung abgelaufen – bitte neu anmelden.', 401, 'not_logged_in');
}

/** Holt ein neues CSRF-Token (nur mit dem Sitzungs-Cookie). quiet: ohne Weiterleitung zur Anmeldung. */
export async function refreshCsrf(quiet = false): Promise<void> {
  let res: Response;
  try {
    res = await fetch(config!.csrf.refreshUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch {
    throw new ApiError('Keine Verbindung. Bitte später erneut versuchen.', 0);
  }
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.token) {
    if (quiet) throw new ApiError('Sitzung abgelaufen.', 401, 'not_logged_in');
    sessionExpired();
  }
  csrfToken = data.token;
}

function url(path: string, query?: Record<string, string>): string {
  const base = config!.apiBase.replace(/\/$/, '');
  let u = base + path;
  if (query) {
    const qs = new URLSearchParams(query).toString();
    if (qs) u += (u.includes('?') ? '&' : '?') + qs;
  }
  return u;
}

const CSRF_ERRORS = ['csrf_invalid', 'rest_cookie_invalid_nonce'];
/** Header für den Service Worker (public/sw.js): Antwort darf zuerst aus seinem Speicher kommen. */
const STALE_OK_HEADER = 'X-Kompanie-Stale-Ok';
const USER_HEADER = 'X-Kompanie-User';

async function requestFull<T>(
  method: string,
  path: string,
  body?: unknown,
  query?: Record<string, string>,
  retry = true,
  /** 401 nur melden, nicht zur Anmeldung weiterleiten (z. B. beim Abmelden) */
  quiet = false,
): Promise<{ data: T; headers: Headers }> {
  if (!config) {
    throw new ApiError('Die App muss über ihre Adresse auf der Webseite geöffnet werden.', 0);
  }
  let res: Response;
  // Service Worker (Issue #108): gespeicherte Antwort sofort, die aus dem Netz kommt im Hintergrund nach. Was vor der
  // letzten eigenen Änderung gespeichert wurde, holt er zuerst aus dem Netz (public/sw.js). Nicht /config: Damit prüft
  // die App den aktuellen Stand (z. B. „Erneut versuchen“ auf der Wartungsseite).
  const staleOk = method === 'GET' && !previewRole() && path !== '/config';
  try {
    res = await fetch(url(path, query), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        // Für wen die Antwort gilt: Nur dieselbe Person bekommt sie später sofort aus dem Speicher.
        ...(method === 'GET' ? { [USER_HEADER]: String(config.user?.id ?? 0) } : {}),
        ...(staleOk ? { [STALE_OK_HEADER]: '1' } : {}),
        ...(csrfToken ? { [config.csrf.header]: csrfToken } : {}),
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
        // Vorschau (Issue #63): bei jeder Anfrage, der Server wendet dann die Rechte der Rolle an.
        ...(previewRole() ? { [PREVIEW_HEADER]: previewRole()! } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    if (!path.startsWith('/reports')) noteError('Keine Verbindung zum Server');
    throw new ApiError('Keine Verbindung. Bitte später erneut versuchen.', 0);
  }
  // Eigene Änderung (auch eine abgelehnte könnte etwas geändert haben): zwischengespeicherte Daten verwerfen.
  // Ausnahme: die Abfrage des Push-Status beim Start, ein POST ohne Änderung an den angezeigten Daten.
  if (method !== 'GET' && !path.endsWith('/push/status')) dataCache.clear();

  const data = await res.json().catch(() => null);

  if (res.status === 403 && retry && CSRF_ERRORS.includes(data?.code)) {
    await refreshCsrf(quiet);
    return requestFull<T>(method, path, body, query, false, quiet);
  }
  if (res.status === 401 && config.user && !quiet) {
    sessionExpired();
  }
  // Wartungsmodus wurde eingeschaltet, während die App offen ist: Wartungsseite zeigen.
  if (res.status === 503 && data?.code === 'maintenance') {
    applyMaintenance({ on: true, reason: String(data.reason ?? ''), since: config.maintenance?.since ?? 0 });
  }
  if (!res.ok) {
    const message: string = data?.message ?? `Fehler ${res.status}`;
    // Für „Fehler melden“ (Issue #64); Fehler des Formulars selbst nicht.
    if (!path.startsWith('/reports')) noteError(`${message} (HTTP ${res.status}${data?.code ? `, ${data.code}` : ''})`);
    throw new ApiError(message, res.status, data?.code ?? '');
  }
  return { data: data as T, headers: res.headers };
}

/**
 * Vorschau „App als Rolle ansehen“ (Issue #63) vor dem Start der App: Ist in diesem Tab eine gewählt, die Konfiguration
 * mit den Rechten der Rolle holen und übernehmen (die mit der Seite gelieferte ist die des Admins). Bestätigt der Server
 * sie nicht (kein Admin mehr, Rolle gelöscht, offline), endet die Vorschau; zurück kommt dann ein Hinweis.
 */
export async function preparePreview(): Promise<string | null> {
  if (!previewRole() || !config) return null;
  try {
    const next = await request<AppConfig>('GET', '/config');
    if (!next.preview) {
      stopPreview();
      return 'Die Vorschau wurde beendet.';
    }
    // Bereiche im selben Objekt ändern: Teile der App halten es fest (z. B. App.tsx).
    const { features, ...rest } = next;
    Object.assign(config.features, features);
    Object.assign(config, rest);
    return null;
  } catch {
    stopPreview();
    return 'Die Vorschau braucht eine Internetverbindung und wurde beendet.';
  }
}

async function request<T>(method: string, path: string, body?: unknown, query?: Record<string, string>): Promise<T> {
  return (await requestFull<T>(method, path, body, query)).data;
}

export const api = {
  me: () => request<Me>('GET', '/me'),
  myPoints: () => request<PointEntry[]>('GET', '/me/points'),
  myPenalties: () => request<Penalty[]>('GET', '/me/penalties'),
  calendar: () => request<CalendarEvent[]>('GET', '/calendar'),

  login: (username: string, password: string, remember: boolean) =>
    request<AppConfig>('POST', '/auth/login', { username, password, remember }),
  // Mit leerem JSON-Inhalt: Die eigenständige Version verlangt ihn bei Anfragen ohne Sitzung.
  logout: () => request<{ logged_out: boolean }>('POST', '/auth/logout', {}),

  events: () => request<PublicEvent[]>('GET', '/events'),
  createEvent: (data: EventInput) => request<PublicEvent>('POST', '/events', data),
  updateEvent: (id: number, data: EventInput) => request<PublicEvent>('PATCH', `/events/${id}`, data),
  deleteEvent: (id: number) => request<{ deleted: boolean }>('DELETE', `/events/${id}`),

  news: async (page = 1) => {
    const { data, headers } = await requestFull<NewsItem[]>('GET', '/news', undefined, { page: String(page) });
    return { items: data, totalPages: Number(headers.get('X-Total-Pages') ?? 1) };
  },
  newsItem: (id: string) => request<NewsItem>('GET', `/news/${encodeURIComponent(id)}`),

  gallery: () => request<{ years: GalleryYear[] }>('GET', '/gallery'),
  galleryAlbum: (id: number) => request<GalleryAlbum>('GET', `/gallery/${id}`),

  catalog: (all = false) => request<CatalogItem[]>('GET', '/catalog', undefined, all ? { all: '1' } : undefined),
  createCatalogItem: (title: string, points: number) => request<CatalogItem>('POST', '/catalog', { title, points }),
  updateCatalogItem: (id: number, data: Partial<Pick<CatalogItem, 'title' | 'points' | 'active' | 'sort'>>) =>
    request<CatalogItem>('PATCH', `/catalog/${id}`, data),

  directory: () => request<DirectoryEntry[]>('GET', '/directory'),
  /** Anzahl je Gruppe in dieser Liste (wie in der Anwesenheit), nur die Summen; fehlt bei älteren Servern (404). */
  directoryCounts: () => request<{ group: Level; count: number }[]>('GET', '/directory/counts'),
  members: (all = false, candidates = false) =>
    request<MemberSummary[]>('GET', '/members', undefined, {
      ...(all ? { all: '1' } : {}),
      ...(candidates ? { candidates: '1' } : {}),
    }),
  /** Anwärter zum Mitglied machen (wer Mitglieder anlegen darf). */
  promote: (id: number) => request<{ id: number; level: Level }>('POST', `/members/${id}/promote`),
  setRole: (id: number, level: Level) =>
    request<{ id: number; level: Level; extra_roles: Level[] }>('PATCH', `/members/${id}/role`, { level }),
  setExtraRoles: (id: number, extra_roles: Level[]) =>
    request<{ id: number; level: Level; extra_roles: Level[] }>('PATCH', `/members/${id}/role`, { extra_roles }),
  member: (id: number) => request<MemberDetail>('GET', `/members/${id}`),
  /** Rechte einer Person zusätzlich zur Rolle geben bzw. entziehen; leere Listen: wie die Rolle. */
  setRights: (id: number, grant: Capability[], revoke: Capability[]) =>
    request<MemberRights>('PUT', `/members/${id}/rights`, { grant, revoke }),
  resetPassword: (id: number) => request<{ password: string }>('POST', `/members/${id}/password`),
  /** Ändert das eigene Passwort; danach gilt eine neue Sitzung, daher Token erneuern. */
  changePassword: async (current_password: string, new_password: string) => {
    await request<{ changed: boolean }>('POST', '/me/password', { current_password, new_password });
    await refreshCsrf();
  },
  createUsers: (level: 'member' | 'candidate', people: NewPerson[]) => request<BulkResult[]>('POST', '/users/bulk', { level, people }),
  account: (id: number) => request<AccountProfile>('GET', `/members/${id}/account`),
  updateAccount: (id: number, data: Partial<PersonName> & { email?: string; username?: string }) =>
    request<AccountProfile>('PATCH', `/members/${id}/account`, data),
  deleteAccount: (id: number) => request<{ deleted: boolean }>('DELETE', `/members/${id}`),
  formerMembers: () => request<FormerMember[]>('GET', '/members/former'),
  markFormer: (id: number, data: FormerInput) => request<FormerInfo>('PUT', `/members/${id}/former`, data),
  updateFormer: (id: number, data: FormerInput) => request<FormerInfo>('PATCH', `/members/${id}/former`, data),
  reactivate: (id: number) => request<{ id: number; level: Level; extra_roles: Level[] }>('DELETE', `/members/${id}/former`),
  importFormer: (csv: string, apply: boolean) => request<FormerImportResult>('POST', '/members/former/import', { csv, apply }),
  createUser: (
    data: PersonName & {
      username?: string;
      email?: string;
      level?: 'member' | 'candidate';
      initial_points?: number;
      initial_points_date?: string;
      rank_id?: number;
      birthday?: string;
      photo?: EncodedFile;
    },
  ) => request<CreatedUser>('POST', '/users', data),
  /** Foto eines Mitglieds hochladen bzw. ersetzen (vorher mit preparePhoto verkleinert). */
  uploadMemberPhoto: (id: number, file: EncodedFile) => request<{ photo_url: string | null }>('PUT', `/members/${id}/photo`, { file }),
  removeMemberPhoto: (id: number) => request<{ photo_url: null }>('DELETE', `/members/${id}/photo`),

  // Dienstgrade (Issue #9)
  ranks: (all = false) => request<Rank[]>('GET', '/ranks', undefined, all ? { all: '1' } : undefined),
  createRank: (data: { title: string; min_points?: number | null }) => request<Rank>('POST', '/ranks', data),
  updateRank: (id: number, data: { title?: string; min_points?: number | null; active?: boolean }) =>
    request<Rank>('PATCH', `/ranks/${id}`, data),
  deleteRank: (id: number) => request<{ deleted: boolean }>('DELETE', `/ranks/${id}`),
  reorderRanks: (ids: number[]) => request<Rank[]>('PUT', '/ranks/order', { ids }),
  setMemberRank: (id: number, rank_id: number | null, reason: string) =>
    request<RankStatus>('PUT', `/members/${id}/rank`, { rank_id, reason }),
  promotions: () => request<Promotion[]>('GET', '/promotions'),
  /** Abzeichen hochladen; bei SVG optional eine PNG-Fassung (Base64) für Benachrichtigungen. */
  uploadRankBadge: async (id: number, file: File, png?: string | null) =>
    request<Rank>('POST', `/ranks/${id}/badge`, { file: await encodeFile(file), ...(png ? { png } : {}) }),
  removeRankBadge: (id: number) => request<Rank>('DELETE', `/ranks/${id}/badge`),
  myRank: () => request<RankStatus & { ranks: Rank[]; history: RankHistoryEntry[] }>('GET', '/me/rank'),

  // Dokumente (Issue #2)
  documents: () => request<DocumentsResponse>('GET', '/documents'),
  createDocument: async (file: File, meta: DocumentMeta) =>
    request<DocumentInfo>('POST', '/documents', { ...meta, file: await encodeFile(file) }),
  updateDocument: (id: number, meta: Partial<DocumentMeta>) => request<DocumentInfo>('PATCH', `/documents/${id}`, meta),
  replaceDocumentFile: async (id: number, file: File) =>
    request<DocumentInfo>('POST', `/documents/${id}/file`, { file: await encodeFile(file) }),
  deleteDocument: (id: number) => request<{ deleted: boolean }>('DELETE', `/documents/${id}`),
  createDocCategory: (title: string) => request<DocCategory>('POST', '/document-categories', { title }),
  updateDocCategory: (id: number, title: string) => request<DocCategory>('PATCH', `/document-categories/${id}`, { title }),
  deleteDocCategory: (id: number) => request<{ deleted: boolean }>('DELETE', `/document-categories/${id}`),
  reorderDocCategories: (ids: number[]) => request<DocCategory[]>('PUT', '/document-categories/order', { ids }),

  // Schwarzes Brett (Issue #5)
  board: () => request<{ items: BoardItem[] }>('GET', '/board'),
  postBoard: (text: string, push = true) => request<BoardMessage>('POST', '/board', { text, push }),
  updateBoard: (id: number, text: string) => request<BoardMessage>('PATCH', `/board/${id}`, { text }),
  polls: () => request<Poll[]>('GET', '/polls'),
  poll: (id: number) => request<Poll>('GET', `/polls/${id}`),
  createPoll: (data: PollInput) => request<Poll>('POST', '/polls', data),
  updatePoll: (id: number, data: PollInput) => request<Poll>('PATCH', `/polls/${id}`, data),
  closePoll: (id: number) => request<Poll>('POST', `/polls/${id}/close`, {}),
  deletePoll: (id: number) => request<{ deleted: boolean }>('DELETE', `/polls/${id}`),
  vote: (id: number, answer: { option_ids?: number[]; text?: string }) => request<Poll>('PUT', `/polls/${id}/vote`, answer),
  withdrawVote: (id: number) => request<Poll>('DELETE', `/polls/${id}/vote`),
  deleteBoard: (id: number) => request<{ deleted: boolean }>('DELETE', `/board/${id}`),

  // Geburtstage (Issue #1)
  birthdays: (days?: number) =>
    request<BirthdayEntry[]>('GET', '/birthdays', undefined, days === undefined ? undefined : { days: String(days) }),
  myBirthday: () => request<{ birthday: Birthday | null }>('GET', '/me/birthday'),
  saveMyBirthday: (data: BirthdayInput) => request<{ birthday: Birthday | null }>('PUT', '/me/birthday', data),
  deleteMyBirthday: () => request<{ birthday: null }>('DELETE', '/me/birthday'),
  setMemberBirthday: (id: number, data: { month: number; day: number; year: number | null }) =>
    request<{ birthday: Birthday | null }>('PUT', `/members/${id}/birthday`, data),
  deleteMemberBirthday: (id: number) => request<{ birthday: null }>('DELETE', `/members/${id}/birthday`),

  // Inventar (Issue #6)
  inventory: () => request<InventoryItem[]>('GET', '/inventory'),
  inventoryLocations: () => request<string[]>('GET', '/inventory/locations'),
  /** Verlauf aller Einträge bzw. eines Eintrags, neueste zuerst; before: nächste Seite (ältere als diese ID). */
  inventoryLog: (itemId?: number, before?: number) =>
    request<InventoryLogEntry[]>(
      'GET',
      itemId === undefined ? '/inventory/log' : `/inventory/${itemId}/log`,
      undefined,
      before === undefined ? undefined : { before: String(before) },
    ),
  createInventory: (data: InventoryInput) => request<InventoryItem>('POST', '/inventory', data),
  updateInventory: (id: number, data: InventoryInput & { adjust?: number }) => request<InventoryItem>('PATCH', `/inventory/${id}`, data),
  deleteInventory: (id: number, reason: string) => request<{ deleted: boolean }>('DELETE', `/inventory/${id}`, { reason }),
  moveInventory: (id: number, data: { quantity: number | string; location: string; reason?: string }) =>
    request<{ source: InventoryItem | null; target: InventoryItem }>('POST', `/inventory/${id}/move`, data),

  // Anwesenheit (Issue #21)
  attendanceLists: () => request<AttendanceSummary[]>('GET', '/attendance'),
  attendanceEvents: () => request<AttendanceEvent[]>('GET', '/attendance/events'),
  attendance: (id: number) => request<AttendanceDetail>('GET', `/attendance/${id}`),
  saveAttendance: (data: AttendanceInput) => request<AttendanceDetail>('PUT', '/attendance', data),
  deleteAttendance: (id: number) => request<{ deleted: boolean }>('DELETE', `/attendance/${id}`),
  myAttendance: () => request<MyAttendance>('GET', '/me/attendance'),
  attendanceGroups: () => request<AttendanceGroup[]>('GET', '/attendance/groups'),
  setAttendanceGroups: (groups: Record<string, string | null>) => request<AttendanceGroup[]>('PUT', '/attendance/groups', { groups }),

  // Titel (Issue #22)
  titles: () => request<Title[]>('GET', '/titles'),
  createTitle: (title: string) => request<Title>('POST', '/titles', { title }),
  updateTitle: (id: number, data: { title?: string; active?: boolean }) => request<Title>('PATCH', `/titles/${id}`, data),
  deleteTitle: (id: number) => request<{ deleted: boolean }>('DELETE', `/titles/${id}`),
  reorderTitles: (ids: number[]) => request<Title[]>('PUT', '/titles/order', { ids }),
  setMemberTitles: (id: number, title_ids: number[]) => request<{ titles: TitleRef[] }>('PUT', `/members/${id}/titles`, { title_ids }),

  // Ämter (Issue #112): Zeitstrahl öffentlich, Pflege mit manage_offices
  offices: () => request<OfficesOverview>('GET', '/offices'),
  createOfficeGroup: (title: string) => request<OfficeGroup>('POST', '/office-groups', { title }),
  updateOfficeGroup: (id: number, title: string) => request<OfficeGroup>('PATCH', `/office-groups/${id}`, { title }),
  deleteOfficeGroup: (id: number) => request<{ deleted: boolean }>('DELETE', `/office-groups/${id}`),
  reorderOfficeGroups: (ids: number[]) => request<OfficeGroup[]>('PUT', '/office-groups/order', { ids }),
  createOffice: (data: { title: string; group_id: number | null }) => request<Office>('POST', '/offices', data),
  updateOffice: (id: number, data: { title?: string; group_id?: number | null; title_id?: number | null }) =>
    request<Office>('PATCH', `/offices/${id}`, data),
  deleteOffice: (id: number) => request<{ deleted: boolean }>('DELETE', `/offices/${id}`),
  reorderOffices: (ids: number[]) => request<Office[]>('PUT', '/offices/order', { ids }),
  officeTitlePreview: (id: number, titleId: number | null) =>
    request<{ add: { name: string; title: string }[]; remove: { name: string; title: string }[] }>(
      'GET',
      `/offices/${id}/title-preview`,
      undefined,
      titleId === null ? {} : { title_id: String(titleId) },
    ),
  createOfficeTerm: (officeId: number, data: OfficeTermInput) => request<OfficeTerm>('POST', `/offices/${officeId}/terms`, data),
  updateOfficeTerm: (id: number, data: OfficeTermInput) => request<OfficeTerm>('PATCH', `/office-terms/${id}`, data),
  deleteOfficeTerm: (id: number) => request<{ deleted: boolean }>('DELETE', `/office-terms/${id}`),
  importOffices: (csv: string, apply: boolean, link = true) => request<OfficeImportResult>('POST', '/offices/import', { csv, apply, link }),

  roles: () => request<RoleAdmin[]>('GET', '/admin/roles'),
  createRole: (data: RoleInput) => request<RoleAdmin[]>('POST', '/admin/roles', data),
  updateRole: (key: Level, data: RoleInput) => request<RoleAdmin[]>('PATCH', `/admin/roles/${encodeURIComponent(key)}`, data),
  deleteRole: (key: Level, replacement?: Level) =>
    request<RoleAdmin[]>('DELETE', `/admin/roles/${encodeURIComponent(key)}`, undefined, replacement ? { replacement } : undefined),
  givePoints: (data: {
    user_ids: number[];
    points?: number;
    catalog_id?: number;
    reason?: string;
    date?: string;
    apply_factor?: boolean;
    /** Aus dem Vorschlag einer Anwesenheitsliste: bucht nur für, wer dafür noch keine Punkte hat. */
    attendance_id?: number;
  }) => request<PointEntry[]>('POST', '/points', data),
  deletePoints: (id: number) => request<{ deleted: boolean }>('DELETE', `/points/${id}`),

  givePenalty: (data: { user_ids: number[]; amount_cents: number; reason: string; date?: string }) =>
    request<Penalty[]>('POST', '/penalties', data),
  setPenaltyStatus: (id: number, status: 'open' | 'paid') => request<Penalty>('PATCH', `/penalties/${id}`, { status }),
  deletePenalty: (id: number) => request<{ deleted: boolean }>('DELETE', `/penalties/${id}`),

  features: () => request<FeatureInfo[]>('GET', '/admin/features'),
  setFeatures: (data: Partial<Record<Feature, boolean>>) => request<FeatureInfo[]>('PUT', '/admin/features', data),
  settings: () => request<AdminSettings>('GET', '/admin/settings'),
  maintenance: () => request<MaintenanceState>('GET', '/admin/maintenance'),
  setMaintenance: (on: boolean, reason?: string) =>
    request<MaintenanceState>('POST', '/admin/maintenance', reason === undefined ? { on } : { on, reason }),
  /** Laufzeit-Konfiguration neu laden (z. B. „Erneut versuchen“ auf der Wartungsseite). */
  config: () => request<AppConfig>('GET', '/config'),
  updateSettings: (data: AdminSettingsInput) => request<AdminSettings>('PATCH', '/admin/settings', data),
  /** „Mit Google verbinden“: speichert den Client und liefert die Adresse der Anmeldung bei Google. */
  googleConnect: (clientId: string, clientSecret: string) =>
    request<{ url: string }>('POST', '/admin/google/connect', { client_id: clientId, client_secret: clientSecret }),
  googleDisconnect: () => request<AdminSettings>('POST', '/admin/google/disconnect', {}),
  testCalendar: () => request<{ count: number; sample: { start: string; title: string }[] }>('POST', '/admin/calendar/test'),
  testNews: () => request<{ count: number }>('POST', '/admin/news/test'),
  syncGallery: (onlyProcess = false) =>
    request<{ albums: number; photos: number; processed: number; pending: number; failed: number; error: string }>(
      'POST',
      '/admin/gallery/sync',
      { only_process: onlyProcess },
    ),
  galleryFailed: () => request<{ photos: FailedPhoto[]; total: number }>('GET', '/admin/gallery/failed'),
  retryGallery: () => request<{ retried: number; pending: number }>('POST', '/admin/gallery/retry', {}),

  status: () => request<AdminStatus>('GET', '/admin/status'),
  checkUpdates: () => request<UpdateStatus>('POST', '/admin/update/check'),
  installUpdate: (version: string, major = false) => request<{ installed: string }>('POST', '/admin/update/install', { version, major }),
  rollback: (version: string) => request<{ installed: string }>('POST', '/admin/update/rollback', { version }),
  updateOptions: (data: { auto?: boolean; channel?: 'stable' | 'early'; status_reports?: boolean }) =>
    request<UpdateStatus>('PATCH', '/admin/update', data),
  backup: () => request<Record<string, unknown>>('GET', '/admin/backup'),
  restoreBackup: (backup: string) => request<{ restored: boolean }>('POST', '/admin/backup', { backup, confirm: true }),
  clearErrorLog: () => request<{ errorLog: ErrorLogEntry[] }>('DELETE', '/admin/error-log'),
  setupDone: () => request<{ done: boolean }>('POST', '/admin/setup/done', {}),

  // Benachrichtigungen (Web Push): Konten über /me/push, Gäste über /push mit dem auth-Geheimnis des Geräts.
  // Mit dem Geheimnis auch für Konten: Ein Abo eines hier nicht mehr angemeldeten Kontos gibt der Server dann frei.
  pushStatus: (device: PushDevice | null) =>
    request<PushStatus>('POST', pushPath('/status'), device ? { endpoint: device.endpoint, auth: device.keys.auth } : {}),
  pushSubscribe: (device: PushDevice, types: PushType[], details?: boolean) =>
    request<PushSubscriptionInfo>('POST', pushPath(''), { endpoint: device.endpoint, keys: device.keys, types, details }),
  pushUpdate: (device: PushDevice, data: { types?: PushType[]; details?: boolean }) =>
    request<PushSubscriptionInfo>('PUT', pushPath(''), { endpoint: device.endpoint, auth: guestAuth(device), ...data }),
  pushUnsubscribe: (device: PushDevice) =>
    request<{ deleted: boolean }>('DELETE', pushPath(''), { endpoint: device.endpoint, auth: guestAuth(device) }),
  // Beim Abmelden: ohne Weiterleitung, wenn die Sitzung schon beendet ist.
  pushStatusQuiet: async (device: PushDevice) =>
    (
      await requestFull<PushStatus>(
        'POST',
        pushPath('/status'),
        { endpoint: device.endpoint, auth: device.keys.auth },
        undefined,
        true,
        true,
      )
    ).data,
  pushForget: async (device: PushDevice) =>
    (await requestFull<{ deleted: boolean }>('DELETE', pushPath(''), { endpoint: device.endpoint }, undefined, true, true)).data,
  pushTest: (device: PushDevice) =>
    request<{ sent: boolean }>('POST', pushPath('/test'), { endpoint: device.endpoint, auth: guestAuth(device) }),
  pushAdmin: () => request<PushAdmin>('GET', '/admin/push'),
  setPushReminderHour: (reminder_hour: number) => request<PushAdmin>('PATCH', '/admin/push', { reminder_hour }),
  setBirthdayNotify: (change: { birthday_notify?: boolean; birthday_hour?: number }) => request<PushAdmin>('PATCH', '/admin/push', change),

  // Fehlermeldungen an die Betreuer der App (Issue #64), auch ohne Anmeldung
  reportForm: () => request<ReportForm>('GET', '/reports/form'),
  sendReport: (data: ReportInput) => request<{ queued: boolean }>('POST', '/reports', data),
};

/** Formular „Fehler melden“: Zeitmarke (Zeitfalle) und die Angaben, die der Server beifügt. */
export interface ReportForm {
  token: string;
  /** Frühestens so viele Sekunden nach dem Öffnen absendbar */
  minSeconds: number;
  /** Höchstgröße des Bildschirmfotos in Bytes */
  maxBytes: number;
  details: { version: string; role: string };
}

export interface ReportInput {
  token: string;
  consent: boolean;
  description: string;
  expected?: string;
  page: string;
  userAgent: string;
  lastError?: string | null;
  includeName?: boolean;
  screenshot?: { type: string; data: string } | null;
  /** Verstecktes Feld gegen Programme (bleibt leer) */
  website?: string;
}

export type PushType = 'board' | 'polls' | 'birthdays' | 'penalties' | 'points' | 'ranks' | 'titles' | 'events' | 'reminder' | 'news';

/** Angaben des Browsers zu seinem Push-Abo (PushSubscription.toJSON()). */
export interface PushDevice {
  endpoint: string;
  keys: { p256dh: string; auth: string };
}

export interface PushSubscriptionInfo {
  types: PushType[];
  details: boolean;
}

export interface PushStatus {
  supported: boolean;
  publicKey: string;
  types: { key: PushType; label: string }[];
  subscription: PushSubscriptionInfo | null;
}

export interface PushAdmin {
  supported: boolean;
  missing: string[];
  devices: number;
  accounts: number;
  guests: number;
  queued: number;
  reminderHour: number;
  /** Benachrichtigung am Geburtstag; null ohne den Bereich Geburtstage */
  birthdays: { enabled: boolean; hour: number } | null;
}

const pushPath = (suffix: string): string => (config?.user ? '/me/push' : '/push') + suffix;
// Nur Gäste weisen sich mit dem Geheimnis aus; Konten über die Anmeldung.
const guestAuth = (device: PushDevice): string | undefined => (config?.user ? undefined : device.keys.auth);

export type StatusLevel = 'ok' | 'warn' | 'error' | 'off';

export interface UpdateStatus {
  configured: boolean;
  current: string;
  channel: 'early' | 'stable';
  auto: boolean;
  checked_at: number | null;
  error: string;
  latest: string | null;
  available: boolean;
  major: boolean;
  majorVersion?: string;
  withdrawn: boolean;
  /** Bei zurückgezogener Version: Ziel des automatischen Wechsels (null = keins da). */
  fallback: string | null;
  /** Nach dem Zurückrollen: latest wird nicht automatisch eingespielt. */
  skipped: boolean;
  /** Automatische Updates sind hier erlaubt (WordPress kann sie sperren). */
  autoAllowed: boolean;
  problems: string[];
  versions: { version: string; published: string }[];
  last: { from: string; to: string; kind: string; at: number; ok: boolean; error: string } | null;
}

export interface AdminStatus {
  items: { key: string; label: string; level: StatusLevel; text: string }[];
  support: string;
  updates: UpdateStatus;
  statusReports: {
    enabled: boolean;
    available: boolean;
    payload: Record<string, unknown>;
    /** Letzte Meldung: Zeitpunkt, angenommen?, Lebenszeichen oder Ereignis */
    last: { at: number; ok: boolean; kind: 'heartbeat' | 'event' } | null;
  };
  setupDone: boolean;
  /** Fehlerprotokoll dieser Installation, neueste zuerst (bleibt auf dem Server) */
  errorLog: ErrorLogEntry[];
}

export interface ErrorLogEntry {
  /** zuletzt aufgetreten */
  at: number;
  /** zuerst aufgetreten (bei mehreren gleichen direkt hintereinander) */
  first: number;
  count: number;
  /** Fehlercode wie in den Statusmeldungen, z. B. CALENDAR_FETCH */
  code: string;
  /** Ursache wie in den Statusmeldungen, z. B. ics_http_404; leer, wenn unbekannt */
  detail: string;
  message: string;
}

export type NewsSourceType = 'none' | 'wordpress' | 'rss';

export interface AdminSettings {
  /** Gemeinsamer Google-Zugang für Galerie und Kalender: „Mit Google verbinden“ oder Dienstkonto */
  google: {
    /** Weiterleitungs-URI, die der Admin im OAuth-Client bei Google einträgt */
    redirect_uri: string;
    client_id: string;
    client_secret_set: boolean;
    connected: boolean;
    email: string;
    calendar_access: boolean;
    service_account_email: string;
    /** Ergebnis der letzten Rückkehr von Google (Unix-Zeit in at) */
    last_result: { ok: boolean; message: string; at: number } | null;
  };
  calendar: {
    /** google: eigener API-Schlüssel; google_connection: Google-Zugang (OAuth mit Kalender-Berechtigung oder Dienstkonto) */
    source: 'ics' | 'google' | 'google_connection' | 'none';
    /** Kalender-IDs ohne API-Schlüssel, aber die OAuth-Verbindung darf den Kalender nicht lesen (neu verbinden) */
    connection_needs_calendar_access: boolean;
    ics_url_set: boolean;
    google_api_key_set: boolean;
    calendar_ids: string;
    cache_minutes: number;
  };
  gallery: {
    configured: boolean;
    root: string;
    service_account_email: string;
    oauth_connected: boolean;
    oauth_email: string;
    /** Darf der Google-Zugang auch den Kalender lesen (OAuth mit calendar.readonly bzw. Dienstkonto)? */
    calendar_access: boolean;
    status: { albums?: number; photos?: number; pending?: number; failed?: number; last_sync?: number; error?: string };
  };
  news: { source: NewsSourceType; url: string } | null;
}

/** Bild der Galerie, das sich nicht aufbereiten ließ (Einstellungen → Galerie). */
export interface FailedPhoto {
  id: number;
  name: string;
  /** „Jahr · Album“ */
  album: string;
  intern: boolean;
  error: string;
  attempts: number;
  drive_url: string;
}

export interface AdminSettingsInput {
  calendar_ics_url?: string;
  google_api_key?: string;
  calendar_ids?: string;
  calendar_cache_minutes?: number;
  drive_service_account?: string;
  drive_oauth?: '';
  gallery_root?: string;
  news_source?: NewsSourceType;
  news_url?: string;
}
