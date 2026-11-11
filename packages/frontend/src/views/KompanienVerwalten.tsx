import { Suspense, useRef, useState, type FormEvent } from 'react';
import { api, type Company, type CompanyInput, type CompanyRef } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { Empty, ErrorBox, lazyView, LinkField, Loading, useAsync } from '../ui';
import { normalizeLink } from '../link';
import { sources } from '../viewData';
import { CompanyLogo } from './Kompanien';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';

const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

const sec = brand.labels.sections;

/** Grenzen wie auf dem Server (CompanyService, LogoImage, OfficeService::YEAR_MIN). */
const LOGO_ACCEPT = '.png,.jpg,.jpeg,.webp,.svg';
const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const NAME_MAX = 100;
const SHORT_MAX = 30;
const YEAR_MIN = 1800;

type Draft = {
  name: string;
  short_name: string;
  founded_year: string;
  description: string;
  website_url: string;
  app_url: string;
  active: boolean;
};

const EMPTY: Draft = {
  name: '',
  short_name: '',
  founded_year: '',
  description: '',
  website_url: '',
  app_url: '',
  active: true,
};

function draftOf(c: Company): Draft {
  return {
    name: c.name,
    short_name: c.short_name,
    founded_year: c.founded_year === null ? '' : String(c.founded_year),
    description: c.description,
    website_url: c.website_url,
    app_url: c.app_url,
    active: c.active,
  };
}

/** Eingaben für den Server. */
function inputOf(d: Draft): CompanyInput {
  const year = d.founded_year.trim();
  return {
    name: d.name.trim(),
    short_name: d.short_name.trim(),
    founded_year: year === '' ? null : Number(year),
    description: d.description,
    website_url: normalizeLink(d.website_url),
    app_url: normalizeLink(d.app_url),
    active: d.active,
  };
}

function CompanyFields({
  draft,
  onChange,
  autoFocus,
  idPrefix,
}: {
  draft: Draft;
  onChange: (d: Draft) => void;
  autoFocus?: boolean;
  idPrefix: string;
}) {
  const set = (patch: Partial<Draft>) => onChange({ ...draft, ...patch });
  const year = new Date().getFullYear();
  return (
    <>
      <label className="field">
        <span>Name</span>
        <input
          type="text"
          maxLength={NAME_MAX}
          value={draft.name}
          onChange={(e) => set({ name: e.target.value })}
          required
          autoFocus={autoFocus}
        />
      </label>
      <label className="field">
        <span>Kurzname (optional, steht ohne Logo an seiner Stelle)</span>
        <input type="text" maxLength={SHORT_MAX} value={draft.short_name} onChange={(e) => set({ short_name: e.target.value })} />
      </label>
      <label className="field">
        <span>Gründungsjahr (optional)</span>
        <input
          type="number"
          inputMode="numeric"
          min={YEAR_MIN}
          max={year}
          value={draft.founded_year}
          onChange={(e) => set({ founded_year: e.target.value })}
        />
      </label>
      <div className="field">
        <Suspense fallback={<Loading shape="text" chunk />}>
          <RichTextEditor
            value={draft.description}
            onChange={(html) => set({ description: html })}
            label="Beschreibung (optional)"
            id={`${idPrefix}-description`}
          />
        </Suspense>
      </div>
      <LinkField label="Webseite (optional)" value={draft.website_url} onChange={(v) => set({ website_url: v })} />
      <LinkField label="App der Kompanie (optional)" value={draft.app_url} onChange={(v) => set({ app_url: v })} />
      <label className="toggle">
        <input type="checkbox" checked={!draft.active} onChange={(e) => set({ active: !e.target.checked })} />
        <span>Aufgelöst (steht als „ehemalig“ unten in der Liste)</span>
      </label>
    </>
  );
}

/** Personen einer Kompanie zuordnen: Mitglieder (und schon Zugeordnete) zum Ankreuzen. */
function CompanyMembers({ company, onSaved, onCancel }: { company: Company; onSaved: () => void; onCancel: () => void }) {
  const members = useAsync(sources.members.load, sources.members.deps, sources.members.key);
  const current = company.member_ids ?? [];
  const [selected, setSelected] = useState<number[]>(current);
  const [filter, setFilter] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  if (members.error) return <ErrorBox message={members.error} onRetry={members.reload} />;
  if (!members.data) return <Loading shape="text" />;
  const q = filter.trim().toLowerCase();
  const options = members.data.filter((m) => (m.member || current.includes(m.id)) && (q === '' || m.name.toLowerCase().includes(q)));

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      await api.setCompanyMembers(company.id, selected);
      onSaved();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form company-member-form" onSubmit={save}>
      <h3>Mitglieder von {company.name}</h3>
      <label className="field">
        <span>Suchen</span>
        <input type="search" value={filter} onChange={(e) => setFilter(e.target.value)} />
      </label>
      <p className="muted small">{selected.length} ausgewählt</p>
      <div className="company-member-options">
        {options.map((m) => (
          <label key={m.id} className="toggle">
            <input
              type="checkbox"
              checked={selected.includes(m.id)}
              onChange={(e) => setSelected(e.target.checked ? [...selected, m.id] : selected.filter((s) => s !== m.id))}
            />
            <span>{m.name}</span>
          </label>
        ))}
        {options.length === 0 && <p className="muted small">Keine Treffer.</p>}
      </div>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          Speichern
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/**
 * Verwaltung → Einrichtungen → Kompanien (Issue #280): anlegen, bearbeiten (Kurzname, Gründungsjahr, Beschreibung, Links,
 * Gruppe der Ämter, aufgelöst), Logo, Reihenfolge per Griff, Mitglieder zuordnen, löschen.
 */
export function CompanyAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.companies.load, sources.companies.deps, sources.companies.key);
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [newKey, setNewKey] = useState(0);
  const [editing, setEditing] = useState<number | null>(null);
  const [editDraft, setEditDraft] = useState<Draft>(EMPTY);
  const [assigning, setAssigning] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const logoInput = useRef<HTMLInputElement>(null);
  const logoFor = useRef<number | null>(null);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      setFormError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    if (await run(() => api.createCompany(inputOf(draft)), 'Kompanie angelegt')) {
      setDraft(EMPTY);
      setNewKey((k) => k + 1);
    }
  }

  function chooseLogo(id: number) {
    logoFor.current = id;
    setFormError(null);
    logoInput.current?.click();
  }

  async function uploadLogo(file: File | null) {
    const id = logoFor.current;
    if (logoInput.current) logoInput.current.value = '';
    if (!file || id === null) return;
    const ext = file.name.toLowerCase().split('.').pop() ?? '';
    if (!['png', 'jpg', 'jpeg', 'webp', 'svg'].includes(ext)) {
      setFormError('Erlaubt sind PNG, JPG, WebP und SVG.');
      return;
    }
    if (file.size > LOGO_MAX_BYTES) {
      setFormError('Das Logo darf höchstens 2 MB groß sein.');
      return;
    }
    await run(() => api.uploadCompanyLogo(id, file), 'Logo gespeichert');
  }

  const saveOrder = useSaveQueue();
  function reorder(next: Company[]) {
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderCompanies(next.map((c) => c.id)),
      setData,
      (e) => {
        setFormError(e.message);
        reload();
      },
    );
  }

  const sort = useDragSort(data ?? [], reorder, editing !== null || assigning !== null);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorBox message={error ?? `Die ${sec.companies} konnten nicht geladen werden.`} onRetry={reload} />;
  const list = data;

  return (
    <div>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>Noch keine {sec.companies}.</Empty>
      ) : (
        <ul className="card-list company-admin-list">
          {list.map((c, i) =>
            editing === c.id ? (
              <li key={c.id} {...sort.itemProps(i, 'card')}>
                <form
                  className="form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (await run(() => api.updateCompany(c.id, inputOf(editDraft)), 'Gespeichert')) setEditing(null);
                  }}
                >
                  <CompanyFields draft={editDraft} onChange={setEditDraft} autoFocus idPrefix={`company-${c.id}`} />
                  <div className="button-row">
                    <button className="btn" type="submit" disabled={busy}>
                      Speichern
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      Abbrechen
                    </button>
                  </div>
                </form>
              </li>
            ) : assigning === c.id ? (
              <li key={c.id} {...sort.itemProps(i, 'card')}>
                <CompanyMembers
                  company={c}
                  onCancel={() => setAssigning(null)}
                  onSaved={() => {
                    setAssigning(null);
                    onToast('Mitglieder gespeichert');
                    reload();
                  }}
                />
              </li>
            ) : (
              <li key={c.id} {...sort.itemProps(i, `card row${c.active ? '' : ' inactive'}`)}>
                {list.length > 1 && sort.handle(i, c.name)}
                <div className="company-admin-logo">
                  <CompanyLogo company={c} />
                </div>
                <div className="row-main">
                  <div className="row-title">
                    {c.name}
                    {!c.active && <span className="badge badge-level-guest">ehemalig</span>}
                  </div>
                  <div className="row-sub">
                    {[c.short_name, c.founded_year ? `gegründet ${c.founded_year}` : '', `${(c.member_ids ?? []).length} Mitglieder`]
                      .filter(Boolean)
                      .join(' · ')}
                  </div>
                  <div className="row-actions">
                    <IconButton
                      icon="edit"
                      label="Bearbeiten"
                      disabled={busy}
                      onClick={() => {
                        setEditDraft(draftOf(c));
                        setEditing(c.id);
                      }}
                    />
                    <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => setAssigning(c.id)}>
                      Mitglieder
                    </button>
                    <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => chooseLogo(c.id)}>
                      {c.logo_url ? 'Logo ersetzen' : 'Logo hochladen'}
                    </button>
                    {c.logo_url && (
                      <button
                        className="btn btn-small btn-secondary"
                        disabled={busy}
                        onClick={async () =>
                          (await confirmAsk(`Logo von „${c.name}“ entfernen?`)) && run(() => api.removeCompanyLogo(c.id), 'Logo entfernt')
                        }
                      >
                        Logo entfernen
                      </button>
                    )}
                    <IconButton
                      icon="delete"
                      label="Löschen"
                      variant="danger"
                      disabled={busy}
                      onClick={async () =>
                        (await confirmAsk(
                          `„${c.name}“ löschen? Die Zuordnungen der Mitglieder gehen verloren. Aufgelöste besser als „aufgelöst“ markieren.`,
                        )) && run(() => api.deleteCompany(c.id), 'Gelöscht')
                      }
                    />
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      <input
        ref={logoInput}
        type="file"
        accept={LOGO_ACCEPT}
        hidden
        aria-label="Logo-Datei"
        onChange={(e) => uploadLogo(e.target.files?.[0] ?? null)}
      />

      <form className="form card" onSubmit={add}>
        <h3>Neue Kompanie</h3>
        <CompanyFields key={newKey} draft={draft} onChange={setDraft} idPrefix="company-new" />
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}

/** Verwaltung → Mitglied: Kompanien der Person ankreuzen (neue nur für Mitglieder, aufgelöste nur zum Abwählen). */
export function MemberCompanies({
  id,
  member,
  companies,
  onChanged,
  onToast,
}: {
  id: number;
  member: boolean;
  companies: CompanyRef[];
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const all = useAsync(sources.companies.load, sources.companies.deps, sources.companies.key);
  const current = companies.map((c) => c.id);
  const [selected, setSelected] = useState<number[]>(current);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const options = (all.data ?? []).filter((c) => current.includes(c.id) || member);
  const changed = selected.length !== current.length || selected.some((s) => !current.includes(s));

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      await api.setMemberCompanies(id, selected);
      onToast(`${sec.companies} gespeichert`);
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="member-companies">
      {all.error ? (
        <ErrorBox message={all.error} onRetry={all.reload} />
      ) : !all.data ? (
        <Loading shape="text" />
      ) : options.length === 0 ? (
        <p className="muted small">
          {member ? `Noch keine ${sec.companies} angelegt (Verwaltung → ${sec.companies}).` : `Nur Mitglieder bekommen ${sec.companies}.`}
        </p>
      ) : (
        <form className="form" onSubmit={save}>
          {options.map((c) => (
            <label key={c.id} className="toggle">
              <input
                type="checkbox"
                checked={selected.includes(c.id)}
                onChange={(e) => setSelected(e.target.checked ? [...selected, c.id] : selected.filter((s) => s !== c.id))}
              />
              <span>
                {c.name}
                {!c.active && <span className="muted small"> (ehemalig)</span>}
              </span>
            </label>
          ))}
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <button className="btn btn-block" type="submit" disabled={busy || !changed}>
            {sec.companies} speichern
          </button>
        </form>
      )}
    </section>
  );
}
