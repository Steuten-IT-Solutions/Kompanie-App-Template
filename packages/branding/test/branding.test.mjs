import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import sharp from 'sharp';
import { contrast, ensureContrast, mix } from '../color.mjs';
import { deriveTheme } from '../theme.mjs';
import { BrandError, build, initialsSvg, loadConfig } from '../brand.mjs';

function tmp(config, files = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'brand-'));
  writeFileSync(join(dir, 'kompanie.json'), JSON.stringify(config));
  mkdirSync(join(dir, 'assets'), { recursive: true });
  for (const [name, data] of Object.entries(files)) writeFileSync(join(dir, 'assets', name), data);
  return dir;
}

test('Kontrast nach WCAG', () => {
  assert.equal(contrast('#ffffff', '#000000').toFixed(1), '21.0');
  assert.equal(mix('#000000', '#ffffff', 0.5), '#808080');
  const fixed = ensureContrast('#ffe600', '#ffffff', 4.5);
  assert.ok(contrast(fixed, '#ffffff') >= 4.5);
});

test('Jede Hauptfarbe ergibt lesbare Farben', () => {
  for (const primary of ['#000000', '#ffffff', '#ffe600', '#00ff00', '#2e6b3f', '#1f4498', '#888888', '#ff0000']) {
    const t = deriveTheme({ primary });
    assert.deepEqual(t.errors, [], primary);
    assert.ok(contrast(t.light['on-primary'], t.light.primary) >= 4.5, `${primary}: Schrift auf Hauptfarbe`);
    assert.ok(contrast(t.light['primary-text'], t.light.card) >= 4.5, `${primary}: Text in Hauptfarbe`);
    assert.ok(contrast(t.light.ink, t.light.bg) >= 4.5, `${primary}: Text auf Hintergrund`);
    assert.ok(contrast(t.dark['accent-text'], t.dark.card) >= 4.5, `${primary}: Akzent im Dunkelmodus`);
    assert.ok(contrast(t.dark.ink, t.dark.bg) >= 4.5, `${primary}: Text im Dunkelmodus`);
    assert.ok(contrast(t.light.muted, t.light.card) >= 4.5, `${primary}: gedämpfter Text`);
  }
});

test('Alle Schrift-Flächen-Paare sind lesbar (Tableiste, Umschalter, Kennzahlen, gedämpfter Text)', () => {
  const cases = [
    { primary: '#00a0e0' },
    { primary: '#ff8800' },
    { primary: '#ffd700' },
    { primary: '#8b0000', accent: '#000080' },
    { primary: '#2e6b3f', accent: '#d9a441' },
    { primary: '#2e6b3f', background: '#c8c8c8' },
    { primary: '#1f4498', background: '#e8e0d0' },
  ];
  for (const colors of cases) {
    const l = deriveTheme(colors).light;
    const d = deriveTheme(colors).dark;
    const id = JSON.stringify(colors);
    const surface = Math.min(4.5, contrast(l['on-primary'], l.primary));
    const pairs = [
      ['on-primary', 'primary-deep', surface],
      ['on-primary-soft', 'primary', surface],
      ['on-primary-soft', 'primary-deep', surface],
      ['on-primary', 'bar-top', 4.5],
      ['on-primary', 'bar-bottom', 4.5],
      ['on-bar-muted', 'bar-top', 4.5],
      ['on-bar-muted', 'bar-bottom', 4.5],
      ['accent-on-primary', 'primary', 4.5],
      ['on-warn', 'warn', 4.5],
      ['muted', 'card', 4.5],
      ['muted', 'bg', 4.5],
      ['accent-dark', 'card', 4.5],
      ['accent-dark', 'bg', 4.5],
      ['on-warn', 'warn-surface', 4.5],
      ['on-danger', 'danger-surface', 4.5],
    ];
    for (const [fg, bg, min] of pairs) {
      assert.ok(contrast(l[fg], l[bg]) >= min - 1e-9, `${id}: ${fg} auf ${bg} = ${contrast(l[fg], l[bg]).toFixed(2)}`);
    }
    assert.ok(contrast(d.muted, d.bg) >= 4.5, `${id}: gedämpfter Text im Dunkelmodus`);
    assert.ok(contrast(d['accent-dark'], d.card) >= 4.5, `${id}: Akzent auf Karte im Dunkelmodus`);
    assert.ok(contrast(d['hint-strong'], d.card) >= 4.5 && contrast(d['hint-text'], d.card) >= 4.5, `${id}: Hinweis im Dunkelmodus`);
    // Dunkelmodus übernimmt warn-surface und on-warn aus dem hellen Schema.
    assert.ok(!('warn-surface' in d) && contrast(l['on-warn'], l['warn-surface']) >= 4.5, `${id}: Warnfläche im Dunkelmodus`);
    assert.ok(!('danger-surface' in d) && !('on-danger' in d), `${id}: Wartungshinweis im Dunkelmodus wie hell`);
    const hintBg = mix(l.card, l.accent, 0.16);
    assert.ok(contrast(l['hint-strong'], hintBg) >= 4.5 && contrast(l['hint-text'], hintBg) >= 4.5, `${id}: Hinweisbanner`);
  }
});

test('Dunkler Hintergrund wird verständlich abgelehnt', () => {
  const t = deriveTheme({ primary: '#2e6b3f', background: '#222222' });
  assert.match(t.errors.join(), /Hintergrundfarbe ist zu dunkel/);
});

test('kompanie.json: Pflichtfelder, Standardwerte, verständliche Fehler', () => {
  const dir = tmp({ name: 'TV Beispiel', colors: { primary: '#123456' } });
  const cfg = loadConfig(join(dir, 'kompanie.json'));
  assert.equal(cfg.shortName, 'TV Beispiel');
  assert.equal(cfg.greeting, 'Hallo, {name}');
  assert.equal(cfg.labels.roles.manager, 'Leitung');
  assert.equal(cfg.labels.roles.organizer, 'Verwalter');
  assert.equal(cfg.legacyFeatures, false);
  assert.equal(
    loadConfig(join(tmp({ name: 'x', colors: { primary: '#123456' }, features: { news: false } }), 'kompanie.json')).legacyFeatures,
    true,
    'veraltetes „features“ ist gültig, kein Abbruch',
  );
  assert.throws(() => loadConfig(join(tmp({ name: 'x', colors: { primary: 'blau' } }), 'kompanie.json')), BrandError);
  assert.throws(() => loadConfig(join(tmp({ colors: { primary: '#123456' } }), 'kompanie.json')), /nicht gültig/);
});

test('Initialen als Ersatz-Logo', () => {
  assert.match(initialsSvg('Musterkompanie Beispielstadt', '#000', '#fff'), />MB</);
  assert.match(initialsSvg('Verein', '#000', '#fff'), />VE</);
  assert.doesNotMatch(initialsSvg('<script> x', '#000', '#fff'), /<script>/);
});

test('Build erzeugt Icons aus einem rechteckigen JPG-Logo', async () => {
  const logo = await sharp({ create: { width: 900, height: 500, channels: 3, background: '#aa3300' } })
    .jpeg()
    .toBuffer();
  const dir = tmp({ name: 'Schützen Musterdorf', colors: { primary: '#2e6b3f' }, font: 'Oswald' }, { 'logo.jpg': logo });
  const out = join(dir, 'out');
  const { warnings } = await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out, publicDir: out });
  assert.deepEqual(warnings, []);
  for (const [file, size] of [
    ['icon-512.png', 512],
    ['icon-192.png', 192],
    ['icon-maskable-512.png', 512],
    ['apple-touch-icon.png', 180],
    ['favicon-32.png', 32],
  ]) {
    const meta = await sharp(join(out, 'icons', file)).metadata();
    assert.equal(meta.width, size, file);
    assert.equal(meta.height, size, file);
  }
  const brand = JSON.parse(readFileSync(join(out, 'brand.json'), 'utf8'));
  assert.equal(brand.name, 'Schützen Musterdorf');
  assert.match(readFileSync(join(out, 'fonts.ts'), 'utf8'), /@fontsource\/oswald/);
  assert.match(readFileSync(join(out, 'theme.css'), 'utf8'), /--primary: #2e6b3f/);
});

test('Zu kleines Logo wird abgelehnt, kein Logo ergibt Initialen', async () => {
  const tiny = await sharp({ create: { width: 64, height: 64, channels: 3, background: '#000' } })
    .png()
    .toBuffer();
  const dir = tmp({ name: 'X', colors: { primary: '#2e6b3f' } }, { 'logo.png': tiny });
  await assert.rejects(
    build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') }),
    /zu klein/,
  );
  const empty = tmp({ name: 'Ohne Logo', colors: { primary: '#2e6b3f' } });
  const r = await build({
    config: join(empty, 'kompanie.json'),
    assets: join(empty, 'assets'),
    out: join(empty, 'o'),
    publicDir: join(empty, 'o'),
  });
  assert.match(r.warnings.join(), /Initialen/);
  assert.ok(existsSync(join(empty, 'o', 'icons', 'icon-512.png')));
});

async function pixel(file, x, y) {
  const { data } = await sharp(file).extract({ left: x, top: y, width: 1, height: 1 }).raw().toBuffer({ resolveWithObject: true });
  return [...data];
}

test('Logo-Verarbeitung: Vollfläche bleibt, durchsichtiger Rand fällt weg, EXIF-Drehung, kleine SVG', async () => {
  // Rotes Quadrat mit weißem Kreis: Der rote Hintergrund gehört zum Logo.
  const full = await sharp({ create: { width: 600, height: 600, channels: 3, background: '#cc0000' } })
    .composite([
      {
        input: Buffer.from(
          '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><circle cx="300" cy="300" r="80" fill="#fff"/></svg>',
        ),
      },
    ])
    .png()
    .toBuffer();
  let dir = tmp({ name: 'A', colors: { primary: '#2e6b3f' } }, { 'logo.png': full });
  await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') });
  assert.equal((await sharp(join(dir, 'o', 'logo.png')).metadata()).width, 512, 'nicht zugeschnitten');
  const small = await sharp(join(dir, 'o', 'logo-small.webp')).metadata();
  assert.equal(small.format, 'webp');
  assert.equal(small.height, 144, 'Kopfzeilen-Logo in dreifacher Größe');
  const [r, g, b] = await pixel(join(dir, 'o', 'icons', 'icon-512.png'), 60, 60);
  assert.ok(r > 180 && g < 40 && b < 40, `Ecke des Icons ist rot (${r},${g},${b})`);

  // Durchsichtiger Rand wird entfernt.
  const padded = await sharp({ create: { width: 800, height: 800, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
    .composite([
      {
        input: await sharp({ create: { width: 400, height: 200, channels: 3, background: '#0000cc' } })
          .png()
          .toBuffer(),
      },
    ])
    .png()
    .toBuffer();
  dir = tmp({ name: 'B', colors: { primary: '#2e6b3f' } }, { 'logo.png': padded });
  await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') });
  const m = await sharp(join(dir, 'o', 'logo.png')).metadata();
  assert.deepEqual([m.width, m.height], [400, 200]);

  // Handyfoto mit EXIF-Ausrichtung 6 (90° gedreht): Ergebnis hochkant.
  const exif = await sharp({ create: { width: 800, height: 600, channels: 3, background: '#336699' } })
    .jpeg()
    .withMetadata({ orientation: 6 })
    .toBuffer();
  dir = tmp({ name: 'C', colors: { primary: '#2e6b3f' } }, { 'logo.jpg': exif });
  await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') });
  const e = await sharp(join(dir, 'o', 'logo.png')).metadata();
  assert.ok(e.height > e.width, `hochkant (${e.width}×${e.height})`);

  // SVG mit kleiner Eigengröße wird scharf gerastert.
  const svg =
    '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><rect width="24" height="24" fill="#123"/></svg>';
  dir = tmp({ name: 'D', colors: { primary: '#2e6b3f' } }, { 'logo.svg': svg });
  await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') });
  assert.equal((await sharp(join(dir, 'o', 'logo.png')).metadata()).width, 512);
});

test('Leere oder nur aus Leerzeichen bestehende Namen und Bezeichnungen werden abgelehnt', () => {
  assert.throws(() => loadConfig(join(tmp({ name: '   ', colors: { primary: '#123456' } }), 'kompanie.json')), /nicht gültig/);
  assert.throws(
    () => loadConfig(join(tmp({ name: 'A', labels: { sections: { news: '' } }, colors: { primary: '#123456' } }), 'kompanie.json')),
    /nicht gültig/,
  );
});
