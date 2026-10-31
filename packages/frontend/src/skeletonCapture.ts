/** Aufbau eines Bereichs ohne Inhalt, für das Gerüst beim Laden (skeleton.tsx). */

/** Text (Länge in Zeichen), Fläche (Breite, Höhe in px) oder Element (Tag, Klassen, Inhalt). */
export type Part = number | { b: [number, number]; c?: string } | { t: string; c?: string; k?: Part[] };

/** Was capture() von einem DOM-Knoten braucht (im Test nachgebaut). */
export interface DomNode {
  nodeType: number;
  textContent: string | null;
  tagName?: string;
  className?: unknown;
  childNodes?: ArrayLike<DomNode>;
  getClientRects?: () => ArrayLike<unknown>;
  getBoundingClientRect?: () => { width: number; height: number };
}

const TEXT_NODE = 3;
const ELEMENT_NODE = 1;
/** Elemente, die so übernommen werden (Abstände und Aussehen hängen teils am Tag). */
const KEEP = new Set(
  'div section article header footer nav main aside ul ol li p h1 h2 h3 h4 h5 h6 table thead tbody tfoot tr td th dl dt dd figure figcaption strong em small b i time'.split(
    ' ',
  ),
);
/**
 * Elemente mit eigenem Text bzw. Inhalt, mit ihrem Tag übernommen: Manche Regeln in styles.css hängen am Tag (z. B.
 * `button.member-tile`, `fieldset.feature-list`), und form, fieldset, blockquote und pre sind von sich aus Blöcke. Als
 * span stünden sie anders da als die echte Seite. Bedienbar sind sie im Gerüst nicht (inert, skeleton.tsx).
 */
const TAGGED = new Set(['a', 'button', 'label', 'form', 'fieldset', 'legend', 'blockquote', 'pre', 'code']);
/** Werden zu einem span (details zeigte zugeklappt nur seine summary) bzw. bleiben einer. */
const INLINE = new Set(['span', 'summary', 'details']);
/** Ersetzte Elemente: nur ihre Fläche. */
const BOX = new Set(['img', 'svg', 'canvas', 'video', 'picture', 'iframe', 'input', 'select', 'textarea', 'progress', 'meter']);
const MAX_ELEMENTS = 300;
const MAX_CHILDREN = 12;
const MAX_TEXT = 160;

/** Aufbau der Knoten ohne Inhalt; leer, wenn nichts Sichtbares darin ist. */
export function capture(nodes: ArrayLike<DomNode>): Part[] {
  let budget = MAX_ELEMENTS;

  function walk(node: DomNode): Part | null {
    if (node.nodeType === TEXT_NODE) {
      const len = (node.textContent ?? '').trim().length;
      // In Stufen von 4 Zeichen: sieht gleich aus und verrät keine genaue Länge.
      return len === 0 ? null : Math.min(MAX_TEXT, Math.ceil(len / 4) * 4);
    }
    if (node.nodeType !== ELEMENT_NODE || budget <= 0) return null;
    const tag = (node.tagName ?? '').toLowerCase();
    // Ausgeblendetes (display: none, z. B. eine zugeklappte Ansicht) nicht übernehmen.
    if (node.getClientRects && node.getClientRects().length === 0) return null;
    if (tag === 'dialog' || tag === 'script' || tag === 'style' || tag === 'template') return null;
    budget--;
    const c = typeof node.className === 'string' && node.className !== '' ? node.className : undefined;
    if (BOX.has(tag)) {
      const r = node.getBoundingClientRect?.() ?? { width: 0, height: 0 };
      if (r.width < 1 || r.height < 1) return null;
      return { b: [Math.round(r.width), Math.round(r.height)], ...(c && { c }) };
    }
    if (!KEEP.has(tag) && !TAGGED.has(tag) && !INLINE.has(tag)) return null;
    const k: Part[] = [];
    const children = node.childNodes ?? [];
    for (let i = 0; i < children.length && k.length < MAX_CHILDREN; i++) {
      const part = walk(children[i]);
      if (part !== null) k.push(part);
    }
    return { t: KEEP.has(tag) || TAGGED.has(tag) ? tag : 'span', ...(c && { c }), ...(k.length > 0 && { k }) };
  }

  const out: Part[] = [];
  for (let i = 0; i < nodes.length; i++) {
    const part = walk(nodes[i]);
    if (part !== null) out.push(part);
  }
  return out;
}
