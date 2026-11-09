// Großes ẞ in Texten, die per CSS in Großbuchstaben erscheinen (src/sharpS.ts): Browser machen aus „ß“ sonst „SS“
// („NUR FÜR ERIKA GROSS“ im Verlauf des Schwarzen Bretts).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { caps, fixSharpS, type SharpSElement, type SharpSNode } from '../src/sharpS.ts';

/** Kleiner Nachbau des DOM: Elemente mit Klassen, Textknoten. */
class FakeElement implements SharpSElement {
  nodeType = 1;
  nodeValue = null;
  parentElement: FakeElement | null = null;
  childNodes: SharpSNode[] = [];
  cls: string;
  attrs: Record<string, string>;
  constructor(cls: string, attrs: Record<string, string> = {}) {
    this.cls = cls;
    this.attrs = attrs;
  }
  get textContent(): string {
    return this.childNodes.map((c) => c.textContent ?? '').join('');
  }
  append(...children: (FakeElement | FakeText)[]): this {
    for (const c of children) {
      c.parentElement = this;
      this.childNodes.push(c);
    }
    return this;
  }
  /** Nur die Auswahl, die sharpS.ts nutzt: Eingabefelder und bearbeitbare Bereiche. */
  closest(selector: string): FakeElement | null {
    assert.match(selector, /contenteditable/);
    for (let el: FakeElement | null = this; el; el = el.parentElement) {
      if (['textarea', 'input', 'script', 'style'].includes(el.cls)) return el;
      const ce = el.attrs.contenteditable;
      if (ce !== undefined && ce !== 'false') return el;
    }
    return null;
  }
}

class FakeText implements SharpSNode {
  nodeType = 3;
  parentElement: FakeElement | null = null;
  childNodes: SharpSNode[] = [];
  nodeValue: string;
  constructor(nodeValue: string) {
    this.nodeValue = nodeValue;
  }
  get textContent(): string {
    return this.nodeValue;
  }
}

// Wie im Browser: text-transform erbt sich auf Kind-Elemente.
const isUpper = (el: SharpSElement): boolean => {
  for (let e = el as FakeElement | null; e; e = e.parentElement) {
    if (e.cls.split(' ').includes('upper')) return true;
  }
  return false;
};

test('caps: ß wird ẞ, anderes bleibt', () => {
  assert.equal(caps('Nur für Erika Groß, gelöschtes Konto'), 'Nur für Erika Groẞ, gelöschtes Konto');
  assert.equal(caps('Straße, Gruß'), 'Straẞe, Gruẞ');
  assert.equal(caps('Ohne'), 'Ohne');
});

test('Plakette in Großbuchstaben: ß wird ẞ, die Zeile darunter bleibt', () => {
  const badge = new FakeText('Nur für Erika Groß, gelöschtes Konto');
  const meta = new FakeText('Erika Groß · 19:02 Uhr');
  const card = new FakeElement('card').append(new FakeElement('badge upper').append(badge), new FakeElement('meta').append(meta));
  assert.equal(fixSharpS(card, isUpper), 1);
  assert.equal(badge.nodeValue, 'Nur für Erika Groẞ, gelöschtes Konto');
  assert.equal(meta.nodeValue, 'Erika Groß · 19:02 Uhr');
});

test('geerbte Großbuchstaben (Text in einem Kind-Element) und einzelner Textknoten', () => {
  const inner = new FakeText('Grüße');
  const title = new FakeElement('upper').append(new FakeElement('strong').append(inner));
  assert.equal(fixSharpS(title, isUpper), 1);
  assert.equal(inner.nodeValue, 'Grüẞe');

  // Wie bei einer Textänderung durch React: nur der Textknoten kommt.
  inner.nodeValue = 'Straße';
  assert.equal(fixSharpS(inner, isUpper), 1);
  assert.equal(inner.nodeValue, 'Straẞe');
  // Zweiter Lauf (die Korrektur selbst meldet der Beobachter wieder): nichts mehr zu tun.
  assert.equal(fixSharpS(inner, isUpper), 0);
});

test('Eingaben und bearbeitbare Bereiche bleiben unberührt', () => {
  const typed = new FakeText('Groß');
  const editor = new FakeElement('upper', { contenteditable: 'true' }).append(new FakeElement('p').append(typed));
  const area = new FakeText('Groß');
  const form = new FakeElement('upper').append(editor, new FakeElement('textarea').append(area));
  assert.equal(fixSharpS(form, isUpper), 0);
  assert.equal(typed.nodeValue, 'Groß');
  assert.equal(area.nodeValue, 'Groß');

  const shown = new FakeText('Groß');
  new FakeElement('upper', { contenteditable: 'false' }).append(shown);
  assert.equal(fixSharpS(shown, isUpper), 1);
});

test('ohne ß: kein Element wird geprüft', () => {
  let checked = 0;
  const tree = new FakeElement('upper').append(new FakeText('Erika Gross'));
  assert.equal(
    fixSharpS(tree, () => {
      checked++;
      return true;
    }),
    0,
  );
  assert.equal(checked, 0);
});
