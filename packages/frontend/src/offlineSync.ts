/**
 * Alle Warteschlangen mit offline vorgemerkten Änderungen übertragen (offlineQueue.ts): Anwesenheit (attendanceSync.ts)
 * und Zu-/Absagen (responseSync.ts). Die App versucht es beim Start, sobald der Browser wieder online meldet und vor dem
 * Abmelden; die Ansichten der Bereiche zusätzlich beim Öffnen.
 */
import { attendanceOutbox, syncAttendance, syncMessage } from './attendanceSync';
import { dataUpdated } from './dataCache';
import { responseOutbox, responseSyncMessage, syncResponses } from './responseSync';

/** Ein Durchlauf über alle Bereiche; zurück kommen die Meldungen für die Person (leer: nichts passiert). */
export async function syncOffline(): Promise<string[]> {
  const attendance = await syncAttendance();
  const responses = await syncResponses();
  // Offene Ansichten laden den Stand vom Server (die Anwesenheit meldet das selbst).
  if (responses.sent > 0 || responses.rejected.length > 0) dataUpdated();
  return [syncMessage(attendance), responseSyncMessage(responses)].filter((m): m is string => m !== null);
}

/** Wie viele Änderungen noch nicht übertragen sind (Listen bzw. Termine). */
export function pendingOfflineCount(): number {
  return attendanceOutbox.ids().length + responseOutbox.count();
}
