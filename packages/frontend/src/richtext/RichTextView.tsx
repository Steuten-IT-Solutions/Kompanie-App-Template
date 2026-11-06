// Anzeige formatierter Inhalte (Issue #172). Der Server bereinigt nach einer Allowlist (RichText im Kern); hier wird
// zusätzlich mit DOMPurify bereinigt, bevor der Inhalt ins Dokument kommt. Links öffnen in neuem Tab.

import DOMPurify from 'dompurify';
import { useLayoutEffect, useMemo, useRef } from 'react';
import { useTheme } from '../theme';
import { makeReadable } from './readable';

/** Bereinigtes HTML für dangerouslySetInnerHTML. */
export function cleanRichHtml(html: string): string {
  const clean = DOMPurify.sanitize(html, {
    ADD_ATTR: ['target', 'data-image'],
    FORBID_TAGS: [
      'style',
      'form',
      'input',
      'button',
      'textarea',
      'select',
      'option',
      'link',
      'meta',
      'base',
      'dialog',
      'iframe',
      'svg',
      'math',
    ],
    FORBID_ATTR: ['class', 'id'],
    RETURN_DOM_FRAGMENT: true,
  });
  clean.querySelectorAll('a').forEach((a) => {
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener noreferrer');
  });
  clean.querySelectorAll('img').forEach((img) => {
    img.setAttribute('loading', 'lazy');
    if (!img.getAttribute('alt')) img.setAttribute('alt', '');
  });
  const div = document.createElement('div');
  div.appendChild(clean);
  return div.innerHTML;
}

/**
 * Formatierter Inhalt, an die Darstellung angepasst (readable.ts): Farben mit zu wenig Kontrast und übergroße
 * Zeilenabstände aus eingefügtem Text fallen beim Anzeigen weg. Bei jedem Wechsel zwischen hell und dunkel wird der
 * Inhalt neu eingesetzt und neu geprüft.
 */
export default function RichTextView({ html, className }: { html: string; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const clean = useMemo(() => cleanRichHtml(html), [html]);
  const { shown } = useTheme();
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.innerHTML = clean;
    makeReadable(el);
  }, [clean, shown]);
  return <div ref={ref} className={`rich-text${className ? ' ' + className : ''}`} />;
}
