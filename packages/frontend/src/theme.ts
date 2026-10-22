// Darstellung hell, dunkel oder wie das Gerät, gespeichert auf diesem Gerät.
// Die gewählte Darstellung steht als data-theme an <html>; ohne Attribut folgt die App dem Gerät.
// Die Dunkel-Regeln des CSS (@media (prefers-color-scheme: dark)) schreibt der Build so um, dass
// data-theme="light" sie abschaltet und data-theme="dark" sie auch ohne Dunkelmodus des Geräts einschaltet
// (vite.config.ts, themeOverride).

export type ThemeChoice = 'system' | 'light' | 'dark';

export const THEME_LABELS: Record<ThemeChoice, string> = {
  system: 'Wie Gerät',
  light: 'Hell',
  dark: 'Dunkel',
};

const KEY = 'kompanie-theme';

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
}
