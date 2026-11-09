/**
 * Webadressen für Links nach außen (Webseite und App einer Kompanie, Link eines Sponsors) eingeben: Ohne Schema wird
 * beim Verlassen https:// ergänzt (beispiel.de/app → https://beispiel.de/app), danach wird geprüft. Der Server prüft
 * noch einmal mit denselben Regeln (Domain\Input::httpsUrl).
 *
 * Das Feld ist bewusst kein type="url": Der Browser lehnt dort Adressen ohne Schema ab und zeigt nur seine eigene
 * Meldung („Gib eine URL ein.“).
 */

/** Wie Input::URL_MAX auf dem Server. */
export const LINK_MAX = 255;

/** Während des Tippens: Leerzeichen raus (Wortvorschläge der Handytastatur hängen eins an). */
export function tidyLink(input: string): string {
  return input.replace(/\s+/g, '');
}

/** Nach der Eingabe (Feld verlassen, Speichern): https:// ergänzen, wenn das Schema fehlt; Schema klein. */
export function normalizeLink(input: string): string {
  const s = tidyLink(input);
  if (s === '') return '';
  // „beispiel.de:8080“ ist eine Adresse mit Port, kein Schema.
  const scheme = /^([a-z][a-z0-9+.-]*):(?!\d)/i.exec(s);
  if (!scheme) return 'https://' + s.replace(/^\/+/, '');
  return scheme[1].toLowerCase() + s.slice(scheme[1].length);
}

const LABEL = /^[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?$/u;
const ENDING = /^(?:\p{L}{2,}|xn--[a-z0-9-]+)$/iu;

/** Was an der Adresse nicht stimmt, sonst null; leer ist erlaubt (die Felder sind optional). */
export function linkProblem(input: string): string | null {
  const s = normalizeLink(input);
  if (s === '') return null;
  const m = /^([a-z][a-z0-9+.-]*):\/\/([^/?#]*)/.exec(s);
  if (!m) return 'Das ist keine Webadresse. Beispiel: beispiel.de oder https://beispiel.de/seite';
  if (m[1] === 'http') return 'Bitte eine sichere Adresse mit https:// angeben, nicht http://.';
  if (m[1] !== 'https') return 'Das ist keine Webadresse. Beispiel: beispiel.de oder https://beispiel.de/seite';
  if (/["<>\p{Cc}]/u.test(s)) return 'Die Adresse enthält Zeichen, die in Webadressen nicht vorkommen (" < >).';
  const authority = m[2];
  if (authority.includes('@')) return 'Die Adresse ist ungültig.';
  const host = authority.replace(/:\d*$/, '');
  if (host === '') return 'Hinter https:// fehlt die Adresse, z. B. beispiel.de.';
  if (!/:\d*$/.test(authority) && authority.includes(':')) return 'Die Adresse ist ungültig.';
  const labels = host.split('.');
  if (labels.length < 2 || labels[labels.length - 1] === '')
    return `Hinter „${host.replace(/\.$/, '')}“ fehlt die Endung, z. B. .de oder .com.`;
  if (host.length > 253 || !labels.every((l) => l.length <= 63 && LABEL.test(l)) || !ENDING.test(labels[labels.length - 1])) {
    return 'Die Adresse ist ungültig.';
  }
  if (s.length > LINK_MAX) return `Die Adresse ist zu lang (höchstens ${LINK_MAX} Zeichen).`;
  return null;
}
