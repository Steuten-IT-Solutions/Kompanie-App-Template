// Wörter in Knöpfen nur trennen, wenn sie allein nicht hineinpassen (src/buttonWords.ts): sonst trennte der Browser
// „BILDER UND VI-DEOS EINREICHEN“, obwohl „VIDEOS“ ganz in die nächste Zeile passt.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { HYPHENATE_ATTR, updateButton, type ButtonBox } from '../src/buttonWords.ts';

/** Knopf, dessen Text ohne Trennung `unhyphenated` px und mit Trennung `hyphenated` px breit ist. */
class FakeButton implements ButtonBox {
  attrs = new Map<string, string>();
  readonly clientWidth: number;
  private unhyphenated: number;
  private hyphenated: number;
  constructor(clientWidth: number, unhyphenated: number, hyphenated = clientWidth) {
    this.clientWidth = clientWidth;
    this.unhyphenated = unhyphenated;
    this.hyphenated = hyphenated;
  }
  get scrollWidth(): number {
    const content = this.attrs.has(HYPHENATE_ATTR) ? this.hyphenated : this.unhyphenated;
    return Math.max(this.clientWidth, content);
  }
  hasAttribute(name: string): boolean {
    return this.attrs.has(name);
  }
  setAttribute(name: string, value: string): void {
    this.attrs.set(name, value);
  }
  removeAttribute(name: string): void {
    this.attrs.delete(name);
  }
}

test('ein Knopf, in den jedes Wort passt, trennt nicht', () => {
  const btn = new FakeButton(160, 160);
  assert.equal(updateButton(btn), false);
  assert.equal(btn.hasAttribute(HYPHENATE_ATTR), false);
});

test('ein Wort, das allein breiter ist als der Knopf, erlaubt die Trennung', () => {
  const btn = new FakeButton(120, 175);
  assert.equal(updateButton(btn), true);
  assert.equal(btn.hasAttribute(HYPHENATE_ATTR), true);
});

test('wird der Knopf breiter, trennt er wieder nicht (gemessen wird ohne Trennung)', () => {
  const btn = new FakeButton(120, 175);
  updateButton(btn);
  // Mit Trennung läuft nichts über; trotzdem muss die Messung ohne Trennung entscheiden.
  assert.equal(updateButton(btn), true);
  const wide = new FakeButton(200, 175);
  wide.setAttribute(HYPHENATE_ATTR, '');
  assert.equal(updateButton(wide), false);
  assert.equal(wide.hasAttribute(HYPHENATE_ATTR), false);
});

test('Rundung bei gebrochenen Pixeln zählt nicht als Überlauf', () => {
  assert.equal(updateButton(new FakeButton(160, 161)), false);
});
