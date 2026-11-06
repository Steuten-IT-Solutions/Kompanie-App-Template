// Lesbarkeit formatierter Inhalte in der Anzeige. Eingefügter Text (z. B. von einer Webseite) bringt oft feste
// Schriftfarben und große Zeilenabstände mit, die nur auf hellem Grund passen. Gespeichert bleibt alles wie eingegeben;
// nur beim Anzeigen fällt eine Farbe weg, die auf dem tatsächlichen Hintergrund (hell oder dunkel) zu wenig Kontrast hat
// (WCAG 2.x: mindestens 4,5 : 1), und ein übergroßer Zeilenabstand wird auf normal zurückgesetzt.

/** Mindestkontrast für normale Schrift (WCAG 2.x, Stufe AA). */
export const TEXT_MIN = 4.5;

/** Größter Zeilenabstand (Vielfaches der Schriftgröße), der aus eingefügtem Text übernommen wird. */
export const MAX_LINE_HEIGHT = 1.8;

export type Rgba = [number, number, number, number];

/** Farbe aus getComputedStyle („rgb(1, 2, 3)“ oder „rgba(1, 2, 3, 0.5)“, auch mit Leerzeichen und „/“). */
export function parseRgb(value: string): Rgba | null {
  const m = value.trim().match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/i);
  if (!m) return null;
  let a = 1;
  if (m[4] !== undefined) a = m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
  return [Number(m[1]), Number(m[2]), Number(m[3]), Math.max(0, Math.min(1, a))];
}

function channel(c: number): number {
  const s = c / 255;
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

/** Relative Leuchtdichte nach WCAG 2.x. */
export function luminance([r, g, b]: Rgba): number {
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** Kontrastverhältnis zweier deckender Farben (1 bis 21). */
export function contrast(a: Rgba, b: Rgba): number {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Halbtransparente Farbe über einer deckenden Farbe. */
export function over(top: Rgba, below: Rgba): Rgba {
  const a = top[3];
  return [top[0] * a + below[0] * (1 - a), top[1] * a + below[1] * (1 - a), top[2] * a + below[2] * (1 - a), 1];
}

const BLACK: Rgba = [0, 0, 0, 1];
const WHITE: Rgba = [255, 255, 255, 1];

/** Schwarz oder Weiß, je nachdem was auf dem Hintergrund besser lesbar ist. */
export function readableOn(bg: Rgba): 'black' | 'white' {
  return contrast(BLACK, bg) >= contrast(WHITE, bg) ? 'black' : 'white';
}

/**
 * Deckender Hintergrund, auf dem ein Element steht: der erste nicht durchsichtige Hintergrund des Elements oder seiner
 * Vorfahren. Halbtransparente Schichten werden darüber gelegt. Ohne deckenden Hintergrund (z. B. ohne Stylesheet) null.
 */
function backgroundOf(el: Element): Rgba | null {
  const layers: Rgba[] = [];
  for (let node: Element | null = el; node; node = node.parentElement) {
    const bg = parseRgb(getComputedStyle(node).backgroundColor);
    if (!bg || bg[3] === 0) continue;
    if (bg[3] >= 1) return layers.reduceRight<Rgba>((below, top) => over(top, below), bg);
    layers.push(bg);
  }
  return null;
}

function textContrast(el: HTMLElement): number | null {
  const fg = parseRgb(getComputedStyle(el).color);
  const bg = backgroundOf(el);
  if (!fg || !bg) return null;
  return contrast(over(fg, bg), bg);
}

/**
 * Passt einen angezeigten formatierten Inhalt an die aktuelle Darstellung an (nach dem Einsetzen und bei jedem
 * Wechsel zwischen hell und dunkel, jeweils auf frisch eingesetztem Inhalt):
 * - Schriftfarben mit zu wenig Kontrast fallen weg; der Text bekommt die Schriftfarbe der App.
 * - Steht Text auf eigener Hintergrundfarbe (Markierung) und ist dort schlecht lesbar, wird er schwarz bzw. weiß.
 * - Zeilenabstände über MAX_LINE_HEIGHT werden auf normal zurückgesetzt.
 */
export function makeReadable(root: HTMLElement): void {
  const styled = root.querySelectorAll<HTMLElement>('[style]');
  // Zuerst die Farben entfernen, dann die verbleibenden Kombinationen prüfen (Kinder erben die neue Farbe).
  styled.forEach((el) => {
    if (!el.style.color) return;
    const c = textContrast(el);
    if (c !== null && c < TEXT_MIN) el.style.removeProperty('color');
  });
  styled.forEach((el) => {
    if (el.style.backgroundColor) {
      const c = textContrast(el);
      const bg = backgroundOf(el);
      if (c !== null && bg && c < TEXT_MIN) el.style.color = readableOn(bg);
    }
    if (el.style.lineHeight) {
      const cs = getComputedStyle(el);
      const lh = parseFloat(cs.lineHeight);
      const fs = parseFloat(cs.fontSize);
      if (lh > 0 && fs > 0 && lh / fs > MAX_LINE_HEIGHT) el.style.removeProperty('line-height');
    }
  });
}
