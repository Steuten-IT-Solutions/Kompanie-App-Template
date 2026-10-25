// Excel-Dateien für Importe (src/sheet.ts): erstes Blatt als Zeilen, Datum als TT.MM.JJJJ, Text für den Server.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { strToU8, unzipSync, zipSync } from 'fflate';
import { excelDate, toTsv, xlsxRows } from '../src/sheet.ts';

/** Kleinste .xlsx-Datei: Arbeitsmappe mit einem Blatt, gemeinsame Texte und Formate wie aus Excel. */
function xlsx(sheet: string, opts: { date1904?: boolean; target?: string } = {}): Uint8Array {
  const target = opts.target ?? 'worksheets/sheet1.xml';
  return zipSync({
    '[Content_Types].xml': strToU8('<Types/>'),
    'xl/workbook.xml': strToU8(
      `<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr${opts.date1904 ? ' date1904="1"' : ''}/><sheets><sheet name="Mitglieder" sheetId="1" r:id="rId1"/><sheet name="Zwei" sheetId="2" r:id="rId2"/></sheets></workbook>`,
    ),
    'xl/_rels/workbook.xml.rels': strToU8(
      `<Relationships><Relationship Id="rId2" Target="worksheets/sheet2.xml"/><Relationship Id="rId1" Target="${target}"/></Relationships>`,
    ),
    'xl/sharedStrings.xml': strToU8(
      '<sst><si><t>Vorname</t></si><si><t>Geburtstag</t></si><si><r><t>Jö</t></r><r><t xml:space="preserve">rg </t></r><rPh><t>X</t></rPh></si><si><t>A &amp; B "C"</t></si></sst>',
    ),
    'xl/styles.xml': strToU8(
      '<styleSheet><numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy;@"/><numFmt numFmtId="165" formatCode="0.0&quot; Tage&quot;"/><numFmt numFmtId="166" formatCode="[h]:mm"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="166"/></cellXfs></styleSheet>',
    ),
    [target.startsWith('/') ? target.slice(1) : `xl/${target}`]: strToU8(sheet),
    'xl/worksheets/sheet2.xml': strToU8(
      '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>falsch</t></is></c></row></sheetData></worksheet>',
    ),
  });
}

test('erstes Blatt: gemeinsame Texte, Zahlen, Datum, Lücken und leere Zeilen', () => {
  const rows = xlsxRows(
    xlsx(
      '<worksheet><sheetData>' +
        '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>' +
        '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" s="1"><v>29254</v></c><c r="D2"><v>12</v></c><c r="E2" s="3"><v>1.5</v></c></row>' +
        '<row r="4"><c r="A4" t="inlineStr"><is><t>Otto</t></is></c><c r="B4" s="2"><v>36526.75</v></c><c r="C4" t="str"><f>A4</f><v>Otto</v></c><c r="D4" s="4"><v>0.5</v></c></row>' +
        '<row r="5"><c r="A5" t="s"><v>3</v></c><c r="B5" t="e"><v>#NV</v></c><c r="C5" t="b"><v>1</v></c><c r="D5"/></row>' +
        '<row r="6"><c r="A6" s="1"/></row>' +
        '</sheetData></worksheet>',
    ),
    unzipSync,
  );
  assert.deepEqual(rows, [
    ['Vorname', 'Geburtstag'],
    ['Jörg', '03.02.1980', '', '12', '1,5'],
    [],
    ['Otto', '01.01.2000', 'Otto', '0,5'],
    ['A & B "C"', '', 'WAHR'],
  ]);
});

test('Blatt über die Beziehungen, auch mit absolutem Pfad; Zellen ohne Adresse der Reihe nach', () => {
  const rows = xlsxRows(
    xlsx('<worksheet><sheetData><row><c t="s"><v>0</v></c><c><v>7</v></c></row></sheetData></worksheet>', {
      target: '/xl/worksheets/blatt.xml',
    }),
    unzipSync,
  );
  assert.deepEqual(rows, [['Vorname', '7']]);
});

test('Datum: 1900 und 1904', () => {
  assert.equal(excelDate(1), '31.12.1899', 'vor dem 01.03.1900 weicht Excel um einen Tag ab');
  assert.equal(excelDate(61), '01.03.1900');
  assert.equal(excelDate(45952), '22.10.2025');
  assert.equal(excelDate(0, true), '01.01.1904');
  const rows = xlsxRows(
    xlsx('<worksheet><sheetData><row r="1"><c r="A1" s="1"><v>27792</v></c></row></sheetData></worksheet>', { date1904: true }),
    unzipSync,
  );
  assert.deepEqual(rows, [['03.02.1980']]);
});

test('keine Excel-Datei', () => {
  assert.throws(() => xlsxRows(strToU8('Vorname;Nachname'), unzipSync), /keine gültige Excel-Datei/);
  assert.throws(() => xlsxRows(zipSync({ 'a.txt': strToU8('x') }), unzipSync), /keine gültige Excel-Datei/);
});

test('Text für den Server: Tabulator, Anführungszeichen verdoppelt, Umbrüche als Leerzeichen', () => {
  assert.equal(
    toTsv([
      ['Vorname', 'Nachname'],
      ['Anna', 'Groß\nKlein'],
      ['Max "Maxi"', 'B\tC'],
    ]),
    'Vorname\tNachname\nAnna\tGroß Klein\n"Max ""Maxi"""\tB C',
  );
});
