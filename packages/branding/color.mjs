// Farbberechnung für das automatische Farbschema: Abstufungen, Mischen, Kontrast nach WCAG 2.

/** '#1f4498' → [31, 68, 152] */
export function hexToRgb(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) throw new Error(`Ungültige Farbe: ${hex}`);
  const n = parseInt(m[1], 16);
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

export function rgbToHex([r, g, b]) {
  const c = (v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0');
  return `#${c(r)}${c(g)}${c(b)}`;
}

/** [h 0–360, s 0–1, l 0–1] */
export function rgbToHsl([r, g, b]) {
  r /= 255;
  g /= 255;
  b /= 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;
  if (max === min) return [0, 0, l];
  const d = max - min;
  const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
  let h;
  if (max === r) h = (g - b) / d + (g < b ? 6 : 0);
  else if (max === g) h = (b - r) / d + 2;
  else h = (r - g) / d + 4;
  return [h * 60, s, l];
}

export function hslToRgb([h, s, l]) {
  h = (((h % 360) + 360) % 360) / 360;
  if (s === 0) return [l * 255, l * 255, l * 255];
  const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
  const p = 2 * l - q;
  const f = (t) => {
    if (t < 0) t += 1;
    if (t > 1) t -= 1;
    if (t < 1 / 6) return p + (q - p) * 6 * t;
    if (t < 1 / 2) return q;
    if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
    return p;
  };
  return [f(h + 1 / 3) * 255, f(h) * 255, f(h - 1 / 3) * 255];
}

export const hsl = (hex) => rgbToHsl(hexToRgb(hex));
export const fromHsl = (h, s, l) => rgbToHex(hslToRgb([h, Math.min(1, Math.max(0, s)), Math.min(1, Math.max(0, l))]));

/** Helligkeit in HSL setzen bzw. verschieben. */
export function withLightness(hex, l) {
  const [h, s] = hsl(hex);
  return fromHsl(h, s, l);
}

export function shiftLightness(hex, delta) {
  const [h, s, l] = hsl(hex);
  return fromHsl(h, s, l + delta);
}

/** Mischt a und b; weight = Anteil von b (0–1). */
export function mix(a, b, weight) {
  const x = hexToRgb(a);
  const y = hexToRgb(b);
  return rgbToHex(x.map((v, i) => v + (y[i] - v) * weight));
}

/** Relative Leuchtdichte nach WCAG 2. */
export function luminance(hex) {
  const [r, g, b] = hexToRgb(hex).map((v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a, b) {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

/** Weiß oder fast Schwarz – was auf der Farbe besser lesbar ist. */
export function readableOn(bg, light = '#ffffff', dark = '#111418') {
  return contrast(bg, light) >= contrast(bg, dark) ? light : dark;
}

/**
 * Verschiebt die Helligkeit von fg schrittweise, bis der Kontrast zu bg mindestens min ist.
 * Richtung: weg von der Helligkeit des Hintergrunds. Gibt null zurück, wenn es nicht reicht.
 */
export function ensureContrast(fg, bg, min) {
  if (contrast(fg, bg) >= min) return fg;
  const [h, s, l] = hsl(fg);
  const darker = luminance(bg) > 0.5;
  for (let step = 1; step <= 100; step++) {
    const candidate = fromHsl(h, s, darker ? l - step * 0.01 : l + step * 0.01);
    if (contrast(candidate, bg) >= min) return candidate;
  }
  return null;
}

/**
 * Verschiebt die Helligkeit des Hintergrunds bg weg von der Schriftfarbe fg,
 * bis der Kontrast mindestens min ist. Gibt im schlimmsten Fall Schwarz oder Weiß zurück.
 */
export function ensureBackground(bg, fg, min) {
  if (contrast(fg, bg) >= min) return bg;
  const [h, s, l] = hsl(bg);
  const lighter = luminance(fg) < 0.5;
  for (let step = 1; step <= 100; step++) {
    const candidate = fromHsl(h, s, lighter ? l + step * 0.01 : l - step * 0.01);
    if (contrast(fg, candidate) >= min) return candidate;
  }
  return lighter ? '#ffffff' : '#000000';
}

/**
 * Abgeschwächte Schriftfarbe (fg zum Hintergrund hin gemischt), die auf allen
 * Hintergründen noch mindestens min Kontrast hat. Ergebnis ist eine feste Farbe.
 */
export function softOn(fg, backgrounds, min, maxWeight = 0.4) {
  const worst = backgrounds.reduce((a, b) => (contrast(fg, a) <= contrast(fg, b) ? a : b));
  for (let w = maxWeight; w > 0; w -= 0.02) {
    const c = mix(fg, worst, w);
    if (backgrounds.every((b) => contrast(c, b) >= min)) return c;
  }
  return fg;
}
