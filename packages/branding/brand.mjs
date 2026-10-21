#!/usr/bin/env node
// Erzeugt aus kompanie.json und dem Logo alles, was die App für eine Kompanie braucht:
//   <out>/brand.json, <out>/brand.ts   Name, Texte, Bezeichnungen, Bereiche, Schrift
//   <out>/theme.css                    Farbschema hell und dunkel
//   <out>/fonts.ts                     Einbindung der gewählten Schrift
//   <out>/logo.png                     Logo (höchstens 512 px)
//   <public>/icons/*.png               App-Icons, Android-Icon mit Sicherheitsabstand, Apple-Icon, Favicon
//
// Aufruf: node brand.mjs [--config kompanie.json] [--assets ordner] --out ordner [--public ordner]
// Standard für --config: $KOMPANIE_CONFIG, sonst das Beispiel im Repo. Standard für --assets: $KOMPANIE_ASSETS,
// sonst der Ordner assets/ neben kompanie.json. Fehlt das Logo, entsteht eines aus den Initialen.

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import sharp from 'sharp';
import { deriveTheme, themeCss } from './theme.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../..');

const FONTS = {
  System: [],
  Oswald: [
    '@fontsource/oswald/latin-500.css',
    '@fontsource/oswald/latin-600.css',
    '@fontsource/oswald/latin-700.css',
    '@fontsource/oswald/latin-ext-500.css',
    '@fontsource/oswald/latin-ext-600.css',
    '@fontsource/oswald/latin-ext-700.css',
  ],
  'Roboto Slab': [
    '@fontsource/roboto-slab/latin-500.css',
    '@fontsource/roboto-slab/latin-700.css',
    '@fontsource/roboto-slab/latin-ext-700.css',
  ],
  'Source Sans 3': [
    '@fontsource/source-sans-3/latin-600.css',
    '@fontsource/source-sans-3/latin-700.css',
    '@fontsource/source-sans-3/latin-ext-700.css',
  ],
};
const FONT_STACK = {
  System: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
  Oswald: "'Oswald', 'Arial Narrow', 'Roboto Condensed', sans-serif",
  'Roboto Slab': "'Roboto Slab', Georgia, serif",
  'Source Sans 3': "'Source Sans 3', 'Segoe UI', Roboto, Arial, sans-serif",
};

const DEFAULTS = {
  greeting: 'Hallo, {name}',
  roles: { guest: 'Gast', candidate: 'Anwärter', member: 'Mitglied', organizer: 'Verwalter', manager: 'Leitung', admin: 'Admin' },
  sections: {
    events: 'Termine',
    news: 'News',
    gallery: 'Galerie',
    points: 'Punkte',
    penalties: 'Strafen',
    ranks: 'Dienstgrade',
    titles: 'Titel',
    attendance: 'Anwesenheit',
    documents: 'Dokumente',
    board: 'Schwarzes Brett',
    polls: 'Umfragen',
    birthdays: 'Geburtstage',
    inventory: 'Inventar',
    directory: 'Mitglieder',
  },
  rank: 'Dienstgrad',
};

export class BrandError extends Error {}

function args(argv) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    if (argv[i].startsWith('--')) out[argv[i].slice(2)] = argv[i + 1];
  }
  return out;
}

/** Liest und prüft kompanie.json; ergänzt Standardwerte. */
export function loadConfig(file) {
  let data;
  try {
    data = JSON.parse(readFileSync(file, 'utf8'));
  } catch (e) {
    throw new BrandError(`kompanie.json kann nicht gelesen werden (${file}): ${e.message}`);
  }
  const schema = JSON.parse(readFileSync(join(repoRoot, 'config/kompanie.schema.json'), 'utf8'));
  const ajv = new Ajv2020({ allErrors: true, strict: false, validateFormats: false });
  const validate = ajv.compile(schema);
  if (!validate(data)) {
    const msgs = validate.errors.map((e) => `${e.instancePath || '(oben)'} ${e.message}`);
    throw new BrandError(`kompanie.json ist nicht gültig:\n  - ${msgs.join('\n  - ')}`);
  }
  const name = data.name.trim();
  return {
    name,
    shortName: (data.shortName ?? name).trim(),
    description: data.description ?? `Die App der ${name}`,
    greeting: data.greeting ?? DEFAULTS.greeting,
    guestWelcome: data.guestWelcome ?? '',
    labels: {
      roles: { ...DEFAULTS.roles, ...(data.labels?.roles ?? {}) },
      sections: { ...DEFAULTS.sections, ...(data.labels?.sections ?? {}) },
      rank: data.labels?.rank ?? DEFAULTS.rank,
    },
    // Veraltet (Bereiche schaltet der Admin in der App); nur für den Hinweis beim Build.
    legacyFeatures: data.features !== undefined,
    font: data.font ?? 'System',
    colors: data.colors,
    legal: data.legal ?? {},
  };
}

/** Initialen als Ersatz-Logo, z. B. „Musterkompanie Beispielstadt“ → „MB“. */
export function initialsSvg(name, background, foreground) {
  const words = name.split(/\s+/).filter((w) => /\p{L}/u.test(w));
  const initials = (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? 'K').slice(0, 2)).toUpperCase();
  return `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
<circle cx="256" cy="256" r="240" fill="${background}"/>
<text x="256" y="256" dy="0.35em" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="210" font-weight="700" fill="${foreground}">${initials.replace(/[<&>]/g, '')}</text>
</svg>`;
}

function findLogo(assetsDir) {
  for (const ext of ['svg', 'png', 'jpg', 'jpeg', 'webp']) {
    const f = join(assetsDir, `logo.${ext}`);
    if (existsSync(f)) return f;
  }
  return null;
}

/** Erzeugt Logo und Icons. */
async function images(logoInput, outDir, publicDir, warnings) {
  const probe = await sharp(logoInput).metadata();
  // SVG so dicht rastern, dass mindestens 1024 px entstehen (auch bei width="24" oder nur viewBox).
  let density = 72;
  if (probe.format === 'svg') {
    const size = Math.max(probe.width ?? 0, probe.height ?? 0) || 100;
    density = Math.min(10000, Math.max(72, Math.ceil((72 * 1024) / size)));
  }
  // rotate() ohne Winkel richtet nach der EXIF-Angabe aus (Handyfotos).
  const load = () => sharp(logoInput, { density }).rotate();
  const meta = await sharp(await load().png().toBuffer()).metadata();
  const longest = Math.max(meta.width ?? 0, meta.height ?? 0);
  if (probe.format !== 'svg' && longest < 192) {
    throw new BrandError(`Das Logo ist zu klein (${longest} px). Bitte ein Logo mit mindestens 512 px liefern.`);
  }
  if (probe.format !== 'svg' && longest < 512) {
    warnings.push(`Das Logo hat nur ${longest} px; für scharfe Icons sind 512 px oder mehr besser.`);
  }
  // Nur durchsichtigen Rand entfernen. Eine vollflächige Hintergrundfarbe gehört zum Logo
  // (trim() würde sonst die Farbe des Pixels oben links als Rand wegschneiden).
  const full = await load().png().toBuffer();
  const { data: corner } = await sharp(full)
    .ensureAlpha()
    .extract({ left: 0, top: 0, width: 1, height: 1 })
    .raw()
    .toBuffer({ resolveWithObject: true });
  const transparentEdge = corner[3] === 0;
  const trimmed = transparentEdge
    ? await sharp(full)
        .trim({ threshold: 1 })
        .png()
        .toBuffer()
        .catch(() => full)
    : full;
  const logo = await sharp(trimmed).resize(512, 512, { fit: 'inside', withoutEnlargement: true }).png().toBuffer();
  writeFileSync(join(outDir, 'logo.png'), logo);
  // Für die Kopfzeile (48 px hoch): Variante in dreifacher Größe für scharfe Darstellung auf
  // Handys, als WebP statt des 512-px-PNG (Issue #18, Punkt 10).
  const small = await sharp(logo)
    .resize(432, 144, { fit: 'inside', withoutEnlargement: true })
    .webp({ quality: 90, alphaQuality: 100 })
    .toBuffer();
  writeFileSync(join(outDir, 'logo-small.webp'), small);

  const icon = async (size, inner, file) => {
    const content = await sharp(logo)
      .resize(inner, inner, { fit: 'contain', background: { r: 255, g: 255, b: 255, alpha: 0 } })
      .toBuffer();
    await sharp({ create: { width: size, height: size, channels: 4, background: '#ffffff' } })
      .composite([{ input: content, gravity: 'center' }])
      .flatten({ background: '#ffffff' })
      .png()
      .toFile(join(publicDir, 'icons', file));
  };
  mkdirSync(join(publicDir, 'icons'), { recursive: true });
  await icon(512, 448, 'icon-512.png');
  await icon(192, 168, 'icon-192.png');
  // Android schneidet Icons rund oder eckig zu: Inhalt nur im inneren Kreis (80 %).
  await icon(512, 320, 'icon-maskable-512.png');
  await icon(180, 156, 'apple-touch-icon.png');
  await icon(32, 30, 'favicon-32.png');
}

export async function build({ config, assets, out, publicDir }) {
  const cfg = loadConfig(config);
  const theme = deriveTheme(cfg.colors);
  if (theme.errors.length) {
    throw new BrandError(`Farben nicht verwendbar:\n  - ${theme.errors.join('\n  - ')}`);
  }
  const warnings = [...theme.warnings];
  if (cfg.legacyFeatures) {
    warnings.push(
      '„features“ in kompanie.json wird ignoriert: Welche Bereiche die App hat, schaltet der Admin in der App (Einstellungen → Bereiche). Der Eintrag kann entfernt werden.',
    );
  }
  mkdirSync(out, { recursive: true });
  mkdirSync(publicDir, { recursive: true });

  const logoFile = findLogo(assets);
  let logoInput = logoFile;
  if (!logoFile) {
    warnings.push(`Kein Logo gefunden (${join(assets, 'logo.png')}); es wird eines aus den Initialen erzeugt.`);
    logoInput = Buffer.from(initialsSvg(cfg.name, theme.light.primary, theme.light['on-primary']));
  }
  await images(logoInput, out, publicDir, warnings);

  const brand = {
    name: cfg.name,
    shortName: cfg.shortName,
    description: cfg.description,
    greeting: cfg.greeting,
    guestWelcome: cfg.guestWelcome,
    labels: cfg.labels,
    font: cfg.font,
    themeColor: theme.light.primary,
    backgroundColor: theme.light.bg,
    legal: cfg.legal,
  };
  writeFileSync(join(out, 'brand.json'), JSON.stringify(brand, null, 2) + '\n');
  writeFileSync(
    join(out, 'brand.ts'),
    `// Automatisch erzeugt aus kompanie.json – nicht von Hand ändern.\nimport brand from './brand.json';\n\nexport default brand;\nexport type Brand = typeof brand;\n`,
  );
  writeFileSync(join(out, 'theme.css'), themeCss(theme).replace(':root {\n', `:root {\n  --font-head: ${FONT_STACK[cfg.font]};\n`));
  writeFileSync(
    join(out, 'fonts.ts'),
    `// Automatisch erzeugt: Schrift „${cfg.font}“, lokal gebündelt (kein Abruf bei Google).\n` +
      FONTS[cfg.font].map((f) => `import '${f}';`).join('\n') +
      '\nexport {};\n',
  );
  return { brand, theme, warnings };
}

async function main() {
  const a = args(process.argv.slice(2));
  const config = resolve(a.config ?? process.env.KOMPANIE_CONFIG ?? join(repoRoot, 'config/kompanie.example.json'));
  const assets = resolve(a.assets ?? process.env.KOMPANIE_ASSETS ?? join(dirname(config), 'assets'));
  const out = resolve(a.out ?? 'generated');
  const publicDir = resolve(a.public ?? out);
  try {
    const { brand, warnings } = await build({ config, assets, out, publicDir });
    for (const w of warnings) console.warn(`Hinweis: ${w}`);
    console.log(`Branding für „${brand.name}“ erzeugt.`);
  } catch (e) {
    console.error(e instanceof BrandError ? `Fehler: ${e.message}` : e);
    process.exit(1);
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
