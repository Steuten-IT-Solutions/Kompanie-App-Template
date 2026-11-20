import { useState } from 'react';
import { eventKinds } from './api';

/** Arten eines Termins oder einer Anwesenheitsliste; ältere Server schicken nur kind (eine oder null). */
export const kindsOf = (x: { kind?: string | null; kinds?: string[] }): string[] => x.kinds ?? (x.kind ? [x.kind] : []);

/**
 * Häkchen je Terminart (mehrere möglich). Eine Art, die nicht mehr in der Liste steht, bleibt angehakt sichtbar, bis
 * jemand sie abwählt. onChange bekommt die neue Auswahl in der Reihenfolge der Einstellungen.
 */
export function EventKindChecks({
  value,
  disabled,
  onChange,
  className,
  id,
  hideLegend,
}: {
  value: string[];
  disabled?: boolean;
  onChange: (kinds: string[]) => void;
  className?: string;
  id?: string;
  /** Überschrift nur für Screenreader (wenn ein Knopf zum Aufklappen sie schon zeigt) */
  hideLegend?: boolean;
}) {
  const kinds = eventKinds();
  const options = [...kinds, ...value.filter((k) => !kinds.includes(k))];
  const toggle = (kind: string, on: boolean) => onChange(options.filter((k) => (k === kind ? on : value.includes(k))));
  return (
    <fieldset id={id} className={`field event-kind-checks ${className ?? ''}`}>
      <legend className={hideLegend ? 'visually-hidden' : 'field-label'}>Terminarten</legend>
      {options.map((k) => (
        <label key={k} className="toggle">
          <input type="checkbox" checked={value.includes(k)} disabled={disabled} onChange={(e) => toggle(k, e.target.checked)} />
          {k}
        </label>
      ))}
    </fieldset>
  );
}

/**
 * Terminarten eingeklappt: Knopf „Terminarten ›“, darunter nur die gewählten Arten; erst auf Tipp die Häkchen
 * (am Termin und an der Anwesenheitsliste).
 */
export function CollapsibleKindChecks({
  id,
  value,
  disabled,
  onChange,
}: {
  id: string;
  value: string[];
  disabled?: boolean;
  onChange: (kinds: string[]) => void;
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        className="member-filters-toggle event-kind-toggle"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(!open)}
      >
        Terminarten
        <span className="member-filters-arrow" aria-hidden="true">
          ›
        </span>
      </button>
      {!open && <p className="small event-kind-summary">{value.length > 0 ? value.join(', ') : <span className="muted">keine</span>}</p>}
      {open && <EventKindChecks id={id} hideLegend value={value} disabled={disabled} onChange={onChange} />}
    </>
  );
}
