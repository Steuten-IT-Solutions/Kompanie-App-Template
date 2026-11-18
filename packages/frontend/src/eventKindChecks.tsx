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
}: {
  value: string[];
  disabled?: boolean;
  onChange: (kinds: string[]) => void;
  className?: string;
}) {
  const kinds = eventKinds();
  const options = [...kinds, ...value.filter((k) => !kinds.includes(k))];
  const toggle = (kind: string, on: boolean) => onChange(options.filter((k) => (k === kind ? on : value.includes(k))));
  return (
    <fieldset className={`field event-kind-checks ${className ?? ''}`}>
      <legend className="field-label">Terminarten</legend>
      {options.map((k) => (
        <label key={k} className="toggle">
          <input type="checkbox" checked={value.includes(k)} disabled={disabled} onChange={(e) => toggle(k, e.target.checked)} />
          {k}
        </label>
      ))}
    </fieldset>
  );
}
