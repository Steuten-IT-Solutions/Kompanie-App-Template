// Raster der Kachel-Seiten mit persönlicher Anordnung (src/tileGrid.ts): Vorgabe, gespeicherte Lage, fehlende und neue
// Kacheln, abgeleitete Spaltenzahl.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { columnsForWidth, flow, resolveTiles, rowCount, type TilePlace } from '../src/tileGrid.ts';

const t = (id: string, col: number, row: number, span = 1): TilePlace => ({ id, col, row, span });

test('Spaltenzahl nach Breite des Rasters: 2, ab 528 px 3, ab 680 px 4', () => {
  assert.equal(columnsForWidth(358), 2);
  assert.equal(columnsForWidth(527), 2);
  assert.equal(columnsForWidth(528), 3);
  assert.equal(columnsForWidth(608), 3, 'Computer: Inhalt höchstens 640 px');
  assert.equal(columnsForWidth(680), 4);
});

test('ohne Anordnung: der Reihe nach, Breite 1, ohne Lücken', () => {
  assert.deepEqual(resolveTiles(['a', 'b', 'c'], undefined, 2), [t('a', 1, 1), t('b', 2, 1), t('c', 1, 2)]);
  assert.deepEqual(resolveTiles(['a', 'b', 'c'], {}, 3), [t('a', 1, 1), t('b', 2, 1), t('c', 3, 1)]);
});

test('gespeicherte Lage mit Lücken und Breiten bleibt, Ergebnis nach Zeile und Spalte', () => {
  const saved = { '4': [t('c', 4, 2), t('a', 1, 1, 2), t('b', 2, 2)] };
  assert.deepEqual(resolveTiles(['a', 'b', 'c'], saved, 4), [t('a', 1, 1, 2), t('b', 2, 2), t('c', 4, 2)]);
});

test('nicht sichtbare Kachel: rechts davon in derselben Zeile rücken nach links, keine wechselt die Zeile', () => {
  const saved = { '4': [t('a', 1, 1), t('x', 2, 1, 2), t('b', 4, 1), t('c', 1, 2)] };
  assert.deepEqual(resolveTiles(['a', 'b', 'c'], saved, 4), [t('a', 1, 1), t('b', 2, 1), t('c', 1, 2)]);
  // gewollte Lücke rechts der fehlenden Kachel bleibt
  const gap = { '4': [t('x', 1, 1), t('b', 3, 1, 2)] };
  assert.deepEqual(resolveTiles(['b'], gap, 4), [t('b', 2, 1, 2)]);
});

test('ganz leere Zeilen fallen weg', () => {
  const saved = { '2': [t('a', 1, 1), t('x', 1, 2, 2), t('b', 2, 4)] };
  assert.deepEqual(resolveTiles(['a', 'b'], saved, 2), [t('a', 1, 1), t('b', 2, 2)]);
});

test('neue Kacheln in der Reihenfolge der Vorgabe unter der letzten Zeile, Breite 1', () => {
  const saved = { '3': [t('b', 2, 1, 2)] };
  assert.deepEqual(resolveTiles(['n1', 'b', 'n2', 'n3', 'n4'], saved, 3), [
    t('b', 2, 1, 2),
    t('n1', 1, 2),
    t('n2', 2, 2),
    t('n3', 3, 2),
    t('n4', 1, 3),
  ]);
});

test('leere gespeicherte Anordnung: alle Kacheln als neue', () => {
  assert.deepEqual(resolveTiles(['a', 'b'], { '2': [] }, 2), [t('a', 1, 1), t('b', 2, 1)]);
});

test('fehlende Spaltenzahl: Lesereihenfolge der nächsten gespeicherten, Breiten gekürzt, ohne Lücken', () => {
  const saved = { '4': [t('c', 1, 1, 4), t('a', 2, 2), t('b', 4, 2, 1)] };
  assert.deepEqual(resolveTiles(['a', 'b', 'c', 'd'], saved, 2), [t('c', 1, 1, 2), t('a', 1, 2), t('b', 2, 2), t('d', 1, 3)]);
  // 3 Spalten: 2 und 4 gleich weit weg, die breitere zählt
  const both = { '2': [t('b', 1, 1), t('a', 2, 1)], '4': [t('a', 1, 1), t('b', 2, 1)] };
  assert.deepEqual(resolveTiles(['a', 'b'], both, 3), [t('a', 1, 1), t('b', 2, 1)]);
});

test('kaputte, doppelte oder überlappende Einträge zählen als nicht gespeichert', () => {
  const saved = { '2': [t('a', 2, 1, 2), t('b', 1, 1), t('b', 2, 1), { id: 'c', col: 1.5, row: 2, span: 1 }] };
  assert.deepEqual(resolveTiles(['a', 'b', 'c'], saved, 2), [t('b', 1, 1), t('a', 1, 2), t('c', 2, 2)]);
});

test('flow: zu breite Kachel beginnt eine neue Zeile', () => {
  const placed = flow([{ id: 'a' }, { id: 'b', span: 2 }, { id: 'c', span: 9 }], 3);
  assert.deepEqual(placed, [t('a', 1, 1), t('b', 2, 1, 2), t('c', 1, 2, 3)]);
  assert.equal(rowCount(placed), 2);
});
