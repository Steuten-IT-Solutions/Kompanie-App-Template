/**
 * Wartungsabo laut Update-Liste (UpdateStatus.maintenance): Text für Admins unter Einstellungen → Zustand und Updates.
 * Nach dem Ende gibt es keine neuen Versionen mehr, auch keine Sicherheitsupdates; die App läuft weiter.
 */
import { t } from './i18n';

/** JJJJ-MM-TT → TT.MM.JJJJ (ohne Zeitzone: das Datum steht so in der Liste) */
export function dayText(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

/** null = keine Angabe (nichts anzeigen) */
export function maintenanceText(maintenance: string | null | undefined, ended: boolean | undefined): string | null {
  if (!maintenance) return null;
  if (maintenance === 'unbefristet') return t('Ablauf Wartungsvertrag: unbefristet.');
  if (ended) {
    return t(
      'Ablauf Wartungsvertrag: {date} (abgelaufen). Die App läuft mit dieser Version weiter; neue Versionen, auch Sicherheitsupdates, gibt es erst nach einer Verlängerung.',
      { date: dayText(maintenance) },
    );
  }
  return t('Ablauf Wartungsvertrag: {date}. Bis dahin bekommt die App neue Versionen.', { date: dayText(maintenance) });
}
