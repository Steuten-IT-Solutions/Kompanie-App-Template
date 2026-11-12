// Texte für das Änderungsprotokoll (Verwaltung → Änderungen), ohne React und Branding testbar.
import type { ChangeAction, ChangeEntry, ChangeField } from './api';

export const ACTION_TEXT: Record<ChangeAction, string> = {
  create: 'angelegt',
  update: 'geändert',
  delete: 'gelöscht',
};

/** Felder, deren Werte Schlüssel von Rechten sind (Rollen, Ausnahmen einer Person): in der Anzeige ihre Namen. */
const CAPABILITY_FIELDS = ['Rechte', 'Rechte zusätzlich', 'Rechte entzogen'];

/** „Mitglied „Max Muster“ geändert“; Einstellungen ohne Anführungszeichen („E-Mail geändert“). */
export function entryTitle(e: Pick<ChangeEntry, 'type' | 'label' | 'action'>): string {
  const action = ACTION_TEXT[e.action] ?? e.action;
  if (e.type === 'Einstellungen' || e.label === '') return `${e.label || e.type} ${action}`;
  return `${e.type} „${e.label}“ ${action}`;
}

/** Wert zum Anzeigen; Rechte mit ihren Namen, leer als „leer“. */
export function valueText(field: string, value: string | null | undefined, capLabel: (key: string) => string): string {
  if (value === null || value === undefined || value === '') return 'leer';
  if (CAPABILITY_FIELDS.includes(field)) {
    return value
      .split(', ')
      .map((k) => capLabel(k))
      .join(', ');
  }
  return value;
}

/**
 * Eine Zeile je Feld: „alt → neu“, beim Anlegen nur der neue, beim Löschen nur der alte Wert. Geheimes nur „geändert“,
 * ausgeblendete persönliche Angaben mit Hinweis; Werte entfernt (Konto gelöscht) als „Werte entfernt“.
 */
export function fieldText(c: ChangeField, capLabel: (key: string) => string): string {
  if (c.secret) return 'geändert';
  if (c.hidden) return 'geändert (Werte nur mit „Persönliches sehen“)';
  const hasOld = 'old' in c;
  const hasNew = 'new' in c;
  if (!hasOld && !hasNew) return 'geändert (Werte entfernt)';
  if (hasOld && hasNew) return `${valueText(c.field, c.old, capLabel)} → ${valueText(c.field, c.new, capLabel)}`;
  return valueText(c.field, hasNew ? c.new : c.old, capLabel);
}

/** Zeilen für die CSV-Datei: eine je Feld (Einträge ohne Felder: eine Zeile ohne Feld). */
export function csvRows(entries: ChangeEntry[], time: (unix: number) => string, capLabel: (key: string) => string): string[][] {
  const rows: string[][] = [];
  for (const e of entries) {
    const base = [time(e.at), e.actor_name, e.area_label, e.type, e.label, ACTION_TEXT[e.action] ?? e.action];
    if (e.changes.length === 0) rows.push([...base, '', '', '']);
    for (const c of e.changes) {
      const shown = !c.secret && !c.hidden;
      rows.push([
        ...base,
        c.field,
        shown && 'old' in c ? valueText(c.field, c.old, capLabel) : '',
        shown && 'new' in c ? valueText(c.field, c.new, capLabel) : c.secret || c.hidden ? 'geändert' : '',
      ]);
    }
  }
  return rows;
}
