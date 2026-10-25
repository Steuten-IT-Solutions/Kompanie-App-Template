// Lizenzen (Issue #173, build/licenses.ts): Positivliste, Wahl bei „A OR B“, Abgleich von Lizenzangabe und Lizenzdatei,
// und dieselbe Projektlizenz an allen Stellen.
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { chooseLicense, composerNotices, copyrightLines, noticesText, npmNotice, packageDir, readProject } from '../build/licenses.ts';

const root = join(import.meta.dirname, '../../..');
const MIT = 'MIT License\n\nCopyright (c) 2024 Jemand\n\nPermission is hereby granted, free of charge, to any person …';
const GPL = 'GNU GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007\n…';

function fakePackage(pkg: Record<string, unknown>, files: Record<string, string>): string {
  const dir = mkdtempSync(join(tmpdir(), 'lizenz-'));
  writeFileSync(join(dir, 'package.json'), JSON.stringify({ name: 'beispiel', version: '1.0.0', ...pkg }));
  for (const [name, text] of Object.entries(files)) writeFileSync(join(dir, name), text);
  return dir;
}

test('Erlaubte Lizenzen werden angenommen, GPL, AGPL und proprietäre abgelehnt', () => {
  for (const id of [
    'MIT',
    'ISC',
    '0BSD',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'Apache-2.0',
    'MPL-2.0',
    'OFL-1.1',
    'LGPL-3.0-or-later',
    'LGPL-2.1-only',
  ]) {
    assert.deepEqual(chooseLicense(id), [id]);
  }
  assert.deepEqual(chooseLicense('LGPL-2.1+'), ['LGPL-2.1-or-later']);
  assert.deepEqual(chooseLicense('LGPL-2.1'), ['LGPL-2.1-only']);
  assert.deepEqual(chooseLicense('LGPL-2.1-only OR LGPL-2.1-or-later'), ['LGPL-2.1-or-later']);
  for (const bad of [
    'GPL-2.0-or-later',
    'GPL-3.0-only',
    'AGPL-3.0-or-later',
    'UNLICENSED',
    'SEE LICENSE IN LICENSE.md',
    'LGPL-2.0-only',
    '',
    'MIT AND',
  ]) {
    assert.equal(chooseLicense(bad), null, bad);
  }
  assert.equal(chooseLicense('Apache-2.0 WITH LLVM-exception'), null);
});

test('Bei einer Wahl die freizügigere, bei „AND“ beide; ein GPL-Teil macht „AND“ unzulässig', () => {
  assert.deepEqual(chooseLicense('(MPL-2.0 OR Apache-2.0)'), ['Apache-2.0']);
  assert.deepEqual(chooseLicense('GPL-2.0-only OR MIT'), ['MIT']);
  assert.deepEqual(chooseLicense('MIT AND OFL-1.1'), ['MIT', 'OFL-1.1']);
  assert.equal(chooseLicense('MIT AND GPL-3.0-only'), null);
  assert.deepEqual(chooseLicense('(MIT AND BSD-3-Clause) OR GPL-2.0-only'), ['MIT', 'BSD-3-Clause']);
});

test('Lizenzangabe und Lizenzdatei müssen zusammenpassen', () => {
  const ok = npmNotice(fakePackage({ license: 'MIT' }, { LICENSE: MIT }));
  assert.equal(ok.license, 'MIT');
  assert.deepEqual(ok.copyright, ['Copyright (c) 2024 Jemand']);
  // Angabe MIT, Datei aber GPL: Abbruch
  assert.throws(() => npmNotice(fakePackage({ license: 'MIT' }, { LICENSE: GPL })), /keine Lizenzdatei mit dem Text der Lizenz MIT/);
  assert.throws(() => npmNotice(fakePackage({ license: 'MIT' }, {})), /vorhanden: keine/);
  assert.throws(() => npmNotice(fakePackage({ license: 'GPL-3.0-or-later' }, { LICENSE: GPL })), /nicht erlaubt/);
  assert.throws(() => npmNotice(fakePackage({}, { LICENSE: MIT })), /keine Lizenzangabe/);
});

test('Bei mehreren Lizenzdateien die der gewählten Lizenz, dazu NOTICE', () => {
  const dir = fakePackage(
    { license: '(MPL-2.0 OR Apache-2.0)' },
    {
      'LICENSE-MPL': 'Mozilla Public License Version 2.0\n…',
      'LICENSE-APACHE': 'Apache License\n Version 2.0, January 2004\n…',
      NOTICE: 'Beispiel\nCopyright 2020 Beispiel GmbH',
    },
  );
  const n = npmNotice(dir);
  assert.equal(n.license, 'Apache-2.0');
  assert.match(n.text, /^Apache License/);
  assert.doesNotMatch(n.text, /Mozilla/);
  assert.match(n.text, /Copyright 2020 Beispiel GmbH/);
});

test('Ausgelieferte npm-Pakete: Angaben stimmen mit den Paketdaten überein', () => {
  const nm = join(root, 'node_modules');
  const expected: Record<string, string> = {
    react: 'MIT',
    'react-dom': 'MIT',
    scheduler: 'MIT',
    fflate: 'MIT',
    'pdfjs-dist': 'Apache-2.0',
    dompurify: 'Apache-2.0',
    '@fontsource/oswald': 'OFL-1.1',
    '@fontsource/source-sans-3': 'OFL-1.1',
    '@fontsource/roboto-slab': 'Apache-2.0',
  };
  for (const [name, license] of Object.entries(expected)) {
    assert.equal(npmNotice(join(nm, name)).license, license, name);
  }
  // pdf.js nennt das Copyright nur in seinen Programmdateien
  const pdf = npmNotice(join(nm, 'pdfjs-dist'), [join(nm, 'pdfjs-dist/legacy/build/pdf.min.mjs')]);
  assert.ok(pdf.copyright.some((c) => /Mozilla Foundation/.test(c)));
  // Modul-IDs aus dem Build → Paketordner (auch CommonJS-Hüllen mit \0 und ?…); eigener Code gehört zu keinem Paket
  assert.equal(packageDir('\0' + join(nm, 'react/cjs/react.production.min.js') + '?commonjs-exports'), join(nm, 'react'));
  assert.equal(packageDir(join(nm, '@fontsource/oswald/latin-500.css')), join(nm, '@fontsource/oswald'));
  assert.equal(packageDir(join(root, 'packages/frontend/src/App.tsx')), null);
});

test('PHP-Bibliotheken aus composer.lock (packages) werden geprüft', () => {
  const dir = mkdtempSync(join(tmpdir(), 'lizenz-composer-'));
  const lock = (license: string[]) =>
    writeFileSync(
      join(dir, 'composer.lock'),
      JSON.stringify({ packages: [{ name: 'acme/mailer', version: 'v1.2.3', license }], 'packages-dev': [] }),
    );
  lock(['LGPL-2.1-or-later']);
  assert.throws(() => composerNotices(dir), /vendor\/acme\/mailer fehlt/);
  mkdirSync(join(dir, 'vendor/acme/mailer'), { recursive: true });
  writeFileSync(join(dir, 'vendor/acme/mailer/LICENSE'), 'GNU LESSER GENERAL PUBLIC LICENSE\n Version 2.1, February 1999\n…');
  const [n] = composerNotices(dir);
  assert.equal(n.name, 'acme/mailer');
  assert.equal(n.version, '1.2.3');
  assert.equal(n.license, 'LGPL-2.1-or-later');
  lock(['GPL-2.0-or-later']);
  assert.throws(() => composerNotices(dir), /nicht erlaubt/);
  // Im Repo: PHPMailer (Issue #172), mit den Urhebern aus den Dateiköpfen statt der Platzhalter aus dem LGPL-Text
  const repo = composerNotices(root);
  assert.deepEqual(
    repo.map((x) => [x.name, x.license]),
    [['phpmailer/phpmailer', 'LGPL-2.1-only']],
  );
  assert.ok(repo[0].copyright.includes('@copyright 2012 - 2020 Marcus Bointon'), repo[0].copyright.join('\n'));
  assert.ok(!repo[0].copyright.some((l) => l.includes('<year>')));
});

test('Copyright-Zeilen ohne Platzhalter und Rechtstext', () => {
  assert.deepEqual(
    copyrightLines(' * Copyright 2024 Mozilla Foundation\n   Copyright [yyyy] [name of copyright owner]\n 2. Grant of Copyright License.'),
    ['Copyright 2024 Mozilla Foundation'],
  );
  assert.deepEqual(copyrightLines('/*! @license DOMPurify | (c) Cure53 and other contributors */'), [
    '@license DOMPurify | (c) Cure53 and other contributors',
  ]);
  assert.deepEqual(copyrightLines('(c) You must retain, in the Source form'), []);
  assert.deepEqual(copyrightLines('    Copyright (C) <year>  <name of author>'), []);
});

test('THIRD-PARTY-NOTICES nennt Projektlizenz, Quellcode und je Baustein den Lizenztext', () => {
  const project = readProject(root);
  const text = noticesText(project, [npmNotice(join(root, 'node_modules/fflate'))]);
  assert.match(text, /GPL-2\.0-or-later/);
  assert.match(text, /https:\/\/github\.com\/Steuten-IT-Solutions\/Kompanie-App-Template/);
  assert.match(text, /fflate 0\.8\.\d+\nLizenz: MIT/);
  assert.match(text, /Permission is hereby granted/);
});

test('Projektlizenz GPL-2.0-or-later überall gleich, LICENSE mit „or later“', () => {
  const json = (p: string) => JSON.parse(readFileSync(join(root, p), 'utf8'));
  const file = (p: string) => readFileSync(join(root, p), 'utf8');
  for (const p of ['package.json', 'packages/frontend/package.json', 'packages/branding/package.json', 'composer.json']) {
    assert.equal(json(p).license, 'GPL-2.0-or-later', p);
  }
  assert.match(file('adapters/wordpress/kompanie-app.php'), /^ \* License:\s+GPL-2\.0-or-later$/m);
  assert.match(file('adapters/standalone/index.php'), /@license GPL-2\.0-or-later/);
  assert.match(file('installer/install.php'), /@license GPL-2\.0-or-later/);
  const license = file('LICENSE');
  assert.match(license, /either version 2 of the License, or \(at your option\) any later\s+version/);
  assert.match(license, /GNU GENERAL PUBLIC LICENSE\s+Version 2, June 1991/);
  assert.equal(readProject(root).repository, 'https://github.com/Steuten-IT-Solutions/Kompanie-App-Template');
});
