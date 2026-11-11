/**
 * Wörter in Knöpfen (.btn) nur trennen, wenn sie sonst nicht in den Knopf passen.
 *
 * Mit `hyphens: auto` trennt der Browser ein Wort, sobald ein Teil davon noch in die Zeile passt, auch wenn das ganze
 * Wort in der nächsten Zeile Platz hätte („BILDER UND VI-DEOS EINREICHEN“). Knöpfe trennen deshalb nicht: Ein Wort,
 * das nicht mehr in die Zeile passt, rutscht ganz in die nächste. Nur wenn ein Wort allein breiter ist als der Knopf
 * (lange Wörter auf schmalen Handys), läuft es über den Rand. Das erkennt dieser Beobachter und setzt am Knopf
 * `data-hyphenate`; dann trennt der Knopf nach deutschen Regeln (styles.css).
 */

export const HYPHENATE_ATTR = 'data-hyphenate';
const BUTTON = '.btn';

/** Das, was {@link updateButton} von einem Knopf braucht (im Browser ein Element). */
export interface ButtonBox {
  readonly scrollWidth: number;
  readonly clientWidth: number;
  hasAttribute(name: string): boolean;
  setAttribute(name: string, value: string): void;
  removeAttribute(name: string): void;
}

/**
 * Misst den Knopf ohne Trennung und erlaubt sie nur, wenn sein Text dann über den Rand liefe. Gibt zurück, ob der
 * Knopf trennen darf.
 */
export function updateButton(btn: ButtonBox): boolean {
  if (btn.hasAttribute(HYPHENATE_ATTR)) btn.removeAttribute(HYPHENATE_ATTR);
  // 1 px Spielraum für Rundungen bei gebrochenen Pixeln
  const overflows = btn.scrollWidth > btn.clientWidth + 1;
  if (overflows) btn.setAttribute(HYPHENATE_ATTR, '');
  return overflows;
}

/**
 * Beobachtet alle Knöpfe ab `root`: neue Knöpfe, geänderte Beschriftungen und geänderte Breiten (Drehen des Handys,
 * Laden der Schrift). Einmal beim Start aufrufen.
 */
export function watchButtonWords(root: Element = document.body): (() => void) | null {
  if (typeof ResizeObserver === 'undefined' || typeof MutationObserver === 'undefined' || !root) return null;
  const watched = new WeakSet<Element>();
  const resize = new ResizeObserver((entries) => {
    for (const e of entries) updateButton(e.target);
  });
  const watch = (btn: Element) => {
    if (watched.has(btn)) return;
    watched.add(btn);
    resize.observe(btn); // misst sofort nach dem nächsten Layout
  };
  const added = (n: Node) => {
    if (n.nodeType !== Node.ELEMENT_NODE) return;
    const el = n as Element;
    if (el.matches(BUTTON)) watch(el);
    el.querySelectorAll(BUTTON).forEach(watch);
  };
  const removed = (n: Node) => {
    if (n.nodeType !== Node.ELEMENT_NODE) return;
    const el = n as Element;
    for (const btn of [...(el.matches(BUTTON) ? [el] : []), ...el.querySelectorAll(BUTTON)]) {
      resize.unobserve(btn);
      watched.delete(btn);
    }
  };
  /** Neue Beschriftung, gleiche Breite: Der ResizeObserver meldet nichts, also selbst messen. */
  const relabeled = (n: Node) => {
    const el = n.nodeType === Node.ELEMENT_NODE ? (n as Element) : n.parentElement;
    const btn = el?.closest(BUTTON);
    if (btn && watched.has(btn)) updateButton(btn);
  };
  added(root);
  const mutations = new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') {
        relabeled(r.target);
        continue;
      }
      r.removedNodes.forEach(removed);
      r.addedNodes.forEach(added);
      relabeled(r.target);
    }
  });
  mutations.observe(root, { childList: true, characterData: true, subtree: true });
  // Die Schrift der Kompanie ist breiter oder schmaler als die Ersatzschrift: danach alle Knöpfe neu messen.
  void document.fonts?.ready.then(() => root.querySelectorAll(BUTTON).forEach((b) => updateButton(b)));
  return () => {
    mutations.disconnect();
    resize.disconnect();
  };
}
