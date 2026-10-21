import { useEffect, useState } from 'react';

/** Einfaches Hash-Routing: #punkte, #spiess/mitglied/12 … */
export function currentPath(): string[] {
  return window.location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
}

export function navigate(path: string): void {
  window.location.hash = '/' + path;
}

export function useRoute(): string[] {
  const [path, setPath] = useState(currentPath);
  useEffect(() => {
    const onChange = () => {
      setPath(currentPath());
      window.scrollTo(0, 0);
    };
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return path;
}

/**
 * Schlüssel einer Ansicht: Wechselt er, baut die App die Ansicht neu auf (und lädt ihre Daten neu). Die Seiten eines
 * Mitglieds und von „Meins“ teilen sich einen Schlüssel: Beim Umschalten bleiben Name, Menü und geladene Daten stehen.
 */
export function viewKey(path: string[]): string {
  if (path[0] === 'verwaltung' && path[1] === 'mitglied' && path[2]) return path.slice(0, 3).join('/');
  if (path[0] === 'meins') return 'meins';
  return path.join('/');
}
