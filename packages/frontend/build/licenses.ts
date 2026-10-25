// Lizenzen der ausgelieferten Drittanbieter-Bausteine (Issue #173), beim Bauen aus dem tatsächlichen Inhalt der App
// erzeugt: Jedes Modul aus node_modules, das in den Build eingeht, bringt sein Paket in die Liste; Pakete, die nur beim
// Bauen laufen (sharp, ajv, TypeScript …), kommen so nie hinein. Dazu die PHP-Bibliotheken aus composer.lock
// (packages, ohne packages-dev). Jede Lizenzangabe wird gegen die Positivliste geprüft und die Lizenzdatei des Pakets
// gegen die Angabe; passt etwas nicht, bricht der Build ab. Ergebnis: THIRD-PARTY-NOTICES.txt neben der App und die
// Daten der Seite „Lizenzen“ (src/views/Lizenzen.tsx). Begründung der Positivliste: docs/LIZENZEN.md.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import type { Plugin } from 'vite';

/**
 * Erlaubte Lizenzen ausgelieferter Bausteine, in der Reihenfolge, in der bei einer Wahl („A OR B“) gewählt wird:
 * freizügige vor schwachem Copyleft, damit der eigene Code später auch unter einer geschlossenen Lizenz angeboten
 * werden kann. GPL, AGPL und proprietäre Lizenzen fehlen bewusst.
 */
export const ALLOWED = [
  'MIT',
  'ISC',
  '0BSD',
  'BSD-2-Clause',
  'BSD-3-Clause',
  'Apache-2.0',
  'MPL-2.0',
  'LGPL-2.1-or-later',
  'LGPL-3.0-or-later',
  'LGPL-2.1-only',
  'OFL-1.1',
] as const;

/** Ältere SPDX-Schreibweisen derselben Lizenzen. */
const ALIASES: Record<string, string> = {
  'LGPL-2.1+': 'LGPL-2.1-or-later',
  'LGPL-3.0+': 'LGPL-3.0-or-later',
  'LGPL-2.1': 'LGPL-2.1-only',
};

/** Woran der Text der Lizenzdatei zu erkennen ist (Kleinbuchstaben, Leerraum zusammengefasst). */
const FINGERPRINTS: Record<string, (t: string) => boolean> = {
  MIT: (t) => t.includes('permission is hereby granted, free of charge'),
  ISC: (t) =>
    t.includes(
      'permission to use, copy, modify, and/or distribute this software for any purpose with or without fee is hereby granted, provided that',
    ),
  '0BSD': (t) =>
    t.includes('permission to use, copy, modify, and/or distribute this software for any purpose with or without fee is hereby granted.'),
  'BSD-2-Clause': (t) => t.includes('redistribution and use in source and binary forms') && !t.includes('neither the name'),
  'BSD-3-Clause': (t) => t.includes('redistribution and use in source and binary forms') && t.includes('neither the name'),
  'Apache-2.0': (t) => /apache license,? version 2\.0/.test(t),
  'MPL-2.0': (t) => /mozilla public license,? version 2\.0/.test(t),
  'LGPL-2.1-or-later': (t) => t.includes('gnu lesser general public license version 2.1'),
  'LGPL-3.0-or-later': (t) => t.includes('gnu lesser general public license version 3'),
  'LGPL-2.1-only': (t) => t.includes('gnu lesser general public license version 2.1'),
  'OFL-1.1': (t) => /sil open font license,? version 1\.1/.test(t),
};

export interface Notice {
  name: string;
  version: string;
  /** Gewählte Lizenz(en), SPDX. */
  license: string;
  /** Angabe des Pakets, wie sie in package.json bzw. composer.lock steht. */
  declared: string;
  source: 'npm' | 'composer';
  homepage: string;
  copyright: string[];
  text: string;
}

export interface Project {
  name: string;
  license: string;
  repository: string;
  text: string;
}

/**
 * Lizenz(en), unter denen das Paket genutzt wird: bei „A OR B“ die erste erlaubte nach ALLOWED, bei „A AND B“ beide.
 * null, wenn die Angabe keine erlaubte Lizenz ergibt (auch bei „WITH“-Ausnahmen, unbekannten Angaben oder Fehlern).
 */
export function chooseLicense(expression: string): string[] | null {
  const tokens = expression.match(/\(|\)|[^\s()]+/g) ?? [];
  let pos = 0;
  const rank = (ids: string[]) => Math.max(...ids.map((id) => ALLOWED.indexOf(id as (typeof ALLOWED)[number])));
  const atom = (): string[] | null => {
    const t = tokens[pos++];
    if (t === '(') {
      const inner = or();
      return tokens[pos++] === ')' ? inner : null;
    }
    if (t === undefined || t === ')' || /^(AND|OR|WITH)$/i.test(t)) return null;
    if (/^WITH$/i.test(tokens[pos] ?? '')) {
      pos += 2;
      return null;
    }
    const id = ALLOWED.find((a) => a.toLowerCase() === (ALIASES[t] ?? t).toLowerCase());
    return id ? [id] : null;
  };
  const and = (): string[] | null => {
    let all = atom();
    while (/^AND$/i.test(tokens[pos] ?? '')) {
      pos++;
      const next = atom();
      all = all && next ? [...new Set([...all, ...next])] : null;
    }
    return all;
  };
  const or = (): string[] | null => {
    let best = and();
    while (/^OR$/i.test(tokens[pos] ?? '')) {
      pos++;
      const next = and();
      if (next && (!best || rank(next) < rank(best))) best = next;
    }
    return best;
  };
  const result = or();
  return pos === tokens.length && result ? result : null;
}

const normalize = (s: string) => s.toLowerCase().replace(/\s+/g, ' ');

/** Copyright-Zeilen (ohne Platzhalter wie „Copyright [yyyy]“ und ohne Wörter wie „Copyright License“). */
export function copyrightLines(text: string): string[] {
  return text
    .split('\n')
    .map((l) =>
      l
        .replace(/^[\s/*#!>-]+/, '')
        .replace(/\s*\*\/\s*$/, '')
        .trim(),
    )
    .filter((l) => /copyright\s*(\(c\)|©)?\s*\d{4}|copyright\s*(\(c\)|©)|(\(c\)|©)\s*\d{4}|@license\b.*(\(c\)|©)/i.test(l))
    .filter((l) => !/<(year|name)\b[^>]*>/i.test(l));
}

/**
 * Lizenztext eines Pakets: je gewählter Lizenz die Lizenzdatei, deren Text dazu passt, dazu eine NOTICE-Datei
 * (Apache-2.0 verlangt, sie weiterzugeben). Fehlt die passende Datei, ist das ein Fehler.
 */
export function licenseText(dir: string, name: string, chosen: string[]): string {
  const files = readdirSync(dir)
    .filter((f) => /^(licen[cs]e|copying|notice)/i.test(f) && statSync(join(dir, f)).isFile())
    .sort((a, b) => a.length - b.length || a.localeCompare(b));
  const parts: string[] = [];
  for (const id of chosen) {
    const file = files.find((f) => !/^notice/i.test(f) && FINGERPRINTS[id](normalize(readFileSync(join(dir, f), 'utf8'))));
    if (!file) {
      throw new Error(`${name}: keine Lizenzdatei mit dem Text der Lizenz ${id} gefunden (vorhanden: ${files.join(', ') || 'keine'}).`);
    }
    parts.push(readFileSync(join(dir, file), 'utf8').trim());
  }
  for (const f of files.filter((f) => /^notice/i.test(f))) parts.push(readFileSync(join(dir, f), 'utf8').trim());
  return parts.join('\n\n');
}

function check(name: string, declared: unknown): string[] {
  if (typeof declared !== 'string' || declared.trim() === '') {
    throw new Error(`${name}: keine Lizenzangabe (SPDX) in den Paketdaten. Ausgelieferte Pakete brauchen eine erlaubte Lizenz.`);
  }
  const chosen = chooseLicense(declared);
  if (!chosen) {
    throw new Error(
      `${name}: Lizenz „${declared}“ ist für ausgelieferte Bausteine nicht erlaubt (erlaubt: ${ALLOWED.join(', ')}; siehe docs/LIZENZEN.md).`,
    );
  }
  return chosen;
}

const url = (v: unknown): string => {
  const s = typeof v === 'string' ? v : typeof v === 'object' && v && 'url' in v ? String((v as { url: unknown }).url) : '';
  return s.replace(/^git\+/, '').replace(/\.git$/, '');
};

/** Eintrag für ein npm-Paket; modules: Dateien des Pakets im Build (für Copyright-Kopfzeilen, z. B. bei pdf.js). */
export function npmNotice(dir: string, modules: string[] = []): Notice {
  const pkg = JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8'));
  const name = String(pkg.name);
  const declared = typeof pkg.license === 'object' && pkg.license ? pkg.license.type : pkg.license;
  const chosen = check(name, declared);
  const text = licenseText(dir, name, chosen);
  const heads = modules.filter((m) => existsSync(m)).map((m) => readFileSync(m, 'utf8').slice(0, 4096));
  const author = typeof pkg.author === 'string' ? pkg.author : (pkg.author?.name ?? '');
  const copyright = [...new Set([...copyrightLines(text), ...heads.flatMap(copyrightLines)])];
  if (copyright.length === 0 && author) copyright.push(`Autor: ${author}`);
  return {
    name,
    version: String(pkg.version),
    license: chosen.join(' AND '),
    declared,
    source: 'npm',
    homepage: url(pkg.homepage) || url(pkg.repository),
    copyright,
    text,
  };
}

/** PHP-Bibliotheken, die die App braucht (composer.lock: packages, nicht packages-dev). */
export function composerNotices(root: string): Notice[] {
  const lockFile = join(root, 'composer.lock');
  if (!existsSync(lockFile)) return [];
  const lock = JSON.parse(readFileSync(lockFile, 'utf8'));
  return ((lock.packages ?? []) as Array<Record<string, unknown>>).map((p) => {
    const name = String(p.name);
    // Composer: mehrere Einträge heißen „zur Wahl“.
    const licenses = Array.isArray(p.license) ? (p.license as string[]) : [];
    const declared = licenses.length > 1 ? licenses.map((l) => `(${l})`).join(' OR ') : licenses[0];
    const chosen = check(name, declared);
    const dir = join(root, 'vendor', name);
    if (!existsSync(dir)) throw new Error(`${name}: vendor/${name} fehlt (für den Lizenztext erst „composer install --no-dev“ ausführen).`);
    const text = licenseText(dir, name, chosen);
    // Copyright-Kopfzeilen der PHP-Dateien in den PSR-4-Ordnern (z. B. „@copyright 2012 - 2020 Marcus Bointon“).
    const psr4 = Object.values(((p.autoload as { 'psr-4'?: Record<string, string | string[]> } | undefined) ?? {})['psr-4'] ?? {}).flat();
    const heads = psr4.flatMap((d) => {
      const sub = join(dir, d);
      if (!existsSync(sub) || !statSync(sub).isDirectory()) return [];
      return readdirSync(sub)
        .filter((f) => f.endsWith('.php'))
        .sort()
        .map((f) => readFileSync(join(sub, f), 'utf8').slice(0, 4096));
    });
    return {
      name,
      version: String(p.version).replace(/^v/, ''),
      license: chosen.join(' AND '),
      declared: String(declared),
      source: 'composer',
      homepage: url(p.homepage) || url((p.source as { url?: string } | undefined)?.url),
      copyright: [...new Set([...copyrightLines(text), ...heads.flatMap(copyrightLines)])],
      text,
    } satisfies Notice;
  });
}

/** Paketordner zu einer Modul-ID aus dem Build (…/node_modules/@scope/name/… oder …/node_modules/name/…). */
export function packageDir(id: string): string | null {
  const path = id.replace(/^\0/, '').replace(/[?#].*$/, '');
  const m = /^(.*\/node_modules\/(?:@[^/]+\/)?[^/]+)\//.exec(path);
  return m ? m[1] : null;
}

/** Abschnitt aus Vites LICENSE.md (zwischen einer Überschrift und der nächsten Trennlinie bzw. Überschrift). */
function viteSection(text: string, start: string, end: RegExp): string {
  const from = text.indexOf(start);
  if (from < 0) throw new Error(`vite/LICENSE.md: Abschnitt „${start}“ nicht gefunden.`);
  const rest = text.slice(from);
  const to = rest.slice(start.length).search(end);
  return (to < 0 ? rest : rest.slice(0, start.length + to)).replace(/^> ?/gm, '').trim();
}

/**
 * Hilfsfunktionen, die Vite selbst in den Build schreibt (Vorladen von Modulen, CommonJS-Umwandlung): Code aus dem
 * Paket vite (MIT) bzw. aus @rollup/plugin-commonjs (MIT), das vite mitbringt.
 */
function viteNotice(viteDir: string, commonjs: boolean): Notice {
  const pkg = JSON.parse(readFileSync(join(viteDir, 'package.json'), 'utf8'));
  check('vite', pkg.license);
  const all = readFileSync(join(viteDir, 'LICENSE.md'), 'utf8');
  const parts = [viteSection(all, '# Vite core license', /^# /m)];
  if (commonjs) parts.push(viteSection(all, '## @rollup/plugin-alias, @rollup/plugin-commonjs', /^-{10,}$/m));
  const text = parts.join('\n\n');
  if (!FINGERPRINTS.MIT(normalize(text))) throw new Error('vite/LICENSE.md: MIT-Text nicht gefunden.');
  return {
    name: commonjs ? 'vite (mit @rollup/plugin-commonjs)' : 'vite',
    version: String(pkg.version),
    license: 'MIT',
    declared: String(pkg.license),
    source: 'npm',
    homepage: url(pkg.homepage) || url(pkg.repository),
    copyright: copyrightLines(text),
    text,
  };
}

const VIRTUAL = 'virtual:kompanie-lizenzen';
const PLACEHOLDER = '__KOMPANIE_LIZENZEN__';
export const NOTICES_FILE = 'THIRD-PARTY-NOTICES.txt';

/** Textfassung für die Pakete (THIRD-PARTY-NOTICES.txt). */
export function noticesText(project: Project, notices: Notice[]): string {
  const line = '='.repeat(78);
  const head = [
    `Drittanbieter-Bausteine der ${project.name}`,
    '',
    `Die ${project.name} steht unter ${project.license} (Datei LICENSE). Vollständiger Quellcode:`,
    project.repository,
    '',
    'Diese Datei entsteht beim Bauen aus den Bausteinen, die tatsächlich in der App enthalten sind',
    '(packages/frontend/build/licenses.ts). Sie nennt je Baustein Version, Lizenz, Herkunft, Copyright',
    'und den Lizenztext.',
  ];
  const body = notices.flatMap((n) => [
    '',
    line,
    `${n.name} ${n.version}`,
    `Lizenz: ${n.license}${n.declared !== n.license ? ` (Angabe des Pakets: ${n.declared})` : ''}`,
    ...(n.homepage ? [`Quelle: ${n.homepage}`] : []),
    ...n.copyright,
    line,
    '',
    n.text,
  ]);
  return [...head, ...body, ''].join('\n');
}

/**
 * Vite-Plugin: prüft die Lizenzen der Bausteine im Build, schreibt THIRD-PARTY-NOTICES.txt nach dist/ und setzt die
 * Daten in das Modul virtual:kompanie-lizenzen ein (vor dem Berechnen der Dateinamen, damit die Prüfsumme im Namen
 * stimmt). Ohne Build (vite dev) bleibt die Liste leer.
 */
export function thirdPartyLicenses(opts: { root: string }): Plugin {
  let data: string | null = null;
  let notices: Notice[] = [];
  let project: Project | null = null;
  let replaced = false;
  let build = false;
  let viteRoot = '';
  return {
    name: 'kompanie-lizenzen',
    configResolved(config) {
      build = config.command === 'build';
      viteRoot = config.root;
    },
    resolveId(id) {
      return id === VIRTUAL ? '\0' + VIRTUAL : null;
    },
    load(id) {
      if (id !== '\0' + VIRTUAL) return null;
      return build
        ? `export default JSON.parse(${JSON.stringify(PLACEHOLDER)});`
        : `export default ${JSON.stringify({ projekt: readProject(opts.root), pakete: [], entwicklung: true })};`;
    },
    renderStart() {
      const packages = new Map<string, string[]>();
      let viteHelpers = false;
      let commonjs = false;
      for (const id of this.getModuleIds()) {
        const dir = packageDir(id);
        if (dir) {
          packages.set(dir, [...(packages.get(dir) ?? []), id.replace(/^\0/, '').replace(/[?#].*$/, '')]);
        } else if (id === '\0commonjsHelpers.js') {
          commonjs = true;
        } else if (id.startsWith('\0vite/')) {
          viteHelpers = true;
        } else if (id.startsWith('\0') && id !== '\0' + VIRTUAL) {
          this.error(`Unbekanntes virtuelles Modul ${JSON.stringify(id)}: Herkunft und Lizenz in build/licenses.ts ergänzen.`);
        }
      }
      try {
        project = readProject(opts.root);
        notices = [...packages].map(([dir, modules]) => npmNotice(dir, modules));
        if (viteHelpers || commonjs) notices.push(viteNotice(join(opts.root, 'node_modules/vite'), commonjs));
        notices.push(...composerNotices(opts.root));
      } catch (e) {
        this.error(`Lizenzprüfung: ${(e as Error).message}`);
      }
      notices.sort((a, b) => a.name.localeCompare(b.name));
      data = JSON.stringify({
        projekt: project,
        pakete: notices.map((n) => ({
          name: n.name,
          version: n.version,
          lizenz: n.license,
          angabe: n.declared,
          quelle: n.source,
          homepage: n.homepage,
          copyright: n.copyright,
          text: n.text,
        })),
      });
    },
    renderChunk(code) {
      const quoted = new RegExp(`(["'\`])${PLACEHOLDER}\\1`);
      if (data === null || !quoted.test(code)) return null;
      replaced = true;
      return { code: code.replace(quoted, () => JSON.stringify(data)), map: null };
    },
    generateBundle(_options, bundle) {
      if (!replaced) this.error(`Lizenzdaten nicht eingesetzt: ${PLACEHOLDER} kommt in keinem Teil des Builds vor.`);
      // Dateien aus node_modules, die als eigene Datei in den Build kommen (z. B. der pdf.js-Worker): Ihr Paket muss in
      // der Liste stehen.
      const names = new Set(notices.map((n) => n.name));
      for (const file of Object.values(bundle)) {
        if (file.type !== 'asset') continue;
        for (const original of file.originalFileNames ?? []) {
          const dir = packageDir(join(viteRoot, original));
          if (dir && !names.has(JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8')).name)) {
            this.error(`${file.fileName} stammt aus ${dir}, das Paket fehlt in den Lizenzen.`);
          }
        }
      }
      this.emitFile({ type: 'asset', fileName: NOTICES_FILE, source: noticesText(project!, notices) });
    },
  };
}

/** Lizenz und Quellcode der App aus package.json und LICENSE im Wurzelverzeichnis. */
export function readProject(root: string): Project {
  const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
  return {
    name: 'Kompanie-App',
    license: String(pkg.license),
    repository: url(pkg.repository),
    text: readFileSync(join(root, 'LICENSE'), 'utf8'),
  };
}
