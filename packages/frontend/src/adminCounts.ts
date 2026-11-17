import { useEffect } from 'react';
import { api, applyAdmissionsDue, applyOpenReports, applyPendingRegistrations, can, config, inBackground } from './api';

/**
 * Zahlen offener Anfragen, Fehlermeldungen und fälliger Aufnahmen für die Kacheln der Verwaltung. Sie kommen mit der Konfiguration der
 * Seite, die der Service Worker zuerst aus seinem Speicher zeigt; beim Öffnen der Verwaltung darum still neu holen
 * (höchstens alle 30 Sekunden). Der Server liefert sie nur an Berechtigte (view_accounts bzw. view_reports).
 */
const MIN_GAP_MS = 30_000;
let lastAt = 0;

export function refreshAdminCounts(now = Date.now()): void {
  if (!config?.user) return;
  if (!can('view_accounts') && !can('view_reports')) return;
  if (now - lastAt < MIN_GAP_MS) return;
  lastAt = now;
  inBackground(() => api.config())
    .then((next) => {
      if (typeof next.pendingRegistrations === 'number') applyPendingRegistrations(next.pendingRegistrations);
      if (typeof next.openReports === 'number') applyOpenReports(next.openReports);
      if (typeof next.admissionsDue === 'number') applyAdmissionsDue(next.admissionsDue);
    })
    .catch(() => {
      // offline oder Fehler: die bisherigen Zahlen bleiben stehen
    });
}

/** Auf der Startseite der Verwaltung: Zahlen beim Anzeigen auffrischen. */
export function useAdminCounts(active: boolean): void {
  useEffect(() => {
    if (active) refreshAdminCounts();
  }, [active]);
}
