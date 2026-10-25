// Einfügen aus Word und Outlook (src/richtext/wordPaste.ts, Issue #172), mit Ausschnitten, wie die Programme sie in die
// Zwischenablage legen.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { DOMParser } from 'linkedom';
import { cleanPastedHtml, isOfficeHtml, rtfImages } from '../src/richtext/wordPaste.ts';

// Wie DOMParser im Browser: ganze Dokumente (Word) so, wie sie sind, Ausschnitte in ein Dokument gesetzt
const parse = (html: string) =>
  new DOMParser().parseFromString(
    /<html[\s>]/i.test(html) ? html : `<!DOCTYPE html><html><body>${html}</body></html>`,
    'text/html',
  ) as unknown as Document;

// Word 365 (Windows): Aufzählung mit zwei Ebenen, nummerierte Liste, lokales Bild, Ballast
const WORD = `<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head><meta name=Generator content="Microsoft Word 15"><style><!-- p.MsoNormal {margin:0cm;} --></style>
<!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/></o:OfficeDocumentSettings></xml><![endif]--></head>
<body lang=DE style='tab-interval:35.4pt'>
<!--StartFragment-->
<p class=MsoNormal><b><span style='font-size:14.0pt;color:#C00000;mso-ansi-language:DE'>Einladung</span></b><o:p></o:p></p>
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol;mso-fareast-font-family:Symbol'><span style='mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </span></span></span><![endif]>Uniform</p>
<p class=MsoListParagraphCxSpMiddle style='margin-left:72.0pt;mso-add-space:auto;text-indent:-18.0pt;mso-list:l0 level2 lfo1'><span style='font-family:"Courier New"'><span style='mso-list:Ignore'>o<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp; </span></span></span>Mütze</p>
<p class=MsoListParagraphCxSpLast style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><span style='mso-list:Ignore'>·<span>&nbsp; </span></span>Schuhe</p>
<p class=MsoNormal>Ablauf:<o:p></o:p></p>
<p class=MsoListParagraphCxSpFirst style='mso-list:l1 level1 lfo2'><span style='mso-list:Ignore'>1.<span>&nbsp; </span></span>Antreten</p>
<p class=MsoListParagraphCxSpLast style='mso-list:l1 level1 lfo2'><span style='mso-list:Ignore'>2.<span>&nbsp; </span></span>Abmarsch</p>
<p class=MsoNormal><span style='mso-no-proof:yes'><!--[if gte vml 1]><v:shape id="Bild_x0020_1" style='width:90pt;height:30pt'><v:imagedata src="file:///C:/Users/x/AppData/Local/Temp/msohtmlclip1/01/clip_image001.png" o:title=""/></v:shape><![endif]--><![if !vml]><img width=120 height=40 src="file:///C:/Users/x/AppData/Local/Temp/msohtmlclip1/01/clip_image002.png" v:shapes="Bild_x0020_1"><![endif]></span><a name="_Hlk1">Gruß</a><o:p></o:p></p>
<!--EndFragment-->
</body></html>`;

test('erkennt HTML aus Word/Outlook', () => {
  assert.equal(isOfficeHtml(WORD), true);
  assert.equal(isOfficeHtml('<p style="color: red">Gmail</p>'), false);
  assert.equal(isOfficeHtml('<div dir="ltr">Outlook im Web</div>'), false);
});

test('Word: Aufzählungen werden echte, verschachtelte Listen', () => {
  const { html } = cleanPastedHtml(WORD, parse);
  assert.match(html, /<ul><li>Uniform<ul><li>Mütze<\/li><\/ul><\/li><li>Schuhe<\/li><\/ul>/);
  assert.match(html, /<ol><li>Antreten<\/li><li>Abmarsch<\/li><\/ol>/);
  assert.doesNotMatch(html, /·|mso-list|Ignore/);
});

test('Word: Ballast entfernt, Formatierung bleibt', () => {
  const { html } = cleanPastedHtml(WORD, parse);
  assert.doesNotMatch(html, /class=|mso-|o:p|<style|<meta|OfficeDocumentSettings|v:shape|lang=|_Hlk1/i);
  assert.match(html, /<b><span style="font-size:14.0pt; color:#C00000">Einladung<\/span><\/b>/);
  assert.match(html, /Gruß/);
});

test('Word: lokale Bilder ohne Daten werden gezählt und entfernt', () => {
  const { html, missingImages } = cleanPastedHtml(WORD, parse, 0);
  assert.equal(missingImages, 1);
  assert.doesNotMatch(html, /file:|<img/);
});

test('Word: lokale Bilder bekommen der Reihe nach die Daten aus dem RTF-Teil', () => {
  const { html, missingImages } = cleanPastedHtml(WORD, parse, 1);
  assert.equal(missingImages, 0);
  const img = parse(html).querySelector('img');
  assert.deepEqual([img?.getAttribute('data-paste-image'), img?.getAttribute('width'), img?.getAttribute('height')], ['0', '120', '40']);
});

test('nummerierte Liste mit anderem Anfang', () => {
  const { html } = cleanPastedHtml(
    `<p class=MsoNormal style='mso-list:l3 level1 lfo4'><span style='mso-list:Ignore'>3)<span> </span></span>Drei</p>`,
    parse,
  );
  assert.equal(html, '<ol start="3"><li>Drei</li></ol>');
});

test('Gmail und Outlook im Web: Formatierung über Styles bleibt unverändert', () => {
  const gmail = '<div dir="ltr"><b>Hallo</b> <span style="background-color:rgb(255,255,0)">gelb</span><br><ul><li>eins</li></ul></div>';
  assert.equal(cleanPastedHtml(gmail, parse).html, gmail);
  const owa = '<div class="elementToProof" style="font-family: Aptos, sans-serif; font-size: 12pt; color: rgb(0, 0, 0);">Text</div>';
  assert.equal(
    cleanPastedHtml(owa, parse).html,
    '<div style="font-family: Aptos, sans-serif; font-size: 12pt; color: rgb(0, 0, 0)">Text</div>',
  );
});

// RTF-Teil aus Word: dasselbe Bild als PNG (\shppict) und als WMF (\nonshppict, wird übersprungen)
const PNG_HEX =
  '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8cfc0f01f0005000201a5f1b3f40000000049454e44ae426082';
const RTF = `{\\rtf1\\ansi{\\*\\shppict{\\pict{\\*\\picprop\\shplid1025{\\sp{\\sn shapeType}{\\sv 75}}}\\picscalex100\\picscaley100\\piccropl0\\piccropr0\\picw26\\pich26\\picwgoal15\\pichgoal15\\pngblip\\bliptag-1{\\*\\blipuid 1}
${PNG_HEX.slice(0, 64)}
${PNG_HEX.slice(64)}}}{\\nonshppict{\\pict\\picw26\\pich26\\wmetafile8 0100090000}}
{\\pict\\jpegblip\\picw1\\pich1 ffd8ffe000104a464946ffd9}}`;

test('RTF: Bilder als PNG und JPEG, WMF übersprungen', () => {
  const images = rtfImages(RTF);
  assert.deepEqual(
    images.map((i) => i.mime),
    ['image/png', 'image/jpeg'],
  );
  assert.equal(Buffer.from(images[0].bytes).toString('hex'), PNG_HEX);
  assert.deepEqual(Array.from(images[1].bytes.slice(0, 2)), [0xff, 0xd8]);
  assert.deepEqual(rtfImages('{\\rtf1 kein Bild}'), []);
});
