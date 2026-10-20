// „Desktop-Website“ im Handy-Browser (Issue #24): Chrome ignoriert dann das viewport-Tag und legt die Seite
// 980 px breit an, die App erschiene winzig. Erkennbar daran, dass die Seite auf einem Touch-Gerät viel breiter ist als
// der Bildschirm. Dann wird die Seite so vergrößert, dass sie wieder genau Handybreite hat. Als Modul statt Inline-Skript,
// weil die Content-Security-Policy der eigenständigen Version keine Inline-Skripte erlaubt.

function fit(): void {
  const root = document.documentElement;
  root.style.removeProperty('zoom');
  root.style.removeProperty('--kompanie-zoom');
  if (!window.matchMedia?.('(pointer: coarse)').matches) return;
  const landscape = window.matchMedia('(orientation: landscape)').matches;
  // iPhone meldet screen.width immer hochkant, Android passt es an die Drehung an.
  const screenW = landscape ? Math.max(screen.width, screen.height) : Math.min(screen.width, screen.height);
  // Geteilter Bildschirm: Das Fenster ist schmaler als der Bildschirm; dann an das Fenster anpassen.
  const targetW = Math.min(screenW, window.outerWidth || screenW);
  const layoutW = root.clientWidth;
  if (targetW > 0 && targetW < 1024 && layoutW > targetW * 1.15) {
    root.style.setProperty('zoom', String(layoutW / targetW));
    // vw/vh wachsen mit „zoom“ mit; die Styles teilen dadurch wieder (styles.css).
    root.style.setProperty('--kompanie-zoom', String(layoutW / targetW));
  }
}

/** Nur neu rechnen, wenn sich Bildschirmbreite oder Ausrichtung ändern – nicht, wenn die Tastatur die Höhe ändert
 * (sonst spränge das Eingabefeld weg). */
let last = '';
function refit(): void {
  const key = `${screen.width}x${screen.height}:${window.matchMedia?.('(orientation: landscape)').matches ? 'q' : 'h'}`;
  if (key === last && document.readyState === 'complete') return;
  last = key;
  fit();
}

refit();
window.addEventListener('load', () => {
  last = '';
  refit();
});
window.addEventListener('resize', refit);
