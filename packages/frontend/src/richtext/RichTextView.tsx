// Anzeige formatierter Inhalte (Issue #172). Der Server bereinigt nach einer Allowlist (RichText im Kern); hier wird
// zusätzlich mit DOMPurify bereinigt, bevor der Inhalt ins Dokument kommt. Links öffnen in neuem Tab.

import DOMPurify from 'dompurify';

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

export default function RichTextView({ html, className }: { html: string; className?: string }) {
  return <div className={`rich-text${className ? ' ' + className : ''}`} dangerouslySetInnerHTML={{ __html: cleanRichHtml(html) }} />;
}
