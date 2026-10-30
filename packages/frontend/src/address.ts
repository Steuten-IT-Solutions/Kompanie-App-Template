/**
 * Postanschriften eingeben: Land aus einer Auswahl (dieselben Länder wie bei der Ländervorwahl in phone.ts), Straße und
 * Hausnummer, Zusatz, PLZ und Ort. Beim Verlassen eines Felds wird geglättet (PLZ wie im Land üblich, Hausnummer aus der
 * Straße in ihr Feld), Fehler sperren das Speichern, Hinweise nicht. Der Server prüft danach noch einmal mit denselben
 * Regeln (Domain\Address).
 */

export interface Address {
  street: string;
  house_number: string;
  extra: string;
  postal_code: string;
  city: string;
  /** ISO 3166-1 alpha-2 (DE); '' = anderes Land, dann country_name */
  country: string;
  country_name: string;
}

/** Wie Address::STREET_MAX usw. auf dem Server */
export const STREET_MAX = 100;
export const HOUSE_NUMBER_MAX = 10;
export const CITY_MAX = 60;
export const COUNTRY_NAME_MAX = 60;

/** Anderes Land (nicht in der Auswahl) */
export const OTHER_COUNTRY = '';

/**
 * Länder der Auswahl, das erste ist voreingestellt; Reihenfolge wie COUNTRIES in phone.ts. Muster der Postleitzahl nach
 * dem Vereinheitlichen, wie Address::COUNTRIES auf dem Server (Wikipedia: List of postal codes).
 */
export const ADDRESS_COUNTRIES = [
  { code: 'DE', name: 'Deutschland', flag: '🇩🇪', postal: /^(0[1-9]|[1-9]\d)\d{3}$/, example: '12345', format: '5 Ziffern' },
  { code: 'AT', name: 'Österreich', flag: '🇦🇹', postal: /^[1-9]\d{3}$/, example: '1010', format: '4 Ziffern' },
  { code: 'CH', name: 'Schweiz', flag: '🇨🇭', postal: /^[1-9]\d{3}$/, example: '8001', format: '4 Ziffern' },
  {
    code: 'NL',
    name: 'Niederlande',
    flag: '🇳🇱',
    postal: /^[1-9]\d{3} [A-Z]{2}$/,
    example: '1234 AB',
    format: '4 Ziffern und 2 Buchstaben',
  },
  { code: 'BE', name: 'Belgien', flag: '🇧🇪', postal: /^[1-9]\d{3}$/, example: '1000', format: '4 Ziffern' },
  { code: 'LU', name: 'Luxemburg', flag: '🇱🇺', postal: /^\d{4}$/, example: '1234', format: '4 Ziffern' },
  { code: 'FR', name: 'Frankreich', flag: '🇫🇷', postal: /^\d{5}$/, example: '75001', format: '5 Ziffern' },
  { code: 'DK', name: 'Dänemark', flag: '🇩🇰', postal: /^\d{4}$/, example: '1050', format: '4 Ziffern' },
  { code: 'PL', name: 'Polen', flag: '🇵🇱', postal: /^\d{2}-\d{3}$/, example: '00-950', format: '2 und 3 Ziffern' },
  { code: 'CZ', name: 'Tschechien', flag: '🇨🇿', postal: /^[1-7]\d{2} \d{2}$/, example: '110 00', format: '5 Ziffern' },
  { code: 'IT', name: 'Italien', flag: '🇮🇹', postal: /^\d{5}$/, example: '00118', format: '5 Ziffern' },
  { code: 'ES', name: 'Spanien', flag: '🇪🇸', postal: /^(0[1-9]|[1-4]\d|5[0-2])\d{3}$/, example: '28001', format: '5 Ziffern' },
  {
    code: 'GB',
    name: 'Großbritannien',
    flag: '🇬🇧',
    postal: /^[A-Z]{1,2}\d[A-Z\d]? \d[A-Z]{2}$/,
    example: 'SW1A 1AA',
    format: 'z. B. SW1A 1AA',
  },
  { code: 'TR', name: 'Türkei', flag: '🇹🇷', postal: /^(0[1-9]|[1-7]\d|8[01])\d{3}$/, example: '06100', format: '5 Ziffern' },
];

export const EMPTY_ADDRESS: Address = {
  street: '',
  house_number: '',
  extra: '',
  postal_code: '',
  city: '',
  country: ADDRESS_COUNTRIES[0].code,
  country_name: '',
};

const country = (code: string) => ADDRESS_COUNTRIES.find((c) => c.code === code);

/** Hausnummer: Ziffern, dahinter ein oder zwei Buchstaben, auch als Bereich (12-14, 3/1); wie Address::HOUSE_NUMBER */
const HOUSE_NUMBER = String.raw`\d{1,5} ?[A-Za-z]{0,2}(?: ?[-/] ?\d{1,5} ?[A-Za-z]?)?`;
const TEXT = /^[\p{L}\p{M}0-9 .,\-'’/()&]+$/u;
const CITY = /^[\p{L}\p{M}0-9 .\-'’()/]+$/u;
const COUNTRY_NAME = /^[\p{L}\p{M} .\-'’()]+$/u;

/** Leerzeichen zusammenfassen, außen weg (wie Text::line) */
const line = (s: string) => s.replace(/\s+/g, ' ').trim();

/** Alle Felder leer (das Land zählt nicht): keine Adresse */
export function isEmptyAddress(a: Address | null | undefined): boolean {
  return !a || [a.street, a.house_number, a.extra, a.postal_code, a.city].every((v) => v.trim() === '');
}

/** Hausnummer ohne Leerzeichen um - und / */
const houseNumber = (s: string) => line(s).replace(/ ?([-/]) ?/g, '$1');

/**
 * Postleitzahl einheitlich wie Address::postalCode: groß, Länderkennung davor weg (D-12345, L-1234), Leerzeichen wie im
 * Land üblich (1234 AB, 110 00, SW1A 1AA), in Polen mit Bindestrich (00-950).
 */
export function normalizePostalCode(input: string, code: string): string {
  let s = input.trim().toUpperCase();
  if (code === OTHER_COUNTRY) return s.replace(/\s+/g, ' ');
  s = s.replace(/^[A-Z]{1,3} ?- ?(?=\d)/, '').replace(/\s+/g, '');
  if (code === 'NL' && /^\d{4}[A-Z]{2}$/.test(s)) return `${s.slice(0, 4)} ${s.slice(4)}`;
  if (code === 'CZ' && /^\d{5}$/.test(s)) return `${s.slice(0, 3)} ${s.slice(3)}`;
  if (code === 'PL' && /^\d{5}$/.test(s)) return `${s.slice(0, 2)}-${s.slice(2)}`;
  if (code === 'GB' && s.length >= 5) return `${s.slice(0, -3)} ${s.slice(-3)}`;
  return s;
}

/**
 * Nach der Eingabe (Feld verlassen, Speichern): Leerzeichen glätten, PLZ vereinheitlichen. Steht die Hausnummer mit in
 * der Straße („Hauptstraße 5a“) und ist ihr Feld leer, kommt sie dorthin; steht im Ort auch die PLZ („12345 Musterstadt“)
 * und ist ihr Feld leer, ebenso.
 */
export function normalizeAddress(a: Address): Address {
  let street = line(a.street);
  let number = houseNumber(a.house_number);
  const m = number === '' ? new RegExp(String.raw`^(.*\p{L}.*?)\s+(${HOUSE_NUMBER})$`, 'u').exec(street) : null;
  if (m) {
    street = m[1];
    number = houseNumber(m[2]);
  }
  let city = line(a.city);
  let postal = a.postal_code;
  const p = postal.trim() === '' ? /^(\d[\d -]{2,8}\d)\s+(\D.*)$/.exec(city) : null;
  if (p) {
    postal = p[1];
    city = p[2];
  }
  return {
    street,
    house_number: number,
    extra: line(a.extra),
    postal_code: normalizePostalCode(postal, a.country),
    city,
    country: a.country,
    country_name: a.country === OTHER_COUNTRY ? line(a.country_name) : '',
  };
}

export type AddressField = 'street' | 'house_number' | 'extra' | 'postal_code' | 'city' | 'country_name';

/**
 * Was an der (geglätteten) Adresse nicht stimmt, je Feld; leer ist erlaubt (keine Adresse). Dieselben Regeln wie
 * Address::check auf dem Server.
 */
export function addressProblems(input: Address): Partial<Record<AddressField, string>> {
  const a = normalizeAddress(input);
  const out: Partial<Record<AddressField, string>> = {};
  if (isEmptyAddress(a)) return out;
  if (a.street === '') out.street = 'Bitte die Straße angeben.';
  else if (a.street.length > STREET_MAX || !TEXT.test(a.street) || !/\p{L}/u.test(a.street)) {
    out.street = "Erlaubt sind Buchstaben, Ziffern, Leerzeichen und . , - ' / ( ) &.";
  }
  if (a.house_number !== '' && (a.house_number.length > HOUSE_NUMBER_MAX || !new RegExp(`^${HOUSE_NUMBER}$`).test(a.house_number))) {
    out.house_number = 'Bitte z. B. 12, 12a oder 12-14.';
  }
  if (a.extra !== '' && (a.extra.length > STREET_MAX || !TEXT.test(a.extra))) {
    out.extra = "Erlaubt sind Buchstaben, Ziffern, Leerzeichen und . , - ' / ( ) &.";
  }
  const c = country(a.country);
  if (a.postal_code === '') out.postal_code = 'Bitte die Postleitzahl angeben.';
  else if (c && !c.postal.test(a.postal_code)) out.postal_code = `Passt nicht zu ${c.name} (${c.format}, z. B. ${c.example}).`;
  else if (!c && !/^[A-Z0-9][A-Z0-9 -]{0,10}[A-Z0-9]$/.test(a.postal_code)) {
    out.postal_code = '2 bis 12 Zeichen aus Buchstaben, Ziffern, Leerzeichen und -.';
  }
  if (a.city === '') out.city = 'Bitte den Ort angeben.';
  else if (a.city.length > CITY_MAX || !CITY.test(a.city) || !/\p{L}.*\p{L}/u.test(a.city)) {
    out.city = "Erlaubt sind Buchstaben, Leerzeichen und . - ' ( ) /.";
  }
  if (
    a.country === OTHER_COUNTRY &&
    (a.country_name.length < 2 || a.country_name.length > COUNTRY_NAME_MAX || !COUNTRY_NAME.test(a.country_name))
  ) {
    out.country_name = 'Bitte das Land angeben.';
  }
  return out;
}

/** Hinweise ohne Sperre: wahrscheinlich vergessen oder vertippt. */
export function addressHints(input: Address): Partial<Record<AddressField, string>> {
  const a = normalizeAddress(input);
  const out: Partial<Record<AddressField, string>> = {};
  if (isEmptyAddress(a)) return out;
  if (a.street !== '' && a.house_number === '' && !/\d/.test(a.street)) out.house_number = 'Hausnummer vergessen?';
  if (/\d/.test(a.city)) out.city = 'Im Ort stehen Ziffern. Gehören sie zur Postleitzahl?';
  return out;
}

/** Adresse wie auf einem Brief, Zeile für Zeile (ohne Land bei Deutschland). */
export function formatAddress(a: Address | null | undefined): string[] {
  if (!a || isEmptyAddress(a)) return [];
  const lines = [line(`${a.street} ${a.house_number}`)];
  if (a.extra) lines.push(a.extra);
  lines.push(line(`${a.postal_code} ${a.city}`));
  const land =
    a.country === OTHER_COUNTRY ? a.country_name : a.country !== ADDRESS_COUNTRIES[0].code ? (country(a.country)?.name ?? a.country) : '';
  if (land) lines.push(land);
  return lines;
}

/** Gerät für mapUrl: Android (App-Auswahl), Apple (Apple Karten) oder sonst (Google Maps im Browser) */
export type MapPlatform = 'android' | 'apple' | 'other';

/**
 * Ort in der Karten-App zeigen (nur den Ort, keine Route), gesucht als freier Text (z. B. Ort eines Termins): auf
 * Android als geo:-Link, dann bietet Android alle Karten-Apps zur Auswahl an (Google Maps, Waze, OsmAnd …); auf
 * Apple-Geräten Apple Karten (maps.apple.com, q), sonst Google Maps (Maps URLs, search/?api=1). null ohne Text.
 */
export function mapSearchUrl(query: string, platform: MapPlatform): string | null {
  const q = line(query);
  if (!q) return null;
  const target = encodeURIComponent(q);
  if (platform === 'android') return `geo:0,0?q=${target}`;
  return platform === 'apple' ? `https://maps.apple.com/?q=${target}` : `https://www.google.com/maps/search/?api=1&query=${target}`;
}

/**
 * Adresse in der Karten-App zeigen (mapSearchUrl): ohne Zusatz (c/o, Hinterhaus …), den die Suche nicht kennt, dafür
 * immer mit dem Land. null ohne Adresse.
 */
export function mapUrl(a: Address | null | undefined, platform: MapPlatform): string | null {
  if (!a || isEmptyAddress(a)) return null;
  const land = a.country === OTHER_COUNTRY ? a.country_name : (country(a.country)?.name ?? a.country);
  return mapSearchUrl(
    [line(`${a.street} ${a.house_number}`), line(`${a.postal_code} ${a.city}`), land].filter(Boolean).join(', '),
    platform,
  );
}

/** Link-Attribute zu mapUrl/mapSearchUrl: https in neuem Tab; geo: nicht, der neue Tab bliebe leer zurück. */
export function mapLinkTarget(url: string): { target?: string; rel?: string } {
  return url.startsWith('https:') ? { target: '_blank', rel: 'noreferrer' } : {};
}

/** Android; iPhone, iPad (gibt sich als Mac mit Touch aus) oder Mac; sonst (PC) */
export function mapPlatform(): MapPlatform {
  if (typeof navigator === 'undefined') return 'other';
  if (/Android/.test(navigator.userAgent)) return 'android';
  return /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent) ? 'apple' : 'other';
}

/** Für die Anfrage: null, wenn keine Adresse eingetragen ist. */
export function addressInput(a: Address | null | undefined): Address | null {
  return !a || isEmptyAddress(a) ? null : normalizeAddress(a);
}
