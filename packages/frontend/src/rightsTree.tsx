import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { config, type Capability } from './api';
import { CAPABILITY_GROUPS, groupState, shown, type CapabilityGroup, type GroupState } from './capabilities';

/** Gruppen mit den Rechten, die zu eingeschalteten Bereichen gehören; leere Gruppen fallen weg. */
export function visibleGroups(): CapabilityGroup[] {
  return CAPABILITY_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => shown(i, config?.features)) })).filter(
    (g) => g.items.length > 0,
  );
}

/** Haken mit drei Zuständen (alle, einige, keine), z. B. für eine ganze Gruppe. */
export function TriCheckbox({
  state,
  disabled,
  onChange,
  label,
}: {
  state: GroupState;
  disabled?: boolean;
  onChange: (on: boolean) => void;
  label: string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = state === 'some';
  }, [state]);
  return (
    <input
      ref={ref}
      type="checkbox"
      aria-label={label}
      checked={state === 'all'}
      aria-checked={state === 'some' ? 'mixed' : state === 'all'}
      disabled={disabled}
      onChange={() => onChange(state !== 'all')}
    />
  );
}

/**
 * Rechte in Gruppen zum Ein- und Ausklappen (Einstellungen → Rollen, Rechte eines Mitglieds): je Gruppe ein Haken für
 * alle ihre Rechte (gesperrte bleiben, wie sie sind) und ein Zähler, darunter die einzelnen Haken. Zu Beginn sind die
 * Gruppen zu, außer denen, die initiallyOpen nennt (z. B. mit Ausnahmen).
 */
export function RightsTree({
  legend,
  isOn,
  isDisabled,
  onToggle,
  badge,
  initiallyOpen = () => false,
  disabled = false,
}: {
  legend: string;
  isOn: (c: Capability) => boolean;
  isDisabled: (c: Capability) => boolean;
  /** Rechte an- oder abhaken (ein einzelnes oder die freien einer Gruppe) */
  onToggle: (keys: Capability[], on: boolean) => void;
  badge?: (c: Capability) => ReactNode;
  initiallyOpen?: (group: CapabilityGroup) => boolean;
  disabled?: boolean;
}) {
  const groups = visibleGroups();
  const [open, setOpen] = useState<Set<string>>(() => new Set(groups.filter(initiallyOpen).map((g) => g.key)));
  const id = useId();
  const allOpen = groups.every((g) => open.has(g.key));
  const flip = (key: string) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  return (
    <fieldset className="rights-tree" disabled={disabled}>
      <legend className="field-label">{legend}</legend>
      <button
        type="button"
        className="btn btn-link rights-tree-all"
        onClick={() => setOpen(allOpen ? new Set() : new Set(groups.map((g) => g.key)))}
      >
        {allOpen ? 'Alle zuklappen' : 'Alle aufklappen'}
      </button>
      {groups.map((g) => {
        const keys = g.items.map((i) => i.key);
        const free = keys.filter((c) => !isDisabled(c));
        const state = groupState(isOn, keys);
        const count = keys.filter(isOn).length;
        const expanded = open.has(g.key);
        const bodyId = `${id}-${g.key}`;
        return (
          <div key={g.key} className="rights-group">
            <div className="rights-group-head">
              <TriCheckbox state={state} disabled={free.length === 0} label={`${g.label}: alle`} onChange={(on) => onToggle(free, on)} />
              <button
                type="button"
                className="rights-group-toggle"
                aria-expanded={expanded}
                aria-controls={bodyId}
                onClick={() => flip(g.key)}
              >
                <span className="rights-group-label">{g.label}</span>
                <span className="rights-group-count muted small">
                  {count} von {keys.length}
                </span>
                <span className="fold-arrow" aria-hidden="true">
                  ›
                </span>
              </button>
            </div>
            <div id={bodyId} className="rights-group-body" hidden={!expanded}>
              {g.hint && <p className="small muted rights-group-hint">{g.hint}</p>}
              {g.items.map((i) => (
                <label key={i.key} className="toggle feature-toggle">
                  <input
                    type="checkbox"
                    checked={isOn(i.key)}
                    disabled={isDisabled(i.key)}
                    onChange={(e) => onToggle([i.key], e.target.checked)}
                  />
                  <span>
                    {i.label}
                    {badge?.(i.key)}
                  </span>
                </label>
              ))}
            </div>
          </div>
        );
      })}
    </fieldset>
  );
}
