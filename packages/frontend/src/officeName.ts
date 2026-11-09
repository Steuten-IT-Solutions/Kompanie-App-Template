/**
 * Bezeichnung eines Amts für eine einzelne Amtszeit oder Person („Prinz“ statt „Prinzen“): die Einzahl, die der Server
 * als holder_title liefert (eigene Einzahl oder verknüpfter Titel), sonst die Bezeichnung des Amts.
 */
export function officeSingular(office: { title: string; holder_title?: string | null }): string {
  return office.holder_title || office.title;
}
