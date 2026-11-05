// Listen (src/lists.ts, Issue #121): CSV für ein deutsches Excel, Schutz vor CSV-Injection, Dateiname.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { columnWidths, csvField, csvFileName, csvPhone, longBirthday, sortRowsBy, toCsv } from '../src/lists.ts';

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

test('Handynummer in der CSV mit 00 statt +, damit Excel kein Apostroph zeigt', () => {
  assert.equal(csvPhone('+49 171 1234567'), '0049 171 1234567');
  assert.equal(csvField(csvPhone('+49 171 1234567')), '0049 171 1234567');
  assert.equal(csvPhone('+12125550100'), '+12125550100', 'ohne Leerzeichen läse Excel eine Zahl ohne führende Nullen');
  assert.equal(csvPhone('0171 1234567'), '0171 1234567');
  assert.equal(csvPhone('+=1'), '+=1');
});

test('Spaltenbreite automatisch: so breit wie der Inhalt, die anderen teilen sich den Rest', () => {
  // Automatische Spalte (Mitte) mit 80: die beiden anderen 2 : 1 aus 320
  assert.deepEqual(columnWidths([2, 2.5, 1], [0, 0, 0], 400, [null, 80, null]), [213, 80, 107]);
  // Mindestbreiten der anderen gelten weiter
  assert.deepEqual(columnWidths([1, 2.5, 1], [200, 0, 0], 400, [null, 80, null]), [200, 80, 120]);
  // Nur automatische: füllen die Breite im Verhältnis
  assert.deepEqual(columnWidths([2.5, 2.5], [0, 0], 400, [100, 300]), [100, 300]);
  assert.deepEqual(columnWidths([2.5, 2.5], [0, 0], 400, [50, 150]), [100, 300]);
  // Zu breit: Die anderen behalten je ein Fünfundzwanzigstel, die automatischen werden im Verhältnis schmaler
  assert.deepEqual(columnWidths([2.5, 2.5, 1], [0, 0, 0], 500, [600, 400, null]), [288, 192, 20]);
});

test('Geburtstag in langer Form', () => {
  assert.equal(longBirthday({ day: 9, month: 3, year: 1990 }), '9. März 1990');
  assert.equal(longBirthday({ day: 24, month: 12, year: null }), '24. Dezember');
  assert.equal(longBirthday({ day: 1, month: 1 }), '1. Januar');
});

test('Zeilen nach einer Spalte sortieren: Zahlen nach Wert, Text wie die Mitgliederliste, leere am Ende', () => {
  const rows = ['Özdemir', '', 'Müller', 'Zander', 'Abel', 'Muster10', 'Muster2'];
  assert.deepEqual(
    sortRowsBy(rows, (r) => r, 'asc'),
    ['Abel', 'Müller', 'Muster2', 'Muster10', 'Özdemir', 'Zander', ''],
  );
  assert.deepEqual(
    sortRowsBy(rows, (r) => r, 'desc'),
    ['Zander', 'Özdemir', 'Muster10', 'Muster2', 'Müller', 'Abel', ''],
  );
  const nums = [{ n: 10 }, { n: null }, { n: 2 }, { n: 2, x: 1 }];
  assert.deepEqual(
    sortRowsBy(nums, (r) => r.n, 'asc'),
    [{ n: 2 }, { n: 2, x: 1 }, { n: 10 }, { n: null }],
    'gleiche Werte in bisheriger Reihenfolge',
  );
  assert.deepEqual(
    sortRowsBy(nums, (r) => r.n, 'desc'),
    [{ n: 10 }, { n: 2 }, { n: 2, x: 1 }, { n: null }],
  );
});
