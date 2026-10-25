/**
 * Handynummern eingeben: Ländervorwahl aus einer Auswahl, die Nummer daneben wie gewohnt (0171 1234567). Gespeichert wird
 * einheitlich international (+49 171 1234567). Der Server prüft danach noch einmal (MemberService::phone: Ziffern,
 * Leerzeichen und + ( ) / -, 5–20 Ziffern, höchstens 30 Zeichen).
 */

/** Wie MemberService::PHONE_MAX auf dem Server. */
export const PHONE_MAX = 30;

/**
 * Länder in der Auswahl, das erste ist voreingestellt. trunk: Die 0 am Anfang wählt man nur im Inland, international fällt
 * sie weg (in Italien gehört sie zur Nummer, in Polen, Spanien, Dänemark, Luxemburg und Tschechien gibt es sie nicht).
 */
export const COUNTRIES = [
  { code: '49', name: 'Deutschland', flag: '🇩🇪', trunk: true },
  { code: '43', name: 'Österreich', flag: '🇦🇹', trunk: true },
  { code: '41', name: 'Schweiz', flag: '🇨🇭', trunk: true },
  { code: '31', name: 'Niederlande', flag: '🇳🇱', trunk: true },
  { code: '32', name: 'Belgien', flag: '🇧🇪', trunk: true },
  { code: '352', name: 'Luxemburg', flag: '🇱🇺', trunk: false },
  { code: '33', name: 'Frankreich', flag: '🇫🇷', trunk: true },
  { code: '45', name: 'Dänemark', flag: '🇩🇰', trunk: false },
  { code: '48', name: 'Polen', flag: '🇵🇱', trunk: false },
  { code: '420', name: 'Tschechien', flag: '🇨🇿', trunk: false },
  { code: '39', name: 'Italien', flag: '🇮🇹', trunk: false },
  { code: '34', name: 'Spanien', flag: '🇪🇸', trunk: false },
  { code: '44', name: 'Großbritannien', flag: '🇬🇧', trunk: true },
  { code: '90', name: 'Türkei', flag: '🇹🇷', trunk: true },
];

/** Vorwahl in der Auswahl für „anderes Land“: Die Nummer steht dann mit + im Feld. */
export const OTHER = '';

/** Während des Tippens: nur Zeichen, die in einer Telefonnummer vorkommen. */
export function tidyPhone(input: string): string {
  return input.replace(/[^\d+ ()/-]/g, '');
}

/** Bekannte Ländervorwahl am Anfang einer internationalen Nummer (nur Ziffern). */
function knownCode(intl: string) {
  return COUNTRIES.find((c) => intl.startsWith(c.code));
}

/** Ziffern einer Nummer mit + oder 00 am Anfang, sonst null. */
function international(s: string): string | null {
  const digits = s.replace(/\(0\)/g, '').replace(/\D/g, '');
  if (s.trim().startsWith('+')) return digits;
  if (digits.startsWith('00')) return digits.slice(2);
  return null;
}

/**
 * Nummer aus Auswahl und Feld: 0171/1234567 mit +49 wird +49 171 1234567. Steht im Feld schon eine internationale Nummer
 * (+31 …, 0031 …), gilt sie statt der Auswahl. Leer bleibt leer; ohne Land bleibt die Eingabe, wie sie ist.
 */
export function composePhone(code: string, national: string): string {
  const s = tidyPhone(national).trim();
  if (s === '') return '';
  const intl = international(s);
  if (intl !== null) return format(intl);
  if (code === OTHER) return s.replace(/\s+/g, ' ');
  let digits = s.replace(/\D/g, '');
  if (COUNTRIES.find((c) => c.code === code)?.trunk && digits.startsWith('0')) digits = digits.slice(1);
  return format(code + digits);
}

/**
 * Gespeicherte Nummer für Auswahl und Feld zerlegen. Ältere Einträge ohne Ländervorwahl (0171 …) stehen beim ersten Land
 * der Liste; wer die Nummer so speichert, sieht es an der Auswahl.
 */
export function splitPhone(value: string): { code: string; national: string } {
  const s = tidyPhone(value).trim();
  const intl = international(s);
  if (intl === null) return { code: s === '' || /^\d/.test(s) || s.startsWith('(') ? COUNTRIES[0].code : OTHER, national: s };
  const c = knownCode(intl);
  if (!c) return { code: OTHER, national: '+' + intl };
  return { code: c.code, national: format(intl).slice(c.code.length + 2) };
}

/** Gespeicherte oder eingegebene Nummer einheitlich schreiben (ohne Ländervorwahl: mit dem ersten Land der Liste). */
export function normalizePhone(value: string): string {
  const { code, national } = splitPhone(value);
  return composePhone(code, national);
}

/**
 * Leerzeichen nach der Ländervorwahl und bei deutschen Handynummern nach der Netzvorwahl: 015x-Nummern haben vier Stellen
 * (1512), 016x und 017x drei (171).
 */
function format(intl: string): string {
  const c = knownCode(intl);
  if (!c) return '+' + intl;
  const rest = intl.slice(c.code.length);
  if (c.code === '49' && /^1[5-7]/.test(rest)) {
    const net = rest.startsWith('15') ? 4 : 3;
    if (rest.length > net) return `+49 ${rest.slice(0, net)} ${rest.slice(net)}`;
  }
  return `+${c.code} ${rest}`;
}

/** Was an der (zusammengesetzten) Nummer nicht stimmt, sonst null; leer ist erlaubt. */
export function phoneProblem(value: string): string | null {
  const s = value.trim();
  if (s === '') return null;
  if (!s.startsWith('+')) return 'Bitte die Nummer mit + und Ländervorwahl eingeben, z. B. +31 6 12345678.';
  const digits = s.replace(/\D/g, '');
  // Internationale Nummern haben höchstens 15 Ziffern (ITU-T E.164), die kürzesten etwa 7.
  if (digits.length < 7) return 'Die Nummer ist zu kurz.';
  if (digits.length > 15 || s.length > PHONE_MAX) return 'Die Nummer hat zu viele Ziffern.';
  return null;
}

/**
 * Hinweis ohne Sperre: Deutsche Handynummern (015x, 016x, 017x) haben nach der +49 10 oder 11 Ziffern; meist fehlt eine
 * oder ist doppelt.
 */
export function phoneHint(value: string): string | null {
  if (!/^\+49 1[5-7]/.test(value) || phoneProblem(value)) return null;
  const n = value.replace(/\D/g, '').length - 2;
  return n === 10 || n === 11 ? null : 'Deutsche Handynummern haben ohne die 0 am Anfang 10 oder 11 Ziffern. Bitte prüfen.';
}
