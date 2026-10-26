/**
 * E-Mail-Adressen eingeben: Tippfehler und typische Handy-Eingaben glätten, prüfen und bei vertippten Anbietern einen
 * Vorschlag machen. Der Server prüft danach noch einmal (FILTER_VALIDATE_EMAIL, höchstens 100 Zeichen).
 *
 * Nur der Teil hinter dem @ wird kleingeschrieben: Domains unterscheiden keine Groß-/Kleinschreibung (RFC 4343), der Teil
 * davor darf es laut RFC 5321 (Abschnitt 2.4).
 */

/** Wie MemberService::EMAIL_MAX auf dem Server. */
export const EMAIL_MAX = 100;

/**
 * Während des Tippens: nur, was den Rest der Eingabe nicht stört. Leerzeichen raus (Wortvorschläge der Handytastatur
 * hängen eins an), ＠ der Vollbreiten-Tastatur wird @, hinter dem @ Komma zu Punkt und klein.
 */
export function tidyEmail(input: string): string {
  const s = input.replace(/\s+/g, '').replace(/＠/g, '@');
  const at = s.lastIndexOf('@');
  if (at < 0) return s;
  return (
    s.slice(0, at + 1) +
    s
      .slice(at + 1)
      .replace(/,/g, '.')
      .toLowerCase()
  );
}

/**
 * Nach der Eingabe (Feld verlassen, Speichern): zusätzlich „mailto:“ und „Name <adresse>“ aus kopiertem Text entfernen,
 * doppelte Punkte und Punkte am Rand der Domain weg.
 */
export function normalizeEmail(input: string): string {
  let s = input.trim();
  const angle = /<([^<>]+)>/.exec(s);
  if (angle) s = angle[1];
  s = tidyEmail(s).replace(/^mailto:/i, '');
  const at = s.lastIndexOf('@');
  if (at < 0) return s;
  const domain = s
    .slice(at + 1)
    .replace(/\.{2,}/g, '.')
    .replace(/^\.+|\.+$/g, '');
  return s.slice(0, at + 1) + domain;
}

const LOCAL = /^[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+)*$/;
const LABEL = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/;

/** Was an der (schon geglätteten) Adresse nicht stimmt, sonst null; leer ist erlaubt (die Felder sind optional). */
export function emailProblem(input: string): string | null {
  const s = normalizeEmail(input);
  if (s === '') return null;
  const at = s.lastIndexOf('@');
  if (at < 0) return 'Es fehlt das @.';
  const local = s.slice(0, at);
  const domain = s.slice(at + 1).toLowerCase();
  if (local === '') return 'Vor dem @ fehlt der Name.';
  if (local.includes('@')) return 'Bitte nur ein @ verwenden.';
  if (domain === '') return 'Hinter dem @ fehlt der Anbieter, z. B. gmx.de.';
  if (!domain.includes('.')) return `Hinter „${domain}“ fehlt die Endung, z. B. .de oder .com.`;
  if (s.length > EMAIL_MAX) return `Die Adresse ist zu lang (höchstens ${EMAIL_MAX} Zeichen).`;
  const labels = domain.split('.');
  if (!LOCAL.test(local) || !labels.every((l) => LABEL.test(l)) || !/^[a-z]{2,}$/.test(labels[labels.length - 1])) {
    return 'Die E-Mail-Adresse ist ungültig.';
  }
  return null;
}

/** Häufige Anbieter; eine Domain, die um einen Buchstaben davon abweicht, ist wahrscheinlich vertippt. */
const PROVIDERS = [
  'gmail.com',
  'googlemail.com',
  'gmx.de',
  'gmx.net',
  'gmx.at',
  'gmx.ch',
  'web.de',
  't-online.de',
  'outlook.de',
  'outlook.com',
  'hotmail.de',
  'hotmail.com',
  'live.de',
  'live.com',
  'yahoo.de',
  'yahoo.com',
  'icloud.com',
  'freenet.de',
  'posteo.de',
  'mailbox.org',
  'arcor.de',
];

/**
 * Vorschlag für eine vertippte Anbieter-Domain (z. B. gmial.com → gmail.com), sonst null. Nur ein Vorschlag: Eine echte
 * Firmen-Domain kann zufällig ähnlich aussehen.
 */
export function emailSuggestion(input: string): string | null {
  const s = normalizeEmail(input);
  const at = s.lastIndexOf('@');
  if (at < 0) return null;
  const domain = s.slice(at + 1);
  // Kurze Domains liegen zu oft zufällig nah an einem Anbieter.
  if (domain.length < 6 || PROVIDERS.includes(domain)) return null;
  const close = PROVIDERS.filter((p) => distance(domain, p) === 1);
  return close.length === 1 ? s.slice(0, at + 1) + close[0] : null;
}

/** Abstand nach Damerau-Levenshtein (eingeschränkt): Einfügen, Löschen, Ersetzen und Vertauschen zweier Nachbarn. */
function distance(a: string, b: string): number {
  const d: number[][] = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array<number>(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) {
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
    }
  }
  return d[a.length][b.length];
}
