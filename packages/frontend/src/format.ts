const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
const dateFmt = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
const weekdayFmt = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });
const timeFmt = new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' });
const monthFmt = new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric' });

export const formatEuro = (cents: number) => euro.format(cents / 100);

/** 'YYYY-MM-DD' als lokales Datum (ohne UTC-Verschiebung). */
export function parseLocalDate(ymd: string): Date {
  const [y, m, d] = ymd.slice(0, 10).split('-').map(Number);
  return new Date(y, m - 1, d);
}

export const formatDate = (ymd: string) => dateFmt.format(parseLocalDate(ymd));

/** Wochentag, Tag und Monat kurz, z. B. „Di., 27. Okt.“ */
export const formatWeekdayDate = (d: Date) => weekdayFmt.format(d);

/** Monat kurz ohne Punkt für das Datumsfeld links an Karten, z. B. „Okt“. */
export const shortMonth = (d: Date) => d.toLocaleDateString('de-DE', { month: 'short' }).replace('.', '');

/** Datum vom Server, das auch nur das Jahr sein kann (JJJJ-MM-TT oder JJJJ, z. B. Eintritt), zum Anzeigen. */
export const formatYearOrDate = (value: string): string => (value.length === 4 ? value : formatDate(value));

/** Dasselbe zum Bearbeiten: TT.MM.JJJJ oder das Jahr. */
export const editYearOrDate = (value: string): string => (value.length === 10 ? value.split('-').reverse().join('.') : value);

/** Zeitpunkt des Servers ('YYYY-MM-DD HH:MM:SS', UTC) als lokales Datum. */
export const formatUtcDate = (utc: string) => dateFmt.format(new Date(utc.replace(' ', 'T') + 'Z'));

export function todayYmd(): string {
  const d = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function formatPoints(points: number): string {
  return (points > 0 ? '+' : '') + points.toLocaleString('de-DE');
}

/**
 * Pluralformen der Standardbezeichnungen für Rollen. Eigene Bezeichnungen lassen sich nicht sicher beugen und
 * bleiben unverändert; Wörter auf -er oder -el (Anwärter, Verwalter, Jungmariner) heißen im Plural ohnehin gleich.
 */
const ROLE_PLURALS: Record<string, string> = { Mitglied: 'Mitglieder', Gast: 'Gäste', Admin: 'Admins' };

/** Rollenbezeichnung mit Anzahl, ab 2 (und bei 0) im Plural: „Mitglied 1“, „Mitglieder 45“. */
export const roleCount = (label: string, count: number): string => `${count === 1 ? label : (ROLE_PLURALS[label] ?? label)} ${count}`;

/**
 * Zähler je Gruppe über Mitgliederlisten in umgekehrter Reihenfolge der Rollen (Einstellungen → Rollen, von unten nach
 * oben): Bei Gast, Anwärter, Mitglied stehen die Mitglieder vor den Anwärtern. Gruppen ohne Personen fehlen.
 */
export function groupCounts(roles: { key: string; label: string }[], counts: Map<string, number>): string[] {
  return roles
    .filter((r) => (counts.get(r.key) ?? 0) > 0)
    .reverse()
    .map((r) => roleCount(r.label, counts.get(r.key) ?? 0));
}

/** "2,50" / "2.50" / "2" → 250 Cent; null bei ungültiger Eingabe. */
export function parseEuroToCents(input: string): number | null {
  const s = input.trim().replace(/\s|€/g, '').replace(',', '.');
  if (!/^\d+(\.\d{1,2})?$/.test(s)) return null;
  return Math.round(parseFloat(s) * 100);
}

/* Termine */

export function eventStart(ev: { start: string; all_day: boolean }): Date {
  return ev.all_day ? parseLocalDate(ev.start) : new Date(ev.start);
}

/** Ende inklusiv für ganztägige Termine (Google/iCal liefern exklusives Ende). */
export function eventEnd(ev: { end: string; start: string; all_day: boolean }): Date {
  if (!ev.all_day) return new Date(ev.end || ev.start);
  const d = parseLocalDate(ev.end || ev.start);
  if (ev.end && ev.end > ev.start) d.setDate(d.getDate() - 1);
  return d;
}

export function formatEventWhen(ev: { start: string; end: string; all_day: boolean }): string {
  const s = eventStart(ev);
  const e = eventEnd(ev);
  const sameDay = s.toDateString() === e.toDateString();
  if (ev.all_day) {
    // Eintägig ohne Uhrzeit: nur das Datum (ohne Zusatz „ganztägig“).
    return sameDay ? weekdayFmt.format(s) : `${weekdayFmt.format(s)} – ${weekdayFmt.format(e)}`;
  }
  if (e.getTime() <= s.getTime()) return `${weekdayFmt.format(s)} · ${timeFmt.format(s)} Uhr`;
  return sameDay
    ? `${weekdayFmt.format(s)} · ${timeFmt.format(s)}–${timeFmt.format(e)} Uhr`
    : `${weekdayFmt.format(s)}, ${timeFmt.format(s)} – ${weekdayFmt.format(e)}, ${timeFmt.format(e)} Uhr`;
}

export const formatMonth = (d: Date) => monthFmt.format(d);

/**
 * Für Texte, die per CSS in Großbuchstaben erscheinen (text-transform: uppercase):
 * Browser machen daraus sonst „SS“ (Spieß → SPIESS). Mit dem großen ẞ bleibt es „SPIEẞ“.
 */
export const caps = (s: string) => s.replace(/ß/g, 'ẞ');

const newsDateFmt = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });

/** Datum eines Beitrags; leer, wenn die Quelle keines oder ein unlesbares liefert. */
export function newsDate(iso: string): string {
  const t = iso ? Date.parse(iso) : NaN;
  return Number.isNaN(t) ? '' : newsDateFmt.format(new Date(t));
}

/** Dateigröße, z. B. „1,2 MB“. */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`;
}

/** Zeitpunkt (ISO 8601) kurz: heute „14:05 Uhr“, sonst „Mo., 19. Okt., 14:05 Uhr“. */
export function formatShortTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const time = `${timeFmt.format(d)} Uhr`;
  return d.toDateString() === new Date().toDateString() ? time : `${weekdayFmt.format(d)}, ${time}`;
}

/**
 * Zeitpunkt (ISO 8601) als Wert für ein Feld datetime-local in der Ortszeit des Geräts („JJJJ-MM-TTTHH:MM“); leer bei
 * null. Für Umfragen (Ende) und das Schwarze Brett (Sichtbar bis).
 */
export function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Eingabe eines Felds datetime-local (Ortszeit des Geräts, ohne Zeitzone) als eindeutiger Zeitpunkt in UTC
 * („…Z“); leer = null. Ungültiges geht unverändert an den Server, der es ablehnt.
 */
export function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toISOString();
}

const yearDateFmt = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

/** Wie formatShortTime, aber in einem anderen Jahr mit Jahreszahl („Mo., 19. Okt. 2027, 14:05 Uhr“), z. B. „Sichtbar bis“. */
export function formatUntil(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.getFullYear() === new Date().getFullYear() ? formatShortTime(iso) : `${yearDateFmt.format(d)}, ${timeFmt.format(d)} Uhr`;
}
