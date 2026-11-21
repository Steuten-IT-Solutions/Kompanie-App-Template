import { nameIn, translatedName } from './names';
import { locale } from './i18n';

/**
 * Namen von Ämtern und ihren Gruppen in der Sprache der App (Einrichten je Sprache): der eigene Name dieser Sprache,
 * sonst der deutsche, wie er gespeichert ist.
 */
export function officeTitle(office: { id: number; title: string }): string {
  return nameIn('office', String(office.id), office.title);
}

/** Bezeichnung einer Gruppe der Ämter (Mehrzahl, Überschrift). */
export function officeGroupTitle(group: { id: number; title: string }): string {
  return nameIn('office_group', String(group.id), group.title);
}

/**
 * Einzahl einer Gruppe für eine einzelne gemeinsame Amtszeit („Königshaus 2026–2028“): Ein eigener Name der Sprache
 * gilt auch hier (die Einzahl gibt es nur auf Deutsch), sonst die Einzahl, sonst die Bezeichnung.
 */
export function officeGroupSingular(group: { id: number; title: string; singular?: string }): string {
  return translatedName('office_group', String(group.id), locale()) || group.singular || group.title;
}

/**
 * Bezeichnung eines Amts für eine einzelne Amtszeit oder Person („Prinz“ statt „Prinzen“): ein eigener Name der Sprache
 * (die Einzahl gibt es nur auf Deutsch), sonst die Einzahl, die der Server als holder_title liefert (eigene Einzahl oder
 * verknüpfter Titel), sonst die Bezeichnung des Amts.
 */
export function officeSingular(office: { id?: number; title: string; holder_title?: string | null }): string {
  const own = office.id === undefined ? '' : translatedName('office', String(office.id), locale());
  return own || office.holder_title || office.title;
}
