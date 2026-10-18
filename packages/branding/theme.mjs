// Leitet aus Haupt- und Akzentfarbe das vollständige Farbschema für hell und dunkel ab
// und prüft die Lesbarkeit. Fehler und Warnungen sind für Menschen formuliert.

import { contrast, ensureContrast, fromHsl, hsl, mix, readableOn, shiftLightness } from './color.mjs';

const TEXT_MIN = 4.5;

/**
 * @param {{primary: string, accent?: string, background?: string}} colors
 * @returns {{light: Record<string,string>, dark: Record<string,string>, warnings: string[], errors: string[]}}
 */
export function deriveTheme(colors) {
  const warnings = [];
  const errors = [];
  const primary = colors.primary.toLowerCase();
  const [ph, ps] = hsl(primary);

  // Akzent: angegeben oder aus der Hauptfarbe (gedrehter Farbton, kräftig, mittelhell).
  const accent = (colors.accent ?? fromHsl(ph + 40, Math.max(0.55, ps * 0.9), 0.55)).toLowerCase();

  const bg = (colors.background ?? mix('#f8f8f6', primary, 0.04)).toLowerCase();
  const card = '#ffffff';
  const ink = mix('#16181d', primary, 0.12);

  // Text in Hauptfarbe (Links, Überschriften) muss auf Hintergrund und Karten lesbar sein.
  const primaryText = ensureContrast(primary, card, TEXT_MIN) && ensureContrast(ensureContrast(primary, card, TEXT_MIN), bg, TEXT_MIN);
  if (!primaryText) {
    errors.push('Die Hauptfarbe lässt sich nicht so abdunkeln, dass Text darin lesbar ist.');
  } else if (primaryText !== primary) {
    warnings.push(`Die Hauptfarbe ist als Schriftfarbe schlecht lesbar; für Text wird ${primaryText} verwendet.`);
  }
  if (contrast(ink, bg) < TEXT_MIN) {
    errors.push('Die Hintergrundfarbe ist zu dunkel: Text darauf wäre nicht lesbar. Bitte einen hellen Hintergrund wählen.');
  }

  const onPrimary = readableOn(primary);
  const onAccent = readableOn(accent);

  const light = {
    primary,
    'primary-deep': shiftLightness(primary, -0.1),
    'primary-ink': mix(shiftLightness(primary, -0.22), '#000000', 0.1),
    'primary-text': primaryText ?? primary,
    'on-primary': onPrimary,
    accent,
    'accent-light': shiftLightness(accent, 0.15),
    'accent-dark': shiftLightness(accent, -0.2),
    'on-accent': onAccent,
    bg,
    card,
    ink,
    'ink-2': mix(ink, bg, 0.22),
    muted: ensureContrast(mix(ink, bg, 0.45), card, TEXT_MIN) ?? mix(ink, bg, 0.3),
    line: mix(bg, primary, 0.1),
    'line-strong': mix(bg, primary, 0.22),
    good: '#1f6b3a',
    danger: '#a3301a',
    warn: '#9c4a14',
  };

  const darkBg = fromHsl(ph, Math.min(ps, 0.45), 0.09);
  const darkCard = fromHsl(ph, Math.min(ps, 0.4), 0.14);
  const darkInk = '#e9e7e2';
  const accentTextDark = ensureContrast(shiftLightness(accent, 0.1), darkCard, TEXT_MIN) ?? '#ffffff';
  const primaryTextDark = ensureContrast(shiftLightness(primary, 0.25), darkCard, TEXT_MIN) ?? '#ffffff';
  const dark = {
    'primary-text': primaryTextDark,
    'accent-text': accentTextDark,
    bg: darkBg,
    card: darkCard,
    ink: darkInk,
    'ink-2': mix(darkInk, darkBg, 0.2),
    muted: ensureContrast(mix(darkInk, darkBg, 0.4), darkCard, TEXT_MIN) ?? '#b0b0b0',
    line: fromHsl(ph, Math.min(ps, 0.35), 0.22),
    'line-strong': fromHsl(ph, Math.min(ps, 0.35), 0.3),
    good: '#6fd08f',
    danger: '#f08a73',
    warn: '#f0a868',
  };
  light['accent-text'] = light['primary-text'];

  for (const [name, a, b] of [
    ['Schrift auf Hauptfarbe', onPrimary, primary],
    ['Schrift auf Akzentfarbe', onAccent, accent],
  ]) {
    const c = contrast(a, b);
    if (c < 3) errors.push(`${name}: Kontrast ${c.toFixed(1)} : 1 ist zu gering.`);
    else if (c < TEXT_MIN) warnings.push(`${name}: Kontrast ${c.toFixed(1)} : 1 – nur für große Schrift ausreichend.`);
  }
  return { light, dark, warnings, errors };
}

/** CSS mit den Farben als Variablen, hell und dunkel. */
export function themeCss(theme) {
  const vars = (obj) =>
    Object.entries(obj)
      .map(([k, v]) => `  --${k}: ${v};`)
      .join('\n');
  return `/* Automatisch erzeugt aus kompanie.json – nicht von Hand ändern. */
:root {
${vars(theme.light)}
  color-scheme: light;
}

@media (prefers-color-scheme: dark) {
  :root {
${vars(theme.dark).replace(/^/gm, '  ')}
    color-scheme: dark;
  }
}
`;
}
