// Zugriff auf die API der Kompanie-App (siehe api/openapi.yaml), unabhängig davon,
// ob sie von WordPress oder der eigenständigen Version kommt.

import brand from './generated/brand';

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
  | 'board';

export interface AppUser {
  id: number;
  name: string;
  login: string;
  level: Level;
  capabilities: Capability[];
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
  /** Rollen der Installation (Einstellungen → Rollen): Bezeichnung und Punktefaktor */
  roles?: RoleInfo[];
  /** null = Gast ohne Anmeldung. */
  user: AppUser | null;
  /** Nur für Admins: Die Einrichtung ist noch nicht abgeschlossen (Assistent anbieten). */
  setupPending?: boolean;
  /** Wartungsmodus (Issue #42): an, Begründung (Klartext), seit (Unix-Zeit) */
  maintenance?: MaintenanceInfo;
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
}

/** Für Einstellungen → Rollen (nur Admins). */
export interface RoleAdmin extends RoleInfo {
  capabilities: Capability[];
  users: number;
  system: boolean;
  deletable: boolean;
}

export type RoleInput = { label?: string; capabilities?: Capability[]; points_factor?: number | string };

export const roleInfo = (level: Level): RoleInfo | undefined => config?.roles?.find((r) => r.key === level);

export const levelLabel = (level: Level): string =>
  roleInfo(level)?.label ?? (brand.labels.roles as Record<string, string | undefined>)[level] ?? level;

/** Punktefaktor der Rolle (1 ohne Angabe). */
export const roleFactor = (level: Level): number => roleInfo(level)?.points_factor ?? 1;

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
  config.roles = list.map(({ key, label, points_factor }) => ({ key, label, points_factor }));
  window.dispatchEvent(new Event(CONFIG_CHANGED));
}

/** Darf der angemeldete Benutzer das? */
export const can = (cap: Capability): boolean => !!config?.user?.capabilities.includes(cap);

export const isMember = (): boolean => can('member');

export interface Me {
  id: number;
  name: string;
  level: Level;
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
  /** false: noch nicht aufbereitet, die Adressen liefern vorerst einen Platzhalter */
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
  name: string;
  level: Level;
  /** Mit Mitgliederbereich; false bei Anwärtern, Rollen nur mit Kalender und Gästen */
  member: boolean;
  /** Dienstgrad (nur mit Bereich und Recht „Dienstgrade verwalten“) */
  rank_title?: string | null;
  /** Abzeichen des Dienstgrads (wie rank_title) */
  rank_badge_url?: string | null;
  /** Titel (nur mit Bereich und Recht „Titel verwalten“) */
  titles?: TitleRef[];
  /** null: Punkte bzw. Strafen sieht nur, wer sie vergeben darf */
  points: number | null;
  open_cents: number | null;
}

export interface MemberDetail extends MemberSummary {
  point_list: PointEntry[];
  penalties: Penalty[];
  /** Dienstgrad und Verlauf (nur mit Bereich und Recht „Dienstgrade verwalten“) */
  rank?: RankStatus;
  rank_history?: RankHistoryEntry[];
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

/**
 * Eintrag am Schwarzen Brett (Issue #5). kind: heute nur „message“; später können weitere Arten dazukommen (z. B.
 * laufende Umfragen), die App überspringt unbekannte.
 */
export interface BoardItem {
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

/** Datei als { name, data (Base64) } für die API (beide Adapter nehmen nur JSON an). */
async function encodeFile(file: File): Promise<{ name: string; data: string }> {
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
}

export interface TitleRef {
  id: number;
  title: string;
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
  username: string;
  email: string;
  level: Level;
  /** Konto angelegt, aber Anfangspunkte bzw. Dienstgrad nicht übernommen */
  warning?: string;
  /** Startpasswort – steht nur in dieser einen Antwort. */
  password: string;
}

/** Ergebnis je Person beim Anlegen mehrerer (Issue #24): angelegt oder mit Grund nicht. */
export type BulkResult = CreatedUser | { name: string; username: string; error: string };

export interface NewPerson {
  name: string;
  username?: string;
  email?: string;
  initial_points?: number;
  initial_points_date?: string;
  rank_id?: number;
}

export interface AccountProfile {
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
  try {
    res = await fetch(url(path, query), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(csrfToken ? { [config.csrf.header]: csrfToken } : {}),
        ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError('Keine Verbindung. Bitte später erneut versuchen.', 0);
  }

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
    throw new ApiError(data?.message ?? `Fehler ${res.status}`, res.status, data?.code ?? '');
  }
  return { data: data as T, headers: res.headers };
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

  members: (all = false, candidates = false) =>
    request<MemberSummary[]>('GET', '/members', undefined, {
      ...(all ? { all: '1' } : {}),
      ...(candidates ? { candidates: '1' } : {}),
    }),
  /** Anwärter zum Mitglied machen (wer Mitglieder anlegen darf). */
  promote: (id: number) => request<{ id: number; level: Level }>('POST', `/members/${id}/promote`),
  setRole: (id: number, level: Level) => request<{ id: number; level: Level }>('PATCH', `/members/${id}/role`, { level }),
  member: (id: number) => request<MemberDetail>('GET', `/members/${id}`),
  resetPassword: (id: number) => request<{ password: string }>('POST', `/members/${id}/password`),
  /** Ändert das eigene Passwort; danach gilt eine neue Sitzung, daher Token erneuern. */
  changePassword: async (current_password: string, new_password: string) => {
    await request<{ changed: boolean }>('POST', '/me/password', { current_password, new_password });
    await refreshCsrf();
  },
  createUsers: (level: 'member' | 'candidate', people: NewPerson[]) => request<BulkResult[]>('POST', '/users/bulk', { level, people }),
  account: (id: number) => request<AccountProfile>('GET', `/members/${id}/account`),
  updateAccount: (id: number, data: { name?: string; email?: string; username?: string }) =>
    request<AccountProfile>('PATCH', `/members/${id}/account`, data),
  deleteAccount: (id: number) => request<{ deleted: boolean }>('DELETE', `/members/${id}`),
  createUser: (data: {
    name: string;
    username?: string;
    email?: string;
    level?: 'member' | 'candidate';
    initial_points?: number;
    initial_points_date?: string;
    rank_id?: number;
  }) => request<CreatedUser>('POST', '/users', data),

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
  postBoard: (text: string) => request<BoardItem>('POST', '/board', { text }),
  updateBoard: (id: number, text: string) => request<BoardItem>('PATCH', `/board/${id}`, { text }),
  deleteBoard: (id: number) => request<{ deleted: boolean }>('DELETE', `/board/${id}`),

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
  testCalendar: () => request<{ count: number; sample: { start: string; title: string }[] }>('POST', '/admin/calendar/test'),
  testNews: () => request<{ count: number }>('POST', '/admin/news/test'),
  syncGallery: (onlyProcess = false) =>
    request<{ albums: number; photos: number; processed: number; pending: number; failed: number; error: string }>(
      'POST',
      '/admin/gallery/sync',
      { only_process: onlyProcess },
    ),

  status: () => request<AdminStatus>('GET', '/admin/status'),
  checkUpdates: () => request<UpdateStatus>('POST', '/admin/update/check'),
  installUpdate: (version: string, major = false) => request<{ installed: string }>('POST', '/admin/update/install', { version, major }),
  rollback: (version: string) => request<{ installed: string }>('POST', '/admin/update/rollback', { version }),
  updateOptions: (data: { auto?: boolean; status_reports?: boolean }) => request<UpdateStatus>('PATCH', '/admin/update', data),
  backup: () => request<Record<string, unknown>>('GET', '/admin/backup'),
  restoreBackup: (backup: string) => request<{ restored: boolean }>('POST', '/admin/backup', { backup, confirm: true }),
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
};

export type PushType = 'board' | 'penalties' | 'points' | 'ranks' | 'titles' | 'events' | 'reminder' | 'news';

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
}

export type NewsSourceType = 'none' | 'wordpress' | 'rss';

export interface AdminSettings {
  calendar: {
    source: 'ics' | 'google' | 'none';
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
    status: { albums?: number; photos?: number; pending?: number; last_sync?: number; error?: string };
  };
  news: { source: NewsSourceType; url: string } | null;
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
