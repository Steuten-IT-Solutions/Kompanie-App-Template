import { useMemo, useState, type FormEvent } from 'react';
import { fromDraft, legacyOf, ruleFromRole } from '../admissionRule';
import { RuleEditor } from '../admissionRuleView';
import { api, applyRoles, config, eventKinds, levelLabel, type Capability, type Level, type RoleAdmin } from '../api';
import { CAPABILITIES, isView, lockedFor, orderedCaps, toggleForRole } from '../capabilities';
import { RightsTree } from '../rightsTree';
import RechteMatrix from './RechteMatrix';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { PUBLIC_PREVIEW, startPreview } from '../preview';
import { Caps, ErrorBox, Loading, useAsync } from '../ui';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { localeTag, SOURCE_LOCALE, t, tn } from '../i18n';
import { useEditLocale } from '../language';
import { setNameTranslations, translatedName } from '../names';

const sec = brand.labels.sections;

const factorText = (f: number) => `×${f.toLocaleString(localeTag())}`;

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
      <h3>{t('Rollen')}</h3>
      <nav className="segmented role-views" aria-label={t('Ansicht')}>
        {(
          [
            ['rollen', t('Rollen')],
            ['matrix', t('Wer sieht was')],
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
                      {tn('{count} Person', '{count} Personen', r.users)}
                      {(r.extra_users ?? 0) > 0 && ` ${t('(davon {count} als Zusatzrolle)', { count: r.extra_users ?? 0 })}`}
                      {!r.system && r.new_accounts && ` · ${t('für neue Konten')}`}
                      {(r.promotable || r.admission_extra) && ` · ${t('Anwärter-Rolle')}`}
                      {' · '}
                      {summary(r)}
                    </div>
                  </div>
                  <div className="role-actions">
                    {r.key !== 'admin' && (
                      <IconButton icon="view" label={t('App als {role} ansehen', { role: r.label })} onClick={() => startPreview(r.key)} />
                    )}
                    <IconButton icon="edit" label={t('{role} bearbeiten', { role: r.label })} onClick={() => setEditing(r.key)} />
                  </div>
                </li>
              ),
            )}
          </ul>
          {editing === 'neu' ? (
            <RoleForm roles={roles} onSaved={saved} onCancel={() => setEditing(null)} />
          ) : (
            <button type="button" className="btn btn-secondary btn-block" onClick={() => setEditing('neu')}>
              {t('+ Neue Rolle')}
            </button>
          )}
          <div className="role-preview">
            <button type="button" className="btn btn-secondary btn-block" onClick={() => startPreview(PUBLIC_PREVIEW)}>
              {t('App ohne Anmeldung ansehen')}
            </button>
            <p className="muted small">
              {t('Die App mit allen Rollen einer bestimmten Person zusammen ansehen: auf ihrer Seite unter „Konto und Rolle“.')}
            </p>
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
      onToast(t('Gruppen gespeichert'));
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form attendance-group-form" onSubmit={save}>
      <h4>{t('{attendance}: Gruppen im Zähler', { attendance: sec.attendance })}</h4>
      {groups.map((g) => (
        <label key={g.key} className="field field-inline">
          <span>
            <Caps text={g.label} />
          </span>
          <select value={current[g.key] ?? ''} disabled={busy} onChange={(e) => setDraft({ ...current, [g.key]: e.target.value })}>
            <option value="">{t('Eigene Gruppe')}</option>
            {targets
              .filter((x) => x.key !== g.key)
              .map((x) => (
                <option key={x.key} value={x.key}>
                  {t('zählt als {group}', { group: x.label })}
                </option>
              ))}
            {current[g.key] && !targets.some((x) => x.key === current[g.key]) && (
              <option value={current[g.key]}>
                {t('zählt als {group}', { group: groups.find((x) => x.key === current[g.key])?.label ?? '' })}
              </option>
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
        {t('Gruppen speichern')}
      </button>
    </form>
  );
}

/** Kurz, was eine Rolle darf: Mitglied, wie viele Rechte zum Sehen, die zum Verwalten einzeln. */
function summary(r: RoleAdmin): string {
  if (r.key === 'admin') return t('alle Rechte');
  if (r.capabilities.length === 0) return t('sieht nichts');
  // Nur Rechte, die in der Liste stehen (Rechte für Bereiche, die es noch nicht gibt, zählen nicht mit).
  const views = CAPABILITIES.filter((c) => isView(c.key) && r.capabilities.includes(c.key)).length;
  return [
    ...(r.capabilities.includes('member') ? [t('gehört zur Kompanie')] : []),
    ...(views > 0 ? [tn('{count} Recht zum Sehen', '{count} Rechte zum Sehen', views)] : []),
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
  const [factor, setFactor] = useState((role?.points_factor ?? 1).toLocaleString(localeTag()));
  const [newAccounts, setNewAccounts] = useState(role?.new_accounts ?? false);
  // Anwärter-Rolle (Aufnahmen): als Grundrolle oder Zusatzrolle; ein Haken. Ohne „Gehört zur Kompanie“ zusätzlich
  // promotable (Grundrolle → Mitglied wie bisher), sonst nur admission_extra. Dazu die Voraussetzung.
  const [promotable, setPromotable] = useState((role?.promotable ?? false) || (role?.admission_extra ?? false));
  // Voraussetzung als Baum aus UND/ODER-Gruppen; ältere Server ohne admission_rule: aus den früheren Feldern.
  const [admissionRule, setAdmissionRule] = useState(() => ruleFromRole(role));
  const [replacement, setReplacement] = useState<Level>('member');
  // Einrichten auf einer weiteren Sprache (bestehende Rollen): Name dieser Sprache, der deutsche bleibt
  const editLang = useEditLocale();
  const foreign = editLang !== SOURCE_LOCALE && !!role;
  const savedForeign = role ? translatedName('role', role.key, editLang) : '';
  const [foreignLabel, setForeignLabel] = useState(savedForeign);
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
  // Voraussetzung nur an Anwärter-Rollen: Grundrolle mit „aufnehmen“ oder Zusatzrolle, die bei der Aufnahme wegfällt.
  // Ältere Server kennen die Felder nicht (dann fehlen sie in der Antwort).
  const admissionKnown = !role || role.admission_count !== undefined;
  const candidate = !fixed && admissionKnown && promotable;
  const ruleKnown = !role || role.admission_rule !== undefined;
  const kinds = eventKinds();
  const legacyRule = ruleKnown ? null : legacyOf(admissionRule);

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
      // Mit „Gehört zur Kompanie“ trägt admission_extra die Anwärter-Rolle allein (als Grund- und Zusatzrolle).
      ...(fixed || !admissionKnown
        ? {}
        : candidate
          ? {
              admission_extra: true,
              // Ältere Server kennen nur Teilnahmen einer Art UND Mindestdauer.
              ...(ruleKnown ? { admission_rule: fromDraft(admissionRule) ?? undefined } : (legacyRule ?? {})),
            }
          : { admission_extra: false }),
      ...(withFactor ? { points_factor: factor } : {}),
    };
    const text = foreignLabel.trim();
    void run(
      async () => {
        const next = await (role ? api.updateRole(role.key, data) : api.createRole(data));
        if (foreign && role && text !== savedForeign) {
          setNameTranslations((await api.saveTranslations(editLang, [{ kind: 'role', ref: role.key, text }])).translations);
        }
        return next;
      },
      role ? t('Rolle gespeichert') : t('Rolle angelegt'),
    );
  }

  async function remove() {
    if (!role) return;
    const question = t('Rolle „{role}“ löschen?', { role: role.label });
    const others =
      role.users > 0
        ? tn(
            'Die Person bekommt stattdessen die Rolle „{role}“.',
            'Die {count} Personen bekommen stattdessen die Rolle „{role}“.',
            role.users,
            {
              role: roles.find((r) => r.key === replacement)?.label ?? '',
            },
          )
        : '';
    if (!(await confirmAsk(others ? `${question} ${others}` : question))) return;
    void run(() => api.deleteRole(role.key, role.users > 0 ? replacement : undefined), t('Rolle gelöscht'));
  }

  return (
    <form className="form role-form" onSubmit={submit}>
      <label className="field">
        <span>{t('Name der Rolle')}</span>
        {foreign ? (
          <input
            type="text"
            lang={editLang}
            maxLength={30}
            value={foreignLabel}
            placeholder={label}
            onChange={(e) => setForeignLabel(e.target.value)}
            autoFocus
          />
        ) : (
          <input type="text" maxLength={30} value={label} onChange={(e) => setLabel(e.target.value)} required autoFocus />
        )}
      </label>
      {fixed && (
        <p className="small muted">
          {admin
            ? t('Admins dürfen alles; die Rechte lassen sich nicht ändern.')
            : key === 'member'
              ? t('Rolle neuer Konten; gehört immer zur Kompanie. Was du hier anhakst, bekommt jedes Mitglied, auch Rechte zum Verwalten.')
              : t('Gilt für Gäste und für alle, die die App ohne Anmeldung öffnen. Gäste gehören nie zur Kompanie.')}
        </p>
      )}
      {key === 'guest' && [...capabilities].some((c) => !isView(c)) && (
        <p className="small rights-warning" role="note">
          {t('Achtung: Was Gast darf, darf jeder, der die App öffnet – auch ohne Konto, z. B. Inhalte ändern oder löschen.')}
        </p>
      )}
      {key === 'guest' &&
        [...capabilities].every((c) => isView(c)) &&
        (capabilities.has('view_documents') || capabilities.has('view_birthdays') || capabilities.has('view_board')) && (
          <p className="small rights-warning" role="note">
            {t('Achtung: Was Gast sieht, sieht jeder, der die App öffnet – auch ohne Konto.')}
          </p>
        )}
      <RightsTree
        legend={t('Rechte')}
        isOn={(c) => admin || capabilities.has(c)}
        isDisabled={(c) => lockedFor(key, fixed, c)}
        onToggle={toggle}
      />
      {!fixed && (
        <fieldset className="feature-list">
          <legend className="field-label">{t('Konten')}</legend>
          <label className="toggle feature-toggle">
            <input type="checkbox" checked={newAccounts} onChange={(e) => setNewAccounts(e.target.checked)} />
            <span>{t('Beim Anlegen neuer Konten wählbar (auch für alle, die nur Mitglieder anlegen dürfen)')}</span>
          </label>
          {admissionKnown ? (
            <label className="toggle feature-toggle">
              <input type="checkbox" checked={promotable} onChange={(e) => setPromotable(e.target.checked)} />
              <span>
                {t(
                  'Anwärter-Rolle: Wer Mitglieder anlegen darf, nimmt Personen mit dieser Rolle unter Verwaltung → Aufnahmen als {member} auf. Als Rolle wird sie dabei zu {member}, als Zusatzrolle wird sie entfernt.',
                  { member: levelLabel('member') },
                )}
              </span>
            </label>
          ) : (
            <label className="toggle feature-toggle">
              <input
                type="checkbox"
                checked={promotable && !withMember}
                disabled={withMember}
                onChange={(e) => setPromotable(e.target.checked)}
              />
              <span>
                {withMember
                  ? t(
                      'Wer Mitglieder anlegen darf, nimmt Personen mit dieser Rolle als {member} auf (z. B. Anwärter); nur ohne „Gehört zur Kompanie“',
                      { member: levelLabel('member') },
                    )
                  : t('Wer Mitglieder anlegen darf, nimmt Personen mit dieser Rolle als {member} auf (z. B. Anwärter)', {
                      member: levelLabel('member'),
                    })}
              </span>
            </label>
          )}
        </fieldset>
      )}
      {candidate && (
        <fieldset className="feature-list role-admission">
          <legend className="field-label">{t('Voraussetzung für die Aufnahme (Vorschlag unter Verwaltung → Aufnahmen)')}</legend>
          <RuleEditor value={admissionRule} onChange={setAdmissionRule} kinds={kinds} />
          {kinds.length === 0 && <p className="small muted">{t('Terminarten legst du unter Einstellungen → Kalender an.')}</p>}
          {!ruleKnown && !legacyRule && (
            <p className="small rights-warning" role="note">
              {t(
                'Dieser Server kennt nur Teilnahmen einer Terminart und Monate, verknüpft mit „Alle müssen erfüllt sein“. Bitte zuerst die App aktualisieren.',
              )}
            </p>
          )}
        </fieldset>
      )}
      {withFactor && (
        <label className="field">
          <span>
            <Caps text={t('{points}faktor (z. B. 0,5 für die Hälfte)', { points: sec.points })} />
          </span>
          <input type="text" inputMode="decimal" value={factor} onChange={(e) => setFactor(e.target.value)} />
        </label>
      )}
      {role?.deletable && role.users > 0 && (
        <label className="field">
          <span>
            {tn('Beim Löschen bekommen die Person die Rolle', 'Beim Löschen bekommen die {count} Personen die Rolle', role.users)}
          </span>
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
          {busy ? t('Speichert …') : role ? t('Speichern') : t('Anlegen')}
        </button>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={busy}>
          {t('Abbrechen')}
        </button>
        {role?.deletable && (
          <button type="button" className="btn btn-danger" onClick={remove} disabled={busy}>
            {t('Löschen')}
          </button>
        )}
      </div>
    </form>
  );
}
