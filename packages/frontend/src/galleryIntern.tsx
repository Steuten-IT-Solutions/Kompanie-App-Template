import { useEffect, useState } from 'react';

/**
 * Haken „intern“ für ein hochgeladenes Bild (nur für Mitglieder). Zeigt die Wahl sofort; schlägt das Speichern fehl
 * (onChange liefert false), springt er zurück.
 */
export function InternToggle({
  checked,
  name,
  disabled = false,
  onChange,
}: {
  checked: boolean;
  name: string;
  disabled?: boolean;
  onChange: (intern: boolean) => Promise<boolean>;
}) {
  const [on, setOn] = useState(checked);
  useEffect(() => setOn(checked), [checked]);
  return (
    <label className="toggle">
      <input
        type="checkbox"
        checked={on}
        disabled={disabled}
        aria-label={`${name} nur für Mitglieder (intern)`}
        onChange={async (e) => {
          const next = e.target.checked;
          setOn(next);
          if (!(await onChange(next))) setOn(!next);
        }}
      />
      <span>intern</span>
    </label>
  );
}
