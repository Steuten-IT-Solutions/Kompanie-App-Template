import { config } from './api';
import { t } from './i18n';

/**
 * Links auf Impressum und Datenschutzerklärung der Kompanie (Einstellungen → Impressum und Datenschutz), für alle sichtbar:
 * auf der Anmeldeseite, im Konto und in der Hilfe. Ohne eingetragene Links nichts.
 */
export function LegalLinks({ className = '' }: { className?: string }) {
  const links = [
    { label: t('Impressum'), url: config?.legal?.imprint ?? null },
    { label: t('Datenschutz'), url: config?.legal?.privacy ?? null },
  ].filter((l): l is { label: string; url: string } => !!l.url);
  if (links.length === 0) return null;
  return (
    <p className={`small muted legal-links ${className}`.trim()}>
      {links.map((l, i) => (
        <span key={l.label}>
          {i > 0 && ' · '}
          <a href={l.url} target="_blank" rel="noopener noreferrer">
            {l.label}
          </a>
        </span>
      ))}
    </p>
  );
}
