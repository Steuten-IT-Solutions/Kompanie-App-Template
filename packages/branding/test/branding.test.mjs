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

test('Dunkler Hintergrund wird verständlich abgelehnt', () => {
  const t = deriveTheme({ primary: '#2e6b3f', background: '#222222' });
  assert.match(t.errors.join(), /Hintergrundfarbe ist zu dunkel/);
});

test('kompanie.json: Pflichtfelder, Standardwerte, verständliche Fehler', () => {
  const dir = tmp({ name: 'TV Beispiel', colors: { primary: '#123456' } });
  const cfg = loadConfig(join(dir, 'kompanie.json'));
  assert.equal(cfg.shortName, 'TV Beispiel');
  assert.equal(cfg.greeting, 'Hallo, {name}');
  assert.equal(cfg.labels.roles.manager, 'Verwalter');
  assert.equal(cfg.features.gallery, true);
  assert.throws(() => loadConfig(join(tmp({ name: 'x', colors: { primary: 'blau' } }), 'kompanie.json')), BrandError);
  assert.throws(() => loadConfig(join(tmp({ colors: { primary: '#123456' } }), 'kompanie.json')), /nicht gültig/);
});

test('Initialen als Ersatz-Logo', () => {
  assert.match(initialsSvg('Musterkompanie Beispielstadt', '#000', '#fff'), />MB</);
  assert.match(initialsSvg('Verein', '#000', '#fff'), />VE</);
  assert.doesNotMatch(initialsSvg('<script> x', '#000', '#fff'), /<script>/);
});

test('Build erzeugt Icons aus einem rechteckigen JPG-Logo', async () => {
  const logo = await sharp({ create: { width: 900, height: 500, channels: 3, background: '#aa3300' } }).jpeg().toBuffer();
  const dir = tmp({ name: 'Schützen Musterdorf', colors: { primary: '#2e6b3f' }, font: 'Oswald' }, { 'logo.jpg': logo });
  const out = join(dir, 'out');
  const { warnings } = await build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out, publicDir: out });
  assert.deepEqual(warnings, []);
  for (const [file, size] of [['icon-512.png', 512], ['icon-192.png', 192], ['icon-maskable-512.png', 512], ['apple-touch-icon.png', 180], ['favicon-32.png', 32]]) {
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
  const tiny = await sharp({ create: { width: 64, height: 64, channels: 3, background: '#000' } }).png().toBuffer();
  const dir = tmp({ name: 'X', colors: { primary: '#2e6b3f' } }, { 'logo.png': tiny });
  await assert.rejects(build({ config: join(dir, 'kompanie.json'), assets: join(dir, 'assets'), out: join(dir, 'o'), publicDir: join(dir, 'o') }), /zu klein/);
  const empty = tmp({ name: 'Ohne Logo', colors: { primary: '#2e6b3f' } });
  const r = await build({ config: join(empty, 'kompanie.json'), assets: join(empty, 'assets'), out: join(empty, 'o'), publicDir: join(empty, 'o') });
  assert.match(r.warnings.join(), /Initialen/);
  assert.ok(existsSync(join(empty, 'o', 'icons', 'icon-512.png')));
});
