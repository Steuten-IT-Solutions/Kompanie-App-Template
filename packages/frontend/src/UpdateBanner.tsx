import { useSyncExternalStore } from 'react';
import { dismissRestart, subscribeUpdate, updateAvailable } from './appUpdate';
import { t } from './i18n';
import { beforeLeave } from './unsaved';

/**
 * Hinweis „Die App wurde aktualisiert“ mit „Neu starten“ (appUpdate.ts). Neu geladen wird nur auf Knopfdruck; nicht
 * gespeicherte Eingaben fragt die App vorher ab. „Später“ blendet ihn aus (beim nächsten Öffnen gilt die neue Version).
 */
export default function UpdateBanner() {
  const available = useSyncExternalStore(subscribeUpdate, updateAvailable);
  if (!available) return null;
  return (
    <div className="update-hint" role="status">
      <div className="update-hint-text">
        <strong>{t('Die App wurde aktualisiert')}</strong>
        <span>{t('Starte sie neu, damit du alles Neue siehst.')}</span>
      </div>
      <button className="btn btn-small update-hint-btn" onClick={() => beforeLeave(() => window.location.reload())}>
        {t('Neu starten')}
      </button>
      <button className="btn btn-link update-hint-close" onClick={dismissRestart} aria-label={t('Später')} title={t('Später')}>
        ✕
      </button>
    </div>
  );
}
