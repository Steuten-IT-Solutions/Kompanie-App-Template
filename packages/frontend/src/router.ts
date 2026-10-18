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
