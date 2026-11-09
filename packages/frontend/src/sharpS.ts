/**
 * Großes ẞ in allem, was per CSS in Großbuchstaben erscheint (text-transform: uppercase).
 *
 * Browser setzen „ß“ in Großbuchstaben als „SS“ (Unicode-Regel): aus „Erika Groß“ wird „ERIKA GROSS“, und den Namen
 * kann man nicht mehr von „Gross“ unterscheiden. Statt jede Plakette, Überschrift und Schaltfläche einzeln anzupassen,
 * ersetzt ein Beobachter app-weit in solchen Texten „ß“ durch „ẞ“. Angezeigt wird ohnehin in Großbuchstaben, der Text
 * sieht also sonst gleich aus. Eingabefelder und bearbeitbare Bereiche bleiben unberührt.
 */

const SHARP_S = /ß/g;
const CAPITAL_SHARP_S = 'ẞ';

/** Text für eine Anzeige in Großbuchstaben: „ß“ wird zu „ẞ“. */
export const caps = (s: string): string => s.replace(SHARP_S, CAPITAL_SHARP_S);

/** Das, was {@link fixSharpS} von einem Knoten braucht (im Browser ein DOM-Knoten). */
export interface SharpSNode {
  nodeType: number;
  nodeValue: string | null;
  textContent: string | null;
  parentElement: SharpSElement | null;
  childNodes: ArrayLike<SharpSNode>;
}

export interface SharpSElement extends SharpSNode {
  closest(selector: string): unknown;
}

const TEXT_NODE = 3;
const ELEMENT_NODE = 1;

/** Bereiche, deren Text Nutzer bearbeiten oder der kein sichtbarer Text ist. */
const SKIP = 'textarea, input, script, style, [contenteditable]:not([contenteditable="false"])';

/**
 * Ersetzt „ß“ durch „ẞ“ in allen Texten unter `node`, deren Element in Großbuchstaben erscheint (`isUpper`).
 * Gibt die Zahl der geänderten Textknoten zurück.
 */
export function fixSharpS(node: SharpSNode, isUpper: (el: SharpSElement) => boolean): number {
  if (!node.textContent?.includes('ß')) return 0;
  if (node.nodeType === TEXT_NODE) {
    const el = node.parentElement;
    if (!el || el.closest(SKIP) || !isUpper(el)) return 0;
    node.nodeValue = caps(node.nodeValue ?? '');
    return 1;
  }
  if (node.nodeType !== ELEMENT_NODE) return 0;
  if ((node as SharpSElement).closest(SKIP)) return 0;
  let changed = 0;
  // Kopie: Das Ändern eines Textes ändert die Liste nicht, aber sicher ist sicher.
  for (const child of Array.from(node.childNodes)) changed += fixSharpS(child, isUpper);
  return changed;
}

const isUpperInBrowser = (el: SharpSElement): boolean => getComputedStyle(el as unknown as Element).textTransform === 'uppercase';

/**
 * Beobachtet die Seite ab `root` und korrigiert neue oder geänderte Texte. Einmal beim Start aufrufen; React ändert
 * Texte, indem es sie neu setzt, und der Beobachter korrigiert sie danach wieder. Eine Korrektur löst selbst eine
 * Änderung aus, findet dann aber kein „ß“ mehr.
 */
export function watchSharpS(root: Node = document.body): MutationObserver | null {
  if (typeof MutationObserver === 'undefined' || !root) return null;
  const fix = (n: Node) => fixSharpS(n as unknown as SharpSNode, isUpperInBrowser);
  fix(root);
  const observer = new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') fix(r.target);
      else r.addedNodes.forEach(fix);
    }
  });
  observer.observe(root, { childList: true, characterData: true, subtree: true });
  return observer;
}
