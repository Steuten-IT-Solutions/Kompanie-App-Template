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
  /** Nur die Auswahlen, die sharpS.ts nutzt: Tags (erstes Wort von `cls`), Rollen, bearbeitbare Bereiche. */
  matches(selector: string): boolean {
    return selector.split(', ').some((part) => {
      if (part.startsWith('[contenteditable]')) {
        const ce = this.attrs.contenteditable;
        return ce !== undefined && ce !== 'false';
      }
      const role = /^\[role="(\w+)"\]$/.exec(part);
      if (role) return this.attrs.role === role[1];
      assert.match(part, /^[a-z]+$/);
      return this.cls.split(' ')[0] === part;
    });
  }
  closest(selector: string): FakeElement | null {
    for (let el: FakeElement | null = this; el; el = el.parentElement) if (el.matches(selector)) return el;
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

test('Beschriftungen und Schaltflächen in Großbuchstaben bleiben, Plaketten in einer Zeile nicht', () => {
  const label = new FakeText('Straße');
  new FakeElement('label field').append(new FakeElement('span upper').append(label));
  const button = new FakeText('Schließen');
  new FakeElement('button upper').append(button);
  const tab = new FakeText('Grüße');
  new FakeElement('div upper', { role: 'tab' }).append(tab);
  for (const t of [label, button, tab]) assert.equal(fixSharpS(t, isUpper), 0);
  assert.deepEqual(
    [label, button, tab].map((t) => t.nodeValue),
    ['Straße', 'Schließen', 'Grüße'],
  );

  // Eine Zeile, die als Ganzes eine Schaltfläche ist, mit einer Plakette darin.
  const badge = new FakeText('Groß');
  new FakeElement('button row').append(new FakeElement('span badge upper').append(badge));
  assert.equal(fixSharpS(badge, isUpper), 1);
  assert.equal(badge.nodeValue, 'Groẞ');
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
