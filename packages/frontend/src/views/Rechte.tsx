import { useState } from 'react';
import { api, type Capability, type MemberRights } from '../api';
import { requires, toggleMany } from '../capabilities';
import { RightsTree } from '../rightsTree';

/** Rechte nach den Rollen und Ausnahmen; was seine Voraussetzung nicht hat, fällt weg (wie RoleService::effective). */
function effective(r: MemberRights): Set<Capability> {
  const out = new Set<Capability>([...r.role, ...r.grant].filter((c) => !r.revoke.includes(c)));
  return new Set([...out].filter((c) => !requires(c) || out.has(requires(c)!)));
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
  const fromRole = new Set(rights.role);

  // Was von den Rollen abweicht. Was wegfällt, weil seine Voraussetzung entzogen ist, entzieht der Server mit.
  const grant = [...checked].filter((c) => !fromRole.has(c));
  const revoke = [...fromRole].filter((c) => !checked.has(c) && !(requires(c) && !checked.has(requires(c)!)));
  const dirty =
    grant.length !== rights.grant.length ||
    revoke.length !== rights.revoke.length ||
    grant.some((c) => !rights.grant.includes(c)) ||
    revoke.some((c) => !rights.revoke.includes(c));
  const overridden = new Set([...rights.grant, ...rights.revoke]);

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
        <RightsTree
          legend={`Rechte von ${name}`}
          disabled={busy}
          isOn={(c) => checked.has(c)}
          isDisabled={(c) => !rights.editable.includes(c)}
          onToggle={(keys, on) => setChecked((prev) => toggleMany(prev, keys, on))}
          badge={(c) =>
            checked.has(c) !== fromRole.has(c) && <span className="badge badge-override">{checked.has(c) ? 'zusätzlich' : 'entzogen'}</span>
          }
          initiallyOpen={(g) => g.items.some((i) => overridden.has(i.key))}
        />
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
