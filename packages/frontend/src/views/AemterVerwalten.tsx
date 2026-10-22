import { useState, type FormEvent } from 'react';
import {
  api,
  can,
  config,
  type Office,
  type OfficeGroup,
  type OfficeImportResult,
  type OfficesOverview,
  type OfficeTerm,
  type OfficeTermInput,
  type OfficeTermKind,
} from '../api';
import { memberListAllowed } from '../adminNav';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { termYears } from './Aemter';

const sec = brand.labels.sections;

type Toast = (msg: string) => void;

/** Aktion mit Sperre und Fehlermeldung im Formular; true, wenn sie geklappt hat. */
function useRunner(onToast: Toast, after: () => void) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function run(action: () => Promise<unknown>, msg: string): Promise<boolean> {
    setBusy(true);
    setError(null);
    try {
      await action();
      onToast(msg);
      after();
      return true;
    } catch (e) {
      setError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }
  return { busy, error, setError, run };
}

function FormError({ error }: { error: string | null }) {
  return error ? (
    <p className="form-error" role="alert">
      {error}
    </p>
  ) : null;
}

/**
 * Verwaltung → Ämter (Issue #112, manage_offices): Gruppen und Ämter anlegen, sortieren, umbenennen; je Amt die
 * Amtszeiten und die Verknüpfung mit einem Titel; Import einer Tabelle für die Ersterfassung.
 * verwaltung/aemter, verwaltung/aemter/<id>, verwaltung/aemter/import
 */
export function OfficeAdmin({ path, onToast }: { path: string[]; onToast: Toast }) {
  const { data, error, loading, reload, setData } = useAsync(api.offices, [], 'offices');
  if (path[0] === 'import') return <OfficeImport onToast={onToast} />;
  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const overview = data!;
  if (/^\d+$/.test(path[0] ?? '')) {
    const office = overview.offices.find((o) => o.id === Number(path[0]));
    if (!office) return <Empty>Dieses Amt gibt es nicht mehr.</Empty>;
    return <OfficeEditor key={office.id} office={office} groups={overview.groups} onToast={onToast} reload={reload} />;
  }
  return <OfficeOverview overview={overview} onToast={onToast} reload={reload} setData={setData} />;
}

function OfficeOverview({
  overview,
  onToast,
  reload,
  setData,
}: {
  overview: OfficesOverview;
  onToast: Toast;
  reload: () => void;
  setData: (d: OfficesOverview) => void;
}) {
  const { busy, error, setError, run } = useRunner(onToast, reload);
  const [title, setTitle] = useState('');
  const [group, setGroup] = useState<string>(overview.groups[0] ? String(overview.groups[0].id) : '');
  const saveOrder = useSaveQueue();

  function reorderOffices(groupId: number | null, next: Office[]) {
    // Neue Reihenfolge innerhalb der Gruppe, die übrigen bleiben, wo sie sind
    const queue = [...next];
    const all = overview.offices.map((o) => (o.group_id === groupId ? queue.shift()! : o));
    setData({ ...overview, offices: all });
    setError(null);
    saveOrder(
      () => api.reorderOffices(all.map((o) => o.id)),
      () => reload(),
      (e) => {
        setError(e.message);
        reload();
      },
    );
  }

  const sections = [
    ...overview.groups.map((g) => ({ key: `g${g.id}`, group: g.id as number | null, title: g.title })),
    { key: 'none', group: null, title: 'Ohne Gruppe' },
  ];

  return (
    <div>
      <p className="muted small">
        Wer welches Amt wann hatte, auch frühere Inhaber ohne Konto. Der Zeitstrahl ist für alle sichtbar, auch ohne Anmeldung. Ein Amt kann
        mit einem {sec.titles} verknüpft werden: Wer es gerade hat, bekommt ihn automatisch.
      </p>
      <div className="button-row">
        <button className="btn btn-secondary" onClick={() => navigate('verwaltung/aemter/import')}>
          Tabelle importieren
        </button>
        <button className="btn btn-secondary" onClick={() => navigate('mitglieder/aemter')}>
          Zeitstrahl ansehen
        </button>
      </div>
      <FormError error={error} />

      <GroupList groups={overview.groups} onToast={onToast} reload={reload} />

      {sections.map((s) => {
        const offices = overview.offices.filter((o) => o.group_id === s.group);
        if (s.group === null && offices.length === 0) return null;
        return (
          <section key={s.key} className="office-admin-group">
            <h3 className="section-title">{s.title}</h3>
            {offices.length === 0 ? (
              <p className="muted small">Noch keine Ämter in dieser Gruppe.</p>
            ) : (
              <OfficeSortList offices={offices} onMove={(next) => reorderOffices(s.group, next)} />
            )}
          </section>
        );
      })}

      <form
        className="form card"
        onSubmit={async (e: FormEvent) => {
          e.preventDefault();
          if (await run(() => api.createOffice({ title: title.trim(), group_id: group === '' ? null : Number(group) }), 'Amt angelegt'))
            setTitle('');
        }}
      >
        <h3>Neues Amt</h3>
        <label className="field">
          <span>Bezeichnung</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>Gruppe</span>
          <select value={group} onChange={(e) => setGroup(e.target.value)}>
            {overview.groups.map((g) => (
              <option key={g.id} value={g.id}>
                {g.title}
              </option>
            ))}
            <option value="">Ohne Gruppe</option>
          </select>
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}

function OfficeSortList({ offices, onMove }: { offices: Office[]; onMove: (next: Office[]) => void }) {
  const sort = useDragSort(offices, onMove);
  return (
    <ul className="card-list">
      {offices.map((o, i) => (
        <li key={o.id} {...sort.itemProps(i, 'card row')}>
          {offices.length > 1 && sort.handle(i, o.title)}
          <div className="row-main">
            <button type="button" className="btn btn-link row-title" onClick={() => navigate(`verwaltung/aemter/${o.id}`)}>
              {o.title}
            </button>
            <div className="row-sub">
              {o.terms.length === 1 ? '1 Amtszeit' : `${o.terms.length} Amtszeiten`}
              {o.linked_title ? ` · vergibt „${o.linked_title}“` : ''}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

function GroupList({ groups, onToast, reload }: { groups: OfficeGroup[]; onToast: Toast; reload: () => void }) {
  const { busy, error, setError, run } = useRunner(onToast, reload);
  const [title, setTitle] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const [list, setList] = useState(groups);
  const [seen, setSeen] = useState(groups);
  if (seen !== groups) {
    setSeen(groups);
    setList(groups);
  }
  const saveOrder = useSaveQueue();
  const sort = useDragSort(
    list,
    (next) => {
      setList(next);
      setError(null);
      saveOrder(
        () => api.reorderOfficeGroups(next.map((g) => g.id)),
        () => reload(),
        (e) => {
          setError(e.message);
          reload();
        },
      );
    },
    editing !== null,
  );

  return (
    <section>
      <h3 className="section-title">Gruppen</h3>
      <p className="muted small">
        Die Gruppen ordnen die Ämter im Zeitstrahl, z. B. Verein und Dachverband. Zum Ändern der Reihenfolge am Griff ziehen.
      </p>
      <FormError error={error} />
      {list.length > 0 && (
        <ul className="card-list">
          {list.map((g, i) =>
            editing === g.id ? (
              <li key={g.id} {...sort.itemProps(i, 'card')}>
                <form
                  className="form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (await run(() => api.updateOfficeGroup(g.id, editTitle.trim()), 'Gespeichert')) setEditing(null);
                  }}
                >
                  <label className="field">
                    <span>Bezeichnung</span>
                    <input type="text" maxLength={60} value={editTitle} onChange={(e) => setEditTitle(e.target.value)} required autoFocus />
                  </label>
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
            ) : (
              <li key={g.id} {...sort.itemProps(i, 'card row')}>
                {list.length > 1 && sort.handle(i, g.title)}
                <div className="row-main">
                  <div className="row-title">{g.title}</div>
                  <div className="row-actions">
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy}
                      onClick={() => {
                        setEditTitle(g.title);
                        setEditing(g.id);
                      }}
                    >
                      Umbenennen
                    </button>
                    <button
                      className="btn btn-small btn-danger"
                      disabled={busy}
                      onClick={() => window.confirm(`Gruppe „${g.title}“ löschen?`) && run(() => api.deleteOfficeGroup(g.id), 'Gelöscht')}
                    >
                      Löschen
                    </button>
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}
      <form
        className="form"
        onSubmit={async (e) => {
          e.preventDefault();
          if (await run(() => api.createOfficeGroup(title.trim()), 'Gruppe angelegt')) setTitle('');
        }}
      >
        <label className="field">
          <span>Neue Gruppe</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <button className="btn" type="submit" disabled={busy}>
          Gruppe anlegen
        </button>
      </form>
    </section>
  );
}

type Person = { id: number; name: string };
type People = { members: Person[]; former: Person[] };

/**
 * Konten zur Auswahl als Inhaber, getrennt nach Mitgliedern und Ehemaligen: wer die jeweilige Liste der Verwaltung sieht,
 * bekommt sie von dort; sonst die öffentliche Mitgliederliste bzw. den Tab „Ehemalige“.
 */
function usePeople() {
  const source = memberListAllowed() ? 'members' : config?.features.directory ? 'directory' : null;
  const formerSource = can('create_users') ? 'members' : config?.features.directory ? 'directory' : null;
  return useAsync(
    async (): Promise<People> => {
      const [members, former] = await Promise.all([
        source === 'members' ? api.members(false, true) : source === 'directory' ? api.directory() : Promise.resolve([]),
        // Ohne die Ehemaligen (z. B. ältere Server) bleiben Mitglieder und freie Namen wählbar.
        (formerSource === 'members'
          ? api.formerMembers()
          : formerSource === 'directory'
            ? api.directoryFormer()
            : Promise.resolve([])
        ).catch(() => []),
      ]);
      const pick = (list: Person[]) => list.map((p) => ({ id: p.id, name: p.name }));
      return { members: pick(members), former: pick(former) };
    },
    [source, formerSource],
    'office-people',
  );
}

function OfficeEditor({ office, groups, onToast, reload }: { office: Office; groups: OfficeGroup[]; onToast: Toast; reload: () => void }) {
  const { busy, error, run } = useRunner(onToast, reload);
  const [title, setTitle] = useState(office.title);
  const [group, setGroup] = useState(office.group_id === null ? '' : String(office.group_id));
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const people = usePeople();

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/aemter')}>
        ‹ Alle {sec.offices}
      </button>
      <h2 className="member-name">{office.title}</h2>
      <FormError error={error} />

      <h3 className="section-title">Amtszeiten</h3>
      {office.terms.length === 0 && editing !== 'new' && <p className="muted small">Noch keine Amtszeiten.</p>}
      <div className="office-admin-terms">
        {office.terms.map((t) =>
          editing === t.id ? (
            <TermForm
              key={t.id}
              initial={t}
              people={people.data ?? NO_PEOPLE}
              onCancel={() => setEditing(null)}
              onSave={async (input) => {
                if (await run(() => api.updateOfficeTerm(t.id, input), 'Amtszeit gespeichert')) setEditing(null);
              }}
              busy={busy}
            />
          ) : (
            <div key={t.id} className="office-term-row">
              <strong>{termYears(t)}</strong>
              <span>{t.holders.map((h) => h.name).join(', ')}</span>
              {t.note && <span className="muted small">{t.note}</span>}
              {t.current && <span className="badge timeline-now">aktuell</span>}
              <span className="row-actions">
                <button className="btn btn-small btn-secondary" disabled={busy || editing !== null} onClick={() => setEditing(t.id)}>
                  Bearbeiten
                </button>
                <button
                  className="btn btn-small btn-danger"
                  disabled={busy || editing !== null}
                  onClick={() => window.confirm(`Amtszeit ${termYears(t)} löschen?`) && run(() => api.deleteOfficeTerm(t.id), 'Gelöscht')}
                >
                  Löschen
                </button>
              </span>
            </div>
          ),
        )}
      </div>
      {editing === 'new' ? (
        <TermForm
          people={people.data ?? NO_PEOPLE}
          onCancel={() => setEditing(null)}
          onSave={async (input) => {
            if (await run(() => api.createOfficeTerm(office.id, input), 'Amtszeit eingetragen')) setEditing(null);
          }}
          busy={busy}
        />
      ) : (
        <button className="btn btn-block" disabled={editing !== null} onClick={() => setEditing('new')}>
          Amtszeit eintragen
        </button>
      )}

      <TitleLink office={office} onToast={onToast} reload={reload} />

      <form
        className="form card"
        onSubmit={async (e) => {
          e.preventDefault();
          await run(
            () => api.updateOffice(office.id, { title: title.trim(), group_id: group === '' ? null : Number(group) }),
            'Gespeichert',
          );
        }}
      >
        <h3>Amt bearbeiten</h3>
        <label className="field">
          <span>Bezeichnung</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>Gruppe</span>
          <select value={group} onChange={(e) => setGroup(e.target.value)}>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {g.title}
              </option>
            ))}
            <option value="">Ohne Gruppe</option>
          </select>
        </label>
        <div className="button-row">
          <button className="btn" type="submit" disabled={busy}>
            Speichern
          </button>
          <button
            type="button"
            className="btn btn-danger"
            disabled={busy}
            onClick={async () => {
              const n = office.terms.length;
              const what = n === 0 ? '' : n === 1 ? ' mit seiner Amtszeit' : ` mit allen ${n} Amtszeiten`;
              if (window.confirm(`„${office.title}“${what} löschen?`) && (await run(() => api.deleteOffice(office.id), 'Amt gelöscht')))
                navigate('verwaltung/aemter');
            }}
          >
            Amt löschen
          </button>
        </div>
      </form>
    </div>
  );
}

/**
 * Verknüpfung mit einem Titel: Vor dem Speichern zeigt die App, wer ihn bekommt und wer ihn verliert. Titel listet nur,
 * wer sie verwalten darf.
 */
function TitleLink({ office, onToast, reload }: { office: Office; onToast: Toast; reload: () => void }) {
  const allowed = !!config?.features.titles && can('manage_titles');
  const titles = useAsync(() => (allowed ? api.titles() : Promise.resolve([])), [allowed], allowed ? 'titles' : undefined);
  const { busy, error, setError, run } = useRunner(onToast, reload);
  const [choice, setChoice] = useState(office.title_id == null ? '' : String(office.title_id));
  const [preview, setPreview] = useState<{ add: { name: string }[]; remove: { name: string }[] } | null>(null);
  if (!config?.features.titles) return null;
  const current = office.title_id == null ? '' : String(office.title_id);

  async function choose(value: string) {
    setChoice(value);
    setPreview(null);
    setError(null);
    if (value === current || value === '') return;
    try {
      setPreview(await api.officeTitlePreview(office.id, Number(value)));
    } catch (e) {
      setError((e as Error).message);
    }
  }

  const names = (list: { name: string }[]) => (list.length === 0 ? 'niemand' : list.map((p) => p.name).join(', '));
  return (
    <section className="card form">
      <h3>{sec.titles} vergeben</h3>
      <p className="muted small">
        Wer eine laufende Amtszeit hat (mit Konto, als Mitglied), bekommt den {sec.titles} automatisch; wer keine mehr hat, verliert ihn.
        Von Hand lässt er sich dann nicht mehr vergeben. Lösen ändert an den vergebenen nichts.
      </p>
      {!allowed ? (
        <p className="muted small">
          {office.linked_title ? `Verknüpft mit „${office.linked_title}“. ` : ''}Ändern kann das, wer {sec.titles} verwalten darf.
        </p>
      ) : titles.error ? (
        <ErrorBox message={titles.error} onRetry={titles.reload} />
      ) : (
        <>
          <label className="field">
            <span>Verknüpfter {sec.titles}</span>
            <select value={choice} onChange={(e) => choose(e.target.value)} disabled={busy}>
              <option value="">Keiner</option>
              {(titles.data ?? [])
                .filter((t) => !t.office || t.office.id === office.id || String(t.id) === choice)
                .map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.title}
                    {!t.active ? ' (deaktiviert)' : ''}
                  </option>
                ))}
            </select>
          </label>
          {preview && (
            <p className="small" role="status">
              Bekommen ihn: {names(preview.add)}. Verlieren ihn: {names(preview.remove)}.
            </p>
          )}
          <FormError error={error} />
          {choice !== current && (
            <button
              className="btn btn-block"
              disabled={busy}
              onClick={() =>
                run(() => api.updateOffice(office.id, { title_id: choice === '' ? null : Number(choice) }), 'Verknüpfung gespeichert').then(
                  (ok) => ok && setPreview(null),
                )
              }
            >
              {choice === '' ? 'Verknüpfung lösen' : 'Verknüpfen'}
            </button>
          )}
        </>
      )}
    </section>
  );
}

const NO_PEOPLE: People = { members: [], former: [] };

/** Person in der Amtszeit: ohne Profil (freier Name), Mitglied oder Ehemaliger (Konto). */
type HolderKind = 'name' | 'member' | 'former';
/** kind fehlt bei vorhandenen Einträgen, bis man sie ändert: ergibt sich dann aus den Listen (siehe kindOf). */
type HolderRow = { kind?: HolderKind; user: string; name: string };

/** Amtszeit eintragen oder bearbeiten: Art, Jahre, Zusatz und eine oder mehrere Personen (Konto oder freier Name). */
function TermForm({
  initial,
  people,
  onSave,
  onCancel,
  busy,
}: {
  initial?: OfficeTerm;
  people: People;
  onSave: (input: OfficeTermInput) => void;
  onCancel: () => void;
  busy: boolean;
}) {
  const [kind, setKind] = useState<OfficeTermKind>(initial?.kind ?? 'period');
  const [start, setStart] = useState(initial ? String(initial.start_year) : '');
  const [end, setEnd] = useState(initial?.end_year != null ? String(initial.end_year) : '');
  const [note, setNote] = useState(initial?.note ?? '');
  const [holders, setHolders] = useState<HolderRow[]>(
    initial?.holders.map((h) => ({ user: h.user_id ? String(h.user_id) : '', name: h.name })) ?? [{ user: '', name: '' }],
  );
  const formerIds = new Set(people.former.map((p) => p.id));
  const kindOf = (h: HolderRow): HolderKind => h.kind ?? (!h.user ? 'name' : formerIds.has(Number(h.user)) ? 'former' : 'member');
  // Konten, die schon in der Amtszeit stehen, aber in keiner Liste (z. B. ohne Recht darauf), bleiben bei den Mitgliedern wählbar.
  const members = [...people.members];
  for (const h of initial?.holders ?? []) {
    if (h.user_id && !formerIds.has(h.user_id) && !members.some((p) => p.id === h.user_id)) members.push({ id: h.user_id, name: h.name });
  }
  const kinds: { value: HolderKind; label: string }[] = [
    { value: 'name', label: 'Ohne Profil' },
    ...(members.length > 0 ? [{ value: 'member' as const, label: 'Mitglied' }] : []),
    ...(people.former.length > 0 ? [{ value: 'former' as const, label: 'Ehemaliger' }] : []),
  ];
  const set = (i: number, row: Partial<HolderRow>) => setHolders(holders.map((h, j) => (j === i ? { ...h, ...row } : h)));

  return (
    <form
      className="form card"
      onSubmit={(e) => {
        e.preventDefault();
        onSave({
          kind,
          start_year: Number(start),
          end_year: kind === 'period' && end.trim() !== '' ? Number(end) : null,
          note: note.trim(),
          holders: holders.map((h) => (kindOf(h) !== 'name' && h.user ? { user_id: Number(h.user) } : { name: h.name.trim() })),
        });
      }}
    >
      <h3>{initial ? 'Amtszeit bearbeiten' : 'Neue Amtszeit'}</h3>
      <label className="field">
        <span>Art</span>
        <select value={kind} onChange={(e) => setKind(e.target.value as OfficeTermKind)}>
          <option value="period">Zeitraum (von–bis oder bis heute)</option>
          <option value="point">Einzelnes Jahr (z. B. eine Ehrung)</option>
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>{kind === 'point' ? 'Jahr' : 'Von (Jahr)'}</span>
          <input
            type="number"
            inputMode="numeric"
            min={1800}
            max={2200}
            value={start}
            onChange={(e) => setStart(e.target.value)}
            required
          />
        </label>
        {kind === 'period' && (
          <label className="field">
            <span>Bis (leer: bis heute)</span>
            <input type="number" inputMode="numeric" min={1800} max={2200} value={end} onChange={(e) => setEnd(e.target.value)} />
          </label>
        )}
      </div>
      <fieldset className="extra-roles">
        <legend>Personen</legend>
        {holders.map((h, i) => {
          const kind = kindOf(h);
          const list = kind === 'former' ? people.former : members;
          return (
            <div key={i} className="office-holder-row">
              {kinds.length > 1 && (
                <label className="field">
                  <span>Profil</span>
                  <select value={kind} onChange={(e) => set(i, { kind: e.target.value as HolderKind, user: '' })}>
                    {kinds.map((k) => (
                      <option key={k.value} value={k.value}>
                        {k.label}
                      </option>
                    ))}
                  </select>
                </label>
              )}
              {kind === 'name' ? (
                <label className="field">
                  <span>Name</span>
                  <input type="text" maxLength={100} value={h.name} onChange={(e) => set(i, { name: e.target.value })} required />
                </label>
              ) : (
                <label className="field">
                  <span>{kind === 'former' ? 'Ehemaliger' : 'Mitglied'}</span>
                  <select value={h.user} onChange={(e) => set(i, { user: e.target.value })} required>
                    <option value="">Bitte wählen</option>
                    {list.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name}
                      </option>
                    ))}
                  </select>
                </label>
              )}
              {holders.length > 1 && (
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  aria-label="Person entfernen"
                  onClick={() => setHolders(holders.filter((_, j) => j !== i))}
                >
                  ✕
                </button>
              )}
            </div>
          );
        })}
        {holders.length < 20 && (
          <button type="button" className="btn btn-small btn-secondary" onClick={() => setHolders([...holders, { user: '', name: '' }])}>
            Weitere Person
          </button>
        )}
      </fieldset>
      <label className="field">
        <span>Zusatz (optional, z. B. „Unter …“ oder „Ehrenvorsitz“)</span>
        <input type="text" maxLength={100} value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
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

const IMPORT_EXAMPLE =
  'gruppe;amt;beginn;ende;art;personen;zusatz\nVerein;Vorsitz;2020;heute;;Erika Muster;\nVerein;Ehrenmitglieder;2008;;;Max Beispiel;';

/**
 * Tabelle importieren (Ersterfassung): einfügen oder Datei wählen, Vorschau mit Fehlern je Zeile, dann übernehmen.
 * Vorhandene Gruppen und Ämter werden nach Namen gefunden; was es schon gibt, wird übersprungen.
 */
function OfficeImport({ onToast }: { onToast: Toast }) {
  const [csv, setCsv] = useState('');
  const [link, setLink] = useState(true);
  const [result, setResult] = useState<OfficeImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(apply: boolean) {
    setBusy(true);
    setError(null);
    try {
      const res = await api.importOffices(csv, apply, link);
      if (apply) {
        onToast(`${res.terms_new === 1 ? '1 Amtszeit' : `${res.terms_new} Amtszeiten`} übernommen`);
        navigate('verwaltung/aemter');
      } else {
        setResult(res);
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const errors = result?.rows.filter((r) => r.error).length ?? 0;
  return (
    <div className="office-import">
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/aemter')}>
        ‹ Alle {sec.offices}
      </button>
      <h2 className="member-name">Tabelle importieren</h2>
      <p className="muted small">
        Eine Zeile je Amtszeit, Spalten getrennt durch Semikolon (oder Tabulator, z. B. aus einer Tabellenkalkulation kopiert):{' '}
        <code>gruppe;amt;beginn;ende;art;personen;zusatz</code>. Ende „heute“ heißt: läuft noch; Ende leer: ein einzelnes Jahr. Art ist
        meist leer („zeitraum“ oder „zeitpunkt“ erzwingt sie). Mehrere Personen mit „&amp;“ trennen.
      </p>
      <label className="field">
        <span>Tabelle</span>
        <textarea
          value={csv}
          placeholder={IMPORT_EXAMPLE}
          onChange={(e) => {
            setCsv(e.target.value);
            setResult(null);
          }}
        />
      </label>
      <label className="field">
        <span>Oder Datei wählen (CSV)</span>
        <input
          type="file"
          accept=".csv,.txt,text/csv,text/plain"
          onChange={async (e) => {
            const file = e.target.files?.[0];
            if (file) {
              setCsv(await file.text());
              setResult(null);
            }
          }}
        />
      </label>
      <label className="toggle">
        <input type="checkbox" checked={link} onChange={(e) => setLink(e.target.checked)} />
        <span>Personen mit dem Konto verknüpfen, wenn der Name genau zu einem Konto passt</span>
      </label>
      <FormError error={error} />
      <div className="button-row">
        <button className="btn btn-secondary" disabled={busy || csv.trim() === ''} onClick={() => send(false)}>
          Vorschau
        </button>
        {result && (
          <button className="btn" disabled={busy || errors > 0 || result.terms_new === 0} onClick={() => send(true)}>
            Übernehmen
          </button>
        )}
      </div>
      {result && (
        <section>
          <p role="status">
            Neu: {result.groups_new} Gruppen, {result.offices_new} Ämter, {result.terms_new} Amtszeiten.
            {errors > 0 && ` ${errors === 1 ? '1 Zeile hat' : `${errors} Zeilen haben`} einen Fehler; bitte erst korrigieren.`}
          </p>
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.duplicate ? 'muted' : ''}>
                <strong>Zeile {r.line}</strong>
                {r.office && (
                  <>
                    {' '}
                    {r.group ? `${r.group} / ` : ''}
                    {r.office}: {r.kind && termYears({ kind: r.kind, start_year: r.start_year!, end_year: r.end_year ?? null })} ·{' '}
                    {(r.holders ?? []).map((h) => h.name + (h.user_id ? ' (Konto)' : '')).join(', ')}
                    {r.note ? ` · ${r.note}` : ''}
                    {r.duplicate ? ' · gibt es schon' : ''}
                  </>
                )}
                {r.error && <p className="form-error">{r.error}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
