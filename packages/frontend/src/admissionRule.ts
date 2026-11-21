import type { AdmissionRuleNode, AdmissionRuleResult } from './api';
import { t, tn } from './i18n';

/**
 * Voraussetzung für die Aufnahme als Baum (Anzeige: admissionRuleView.tsx) (Server: Domain\AdmissionRule): Gruppen verknüpfen Bedingungen und weitere
 * Gruppen mit UND (alle) oder ODER (eine reicht), beliebig tief. Bedingungen: Teilnahmen an Terminen einer Art und
 * Monate als Anwärter.
 */

export const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** JJJJ-MM-TT → TT.MM.JJJJ (wie formatDate, ohne Abhängigkeiten, damit die Tests es direkt laden). */
export const dateText = (ymd: string) => `${ymd.slice(8, 10)}.${ymd.slice(5, 7)}.${ymd.slice(0, 4)}`;

/** Grenzen wie auf dem Server (AdmissionRule::CONDITIONS_MAX, GROUPS_MAX) */
export const CONDITIONS_MAX = 20;
export const GROUPS_MAX = 20;

/** Bearbeitete Form: Zahlen als Text aus den Eingabefeldern. */
export type DraftNode =
  | { type: 'group'; op: 'and' | 'or'; items: DraftNode[] }
  | { type: 'attendance'; kind: string; count: string }
  | { type: 'duration'; months: string };
export type DraftGroup = Extract<DraftNode, { type: 'group' }>;

export const emptyRule = (): DraftGroup => ({ type: 'group', op: 'and', items: [] });

/** Baum vom Server, sonst aus den früheren Feldern (Teilnahmen einer Art UND Mindestdauer). */
export function ruleFromRole(role?: {
  admission_rule?: AdmissionRuleNode;
  admission_kind?: string | null;
  admission_count?: number;
  admission_months?: number;
}): DraftGroup {
  if (role?.admission_rule?.type === 'group') return toDraft(role.admission_rule) as DraftGroup;
  const items: DraftNode[] = [];
  if (role?.admission_kind && (role.admission_count ?? 0) > 0)
    items.push({ type: 'attendance', kind: role.admission_kind, count: String(role.admission_count) });
  if ((role?.admission_months ?? 0) > 0) items.push({ type: 'duration', months: String(role?.admission_months) });
  return { type: 'group', op: 'and', items };
}

function toDraft(n: AdmissionRuleNode): DraftNode {
  if (n.type === 'group') return { type: 'group', op: n.op, items: n.items.map(toDraft) };
  if (n.type === 'attendance') return { type: 'attendance', kind: n.kind, count: String(n.count) };
  return { type: 'duration', months: String(n.months) };
}

/** Zum Speichern: Zahlen als Zahl (ungültige prüft der Server), leere Gruppen fallen weg. */
export function fromDraft(n: DraftNode): AdmissionRuleNode | null {
  if (n.type === 'group') {
    const items = n.items.map(fromDraft).filter((i): i is AdmissionRuleNode => i !== null);
    return { type: 'group', op: n.op, items };
  }
  if (n.type === 'attendance') return { type: 'attendance', kind: n.kind, count: Number(n.count) };
  return { type: 'duration', months: Number(n.months) };
}

/** Für ältere Server: die frühere Form (eine Art Teilnahmen UND Mindestdauer), null, wenn der Baum mehr ausdrückt. */
export function legacyOf(g: DraftGroup): { admission_kind: string; admission_count: string; admission_months: string } | null {
  if (g.items.length > 1 && g.op !== 'and') return null;
  const att = g.items.filter((i): i is Extract<DraftNode, { type: 'attendance' }> => i.type === 'attendance');
  const dur = g.items.filter((i): i is Extract<DraftNode, { type: 'duration' }> => i.type === 'duration');
  if (att.length > 1 || dur.length > 1 || att.length + dur.length !== g.items.length) return null;
  return {
    admission_kind: att[0]?.kind ?? '',
    admission_count: att[0]?.count ?? '0',
    admission_months: dur[0]?.months ?? '0',
  };
}

/** Bedingungen und Gruppen (ohne die oberste) zählen. */
export function countNodes(n: DraftNode): { conditions: number; groups: number } {
  if (n.type !== 'group') return { conditions: 1, groups: 0 };
  return n.items.reduce(
    (acc, i) => {
      const c = countNodes(i);
      return { conditions: acc.conditions + c.conditions, groups: acc.groups + c.groups + (i.type === 'group' ? 1 : 0) };
    },
    { conditions: 0, groups: 0 },
  );
}

/** Knoten in beliebiger Form (gespeichert, bearbeitet oder ausgewertet) für describeRule. */
type AnyNode = { type: string; op?: string; items?: AnyNode[]; kind?: string; count?: number | string; months?: number | string };

/** Voraussetzung in einer Zeile, z. B. „6 Teilnahmen an Versammlung und (12 Monate als Anwärter oder …)“; leer ohne. */
export function describeRule(n: AnyNode, inner = false): string {
  if (n.type === 'attendance')
    return tn('{count} Teilnahme an „{kind}“', '{count} Teilnahmen an „{kind}“', Number(n.count ?? 0), { kind: n.kind || '?' });
  if (n.type === 'duration') return tn('{count} Monat als Anwärter', '{count} Monate als Anwärter', Number(n.months ?? 0));
  const parts = (n.items ?? []).map((i) => describeRule(i, true)).filter((p) => p !== '');
  const text = parts.join(n.op === 'or' ? ` ${t('oder')} ` : ` ${t('und')} `);
  return inner && parts.length > 1 ? `(${text})` : text;
}

/** Was an einer Bedingung noch fehlt, in Worten; null, wenn erfüllt. */
function leafState(r: AdmissionRuleResult): string | null {
  if (r.status === 'fulfilled') return null;
  if (r.type === 'attendance')
    return tn('noch {count} Teilnahme an „{kind}“', 'noch {count} Teilnahmen an „{kind}“', r.missing ?? 0, { kind: r.kind ?? '' });
  if (r.type === 'duration') return r.due ? t('Mindestdauer ab {date}', { date: dateText(r.due) }) : t('„Anwärter seit“ fehlt');
  return null;
}

/** Was insgesamt noch fehlt, z. B. „noch 2 Teilnahmen an „Versammlung“ oder Mindestdauer ab 01.03.2027“. */
export function missingText(r: AdmissionRuleResult, inner = false): string {
  if (r.status === 'fulfilled') return '';
  if (r.type !== 'group') return leafState(r) ?? '';
  const parts = (r.items ?? []).map((i) => missingText(i, true)).filter((p) => p !== '');
  const text = parts.join(r.op === 'or' ? ` ${t('oder')} ` : ' · ');
  return inner && parts.length > 1 && r.op === 'or' ? `(${text})` : text;
}
