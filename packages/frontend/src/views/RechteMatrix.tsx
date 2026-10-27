import { Fragment, useState } from 'react';
import { api, type Capability, type Level, type RoleAdmin } from '../api';
import { groupState, lockedFor, orderedCaps, toggleForRole } from '../capabilities';
import { TriCheckbox, visibleGroups } from '../rightsTree';

/**
 * Einstellungen → Rollen → „Wer sieht was“: alle Rechte (Zeilen, in Gruppen zum Ein- und Ausklappen) für alle Rollen
 * (Spalten) auf einen Blick, direkt anzuhaken. Dieselben Regeln wie im Formular einer Rolle (toggleForRole); Admin hat
 * alle Rechte. Gespeichert werden nur die geänderten Rollen, nacheinander.
 */
export default function RechteMatrix({
  roles,
  onSaved,
  onToast,
}: {
  roles: RoleAdmin[];
  onSaved: (list: RoleAdmin[], msg: string) => void;
  onToast: (msg: string) => void;
}) {
  const groups = visibleGroups();
  const initial = () => Object.fromEntries(roles.map((r) => [r.key, new Set(r.capabilities)])) as Record<Level, Set<Capability>>;
  const [draft, setDraft] = useState<Record<Level, Set<Capability>>>(initial);
  const [open, setOpen] = useState<Set<string>>(() => new Set());
  const [busy, setBusy] = useState(false);

  const isOn = (r: RoleAdmin, c: Capability) => r.key === 'admin' || !!draft[r.key]?.has(c);
  const locked = (r: RoleAdmin, c: Capability) => lockedFor(r.key, r.system, c);
  const changed = roles.filter((r) => {
    const now = orderedCaps(draft[r.key] ?? new Set());
    const before = orderedCaps(new Set(r.capabilities));
    return r.key !== 'admin' && (now.length !== before.length || now.some((c, i) => c !== before[i]));
  });
  const toggle = (r: RoleAdmin, keys: Capability[], on: boolean) =>
    setDraft((prev) => ({ ...prev, [r.key]: toggleForRole(r.key, r.system, prev[r.key] ?? new Set(), keys, on) }));
  const flip = (key: string) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  async function save() {
    setBusy(true);
    let list: RoleAdmin[] | null = null;
    try {
      for (const r of changed) {
        try {
          list = await api.updateRole(r.key, { capabilities: orderedCaps(draft[r.key] ?? new Set()) });
        } catch (e) {
          throw new Error(`${r.label}: ${(e as Error).message}`);
        }
      }
      if (list) onSaved(list, changed.length === 1 ? 'Rechte gespeichert' : `Rechte von ${changed.length} Rollen gespeichert`);
    } catch (e) {
      // Was schon gespeichert ist, bleibt; die Liste zeigt den Stand des Servers.
      if (list) onSaved(list, (e as Error).message);
      else onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="rights-matrix-view">
      <p className="small muted">
        Je Zeile ein Recht, je Spalte eine Rolle. Der Haken in der Zeile einer Gruppe setzt alle Rechte der Gruppe für diese Rolle. Gast
        gilt auch für alle, die die App ohne Anmeldung öffnen.
      </p>
      <div className="rights-matrix-scroll">
        <table className="rights-matrix">
          <thead>
            <tr>
              <th scope="col">Recht</th>
              {roles.map((r) => (
                <th key={r.key} scope="col">
                  {r.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {groups.map((g) => {
              const keys = g.items.map((i) => i.key);
              const expanded = open.has(g.key);
              return (
                <Fragment key={g.key}>
                  <tr className="rights-matrix-group">
                    <th scope="row">
                      <button type="button" className="rights-matrix-toggle" aria-expanded={expanded} onClick={() => flip(g.key)}>
                        {g.label}
                        <span className="fold-arrow" aria-hidden="true">
                          ›
                        </span>
                      </button>
                    </th>
                    {roles.map((r) => {
                      const free = keys.filter((c) => !locked(r, c));
                      return (
                        <td key={r.key}>
                          <TriCheckbox
                            state={groupState((c) => isOn(r, c), keys)}
                            disabled={busy || free.length === 0}
                            label={`${g.label} für ${r.label}`}
                            onChange={(on) => toggle(r, free, on)}
                          />
                        </td>
                      );
                    })}
                  </tr>
                  {expanded &&
                    g.items.map((i) => (
                      <tr key={i.key}>
                        <th scope="row">{i.label}</th>
                        {roles.map((r) => (
                          <td key={r.key}>
                            <input
                              type="checkbox"
                              aria-label={`${i.label} für ${r.label}`}
                              checked={isOn(r, i.key)}
                              disabled={busy || locked(r, i.key)}
                              onChange={(e) => toggle(r, [i.key], e.target.checked)}
                            />
                          </td>
                        ))}
                      </tr>
                    ))}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
      <div className="button-row">
        <button type="button" className="btn" disabled={busy || changed.length === 0} onClick={save}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button type="button" className="btn btn-secondary" disabled={busy || changed.length === 0} onClick={() => setDraft(initial())}>
          Verwerfen
        </button>
      </div>
    </div>
  );
}
