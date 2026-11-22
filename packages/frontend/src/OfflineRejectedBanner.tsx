import { t } from './i18n';
import { dismissRejected, useRejected } from './offlineRejectedStore';
import { rejectedText } from './offlineRejected';

/**
 * Hinweis oben in der App: Offline vorgemerkte Änderungen, die der Server beim späteren Senden abgelehnt hat
 * (offlineRejected.ts). Er bleibt, bis die Person ihn wegtippt, damit niemand eine verlorene Absage oder Buchung
 * übersieht. Bei mehreren: die neueste und „+N weitere“, aufklappbar.
 */
export default function OfflineRejectedBanner() {
  const items = useRejected();
  if (items.length === 0) return null;
  const [[, first], ...rest] = items;
  return (
    <div className="update-hint offline-rejected" role="alert">
      <div className="update-hint-text">
        <strong>{t('Nicht übertragen')}</strong>
        <span>{rejectedText(first)}</span>
        {rest.length > 0 && (
          <details className="offline-rejected-more">
            <summary>{t('+{count} weitere', { count: rest.length })}</summary>
            <ul>
              {rest.map(([key, r]) => (
                <li key={key}>{rejectedText(r)}</li>
              ))}
            </ul>
          </details>
        )}
        <span className="offline-rejected-hint">{t('Bitte trag es erneut ein oder sprich mit der Verwaltung.')}</span>
      </div>
      <button className="btn btn-link update-hint-close" onClick={() => dismissRejected()} aria-label={t('Gelesen')} title={t('Gelesen')}>
        ✕
      </button>
    </div>
  );
}
