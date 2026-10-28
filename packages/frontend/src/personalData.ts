import { api } from './api';

/**
 * Auskunft nach Art. 15 DSGVO als Datei: eigene Daten (ohne id) oder die einer Person (Verwaltung). Der Service Worker
 * speichert die Antwort nicht (public/sw.js).
 */
export async function downloadPersonalData(id?: number): Promise<void> {
  const data = id === undefined ? await api.myData() : await api.memberData(id);
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `${id === undefined ? 'meine-daten' : `daten-${id}`}-${new Date().toISOString().slice(0, 10)}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10000);
}
