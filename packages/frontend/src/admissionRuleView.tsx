import { Fragment } from 'react';
import type { AdmissionRuleResult } from './api';
import { countNodes, CONDITIONS_MAX, dateText, describeRule, GROUPS_MAX, plural, type DraftGroup, type DraftNode } from './admissionRule';
import { IconButton } from './icons';

const STATE_LABEL = { fulfilled: 'erfüllt', open: 'offen', unknown: 'unbekannt' } as const;

/** Ausgewertete Voraussetzung als Liste mit Haken (erfüllt) und Kreis (offen), Gruppen eingerückt. */
export function RuleResult({ result }: { result: AdmissionRuleResult }) {
  return (
    <div className="rule-result">
      <ResultGroup group={result} />
    </div>
  );
}

function ResultGroup({ group }: { group: AdmissionRuleResult }) {
  const items = group.items ?? [];
  return (
    <ul className="rule-result-list">
      {items.map((r, i) => (
        <Fragment key={i}>
          {i > 0 && (
            <li className="rule-joiner" aria-hidden="true">
              {group.op === 'or' ? 'oder' : 'und'}
            </li>
          )}
          <li className={`rule-result-item rule-${r.status}`}>
            <span className="rule-mark" aria-label={STATE_LABEL[r.status]} role="img">
              {r.status === 'fulfilled' ? '✓' : '○'}
            </span>
            {r.type === 'group' ? (
              <div className="rule-result-group">
                <span className="small muted">{r.op === 'or' ? 'Eine davon:' : 'Alle davon:'}</span>
                <ResultGroup group={r} />
              </div>
            ) : (
              <span>
                {r.type === 'attendance'
                  ? `${r.attended ?? 0} von ${plural(r.count ?? 0, 'Teilnahme', 'Teilnahmen')} an „${r.kind}“`
                  : `${plural(r.months ?? 0, 'Monat', 'Monate')} als Anwärter${r.due ? ` (ab ${dateText(r.due)})` : ' („Anwärter seit“ fehlt)'}`}
              </span>
            )}
          </li>
        </Fragment>
      ))}
    </ul>
  );
}

/**
 * Editor der Voraussetzung (Einstellungen → Rollen): oben UND/ODER, darunter je Bedingung eine Karte, Gruppen mit eigenem
 * UND/ODER eingerückt. „+ Bedingung“ und „+ Gruppe“ fügen in die jeweilige Gruppe ein.
 */
export function RuleEditor({ value, onChange, kinds }: { value: DraftGroup; onChange: (next: DraftGroup) => void; kinds: string[] }) {
  const counts = countNodes(value);
  const full = { conditions: counts.conditions >= CONDITIONS_MAX, groups: counts.groups >= GROUPS_MAX };
  const sentence = describeRule(value);
  return (
    <div className="rule-editor stack">
      <GroupEditor group={value} onChange={(g) => onChange(g)} kinds={kinds} full={full} top />
      <p className="small muted rule-sentence">{sentence ? `Voraussetzung: ${sentence}` : 'Ohne Voraussetzung'}</p>
    </div>
  );
}

function GroupEditor({
  group,
  onChange,
  onRemove,
  kinds,
  full,
  top = false,
}: {
  group: DraftGroup;
  onChange: (next: DraftGroup) => void;
  onRemove?: () => void;
  kinds: string[];
  full: { conditions: boolean; groups: boolean };
  top?: boolean;
}) {
  const set = (items: DraftNode[]) => onChange({ ...group, items });
  const replace = (i: number, next: DraftNode) => set(group.items.map((x, j) => (j === i ? next : x)));
  const remove = (i: number) => set(group.items.filter((_, j) => j !== i));
  const add = (node: DraftNode) => set([...group.items, node]);
  return (
    <div className={top ? 'rule-group rule-group-top' : 'rule-group'}>
      <div className="rule-group-head">
        <div className="segmented small" role="group" aria-label="Verknüpfung">
          <button
            type="button"
            className={group.op === 'and' ? 'active' : ''}
            aria-pressed={group.op === 'and'}
            onClick={() => onChange({ ...group, op: 'and' })}
          >
            Alle müssen erfüllt sein
          </button>
          <button
            type="button"
            className={group.op === 'or' ? 'active' : ''}
            aria-pressed={group.op === 'or'}
            onClick={() => onChange({ ...group, op: 'or' })}
          >
            Eine reicht
          </button>
        </div>
        {onRemove && <IconButton icon="delete" label="Gruppe entfernen" variant="danger" onClick={onRemove} />}
      </div>
      {group.items.map((item, i) => (
        <Fragment key={i}>
          {i > 0 && (
            <p className="rule-joiner" aria-hidden="true">
              {group.op === 'or' ? 'oder' : 'und'}
            </p>
          )}
          {item.type === 'group' ? (
            <GroupEditor group={item} onChange={(g) => replace(i, g)} onRemove={() => remove(i)} kinds={kinds} full={full} />
          ) : (
            <ConditionEditor node={item} onChange={(n) => replace(i, n)} onRemove={() => remove(i)} kinds={kinds} />
          )}
        </Fragment>
      ))}
      <div className="button-row rule-add">
        <button
          type="button"
          className="btn btn-small btn-secondary"
          disabled={full.conditions}
          onClick={() => add({ type: 'attendance', kind: kinds[0] ?? '', count: '1' })}
        >
          + Teilnahmen
        </button>
        <button
          type="button"
          className="btn btn-small btn-secondary"
          disabled={full.conditions}
          onClick={() => add({ type: 'duration', months: '12' })}
        >
          + Dauer
        </button>
        <button
          type="button"
          className="btn btn-small btn-secondary"
          disabled={full.groups || full.conditions}
          onClick={() =>
            add({ type: 'group', op: group.op === 'and' ? 'or' : 'and', items: [{ type: 'attendance', kind: kinds[0] ?? '', count: '1' }] })
          }
        >
          + Gruppe
        </button>
      </div>
    </div>
  );
}

function ConditionEditor({
  node,
  onChange,
  onRemove,
  kinds,
}: {
  node: Exclude<DraftNode, DraftGroup>;
  onChange: (next: DraftNode) => void;
  onRemove: () => void;
  kinds: string[];
}) {
  const options = node.type === 'attendance' && node.kind && !kinds.includes(node.kind) ? [...kinds, node.kind] : kinds;
  return (
    <div className="rule-condition card">
      {node.type === 'attendance' ? (
        <div className="rule-fields">
          <label className="field">
            <span>Mindestens so viele Teilnahmen („anwesend“)</span>
            <input
              type="number"
              min={1}
              max={100}
              value={node.count}
              onChange={(e) => onChange({ ...node, count: e.target.value })}
              required
            />
          </label>
          <label className="field">
            <span>an Terminen der Art</span>
            <select value={node.kind} onChange={(e) => onChange({ ...node, kind: e.target.value })} required>
              {options.length === 0 && <option value="">keine Terminart eingerichtet</option>}
              {options.map((k) => (
                <option key={k} value={k}>
                  {k}
                </option>
              ))}
            </select>
          </label>
        </div>
      ) : (
        <div className="rule-fields">
          <label className="field">
            <span>Mindestens so viele Monate als Anwärter</span>
            <input
              type="number"
              min={1}
              max={120}
              value={node.months}
              onChange={(e) => onChange({ ...node, months: e.target.value })}
              required
            />
          </label>
        </div>
      )}
      <IconButton icon="delete" label="Bedingung entfernen" variant="danger" onClick={onRemove} />
    </div>
  );
}
