// Zugriff auf die API der Kompanie-App (siehe api/openapi.yaml), unabhängig davon,
// ob sie von WordPress oder der eigenständigen Version kommt.

import brand from './generated/brand';

export type Level = 'admin' | 'manager' | 'member' | 'guest';
export type Capability = 'member' | 'manage' | 'manage_events' | 'create_users' | 'promote_users' | 'manage_settings';
export type Feature = 'events' | 'calendar' | 'news' | 'gallery' | 'points' | 'penalties';

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
  /** null = Gast ohne Anmeldung. */
  user: AppUser | null;
  /** Nur für Admins: Die Einrichtung ist noch nicht abgeschlossen (Assistent anbieten). */
  setupPending?: boolean;
}

declare global {
  interface Window {
    KOMPANIE_CONFIG?: AppConfig;
  }
}

export const config: AppConfig | undefined = window.KOMPANIE_CONFIG;

export const levelLabel = (level: Level): string => brand.labels.roles[level] ?? level;

/** Darf der angemeldete Benutzer das? */
export const can = (cap: Capability): boolean => !!config?.user?.capabilities.includes(cap);

export const isMember = (): boolean => can('member');

export interface Me {
  id: number;
  name: string;
  level: Level;
  points: number;
  open_cents: number;
}

export interface PointEntry {
  id: number;
  user_id: number;
  points: number;
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
  points: number;
  open_cents: number;
}

export interface MemberDetail extends MemberSummary {
  point_list: PointEntry[];
  penalties: Penalty[];
}

export interface CreatedUser {
  id: number;
  name: string;
  username: string;
  email: string;
  /** Startpasswort – steht nur in dieser einen Antwort. */
  password: string;
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

/** Holt ein neues CSRF-Token (nur mit dem Sitzungs-Cookie). */
export async function refreshCsrf(): Promise<void> {
  let res: Response;
  try {
    res = await fetch(config!.csrf.refreshUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch {
    throw new ApiError('Keine Verbindung. Bitte später erneut versuchen.', 0);
  }
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.token) {
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
    await refreshCsrf();
    return requestFull<T>(method, path, body, query, false);
  }
  if (res.status === 401 && config.user) {
    sessionExpired();
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

  members: (all = false) => request<MemberSummary[]>('GET', '/members', undefined, all ? { all: '1' } : undefined),
  setRole: (id: number, level: Level) => request<{ id: number; level: Level }>('PATCH', `/members/${id}/role`, { level }),
  member: (id: number) => request<MemberDetail>('GET', `/members/${id}`),
  resetPassword: (id: number) => request<{ password: string }>('POST', `/members/${id}/password`),
  /** Ändert das eigene Passwort; danach gilt eine neue Sitzung, daher Token erneuern. */
  changePassword: async (current_password: string, new_password: string) => {
    await request<{ changed: boolean }>('POST', '/me/password', { current_password, new_password });
    await refreshCsrf();
  },
  createUser: (data: { name: string; username?: string; email?: string }) => request<CreatedUser>('POST', '/users', data),

  givePoints: (data: { user_ids: number[]; points?: number; catalog_id?: number; reason?: string; date?: string }) =>
    request<PointEntry[]>('POST', '/points', data),
  deletePoints: (id: number) => request<{ deleted: boolean }>('DELETE', `/points/${id}`),

  givePenalty: (data: { user_ids: number[]; amount_cents: number; reason: string; date?: string }) =>
    request<Penalty[]>('POST', '/penalties', data),
  setPenaltyStatus: (id: number, status: 'open' | 'paid') => request<Penalty>('PATCH', `/penalties/${id}`, { status }),
  deletePenalty: (id: number) => request<{ deleted: boolean }>('DELETE', `/penalties/${id}`),

  settings: () => request<AdminSettings>('GET', '/admin/settings'),
  updateSettings: (data: AdminSettingsInput) => request<AdminSettings>('PATCH', '/admin/settings', data),
  testCalendar: () => request<{ count: number; sample: { start: string; title: string }[] }>('POST', '/admin/calendar/test'),
  testNews: () => request<{ count: number }>('POST', '/admin/news/test'),
  syncGallery: (onlyProcess = false) =>
    request<{ albums: number; photos: number; processed: number; pending: number; failed: number; error: string }>('POST', '/admin/gallery/sync', { only_process: onlyProcess }),

  status: () => request<AdminStatus>('GET', '/admin/status'),
  checkUpdates: () => request<UpdateStatus>('POST', '/admin/update/check'),
  installUpdate: (version: string, major = false) => request<{ installed: string }>('POST', '/admin/update/install', { version, major }),
  rollback: (version: string) => request<{ installed: string }>('POST', '/admin/update/rollback', { version }),
  updateOptions: (data: { auto?: boolean; status_reports?: boolean }) => request<UpdateStatus>('PATCH', '/admin/update', data),
  backup: () => request<Record<string, unknown>>('GET', '/admin/backup'),
  restoreBackup: (backup: string) => request<{ restored: boolean }>('POST', '/admin/backup', { backup, confirm: true }),
  setupDone: () => request<{ done: boolean }>('POST', '/admin/setup/done', {}),
};

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
  problems: string[];
  versions: { version: string; published: string }[];
  last: { from: string; to: string; kind: string; at: number; ok: boolean; error: string } | null;
}

export interface AdminStatus {
  items: { key: string; label: string; level: StatusLevel; text: string }[];
  support: string;
  updates: UpdateStatus;
  statusReports: { enabled: boolean; available: boolean; payload: Record<string, unknown> };
  setupDone: boolean;
}

export type NewsSourceType = 'none' | 'wordpress' | 'rss';

export interface AdminSettings {
  calendar: { source: 'ics' | 'google' | 'none'; ics_url_set: boolean; google_api_key_set: boolean; calendar_ids: string; cache_minutes: number };
  gallery: {
    configured: boolean;
    root: string;
    service_account_email: string;
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
  gallery_root?: string;
  news_source?: NewsSourceType;
  news_url?: string;
}
