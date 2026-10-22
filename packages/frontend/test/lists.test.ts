// Listen (src/lists.ts, Issue #121): CSV für ein deutsches Excel, Schutz vor CSV-Injection, Dateiname, Sortieren nach Namen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { csvField, csvFileName, sortRowsByName, toCsv, type SortableRow } from '../src/lists.ts';

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

test('Nach Namen sortieren: Nachname, Vorname, Umlaute wie Grundbuchstaben; leere und gelöschte Zeilen ans Ende', () => {
  const member = (first: string, last: string): SortableRow => ({
    kind: 'member',
    name: `${first} ${last}`.trim(),
    first_name: first,
    last_name: last,
  });
  const rows: SortableRow[] = [
    { kind: 'free', name: '' },
    member('Ada', 'Zander'),
    { kind: 'member', name: '', deleted: true },
    { kind: 'free', name: 'Gast Nachbarverein' },
    member('Bernd', 'Özdemir'),
    member('Kasse', ''),
    member('Anna', 'Müller'),
    member('Zoe', 'Müller'),
  ];
  assert.deepEqual(
    sortRowsByName(rows).map((r) => (r.deleted ? '(gelöscht)' : r.name)),
    ['Kasse', 'Anna Müller', 'Zoe Müller', 'Gast Nachbarverein', 'Bernd Özdemir', 'Ada Zander', '', '(gelöscht)'],
  );
});
