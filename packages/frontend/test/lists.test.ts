// Listen (src/lists.ts, Issue #121): CSV für ein deutsches Excel, Schutz vor CSV-Injection, Dateiname.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { columnWidths, csvField, csvFileName, toCsv } from '../src/lists.ts';

const BOM = String.fromCharCode(0xfeff);

test('CSV: BOM, Semikolon, CRLF; Felder nur bei Bedarf in Anführungszeichen', () => {
  const csv = toCsv(
    ['Nr.', 'Name', 'Unterschrift'],
    [
      [1, 'Anna Müller', ''],
      [2, 'Bernd "Bär" Özdemir', ''],
    ],
  );
  assert.equal(csv, BOM + 'Nr.;Name;Unterschrift\r\n1;Anna Müller;\r\n2;"Bernd ""Bär"" Özdemir";\r\n');
  assert.equal(csvField('a;b'), '"a;b"');
  assert.equal(csvField('Zeile 1\nZeile 2'), '"Zeile 1\nZeile 2"');
});

test('CSV: Zahlen mit Komma, Texte mit Formelzeichen entschärft', () => {
  assert.equal(csvField(1.5), '1,5');
  assert.equal(csvField(-3), '-3', 'eigene Zahlen bleiben Zahlen');
  for (const s of ['=SUMME(A1:A2)', '+1', '-1', '@A1', '\tx', '\rx']) {
    assert.ok(csvField(s).replace(/^"/, '').startsWith("'"), s);
  }
  assert.equal(csvField('=1;2'), `"'=1;2"`);
  assert.equal(csvField('Anna'), 'Anna');
});

test('Dateiname aus dem Titel', () => {
  assert.equal(csvFileName('Ausflug: Xanten / 2026?'), 'Ausflug Xanten 2026.csv');
  assert.equal(csvFileName('  '), 'liste.csv');
  assert.equal(csvFileName('a'.repeat(120)), 'a'.repeat(80) + '.csv');
});

test('Spaltenbreiten: nach Anteil, Namen mindestens so breit wie verlangt, sonst nach Anteil', () => {
  assert.deepEqual(columnWidths([2, 1, 1], [0, 0, 0], 400), [200, 100, 100]);
  // Rundungsrest an die letzte Spalte
  assert.deepEqual(columnWidths([1, 1, 1], [0, 0, 0], 100), [33, 33, 34]);
  // Die erste Spalte braucht 250: Die anderen teilen sich den Rest nach Anteil
  assert.deepEqual(columnWidths([2, 1, 1], [250, 0, 0], 400), [250, 75, 75]);
  // Verlangt, aber ohnehin breit genug: nichts ändert sich
  assert.deepEqual(columnWidths([2, 1, 1], [150, 0, 0], 400), [200, 100, 100]);
  // Zwei verbreiterte Spalten, auch eine erst in der zweiten Runde
  assert.deepEqual(columnWidths([1, 1, 2], [150, 120, 0], 400), [150, 120, 130]);
  // Passt nicht: nach Anteil
  assert.deepEqual(columnWidths([1, 1], [300, 300], 400), [200, 200]);
  assert.deepEqual(columnWidths([], [], 400), []);
});
