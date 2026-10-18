// Leitet aus Haupt- und Akzentfarbe das vollständige Farbschema für hell und dunkel ab
// und prüft die Lesbarkeit. Fehler und Warnungen sind für Menschen formuliert.

import { contrast, ensureBackground, ensureContrast, fromHsl, hsl, mix, readableOn, shiftLightness, softOn } from './color.mjs';

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
  // Flächen in der Hauptfarbe (Kopfzeile, Knöpfe, Kennzahlen, Tableiste) tragen Schrift in onPrimary.
  // Die abgedunkelten Stufen dürfen dabei nicht schlechter lesbar werden als die Hauptfarbe selbst.
  const surfaceMin = Math.min(TEXT_MIN, contrast(onPrimary, primary));
  const primaryDeep = ensureBackground(shiftLightness(primary, -0.1), onPrimary, surfaceMin);
  const barTop = primaryDeep;
  const barBottom = ensureBackground(mix(shiftLightness(primary, -0.22), '#000000', 0.1), onPrimary, TEXT_MIN);
  const barTopText = ensureBackground(barTop, onPrimary, TEXT_MIN);
  const accentLight = shiftLightness(accent, 0.15);
  // Akzent als Schrift auf der Hauptfarbe (aktiver Umschalter, Schrittnummern); sonst onPrimary.
  const accentOnPrimary = ensureContrast(accentLight, primary, TEXT_MIN) ?? onPrimary;
  const warn = '#9c4a14';

  const light = {
    primary,
    'primary-deep': primaryDeep,
    'primary-ink': mix(shiftLightness(primary, -0.22), '#000000', 0.1),
    'on-primary-soft': softOn(onPrimary, [primary, primaryDeep], TEXT_MIN, 0.18),
    'accent-on-primary': accentOnPrimary,
    'bar-top': barTopText,
    'bar-bottom': barBottom,
    'on-bar-muted': softOn(onPrimary, [barTopText, barBottom], TEXT_MIN),
    'primary-text': primaryText ?? primary,
    'on-primary': onPrimary,
    accent,
    'accent-light': accentLight,
    // Akzent als Schrift auf Karten (Datum der News, Pfeile).
    'accent-dark': ensureContrast(ensureContrast(shiftLightness(accent, -0.2), card, TEXT_MIN) ?? ink, bg, TEXT_MIN) ?? ink,
    'on-accent': onAccent,
    bg,
    card,
    ink,
    'ink-2': mix(ink, bg, 0.22),
    // Gedämpfte Schrift steht auf Karten und auf dem Seitenhintergrund.
    muted: ensureContrast(ensureContrast(mix(ink, bg, 0.45), card, TEXT_MIN) ?? ink, bg, TEXT_MIN) ?? ink,
    line: mix(bg, primary, 0.1),
    'line-strong': mix(bg, primary, 0.22),
    good: '#1f6b3a',
    danger: '#a3301a',
    warn,
    // Fläche für Warnungen (offene Beträge, Warnknöpfe) mit weißer Schrift, hell wie dunkel.
    'warn-surface': warn,
    'on-warn': '#ffffff',
  };
  // Hinweisbanner: leicht in der Akzentfarbe getönte Karte (siehe styles.css .install-hint).
  const hintBg = mix(card, accent, 0.16);
  light['hint-strong'] = ensureContrast(light['primary-ink'], hintBg, TEXT_MIN) ?? ink;
  light['hint-text'] = ensureContrast(light['ink-2'], hintBg, TEXT_MIN) ?? ink;

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
    muted: ensureContrast(ensureContrast(mix(darkInk, darkBg, 0.4), darkCard, TEXT_MIN) ?? darkInk, darkBg, TEXT_MIN) ?? darkInk,
    line: fromHsl(ph, Math.min(ps, 0.35), 0.22),
    'line-strong': fromHsl(ph, Math.min(ps, 0.35), 0.3),
    good: '#6fd08f',
    danger: '#f08a73',
    warn: '#f0a868',
    'accent-dark': accentTextDark,
    'hint-strong': accentTextDark,
    'hint-text': mix(darkInk, darkBg, 0.2),
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
