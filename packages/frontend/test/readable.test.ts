// Lesbarkeit formatierter Inhalte (src/richtext/readable.ts): Farben lesen, Kontrast nach WCAG 2.x.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { contrast, over, parseRgb, readableOn, TEXT_MIN } from '../src/richtext/readable.ts';

test('Farben aus getComputedStyle', () => {
  assert.deepEqual(parseRgb('rgb(1, 2, 3)'), [1, 2, 3, 1]);
  assert.deepEqual(parseRgb('rgba(10, 20, 30, 0.5)'), [10, 20, 30, 0.5]);
  assert.deepEqual(parseRgb('rgb(10 20 30 / 25%)'), [10, 20, 30, 0.25]);
  assert.deepEqual(parseRgb('rgba(0, 0, 0, 0)'), [0, 0, 0, 0]);
  assert.equal(parseRgb('transparent'), null);
  assert.equal(parseRgb('color(srgb 1 0 0)'), null);
});

test('Kontrast nach WCAG: Schwarz auf Weiß 21 : 1, gleiche Farben 1 : 1', () => {
  assert.equal(contrast([0, 0, 0, 1], [255, 255, 255, 1]), 21);
  assert.equal(contrast([40, 80, 40, 1], [40, 80, 40, 1]), 1);
  // #767676 auf Weiß ist die bekannte Grenze von 4,5 : 1.
  assert.ok(Math.abs(contrast([0x76, 0x76, 0x76, 1], [255, 255, 255, 1]) - 4.54) < 0.01);
});

test('dunkelgrüne Schrift: auf Weiß lesbar, auf dunkelgrüner Karte nicht', () => {
  const green = parseRgb('rgb(51, 85, 34)')!;
  assert.ok(contrast(green, [255, 255, 255, 1]) >= TEXT_MIN);
  assert.ok(contrast(green, [27, 50, 27, 1]) < TEXT_MIN);
});

test('halbtransparente Farbe über Hintergrund und lesbare Schrift auf Markierungen', () => {
  assert.deepEqual(over([255, 255, 255, 0.5], [0, 0, 0, 1]), [127.5, 127.5, 127.5, 1]);
  assert.equal(readableOn([255, 255, 0, 1]), 'black');
  assert.equal(readableOn([20, 40, 20, 1]), 'white');
});
