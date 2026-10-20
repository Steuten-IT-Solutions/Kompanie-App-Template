// „Desktop-Website“ im Handy-Browser (Issue #24, wie Lank): Chrome ignoriert dann das viewport-Tag und legt die Seite
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
  const layoutW = root.clientWidth;
  if (screenW > 0 && screenW < 1024 && layoutW > screenW * 1.15) {
    root.style.setProperty('zoom', String(layoutW / screenW));
    // vw/vh wachsen mit „zoom“ mit; die Styles teilen dadurch wieder (styles.css).
    root.style.setProperty('--kompanie-zoom', String(layoutW / screenW));
  }
}

fit();
window.addEventListener('load', fit);
window.addEventListener('resize', fit);
