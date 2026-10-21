import { useState } from 'react';
import { api, type Capability, type MemberRights } from '../api';
import { CAPABILITIES } from '../capabilities';

/** Rechte nach den Rollen und Ausnahmen; der Mitgliederbereich schließt den internen Kalender ein. */
function effective(r: MemberRights): Set<Capability> {
  const out = new Set<Capability>([...r.role, ...r.grant].filter((c) => !r.revoke.includes(c)));
  if (out.has('member')) out.add('calendar');
  return out;
}

export const hasOverrides = (r: MemberRights | null | undefined): boolean => !!r && (r.grant.length > 0 || r.revoke.length > 0);

/**
 * Rechte einer Person übersteuern: einzelne Rechte zusätzlich zu ihren Rollen (Grundrolle und Zusatzrollen) geben oder
 * entziehen, ohne dafür eine eigene Rolle anzulegen. Wer nicht Admin ist, ändert nur Rechte, die er selbst hat (editable).
 */
export function MemberRightsPanel({
  id,
  name,
  roles,
  plural,
  rights,
  onChanged,
  onToast,
}: {
  id: number;
  name: string;
  /** Bezeichnungen ihrer Rollen, z. B. „Mitglied, Kassenwart“ */
  roles: string;
  /** mehrere Rollen (Zusatzrollen) */
  plural: boolean;
  rights: MemberRights;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [checked, setChecked] = useState<Set<Capability>>(() => effective(rights));
  const [busy, setBusy] = useState(false);
  const fromRole = effective({ ...rights, grant: [], revoke: [] });
  const member = checked.has('member');
  const isOn = (c: Capability) => checked.has(c) || (c === 'calendar' && member);

  // Was von den Rollen abweicht; der Kalender zählt neben dem Mitgliederbereich nicht extra.
  const grant = [...checked].filter((c) => !fromRole.has(c) && !(c === 'calendar' && member));
  const revoke = [...fromRole].filter((c) => !isOn(c));
  const dirty =
    grant.length !== rights.grant.length ||
    revoke.length !== rights.revoke.length ||
    grant.some((c) => !rights.grant.includes(c)) ||
    revoke.some((c) => !rights.revoke.includes(c));

  function toggle(c: Capability, on: boolean) {
    const next = new Set(checked);
    if (on) next.add(c);
    else next.delete(c);
    setChecked(next);
  }

  async function save(g: Capability[], r: Capability[], msg: string) {
    setBusy(true);
    try {
      const saved = await api.setRights(id, g, r);
      setChecked(effective(saved));
      onToast(msg);
      onChanged();
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="member-rights">
      <h3 className="section-title">Rechte</h3>
      <div className="card">
        <p className="small muted">
          {plural ? `Grundlage sind die Rollen „${roles}“.` : `Grundlage ist die Rolle „${roles}“.`} Hier kannst du {name} einzelne Rechte
          zusätzlich geben oder entziehen. Die Ausnahmen bleiben auch bei einem Rollenwechsel bestehen.
        </p>
        <fieldset className="feature-list" disabled={busy}>
          <legend className="field-label">Rechte von {name}</legend>
          {CAPABILITIES.map((c) => {
            const on = isOn(c.key);
            const changed = !(c.key === 'calendar' && member) && on !== fromRole.has(c.key);
            return (
              <label key={c.key} className="toggle feature-toggle">
                <input
                  type="checkbox"
                  checked={on}
                  disabled={!rights.editable.includes(c.key) || (c.key === 'calendar' && member)}
                  onChange={(e) => toggle(c.key, e.target.checked)}
                />
                <span>
                  {c.label}
                  {changed && <span className="badge badge-override">{on ? 'zusätzlich' : 'entzogen'}</span>}
                </span>
              </label>
            );
          })}
        </fieldset>
        <div className="button-row">
          <button className="btn" disabled={busy || !dirty} onClick={() => save(grant, revoke, 'Rechte gespeichert')}>
            Rechte speichern
          </button>
          {hasOverrides(rights) && (
            <button
              className="btn btn-secondary"
              disabled={busy}
              onClick={() =>
                window.confirm(
                  `Alle Ausnahmen von ${name} entfernen? Dann gelten nur die Rechte ${plural ? 'der Rollen' : 'der Rolle'}.`,
                ) && save([], [], 'Rechte wie die Rolle')
              }
            >
              Wie die Rolle
            </button>
          )}
        </div>
      </div>
    </section>
  );
}
