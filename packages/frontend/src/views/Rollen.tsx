import { useState, type FormEvent } from 'react';
import { api, applyRoles, config, type Capability, type Level, type RoleAdmin } from '../api';
import brand from '../generated/brand';
import { ErrorBox, Loading, useAsync } from '../ui';

const sec = brand.labels.sections;

/** Rechte, die der Admin einer Rolle geben kann (Einstellungen bleiben den Admins vorbehalten). */
const CAPABILITIES: { key: Capability; label: string }[] = [
  { key: 'member', label: 'Mitgliederbereich: eigene Punkte und Strafen, interner Kalender, interne Galerie' },
  { key: 'manage_points', label: `${sec.points} vergeben, Katalog pflegen` },
  { key: 'manage_penalties', label: `${sec.penalties} vergeben und als bezahlt markieren` },
  { key: 'manage_events', label: 'Öffentliche Termine pflegen' },
  { key: 'manage_documents', label: 'Dokumente pflegen und alle Dokumente sehen' },
  { key: 'create_users', label: 'Mitglieder anlegen und Startpasswörter erzeugen (nicht für Personen mit mehr Rechten)' },
  { key: 'promote_users', label: 'Rollen vergeben (nur Rollen mit eigenen Rechten, ohne diese beiden Rechte)' },
];

const factorText = (f: number) => `×${f.toLocaleString('de-DE')}`;

/**
 * Einstellungen → Rollen (Issue #28): Rollen anlegen, umbenennen, Rechte und Punktefaktor festlegen,
 * löschen. Gast und Admin sind fest (nur umbenennen), Mitglied ist die Rolle neuer Konten.
 */
export default function Rollen({ onToast, refresh = 0 }: { onToast: (msg: string) => void; refresh?: number }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.roles(), [refresh]);
  const [editing, setEditing] = useState<Level | 'neu' | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const roles = data!;

  const saved = (list: RoleAdmin[], msg: string) => {
    setData(list);
    applyRoles(list);
    setEditing(null);
    onToast(msg);
  };

  return (
    <section className="card settings-section" id="einstellungen-rollen">
      <h3>Rollen</h3>
      <p className="small muted">
        Jede Person hat eine Rolle. Hier legst du fest, welche Rollen es gibt und was sie dürfen. {config?.features.points && 'Der Punktefaktor gilt beim Buchen, z. B. ×0,5 für junge Mitglieder.'}
      </p>
      <ul className="card-list role-list">
        {roles.map((r) =>
          editing === r.key ? (
            <li key={r.key} className="role-item">
              <RoleForm role={r} roles={roles} onSaved={saved} onCancel={() => setEditing(null)} />
            </li>
          ) : (
            <li key={r.key} className="row role-item">
              <div className="row-main">
                <div className="row-title">
                  {r.label}
                  {config?.features.points && r.points_factor !== 1 && <span className="badge badge-role">{factorText(r.points_factor)}</span>}
                </div>
                <div className="row-sub">
                  {r.users === 1 ? '1 Person' : `${r.users} Personen`}
                  {' · '}
                  {r.key === 'admin'
                    ? 'alle Rechte'
                    : r.capabilities.length === 0
                      ? 'nur öffentliche Inhalte'
                      : CAPABILITIES.filter((c) => r.capabilities.includes(c.key))
                          .map((c) => c.label.split(':')[0])
                          .join(', ')}
                </div>
              </div>
              <button type="button" className="btn btn-secondary" onClick={() => setEditing(r.key)} aria-label={`${r.label} bearbeiten`}>
                Bearbeiten
              </button>
            </li>
          ),
        )}
      </ul>
      {editing === 'neu' ? (
        <RoleForm roles={roles} onSaved={saved} onCancel={() => setEditing(null)} />
      ) : (
        <button type="button" className="btn btn-secondary btn-block" onClick={() => setEditing('neu')}>
          + Neue Rolle
        </button>
      )}
    </section>
  );
}

function RoleForm({
  role,
  roles,
  onSaved,
  onCancel,
}: {
  role?: RoleAdmin;
  roles: RoleAdmin[];
  onSaved: (list: RoleAdmin[], msg: string) => void;
  onCancel: () => void;
}) {
  const [label, setLabel] = useState(role?.label ?? '');
  const [capabilities, setCapabilities] = useState<Capability[]>(role?.capabilities ?? ['member']);
  const [factor, setFactor] = useState((role?.points_factor ?? 1).toLocaleString('de-DE'));
  const [replacement, setReplacement] = useState<Level>('member');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const fixed = role?.system ?? false;

  const toggle = (cap: Capability, on: boolean) => {
    // Ohne Mitgliederbereich gibt es keine weiteren Rechte (die Person sähe ihre Mitglieder nicht).
    if (cap === 'member' && !on) return setCapabilities([]);
    setCapabilities((prev) => (on ? Array.from(new Set<Capability>([...prev, 'member', cap])) : prev.filter((c) => c !== cap)));
  };

  async function run(action: () => Promise<RoleAdmin[]>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      onSaved(await action(), msg);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    const withFactor = config?.features.points && (!fixed || role?.key === 'member');
    const data = { label, ...(fixed ? {} : { capabilities }), ...(withFactor ? { points_factor: factor } : {}) };
    void run(() => (role ? api.updateRole(role.key, data) : api.createRole(data)), role ? 'Rolle gespeichert' : 'Rolle angelegt');
  }

  function remove() {
    if (!role) return;
    const others = role.users > 0 ? ` Die ${role.users === 1 ? 'Person bekommt' : `${role.users} Personen bekommen`} die Rolle „${roles.find((r) => r.key === replacement)?.label}“.` : '';
    if (!window.confirm(`Rolle „${role.label}“ löschen?${others}`)) return;
    void run(() => api.deleteRole(role.key, role.users > 0 ? replacement : undefined), 'Rolle gelöscht');
  }

  return (
    <form className="form role-form" onSubmit={submit}>
      <label className="field">
        <span>Name der Rolle</span>
        <input type="text" maxLength={30} value={label} onChange={(e) => setLabel(e.target.value)} required autoFocus />
      </label>
      {fixed ? (
        <p className="small muted">
          {role?.key === 'admin'
            ? 'Admins dürfen alles; das lässt sich nicht ändern.'
            : role?.key === 'member'
              ? 'Rolle neuer Konten mit dem Mitgliederbereich; die Rechte lassen sich nicht ändern.'
              : 'Gäste sehen nur öffentliche Inhalte; das lässt sich nicht ändern.'}
        </p>
      ) : (
        <fieldset className="feature-list">
          <legend className="field-label">Rechte</legend>
          {CAPABILITIES.map((c) => (
            <label key={c.key} className="toggle feature-toggle">
              <input type="checkbox" checked={capabilities.includes(c.key)} onChange={(e) => toggle(c.key, e.target.checked)} />
              <span>{c.label}</span>
            </label>
          ))}
        </fieldset>
      )}
      {config?.features.points && (!fixed || role?.key === 'member') && (
        <label className="field">
          <span>Punktefaktor (z. B. 0,5 für halbe Punkte)</span>
          <input type="text" inputMode="decimal" value={factor} onChange={(e) => setFactor(e.target.value)} />
        </label>
      )}
      {role?.deletable && role.users > 0 && (
        <label className="field">
          <span>Beim Löschen bekommen die {role.users === 1 ? 'Person' : `${role.users} Personen`} die Rolle</span>
          <select value={replacement} onChange={(e) => setReplacement(e.target.value)}>
            {roles
              .filter((r) => r.key !== role.key && r.key !== 'admin')
              .map((r) => (
                <option key={r.key} value={r.key}>
                  {r.label}
                </option>
              ))}
          </select>
        </label>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button type="submit" className="btn" disabled={busy}>
          {busy ? 'Speichert …' : role ? 'Speichern' : 'Anlegen'}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={busy}>
          Abbrechen
        </button>
        {role?.deletable && (
          <button type="button" className="btn btn-danger" onClick={remove} disabled={busy}>
            Löschen
          </button>
        )}
      </div>
    </form>
  );
}
