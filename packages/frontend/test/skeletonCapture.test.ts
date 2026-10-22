// Gerüst der Seiten (src/skeletonCapture.ts): nur der Aufbau, kein Inhalt.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { capture, type DomNode } from '../src/skeletonCapture.ts';

const text = (s: string): DomNode => ({ nodeType: 3, textContent: s });
function el(tag: string, className: string, children: DomNode[] = [], size?: [number, number], hidden = false): DomNode {
  return {
    nodeType: 1,
    tagName: tag.toUpperCase(),
    className,
    textContent: null,
    childNodes: children,
    getClientRects: () => (hidden ? [] : [{}]),
    getBoundingClientRect: () => ({ width: size?.[0] ?? 100, height: size?.[1] ?? 20 }),
  };
}

test('Merkt Elemente, Klassen und Textlängen in Stufen – keinen Text und keine Adressen', () => {
  const page = [
    el('ul', 'card-list', [
      el('li', 'card row', [
        el('div', 'row-title', [text('Strafe für Max Mustermann')]),
        el('a', 'link', [text('Details')]),
        el('img', 'avatar', [], [40, 40]),
      ]),
    ]),
    text('   '),
  ];
  const parts = capture(page);
  assert.deepEqual(parts, [
    {
      t: 'ul',
      c: 'card-list',
      k: [
        {
          t: 'li',
          c: 'card row',
          k: [
            { t: 'div', c: 'row-title', k: [28] },
            { t: 'span', c: 'link', k: [8] },
            { b: [40, 40], c: 'avatar' },
          ],
        },
      ],
    },
  ]);
  assert.equal(JSON.stringify(parts).includes('Max'), false);
});

test('Ausgeblendetes, Skripte und unbekannte Elemente fallen weg; lange Listen werden gekürzt', () => {
  const rows = Array.from({ length: 30 }, () => el('li', 'card', [text('x')]));
  const parts = capture([el('div', '', [], undefined, true), el('script', ''), el('my-widget', ''), el('ul', '', rows)]);
  assert.equal(parts.length, 1);
  const list = parts[0] as { k: unknown[] };
  assert.equal(list.k.length, 12);
});
