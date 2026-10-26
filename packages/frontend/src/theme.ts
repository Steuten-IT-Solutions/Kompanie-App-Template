// Darstellung hell, dunkel oder wie das Gerät, gespeichert auf diesem Gerät.
// Die gewählte Darstellung steht als data-theme an <html>; ohne Attribut folgt die App dem Gerät.
// Die Dunkel-Regeln des CSS (@media (prefers-color-scheme: dark)) schreibt der Build so um, dass
// data-theme="light" sie abschaltet und data-theme="dark" sie auch ohne Dunkelmodus des Geräts einschaltet
// (vite.config.ts, themeOverride).
import { useEffect, useState } from 'react';

export type ThemeChoice = 'system' | 'light' | 'dark';

export const THEME_LABELS: Record<ThemeChoice, string> = {
  system: 'Wie Gerät',
  light: 'Hell',
  dark: 'Dunkel',
};

const KEY = 'kompanie-theme';
const CHANGED = 'kompanie-theme-changed';

export function loadTheme(): ThemeChoice {
  try {
    const v = localStorage.getItem(KEY);
    return v === 'light' || v === 'dark' ? v : 'system';
  } catch {
    return 'system';
  }
}

export function applyTheme(choice: ThemeChoice): void {
  const root = document.documentElement;
  if (choice === 'system') delete root.dataset.theme;
  else root.dataset.theme = choice;
}

export function saveTheme(choice: ThemeChoice): void {
  applyTheme(choice);
  try {
    if (choice === 'system') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, choice);
  } catch {
    /* egal: gilt dann nur bis zum Neuladen */
  }
  window.dispatchEvent(new Event(CHANGED));
}

const deviceDark = () => window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;

/** Darstellung, die gerade zu sehen ist. */
export function shownTheme(choice: ThemeChoice = loadTheme()): 'light' | 'dark' {
  return choice === 'system' ? (deviceDark() ? 'dark' : 'light') : choice;
}

/**
 * Knopf in der Kopfzeile: auf die andere Darstellung wechseln. Ist das die des Geräts, folgt die App wieder dem Gerät
 * (dann wechselt sie mit ihm, z. B. abends); sonst bleibt die Wahl fest.
 */
export function toggleTheme(): void {
  const next = shownTheme() === 'dark' ? 'light' : 'dark';
  saveTheme(next === (deviceDark() ? 'dark' : 'light') ? 'system' : next);
}

/** Gewählte und sichtbare Darstellung; aktualisiert sich bei jeder Wahl und wenn das Gerät umschaltet. */
export function useTheme(): { choice: ThemeChoice; shown: 'light' | 'dark' } {
  const [, setTick] = useState(0);
  useEffect(() => {
    const update = () => setTick((t) => t + 1);
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    window.addEventListener(CHANGED, update);
    media?.addEventListener?.('change', update);
    return () => {
      window.removeEventListener(CHANGED, update);
      media?.removeEventListener?.('change', update);
    };
  }, []);
  const choice = loadTheme();
  return { choice, shown: shownTheme(choice) };
}
