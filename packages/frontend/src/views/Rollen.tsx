import { useMemo, useState, type FormEvent } from 'react';
import { api, applyRoles, config, levelLabel, type Capability, type Level, type RoleAdmin } from '../api';
import { CAPABILITIES, isView, lockedFor, orderedCaps, toggleForRole } from '../capabilities';
import { RightsTree } from '../rightsTree';
import RechteMatrix from './RechteMatrix';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { PUBLIC_PREVIEW, startPreview } from '../preview';
import { ErrorBox, Loading, useAsync } from '../ui';

const sec = brand.labels.sections;

const factorText = (f: number) => `×${f.toLocaleString('de-DE')}`;

/**
 * Einstellungen → Rollen (Issue #28): Rollen anlegen, umbenennen, Rechte und Punktefaktor festlegen, ordnen (per
 * Ziehen, alle Rollen), löschen. Gast, Mitglied und Admin sind fest (umbenennen, Mitglied und Admin mit Punktefaktor;
 * bei Gast und Mitglied die Rechte zum Sehen); Mitglied ist die Rolle neuer Konten, Gast gilt auch ohne Anmeldung.
 * Anwärter sind eine eigene Rolle (z. B. mit dem Kalender). Umschaltbar auf die Übersicht „Wer sieht was“.
 */
/** Rollen in der Reihenfolge der Schlüssel; unbekannte (inzwischen dazugekommene) hinten. */
function sortedBy(roles: RoleAdmin[], keys: Level[]): RoleAdmin[] {
  const pos = (k: Level) => (keys.indexOf(k) < 0 ? keys.length : keys.indexOf(k));
  return [...roles].sort((a, b) => pos(a.key) - pos(b.key));
}

export default function Rollen({ onToast, refresh = 0 }: { onToast: (msg: string) => void; refresh?: number }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.roles(), [refresh], 'admin/roles');
  const [editing, setEditing] = useState<Level | 'neu' | null>(null);
  const [view, setView] = useState<'rollen' | 'matrix'>('rollen');
  // Reihenfolge, solange sie noch gespeichert wird: Lädt die Liste inzwischen neu (z. B. Daten aus dem Hintergrund,
  // Issue #108), sprängen Rollen sonst auf einen Zwischenstand zurück.
  const [order, setOrder] = useState<Level[] | null>(null);
  const list = useMemo(() => (order ? sortedBy(data ?? [], order) : (data ?? [])), [data, order]);

  const show = (list: RoleAdmin[]) => {
    setData(list);
    applyRoles(list);
  };
  const saveOrder = useSaveQueue();
  function reorder(next: RoleAdmin[]) {
    // Gleich in der neuen Reihenfolge zeigen, damit der Eintrag nach dem Loslassen nicht zurückspringt
    setOrder(next.map((r) => r.key));
    saveOrder(
      () => api.reorderRoles(next.map((r) => r.key)),
      (saved) => {
        setOrder(null);
        show(saved);
      },
      (e) => {
        setOrder(null);
        onToast(e.message);
        reload();
      },
    );
  }
  const sort = useDragSort(list, reorder, editing !== null);

  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const roles = list;

  const saved = (list: RoleAdmin[], msg: string) => {
    show(list);
    setEditing(null);
    onToast(msg);
  };

  return (
    <section className="card settings-section" id="einstellungen-rollen">
      <h3>Rollen</h3>
      <p className="small muted">
        Jede Person hat eine Grundrolle und auf Wunsch weitere eigene Rollen als Zusatzrollen; sie hat dann die Rechte aller ihrer Rollen
        zusammen. Hier legst du fest, welche Rollen es gibt und was sie dürfen. Die Reihenfolge gilt überall, wo Rollen stehen, auch in den
        Zählern; zum Ändern eine Rolle am Griff links ziehen.{' '}
        {config?.features.points &&
          `Der ${sec.points}faktor gilt beim Buchen, z. B. ×0,5 für junge Mitglieder; bei mehreren Rollen der kleinste.`}
      </p>
      <nav className="segmented role-views" aria-label="Ansicht">
        {(
          [
            ['rollen', 'Rollen'],
            ['matrix', 'Wer sieht was'],
          ] as const
        ).map(([id, label]) => (
          <button key={id} type="button" className={view === id ? 'active' : ''} aria-pressed={view === id} onClick={() => setView(id)}>
            {label}
          </button>
        ))}
      </nav>
      {view === 'matrix' ? (
        <RechteMatrix
          key={roles.map((r) => `${r.key}:${r.capabilities.join(',')}`).join('|')}
          roles={roles}
          onSaved={(list, msg) => {
            show(list);
            onToast(msg);
          }}
          onToast={onToast}
        />
      ) : (
        <>
          <ul className="card-list role-list">
            {roles.map((r, i) =>
              editing === r.key ? (
                <li key={r.key} {...sort.itemProps(i, 'role-item')}>
                  <RoleForm role={r} roles={roles} onSaved={saved} onCancel={() => setEditing(null)} />
                </li>
              ) : (
                <li key={r.key} {...sort.itemProps(i, 'row role-item')}>
                  {sort.handle(i, r.label)}
                  <div className="row-main">
                    <div className="row-title">
                      {r.label}
                      {config?.features.points && r.points_factor !== 1 && (
                        <span className="badge badge-role">{factorText(r.points_factor)}</span>
                      )}
                    </div>
                    <div className="row-sub">
                      {r.users === 1 ? '1 Person' : `${r.users} Personen`}
                      {(r.extra_users ?? 0) > 0 && ` (davon ${r.extra_users} als Zusatzrolle)`}
                      {!r.system && r.new_accounts && ' · für neue Konten'}
                      {r.promotable && ` · aufnehmen als ${levelLabel('member')}`}
                      {' · '}
                      {summary(r)}
                    </div>
                  </div>
                  <div className="role-actions">
                    {r.key !== 'admin' && (
                      <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => startPreview(r.key)}
                        aria-label={`App als ${r.label} ansehen`}
                        title="App als diese Rolle ansehen"
                      >
                        Ansehen
                      </button>
                    )}
                    <button
                      type="button"
                      className="btn btn-secondary"
                      onClick={() => setEditing(r.key)}
                      aria-label={`${r.label} bearbeiten`}
                    >
                      Bearbeiten
                    </button>
                  </div>
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
          <div className="role-preview">
            <p className="small muted">
              „Ansehen“ zeigt die App, wie eine Person mit genau dieser Rolle sie sieht (ohne Zusatzrollen) – mit deinen eigenen Daten, nur
              zum Ansehen. Beenden über das Banner oben.
            </p>
            <button type="button" className="btn btn-secondary btn-block" onClick={() => startPreview(PUBLIC_PREVIEW)}>
              App ohne Anmeldung ansehen
            </button>
          </div>
        </>
      )}
      {config?.features.attendance && <AttendanceGroups roles={roles} onToast={onToast} />}
    </section>
  );
}

/**
 * Einstellungen → Rollen → Anwesenheit: Gruppen im Zähler (Issue #21). Jede Rolle zählt als eigene Gruppe oder als
 * eine andere Rolle; Ketten gibt es nicht.
 */
function AttendanceGroups({ roles, onToast }: { roles: RoleAdmin[]; onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.attendanceGroups(), [roles], 'attendance-groups');
  const [draft, setDraft] = useState<Record<string, string> | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const groups = data!;
  const current = draft ?? Object.fromEntries(groups.map((g) => [g.key, g.counts_as ?? '']));
  // Ziele: nur Rollen, die selbst als eigene Gruppe zählen.
  const targets = groups.filter((g) => (current[g.key] ?? '') === '');
  const changed = draft !== null && groups.some((g) => (draft[g.key] ?? '') !== (g.counts_as ?? ''));

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      setData(await api.setAttendanceGroups(Object.fromEntries(Object.entries(current).map(([k, v]) => [k, v === '' ? null : v]))));
      setDraft(null);
      onToast('Gruppen gespeichert');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form attendance-group-form" onSubmit={save}>
      <h4>{sec.attendance}: Gruppen im Zähler</h4>
      <p className="small muted">
        Als was zählt jede Rolle in den Listen? „Eigene Gruppe“ zeigt sie gesondert, sonst zählt sie bei der gewählten Rolle mit.
      </p>
      {groups.map((g) => (
        <label key={g.key} className="field field-inline">
          <span>{g.label}</span>
          <select value={current[g.key] ?? ''} disabled={busy} onChange={(e) => setDraft({ ...current, [g.key]: e.target.value })}>
            <option value="">Eigene Gruppe</option>
            {targets
              .filter((t) => t.key !== g.key)
              .map((t) => (
                <option key={t.key} value={t.key}>
                  zählt als {t.label}
                </option>
              ))}
            {current[g.key] && !targets.some((t) => t.key === current[g.key]) && (
              <option value={current[g.key]}>zählt als {groups.find((x) => x.key === current[g.key])?.label}</option>
            )}
          </select>
        </label>
      ))}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-secondary btn-block" type="submit" disabled={busy || !changed}>
        Gruppen speichern
      </button>
    </form>
  );
}

/** Kurz, was eine Rolle darf: Mitglied, wie viele Rechte zum Sehen, die zum Verwalten einzeln. */
function summary(r: RoleAdmin): string {
  if (r.key === 'admin') return 'alle Rechte';
  if (r.capabilities.length === 0) return 'sieht nichts';
  const views = r.capabilities.filter(isView).length;
  return [
    ...(r.capabilities.includes('member') ? ['gehört zur Kompanie'] : []),
    ...(views > 0 ? [views === 1 ? '1 Recht zum Sehen' : `${views} Rechte zum Sehen`] : []),
    ...CAPABILITIES.filter((c) => !isView(c.key) && c.key !== 'member' && r.capabilities.includes(c.key)).map((c) => c.short),
  ].join(', ');
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
  // Neue Rollen sehen zuerst, was Mitglieder sehen.
  const [capabilities, setCapabilities] = useState<Set<Capability>>(
    () => new Set(role?.capabilities ?? roles.find((r) => r.key === 'member')?.capabilities ?? ['member']),
  );
  const [factor, setFactor] = useState((role?.points_factor ?? 1).toLocaleString('de-DE'));
  const [newAccounts, setNewAccounts] = useState(role?.new_accounts ?? false);
  const [promotable, setPromotable] = useState(role?.promotable ?? false);
  const [replacement, setReplacement] = useState<Level>('member');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const fixed = role?.system ?? false;
  const key = role?.key ?? '';
  const admin = key === 'admin';
  const toggle = (keys: Capability[], on: boolean) => setCapabilities((prev) => toggleForRole(key, fixed, prev, keys, on));

  // Punkte bekommen nur Mitglieder der Kompanie: Faktor für Mitglied, Admin und eigene Rollen mit member.
  const withFactor = !!config?.features.points && (fixed ? key === 'member' || admin : capabilities.has('member'));
  const withMember = capabilities.has('member');
  const ordered = orderedCaps(capabilities);

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
    const data = {
      label,
      ...(admin ? {} : { capabilities: ordered }),
      ...(fixed ? {} : { new_accounts: newAccounts, promotable: promotable && !withMember }),
      ...(withFactor ? { points_factor: factor } : {}),
    };
    void run(() => (role ? api.updateRole(role.key, data) : api.createRole(data)), role ? 'Rolle gespeichert' : 'Rolle angelegt');
  }

  function remove() {
    if (!role) return;
    const others =
      role.users > 0
        ? ` Die ${role.users === 1 ? 'Person bekommt' : `${role.users} Personen bekommen`} stattdessen die Rolle „${roles.find((r) => r.key === replacement)?.label}“.`
        : '';
    if (!window.confirm(`Rolle „${role.label}“ löschen?${others}`)) return;
    void run(() => api.deleteRole(role.key, role.users > 0 ? replacement : undefined), 'Rolle gelöscht');
  }

  return (
    <form className="form role-form" onSubmit={submit}>
      <label className="field">
        <span>Name der Rolle</span>
        <input type="text" maxLength={30} value={label} onChange={(e) => setLabel(e.target.value)} required autoFocus />
      </label>
      {fixed && (
        <p className="small muted">
          {admin
            ? 'Admins dürfen alles; die Rechte lassen sich nicht ändern.'
            : key === 'member'
              ? 'Rolle neuer Konten; gehört immer zur Kompanie. Einstellen lässt sich, was Mitglieder sehen; Rechte zum Verwalten bekommen sie über eigene Rollen.'
              : 'Gilt für Gäste und für alle, die die App ohne Anmeldung öffnen. Einstellen lässt sich, was sie sehen.'}
        </p>
      )}
      {key === 'guest' && (capabilities.has('view_documents') || capabilities.has('view_birthdays') || capabilities.has('view_board')) && (
        <p className="small rights-warning" role="note">
          Achtung: Was Gast sieht, sieht jeder, der die App öffnet – auch ohne Konto.
        </p>
      )}
      <RightsTree
        legend="Rechte"
        isOn={(c) => admin || capabilities.has(c)}
        isDisabled={(c) => lockedFor(key, fixed, c)}
        onToggle={toggle}
      />
      {!fixed && (
        <fieldset className="feature-list">
          <legend className="field-label">Konten</legend>
          <label className="toggle feature-toggle">
            <input type="checkbox" checked={newAccounts} onChange={(e) => setNewAccounts(e.target.checked)} />
            <span>Beim Anlegen neuer Konten wählbar (sonst sind neue Konten {levelLabel('member')})</span>
          </label>
          <label className="toggle feature-toggle">
            <input
              type="checkbox"
              checked={promotable && !withMember}
              disabled={withMember}
              onChange={(e) => setPromotable(e.target.checked)}
            />
            <span>
              Wer Mitglieder anlegen darf, nimmt Personen mit dieser Rolle als {levelLabel('member')} auf (z. B. Anwärter)
              {withMember && '; nur ohne „Gehört zur Kompanie“'}
            </span>
          </label>
        </fieldset>
      )}
      {withFactor && (
        <label className="field">
          <span>{sec.points}faktor (z. B. 0,5 für die Hälfte)</span>
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
