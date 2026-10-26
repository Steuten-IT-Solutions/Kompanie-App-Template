// Einfügen aus Word und Outlook (Issue #172). Outlook für Windows nutzt Word als Editor und liefert dasselbe HTML.
//
// Word legt Aufzählungen nicht als <ul>/<ol> in die Zwischenablage, sondern als Absätze mit „mso-list“-Styles und dem
// Aufzählungszeichen in einem <span style="mso-list:Ignore">. Bilder verweisen auf lokale Dateien (file:///…), die der
// Browser nicht lesen darf; ihre Daten stehen aber meist im RTF-Teil der Zwischenablage (\pict mit \pngblip oder
// \jpegblip). Dazu kommt Ballast: mso-*-Styles, Klassen, <o:p>, bedingte Kommentare, <style>, VML (<v:…>).
//
// Eigene Umsetzung (die Word-Aufbereitung von RoosterJS diente nur als Orientierung, es ist kein Code übernommen).
// Rein und ohne Browser testbar: Das Parsen übernimmt eine übergebene Funktion (im Browser DOMParser).

export type ParseHtml = (html: string) => Document;

export interface PasteResult {
  /** Aufbereitetes HTML; Bilder mit Daten aus dem RTF-Teil stehen als <img data-paste-image="Nummer"> darin. */
  html: string;
  /** Lokale Bilder (file:///…), für die es keine Daten gab: entfernt, die App weist darauf hin. */
  missingImages: number;
}

export interface RtfImage {
  mime: 'image/png' | 'image/jpeg';
  bytes: Uint8Array<ArrayBuffer>;
}

/** Stammt das HTML aus Word bzw. Outlook für Windows? */
export function isOfficeHtml(html: string): boolean {
  return /urn:schemas-microsoft-com:office|class="?Mso|mso-[a-z-]+\s*:/i.test(html);
}

/** Bilder aus dem RTF-Teil der Zwischenablage, in der Reihenfolge des Dokuments (nur PNG und JPEG). */
export function rtfImages(rtf: string): RtfImage[] {
  const out: RtfImage[] = [];
  let from = 0;
  for (;;) {
    const start = rtf.indexOf('\\pict', from);
    if (start < 0) break;
    // Ende der Gruppe {\pict …}: Klammern zählen (Untergruppen wie {\*\blipuid …} überspringen)
    let depth = 1;
    let i = start;
    let data = '';
    while (i < rtf.length && depth > 0) {
      const ch = rtf[i];
      if (ch === '{') depth++;
      else if (ch === '}') depth--;
      else if (depth === 1) data += ch;
      i++;
    }
    from = i;
    const type = /\\pngblip\b/.test(data) ? 'image/png' : /\\jpegblip\b/.test(data) ? 'image/jpeg' : null;
    if (type === null) continue; // WMF/EMF usw. kann der Browser nicht anzeigen
    // Nach den Steuerwörtern (\wort123 mit optionalem Leerzeichen) folgen die Daten als Hexadezimalzeichen.
    const hex = data.replace(/\\[a-z]+-?\d* ?/gi, '').replace(/\s+/g, '');
    if (hex.length < 16 || hex.length % 2 !== 0 || !/^[0-9a-fA-F]+$/.test(hex)) continue;
    const bytes = new Uint8Array(new ArrayBuffer(hex.length / 2));
    for (let k = 0; k < bytes.length; k++) bytes[k] = parseInt(hex.substr(k * 2, 2), 16);
    out.push({ mime: type, bytes });
  }
  return out;
}

const BULLETS_ORDERED = /^(\d+|[a-zA-Z]|[ivxlcdmIVXLCDM]+)[.)]$/;

/** Styles ohne mso-* und ohne Tabstopps; leer, wenn nichts übrig bleibt. */
function cleanStyle(style: string): string {
  return style
    .split(';')
    .map((d) => d.trim())
    .filter((d) => d !== '' && !/^(mso-|tab-stops|page-break|text-autospace|layout-grid)/i.test(d))
    .join('; ');
}

function isListParagraph(el: Element): boolean {
  const style = el.getAttribute('style') ?? '';
  return /mso-list\s*:\s*(?!ignore)l\d+/i.test(style) || /MsoListParagraph/i.test(el.getAttribute('class') ?? '');
}

function listLevel(el: Element): number {
  const m = (el.getAttribute('style') ?? '').match(/level(\d+)/i);
  return m ? Math.max(1, parseInt(m[1], 10)) : 1;
}

/** Aufzählungszeichen aus dem Ignore-Span holen (und entfernen); null, wenn es keins gibt. */
function takeMarker(el: Element): string | null {
  const span = Array.from(el.querySelectorAll('span')).find((s) => /mso-list\s*:\s*ignore/i.test(s.getAttribute('style') ?? ''));
  if (!span) return null;
  const marker = (span.textContent ?? '').replace(/ /g, ' ').trim();
  span.remove();
  return marker;
}

/** Word-Aufzählungen (Absätze mit mso-list) in echte, verschachtelte Listen umwandeln. */
function convertLists(doc: Document, root: Element): void {
  const children = Array.from(root.children);
  let stack: { level: number; list: Element; type: string }[] = [];
  for (const el of children) {
    if (el.tagName.toLowerCase() !== 'p' || !isListParagraph(el)) {
      stack = [];
      continue;
    }
    const level = listLevel(el);
    const marker = takeMarker(el) ?? '';
    const type = BULLETS_ORDERED.test(marker) ? 'ol' : 'ul';
    while (stack.length > 0 && stack[stack.length - 1].level > level) stack.pop();
    if (stack.length > 0 && stack[stack.length - 1].level === level && stack[stack.length - 1].type !== type) stack.pop();
    if (stack.length === 0 || stack[stack.length - 1].level < level) {
      const list = doc.createElement(type);
      if (type === 'ol' && /^\d+[.)]$/.test(marker) && marker !== '1.' && marker !== '1)')
        list.setAttribute('start', String(parseInt(marker, 10)));
      const parentLi = stack.length > 0 ? stack[stack.length - 1].list.lastElementChild : null;
      if (parentLi) parentLi.appendChild(list);
      else el.parentNode?.insertBefore(list, el);
      stack.push({ level, list, type });
    }
    const li = doc.createElement('li');
    const style = cleanStyle(el.getAttribute('style') ?? '').replace(/(^|;\s*)(margin-left|text-indent)\s*:[^;]*/gi, '');
    if (style.trim().replace(/^;\s*/, '') !== '') li.setAttribute('style', style.replace(/^;\s*/, ''));
    while (el.firstChild) li.appendChild(el.firstChild);
    stack[stack.length - 1].list.appendChild(li);
    el.remove();
  }
}

function removeComments(node: Node): void {
  for (const child of Array.from(node.childNodes)) {
    if (child.nodeType === 8) child.parentNode?.removeChild(child);
    else removeComments(child);
  }
}

/**
 * Aufbereiten, bevor der Editor das HTML übernimmt. $images: Anzahl der Bilder aus dem RTF-Teil; lokale Bilder bekommen
 * der Reihe nach eine davon (data-paste-image), solange es welche gibt.
 */
export function cleanPastedHtml(html: string, parse: ParseHtml, images = 0): PasteResult {
  // Bedingte Kommentare <!--[if …]>…<![endif]--> samt Inhalt (VML, Office-Einstellungen) entfernen; bei den
  // „sichtbaren“ <![if !vml]>…<![endif]> nur die Marken (ihr Inhalt ist das, was andere Programme zeigen sollen).
  const stripped = html.replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '').replace(/<!\[if[^\]]*\]>|<!\[endif\]>/gi, '');
  const doc = parse(stripped);
  const body = doc.body ?? doc.documentElement;
  removeComments(body);
  for (const el of Array.from(body.querySelectorAll('style, meta, link, title, xml, script'))) el.remove();
  let missingImages = 0;
  let next = 0;
  for (const img of Array.from(body.querySelectorAll('img'))) {
    const src = img.getAttribute('src') ?? '';
    if (/^(file:|cid:|blob:|webkit-fake-url:)/i.test(src) || src === '') {
      if (next < images) {
        const marker = doc.createElement('img');
        marker.setAttribute('data-paste-image', String(next++));
        for (const a of ['width', 'height', 'alt']) {
          const v = img.getAttribute(a);
          if (v) marker.setAttribute(a, v);
        }
        img.replaceWith(marker);
      } else {
        missingImages++;
        img.remove();
      }
    }
  }
  for (const container of [body, ...Array.from(body.querySelectorAll('div, td, blockquote'))]) convertLists(doc, container);
  // Namensraum-Elemente (o:p, v:shape, w:…): leere entfernen, sonst auflösen
  for (const el of Array.from(body.querySelectorAll('*'))) {
    const tag = el.tagName.toLowerCase();
    if (tag.includes(':')) {
      if ((el.textContent ?? '').trim() === '' || tag.startsWith('v:')) el.remove();
      else el.replaceWith(...Array.from(el.childNodes));
    }
  }
  for (const el of Array.from(body.querySelectorAll('*'))) {
    el.removeAttribute('class');
    el.removeAttribute('lang');
    const style = el.getAttribute('style');
    if (style !== null) {
      const cleaned = cleanStyle(style);
      if (cleaned === '') el.removeAttribute('style');
      else el.setAttribute('style', cleaned);
    }
    // Leere Spans (z. B. vom entfernten Aufzählungszeichen) entfernen
    if (el.tagName.toLowerCase() === 'span' && (el.textContent ?? '') === '' && !el.querySelector('img')) {
      el.remove();
      continue;
    }
    // Sprungmarken aus Word (<a name="_Hlk…">) ohne Adresse auflösen
    if (el.tagName.toLowerCase() === 'a' && !el.getAttribute('href')) el.replaceWith(...Array.from(el.childNodes));
  }
  return { html: body.innerHTML.trim(), missingImages };
}
