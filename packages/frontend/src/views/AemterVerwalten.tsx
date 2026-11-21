import { createContext, useContext, useMemo, useState, type FormEvent } from 'react';
import {
  api,
  can,
  config,
  type Office,
  type OfficeGroup,
  type OfficeGroupTerm,
  type OfficeGroupTermInput,
  type OfficeHolder,
  type OfficeImportResult,
  type OfficeLinkSuggestion,
  type OfficePersonInput,
  type OfficesOverview,
  type OfficeTerm,
  type OfficeTermInput,
  type OfficeTermKind,
} from '../api';
import { memberListAllowed } from '../adminNav';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { MemberAvatar } from '../memberPhoto';
import { navigate } from '../router';
import { Caps, Empty, ErrorBox, FileInput, Loading, useAsync } from '../ui';
import { nameWithCompanions, termYears } from './Aemter';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { t, tn } from '../i18n';

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
 * Freie Namen lassen sich auf Vorschlag mit Mitgliedern oder Ehemaligen verknüpfen.
 * verwaltung/aemter, verwaltung/aemter/<id>, verwaltung/aemter/import, verwaltung/aemter/verknuepfen
 */
export function OfficeAdmin({ path, onToast }: { path: string[]; onToast: Toast }) {
  const { data, error, loading, reload, setData } = useAsync(api.offices, [], 'offices');
  if (path[0] === 'import') return <OfficeImport onToast={onToast} />;
  if (path[0] === 'verknuepfen') return <OfficeLink onToast={onToast} />;
  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const overview = data!;
  return (
    <KnownPhotos.Provider value={knownPhotos(overview.offices)}>
      {/^\d+$/.test(path[0] ?? '') ? (
        <OfficeEditorFor overview={overview} id={Number(path[0])} onToast={onToast} reload={reload} />
      ) : (
        <OfficeOverview overview={overview} onToast={onToast} reload={reload} setData={setData} />
      )}
    </KnownPhotos.Provider>
  );
}

function OfficeEditorFor({ overview, id, onToast, reload }: { overview: OfficesOverview; id: number; onToast: Toast; reload: () => void }) {
  const office = overview.offices.find((o) => o.id === id);
  if (!office) return <Empty>{t('Dieses Amt gibt es nicht mehr.')}</Empty>;
  return <OfficeEditor key={office.id} office={office} groups={overview.groups} onToast={onToast} reload={reload} />;
}

type HolderPhoto = { uid: string; url: string };

/** Fotos freier Namen aus allen Amtszeiten (Issue #283): Name (klein) => zuletzt eingetragenes Foto, zur Übernahme. */
const KnownPhotos = createContext<Map<string, HolderPhoto>>(new Map());

function knownPhotos(offices: Office[]): Map<string, HolderPhoto> {
  const out = new Map<string, HolderPhoto>();
  for (const o of offices) {
    for (const t of o.terms) {
      for (const h of t.holders) {
        const key = h.name.trim().toLowerCase();
        // Amtszeiten kommen neueste zuerst: die erste Fundstelle gewinnt
        if (!h.user_id && h.image_uid && h.photo_url && !out.has(key)) out.set(key, { uid: h.image_uid, url: h.photo_url });
      }
    }
  }
  return out;
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
    ...overview.groups.map((g) => ({
      key: `g${g.id}`,
      group: g.id as number | null,
      title: g.title,
      singular: g.singular || g.title,
      joint: !!g.joint_terms,
    })),
    { key: 'none', group: null, title: t('Ohne Gruppe'), singular: '', joint: false },
  ];

  return (
    <div>
      <div className="button-row">
        <button className="btn btn-secondary" onClick={() => navigate('verwaltung/aemter/import')}>
          {t('Tabelle importieren')}
        </button>
        <button className="btn btn-secondary" onClick={() => navigate('mitglieder/aemter')}>
          {t('Zeitstrahl ansehen')}
        </button>
        <button className="btn btn-secondary" onClick={() => navigate('verwaltung/aemter/verknuepfen')}>
          {t('Namen mit Mitgliedern verknüpfen')}
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
              <p className="muted small">{t('Noch keine Ämter in dieser Gruppe.')}</p>
            ) : (
              <OfficeSortList offices={offices} onMove={(next) => reorderOffices(s.group, next)} />
            )}
            {s.joint && offices.length > 0 && (
              <JointTermAdmin
                groupId={s.group!}
                groupTitle={s.singular}
                offices={offices}
                terms={(overview.group_terms ?? []).filter((t) => t.group_id === s.group)}
                onToast={onToast}
                reload={reload}
              />
            )}
          </section>
        );
      })}

      <form
        className="form card"
        onSubmit={async (e: FormEvent) => {
          e.preventDefault();
          if (await run(() => api.createOffice({ title: title.trim(), group_id: group === '' ? null : Number(group) }), t('Amt angelegt')))
            setTitle('');
        }}
      >
        <h3>{t('Neues Amt')}</h3>
        <label className="field">
          <span>{t('Bezeichnung')}</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>{t('Gruppe')}</span>
          <select value={group} onChange={(e) => setGroup(e.target.value)}>
            {overview.groups.map((g) => (
              <option key={g.id} value={g.id}>
                {g.title}
              </option>
            ))}
            <option value="">{t('Ohne Gruppe')}</option>
          </select>
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          {t('Anlegen')}
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
              {tn('{count} Amtszeit', '{count} Amtszeiten', o.terms.length)}
              {o.linked_title ? ' · ' + t('vergibt „{title}“', { title: o.linked_title }) : ''}
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
  const [editSingular, setEditSingular] = useState('');
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
    <section className="stack">
      <h3 className="section-title">{t('Gruppen')}</h3>
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
                    if (await run(() => api.updateOfficeGroup(g.id, editTitle.trim(), editSingular.trim()), t('Gespeichert')))
                      setEditing(null);
                  }}
                >
                  <label className="field">
                    <span>{t('Bezeichnung')}</span>
                    <input type="text" maxLength={60} value={editTitle} onChange={(e) => setEditTitle(e.target.value)} required autoFocus />
                  </label>
                  <label className="field">
                    <span>{t('Einzahl (optional, z. B. „Königshaus“ zu „Königshäuser“)')}</span>
                    <input
                      type="text"
                      maxLength={60}
                      value={editSingular}
                      placeholder={editTitle}
                      onChange={(e) => setEditSingular(e.target.value)}
                    />
                  </label>
                  <div className="button-row">
                    <button className="btn" type="submit" disabled={busy}>
                      {t('Speichern')}
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      {t('Abbrechen')}
                    </button>
                  </div>
                </form>
              </li>
            ) : (
              <li key={g.id} {...sort.itemProps(i, 'card row')}>
                {list.length > 1 && sort.handle(i, g.title)}
                <div className="row-main">
                  <div className="row-title">
                    {g.title}
                    {g.singular && <span className="muted small"> · {t('Einzahl: {singular}', { singular: g.singular })}</span>}
                  </div>
                  <div className="row-actions">
                    <IconButton
                      icon="edit"
                      label={t('Bearbeiten')}
                      disabled={busy}
                      onClick={() => {
                        setEditTitle(g.title);
                        setEditSingular(g.singular ?? '');
                        setEditing(g.id);
                      }}
                    />
                    <label className="toggle joint-toggle">
                      <input
                        type="checkbox"
                        checked={!!g.joint_terms}
                        disabled={busy}
                        onChange={(e) =>
                          run(
                            () => api.setOfficeGroupJoint(g.id, e.target.checked),
                            e.target.checked ? t('Gemeinsame Amtszeiten an') : t('Gemeinsame Amtszeiten aus'),
                          )
                        }
                      />
                      <span>{t('Amtszeiten gemeinsam')}</span>
                    </label>
                    <IconButton
                      icon="delete"
                      label={t('Löschen')}
                      variant="danger"
                      disabled={busy}
                      onClick={async () =>
                        (await confirmAsk(t('Gruppe „{name}“ löschen?', { name: g.title }))) &&
                        run(() => api.deleteOfficeGroup(g.id), t('Gelöscht'))
                      }
                    />
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
          if (await run(() => api.createOfficeGroup(title.trim()), t('Gruppe angelegt'))) setTitle('');
        }}
      >
        <label className="field">
          <span>{t('Neue Gruppe')}</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <button className="btn" type="submit" disabled={busy}>
          {t('Gruppe anlegen')}
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
  const formerSource = can('manage_former') ? 'members' : config?.features.directory ? 'directory' : null;
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
  const [singular, setSingular] = useState(office.singular ?? '');
  const [group, setGroup] = useState(office.group_id === null ? '' : String(office.group_id));
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const people = usePeople();

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/aemter')}>
        ‹ {t('Alle {offices}', { offices: sec.offices })}
      </button>
      <h2 className="member-name">{office.title}</h2>
      <FormError error={error} />

      <h3 className="section-title">{t('Amtszeiten')}</h3>
      {office.terms.length === 0 && editing !== 'new' && <p className="muted small">{t('Noch keine Amtszeiten.')}</p>}
      <div className="office-admin-terms">
        {office.terms.map((term) =>
          editing === term.id ? (
            <TermForm
              key={term.id}
              initial={term}
              people={people.data ?? NO_PEOPLE}
              onCancel={() => setEditing(null)}
              onSave={async (input) => {
                if (await run(() => api.updateOfficeTerm(term.id, input), t('Amtszeit gespeichert'))) setEditing(null);
              }}
              busy={busy}
            />
          ) : (
            <div key={term.id} className="office-term-row">
              <strong>{termYears(term)}</strong>
              <span>{term.holders.map(nameWithCompanions).join(', ')}</span>
              {term.note && <span className="muted small">{term.note}</span>}
              {term.honor && <span className="badge timeline-honor">{t('Ehrung')}</span>}
              {term.current && <span className="badge timeline-now">{t('aktuell')}</span>}
              <span className="row-actions">
                <IconButton icon="edit" label={t('Bearbeiten')} disabled={busy || editing !== null} onClick={() => setEditing(term.id)} />
                <IconButton
                  icon="delete"
                  label={t('Löschen')}
                  variant="danger"
                  disabled={busy || editing !== null}
                  onClick={async () =>
                    (await confirmAsk(t('Amtszeit {years} löschen?', { years: termYears(term) }))) &&
                    run(() => api.deleteOfficeTerm(term.id), t('Gelöscht'))
                  }
                />
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
            if (await run(() => api.createOfficeTerm(office.id, input), t('Amtszeit eingetragen'))) setEditing(null);
          }}
          busy={busy}
        />
      ) : (
        <button className="btn btn-block" disabled={editing !== null} onClick={() => setEditing('new')}>
          {t('Amtszeit eintragen')}
        </button>
      )}

      <TitleLink office={office} onToast={onToast} reload={reload} />

      <form
        className="form card"
        onSubmit={async (e) => {
          e.preventDefault();
          await run(
            () =>
              api.updateOffice(office.id, {
                title: title.trim(),
                singular: singular.trim(),
                group_id: group === '' ? null : Number(group),
              }),
            t('Gespeichert'),
          );
        }}
      >
        <h3>{t('Amt bearbeiten')}</h3>
        <label className="field">
          <span>{t('Bezeichnung')}</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>{t('Einzahl (optional, z. B. „Kapitän“ zu „Kapitäne“)')}</span>
          <input
            type="text"
            maxLength={60}
            value={singular}
            placeholder={office.linked_title ?? ''}
            onChange={(e) => setSingular(e.target.value)}
          />
        </label>
        <label className="field">
          <span>{t('Gruppe')}</span>
          <select value={group} onChange={(e) => setGroup(e.target.value)}>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {g.title}
              </option>
            ))}
            <option value="">{t('Ohne Gruppe')}</option>
          </select>
        </label>
        <div className="button-row">
          <button className="btn" type="submit" disabled={busy}>
            {t('Speichern')}
          </button>
          <button
            type="button"
            className="btn btn-danger"
            disabled={busy}
            onClick={async () => {
              const n = office.terms.length;
              const question =
                n === 0
                  ? t('„{name}“ löschen?', { name: office.title })
                  : tn('„{name}“ mit seiner Amtszeit löschen?', '„{name}“ mit allen {count} Amtszeiten löschen?', n, {
                      name: office.title,
                    });
              if ((await confirmAsk(question)) && (await run(() => api.deleteOffice(office.id), t('Amt gelöscht'))))
                navigate('verwaltung/aemter');
            }}
          >
            {t('Amt löschen')}
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

  const names = (list: { name: string }[]) => (list.length === 0 ? t('niemand') : list.map((p) => p.name).join(', '));
  return (
    <section className="card form">
      <h3>{t('{titles} vergeben', { titles: sec.titles })}</h3>
      {!allowed ? (
        <p className="muted small">
          {office.linked_title ? t('Verknüpft mit „{title}“.', { title: office.linked_title }) + ' ' : ''}
          {t('Ändern kann das, wer {titles} verwalten darf.', { titles: sec.titles })}
        </p>
      ) : titles.error ? (
        <ErrorBox message={titles.error} onRetry={titles.reload} />
      ) : (
        <>
          <label className="field">
            <span>
              {t('Verknüpfter')} <Caps text={sec.titles} />
            </span>
            <select value={choice} onChange={(e) => choose(e.target.value)} disabled={busy}>
              <option value="">{t('Keiner')}</option>
              {(titles.data ?? [])
                .filter((ti) => !ti.office || ti.office.id === office.id || String(ti.id) === choice)
                .map((ti) => (
                  <option key={ti.id} value={ti.id}>
                    {ti.title}
                    {!ti.active ? ' ' + t('(deaktiviert)') : ''}
                  </option>
                ))}
            </select>
          </label>
          {preview && (
            <p className="small" role="status">
              {t('Bekommen ihn: {add}. Verlieren ihn: {remove}.', { add: names(preview.add), remove: names(preview.remove) })}
            </p>
          )}
          <FormError error={error} />
          {choice !== current && (
            <button
              className="btn btn-block"
              disabled={busy}
              onClick={() =>
                run(
                  () => api.updateOffice(office.id, { title_id: choice === '' ? null : Number(choice) }),
                  t('Verknüpfung gespeichert'),
                ).then((ok) => ok && setPreview(null))
              }
            >
              {choice === '' ? t('Verknüpfung lösen') : t('Verknüpfen')}
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
type HolderRow = { kind?: HolderKind; user: string; name: string; image?: HolderPhoto | null; companions?: HolderRow[] };

/** Höchstens so viele Begleitpersonen je Person (wie OfficeService::COMPANIONS_MAX). */
const COMPANIONS_MAX = 5;

/**
 * Gemeinsame Amtszeiten einer Gruppe (Issue #282): Liste mit Bearbeiten und Löschen, Formular für eine neue. Die Jahre
 * gelten für alle Ämter; je Amt stehen die Personen darunter.
 */
function JointTermAdmin({
  groupId,
  groupTitle,
  offices,
  terms,
  onToast,
  reload,
}: {
  groupId: number;
  groupTitle: string;
  offices: Office[];
  terms: OfficeGroupTerm[];
  onToast: Toast;
  reload: () => void;
}) {
  const { busy, error, run } = useRunner(onToast, reload);
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const people = usePeople();
  const holdersOf = (gt: OfficeGroupTerm | undefined, o: Office) => o.terms.find((t) => gt && t.group_term_id === gt.id)?.holders;
  const form = (gt?: OfficeGroupTerm) => (
    <JointTermForm
      key={gt?.id ?? 'new'}
      initial={gt}
      offices={offices}
      holdersOf={(o) => holdersOf(gt, o)}
      people={people.data ?? NO_PEOPLE}
      busy={busy}
      onCancel={() => setEditing(null)}
      onSave={async (input) => {
        const ok = await run(
          () => (gt ? api.updateOfficeGroupTerm(gt.id, input) : api.createOfficeGroupTerm(groupId, input)),
          gt ? t('Gespeichert') : t('Gemeinsame Amtszeit angelegt'),
        );
        if (ok) setEditing(null);
      }}
    />
  );
  return (
    <div className="joint-admin">
      <h4 className="section-title">{t('Gemeinsame Amtszeiten')}</h4>
      <FormError error={error} />
      {terms.length === 0 && <p className="muted small">{t('Noch keine gemeinsamen Amtszeiten.')}</p>}
      <ul className="card-list">
        {terms.map((gt) =>
          editing === gt.id ? (
            <li key={gt.id}>{form(gt)}</li>
          ) : (
            <li key={gt.id} className="card row">
              {gt.image_url && <img className="joint-admin-image" src={gt.image_url} alt="" loading="lazy" />}
              <div className="row-main">
                <div className="row-title">
                  {groupTitle} {termYears(gt)}
                  {gt.current && <span className="badge">{t('aktuell')}</span>}
                </div>
                <div className="row-sub">
                  {offices
                    .flatMap((o) => {
                      const h = holdersOf(gt, o);
                      return h && h.length > 0
                        ? [t('{office}: {people}', { office: o.title, people: h.map(nameWithCompanions).join(', ') })]
                        : [];
                    })
                    .join(' · ') || t('Noch keine Personen')}
                </div>
                <div className="row-actions">
                  <IconButton icon="edit" label={t('Bearbeiten')} disabled={busy} onClick={() => setEditing(gt.id)} />
                  <IconButton
                    icon="delete"
                    label={t('Löschen')}
                    variant="danger"
                    disabled={busy}
                    onClick={async () =>
                      (await confirmAsk(
                        t('Gemeinsame Amtszeit {years} mit allen Amtszeiten darin und dem Bild löschen?', { years: termYears(gt) }),
                      )) && run(() => api.deleteOfficeGroupTerm(gt.id), t('Gelöscht'))
                    }
                  />
                </div>
              </div>
            </li>
          ),
        )}
      </ul>
      {editing === 'new' ? (
        form()
      ) : (
        <button className="btn btn-secondary" disabled={busy} onClick={() => setEditing('new')}>
          {t('Neue gemeinsame Amtszeit')}
        </button>
      )}
    </div>
  );
}

/** Formular einer gemeinsamen Amtszeit: Jahre, Zusatz, Gruppenbild und je Amt die Personen. */
function JointTermForm({
  initial,
  offices,
  holdersOf,
  people,
  onSave,
  onCancel,
  busy,
}: {
  initial?: OfficeGroupTerm;
  offices: Office[];
  holdersOf: (o: Office) => OfficeHolder[] | undefined;
  people: People;
  onSave: (input: OfficeGroupTermInput) => void;
  onCancel: () => void;
  busy: boolean;
}) {
  const [kind, setKind] = useState<OfficeTermKind>(initial?.kind ?? 'period');
  const [start, setStart] = useState(initial ? String(initial.start_year) : '');
  const [end, setEnd] = useState(initial?.end_year != null ? String(initial.end_year) : '');
  const [note, setNote] = useState(initial?.note ?? '');
  const [image, setImage] = useState<{ uid: string | null; url: string | null }>({
    uid: initial?.image_uid ?? null,
    url: initial?.image_url ?? null,
  });
  const [uploading, setUploading] = useState(false);
  const [imageError, setImageError] = useState<string | null>(null);
  const [holders, setHolders] = useState<Record<number, HolderRow[]>>(() =>
    Object.fromEntries(offices.map((o) => [o.id, holderRows(holdersOf(o), false)])),
  );

  async function upload(file: File | null) {
    if (!file) return;
    setUploading(true);
    setImageError(null);
    try {
      const img = await api.uploadRichImage(file);
      setImage({ uid: img.uid, url: img.url });
    } catch (e) {
      setImageError((e as Error).message);
    } finally {
      setUploading(false);
    }
  }

  return (
    <form
      className="form card joint-term-form"
      onSubmit={(e) => {
        e.preventDefault();
        onSave({
          kind,
          start_year: Number(start),
          end_year: kind === 'period' && end.trim() !== '' ? Number(end) : null,
          note: note.trim(),
          image_uid: image.uid,
          offices: offices.map((o) => ({ office_id: o.id, holders: holderInput(holders[o.id] ?? [], people) })),
        });
      }}
    >
      <h3>{initial ? t('Gemeinsame Amtszeit bearbeiten') : t('Neue gemeinsame Amtszeit')}</h3>
      <YearFields kind={kind} setKind={setKind} start={start} setStart={setStart} end={end} setEnd={setEnd} />
      <label className="field">
        <span>{t('Zusatz (optional)')}</span>
        <input type="text" maxLength={100} value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
      <div className="field">
        <span>{t('Gruppenbild (optional)')}</span>
        {image.url && <img className="joint-term-form-image" src={image.url} alt={t('Gruppenbild')} />}
        <div className="button-row">
          <label className="btn btn-small btn-secondary">
            {uploading ? t('Lädt …') : image.url ? t('Bild ersetzen') : t('Bild wählen')}
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              hidden
              aria-label={t('Gruppenbild')}
              disabled={uploading}
              onChange={(e) => {
                void upload(e.target.files?.[0] ?? null);
                e.target.value = '';
              }}
            />
          </label>
          {image.url && (
            <button type="button" className="btn btn-small btn-secondary" onClick={() => setImage({ uid: null, url: null })}>
              {t('Bild entfernen')}
            </button>
          )}
        </div>
        <FormError error={imageError} />
      </div>
      {offices.map((o) => (
        <HolderFields
          key={o.id}
          holders={holders[o.id] ?? []}
          setHolders={(rows) => setHolders({ ...holders, [o.id]: rows })}
          people={people}
          initial={holdersOf(o)}
          legend={o.title}
          optional
        />
      ))}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || uploading}>
          {t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

/** Zeilen der Personen aus einer gespeicherten Amtszeit, mit ihren Begleitpersonen. */
function holderRows(holders: OfficeHolder[] | undefined, empty: boolean): HolderRow[] {
  const row = (h: OfficeHolder): HolderRow => ({
    user: h.user_id ? String(h.user_id) : '',
    name: h.name,
    image: !h.user_id && h.image_uid && h.photo_url ? { uid: h.image_uid, url: h.photo_url } : null,
  });
  const rows = holders?.map((h) => ({ ...row(h), companions: (h.companions ?? []).map(row) })) ?? [];
  return rows.length > 0 || !empty ? rows : [{ user: '', name: '' }];
}

/** Zeilen für den Server: Konto oder freier Name, je mit Begleitpersonen. */
function holderInput(holders: HolderRow[], people: People): OfficeTermInput['holders'] {
  const formerIds = new Set(people.former.map((p) => p.id));
  const kindOf = (h: HolderRow): HolderKind => h.kind ?? (!h.user ? 'name' : formerIds.has(Number(h.user)) ? 'former' : 'member');
  const person = (h: HolderRow): OfficePersonInput =>
    kindOf(h) !== 'name' && h.user ? { user_id: Number(h.user) } : { name: h.name.trim(), image_uid: h.image?.uid ?? null };
  return holders.map((h) => ({ ...person(h), companions: (h.companions ?? []).map(person) }));
}

/**
 * Personen einer Amtszeit (Konto oder freier Name), bis zu 20, je mit bis zu 5 Begleitpersonen (z. B. Partnerin oder
 * Partner, die das Amt nicht selbst bekleiden). Mit optional dürfen alle Zeilen fehlen (gemeinsame Amtszeit: Amt ohne
 * Personen; Begleitpersonen).
 */
function HolderFields({
  holders,
  setHolders,
  people,
  initial,
  legend,
  optional = false,
  companions = true,
}: {
  holders: HolderRow[];
  setHolders: (rows: HolderRow[]) => void;
  people: People;
  initial?: OfficeHolder[];
  legend: string;
  optional?: boolean;
  /** false: Zeilen sind selbst Begleitpersonen (keine weitere Ebene, höchstens 5) */
  companions?: boolean;
}) {
  const formerIds = new Set(people.former.map((p) => p.id));
  const kindOf = (h: HolderRow): HolderKind => h.kind ?? (!h.user ? 'name' : formerIds.has(Number(h.user)) ? 'former' : 'member');
  // Konten, die schon in der Amtszeit stehen, aber in keiner Liste (z. B. ohne Recht darauf), bleiben bei den Mitgliedern wählbar.
  const members = [...people.members];
  for (const h of (initial ?? []).flatMap((x) => [x, ...(x.companions ?? [])])) {
    if (h.user_id && !formerIds.has(h.user_id) && !members.some((p) => p.id === h.user_id)) members.push({ id: h.user_id, name: h.name });
  }
  const kinds: { value: HolderKind; label: string }[] = [
    { value: 'name', label: t('Ohne Profil') },
    ...(members.length > 0 ? [{ value: 'member' as const, label: t('Mitglied') }] : []),
    ...(people.former.length > 0 ? [{ value: 'former' as const, label: t('Ehemaliger') }] : []),
  ];
  const set = (i: number, row: Partial<HolderRow>) => setHolders(holders.map((h, j) => (j === i ? { ...h, ...row } : h)));
  return (
    <fieldset className="extra-roles">
      <legend>{legend}</legend>
      {holders.map((h, i) => {
        const kind = kindOf(h);
        const list = kind === 'former' ? people.former : members;
        const who = (kind === 'name' ? h.name : list.find((p) => String(p.id) === h.user)?.name)?.trim() || t('dieser Person');
        return (
          <div key={i} className="office-holder">
            <div className="office-holder-row">
              {kinds.length > 1 && (
                <label className="field">
                  <span>{t('Profil')}</span>
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
                <>
                  <label className="field">
                    <span>{t('Name')}</span>
                    <input type="text" maxLength={100} value={h.name} onChange={(e) => set(i, { name: e.target.value })} required />
                  </label>
                  <HolderPhotoField row={h} onChange={(image) => set(i, { image })} />
                </>
              ) : (
                <label className="field">
                  <span>{kind === 'former' ? t('Ehemaliger') : t('Mitglied')}</span>
                  <select value={h.user} onChange={(e) => set(i, { user: e.target.value })} required>
                    <option value="">{t('Bitte wählen')}</option>
                    {list.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name}
                      </option>
                    ))}
                  </select>
                </label>
              )}
              {(optional || holders.length > 1) && (
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  aria-label={companions ? t('Person entfernen') : t('Begleitperson entfernen')}
                  onClick={() => setHolders(holders.filter((_, j) => j !== i))}
                >
                  ✕
                </button>
              )}
            </div>
            {companions &&
              (h.companions?.length ? (
                <div className="office-companions">
                  <HolderFields
                    holders={h.companions}
                    setHolders={(rows) => set(i, { companions: rows })}
                    people={people}
                    initial={initial}
                    legend={t('Begleitung von {name}', { name: who })}
                    optional
                    companions={false}
                  />
                </div>
              ) : (
                <button
                  type="button"
                  className="btn btn-small btn-link office-companion-add"
                  onClick={() => set(i, { companions: [{ user: '', name: '' }] })}
                >
                  {t('+ Begleitung (z. B. Partner/in)')}
                </button>
              ))}
          </div>
        );
      })}
      {holders.length < (companions ? 20 : COMPANIONS_MAX) && (holders.length > 0 || companions) && (
        <button type="button" className="btn btn-small btn-secondary" onClick={() => setHolders([...holders, { user: '', name: '' }])}>
          {!companions ? t('Weitere Begleitperson') : holders.length === 0 ? t('Person hinzufügen') : t('Weitere Person')}
        </button>
      )}
    </fieldset>
  );
}

/**
 * Foto eines freien Namens (Issue #283): hochladen, entfernen oder das Foto aus einer früheren Amtszeit mit demselben
 * Namen übernehmen. Öffentlich wie die Ämter.
 */
function HolderPhotoField({ row, onChange }: { row: HolderRow; onChange: (image: HolderPhoto | null) => void }) {
  const known = useContext(KnownPhotos);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const suggestion = useMemo(() => known.get(row.name.trim().toLowerCase()) ?? null, [known, row.name]);
  const label = row.name.trim() || t('Person');

  async function upload(file: File | null) {
    if (!file) return;
    setUploading(true);
    setError(null);
    try {
      const img = await api.uploadRichImage(file);
      onChange({ uid: img.uid, url: img.url });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="office-holder-photo">
      {row.image && <MemberAvatar url={row.image.url} name={label} />}
      <label className="btn btn-small btn-secondary">
        {uploading ? t('Lädt …') : row.image ? t('Foto ersetzen') : t('Foto')}
        <input
          type="file"
          accept=".jpg,.jpeg,.png,.webp"
          hidden
          aria-label={t('Foto von {name}', { name: label })}
          disabled={uploading}
          onChange={(e) => {
            void upload(e.target.files?.[0] ?? null);
            e.target.value = '';
          }}
        />
      </label>
      {row.image && (
        <button type="button" className="btn btn-small btn-secondary" onClick={() => onChange(null)}>
          {t('Foto entfernen')}
        </button>
      )}
      {!row.image && suggestion && (
        <button type="button" className="btn btn-small btn-secondary" onClick={() => onChange(suggestion)}>
          {t('Foto von früher übernehmen')}
        </button>
      )}
      <FormError error={error} />
    </div>
  );
}

/** Art und Jahre einer Amtszeit (auch für gemeinsame Amtszeiten). */
function YearFields({
  kind,
  setKind,
  start,
  setStart,
  end,
  setEnd,
}: {
  kind: OfficeTermKind;
  setKind: (k: OfficeTermKind) => void;
  start: string;
  setStart: (v: string) => void;
  end: string;
  setEnd: (v: string) => void;
}) {
  return (
    <>
      <label className="field">
        <span>{t('Art')}</span>
        <select value={kind} onChange={(e) => setKind(e.target.value as OfficeTermKind)}>
          <option value="period">{t('Zeitraum (von–bis oder bis heute)')}</option>
          <option value="point">{t('Einzelnes Jahr')}</option>
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>{kind === 'point' ? t('Jahr') : t('Von (Jahr)')}</span>
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
            <span>{t('Bis (leer: bis heute)')}</span>
            <input type="number" inputMode="numeric" min={1800} max={2200} value={end} onChange={(e) => setEnd(e.target.value)} />
          </label>
        )}
      </div>
    </>
  );
}

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
  const [honor, setHonor] = useState(initial?.honor ?? false);
  const [holders, setHolders] = useState<HolderRow[]>(holderRows(initial?.holders, true));

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
          honor,
          holders: holderInput(holders, people),
        });
      }}
    >
      <h3>{initial ? t('Amtszeit bearbeiten') : t('Neue Amtszeit')}</h3>
      {initial?.group_term_id && (
        <p className="muted small">
          {t('Gehört zu einer gemeinsamen Amtszeit der Gruppe. Andere Jahre lösen sie daraus; Jahre für alle änderst du bei der Gruppe.')}
        </p>
      )}
      <YearFields kind={kind} setKind={setKind} start={start} setStart={setStart} end={end} setEnd={setEnd} />
      <HolderFields holders={holders} setHolders={setHolders} people={people} initial={initial?.holders} legend={t('Personen')} />
      <label className="field">
        <span>{t('Zusatz (optional, z. B. „Unter …“ oder „Ehrenvorsitz“)')}</span>
        <input type="text" maxLength={100} value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
      <label className="toggle">
        <input type="checkbox" checked={honor} onChange={(e) => setHonor(e.target.checked)} />
        <span>{t('Ehrung (im Zeitstrahl mit Stern, z. B. Ehrenmitglied)')}</span>
      </label>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

const IMPORT_EXAMPLE =
  'gruppe;amt;beginn;ende;art;personen;zusatz;ehrung\nVerein;Vorsitz;2020;heute;;Erika Muster;;\nVerein;Ehrenmitglieder;2008;;;Max Beispiel;;ja';

/**
 * Tabelle importieren (Ersterfassung): einfügen oder Datei wählen, Vorschau mit Fehlern je Zeile, dann übernehmen.
 * Vorhandene Gruppen und Ämter werden nach Namen gefunden; was es schon gibt, wird übersprungen.
 */
const LINK_MATCH: Record<OfficeLinkSuggestion['candidates'][number]['match'], string> = {
  name: t('gleicher Name'),
  similar: t('ähnlicher Name'),
};

/**
 * Freie Namen in Amtszeiten mit Mitgliedern oder Ehemaligen verknüpfen: Die App schlägt passende Personen vor
 * (tolerant bei Umlauten, Bindestrichen und Leerzeichen); verknüpft wird erst nach Bestätigung.
 */
function OfficeLink({ onToast }: { onToast: Toast }) {
  const { data, error, loading, reload } = useAsync(api.officeLinkSuggestions, [], 'office-link-suggestions');
  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const list = data ?? [];
  return (
    <div className="office-link">
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/aemter')}>
        ‹ {t('Alle {offices}', { offices: sec.offices })}
      </button>
      {list.length === 0 ? (
        <Empty>{t('Keine Vorschläge: Kein freier Name passt zu einer Person in der App.')}</Empty>
      ) : (
        <ul className="card-list">
          {list.map((s) => (
            <li key={s.name}>
              <LinkCard suggestion={s} onToast={onToast} onDone={reload} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function LinkCard({ suggestion: s, onToast, onDone }: { suggestion: OfficeLinkSuggestion; onToast: Toast; onDone: () => void }) {
  const [choice, setChoice] = useState(s.candidates[0]?.match === 'name' ? String(s.candidates[0].id) : '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const person = s.candidates.find((c) => String(c.id) === choice);
  async function link() {
    if (
      !person ||
      !(await confirmAsk(
        tn('„{name}“ in einer Amtszeit mit {person} verknüpfen?', '„{name}“ in {count} Einträgen mit {person} verknüpfen?', s.entries, {
          name: s.name,
          person: person.name,
        }),
      ))
    ) {
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await api.linkOfficeName(s.name, person.id);
      onToast(tn('{count} Eintrag mit {person} verknüpft', '{count} Einträge mit {person} verknüpft', res.linked, { person: person.name }));
      onDone();
    } catch (e) {
      setError((e as Error).message);
      setBusy(false);
    }
  }
  return (
    <div className="card office-link-card">
      <div className="row-title">{s.name}</div>
      <div className="row-sub">
        {tn('{count} Eintrag', '{count} Einträge', s.entries)}: {s.terms.join(', ')}
        {s.entries > s.terms.length && ' …'}
      </div>
      <fieldset className="fieldset">
        <legend>{t('Verknüpfen mit')}</legend>
        {s.candidates.map((c) => (
          <label key={c.id} className="toggle">
            <input type="radio" name={`link-${s.name}`} checked={choice === String(c.id)} onChange={() => setChoice(String(c.id))} />
            {c.name}{' '}
            <span className="muted small">
              ({LINK_MATCH[c.match]}
              {c.former ? ', ' + t('ehemalig') : ''})
            </span>
          </label>
        ))}
      </fieldset>
      <FormError error={error} />
      <button type="button" className="btn" disabled={busy || !person} onClick={link}>
        {busy ? t('Verknüpft …') : t('Verknüpfen')}
      </button>
    </div>
  );
}

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
        onToast(tn('{count} Amtszeit übernommen', '{count} Amtszeiten übernommen', res.terms_new));
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
        ‹ {t('Alle {offices}', { offices: sec.offices })}
      </button>
      <h2 className="member-name">{t('Tabelle importieren')}</h2>
      <label className="field">
        <span>{t('Tabelle')}</span>
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
        <span>{t('Oder Datei wählen (CSV)')}</span>
        <FileInput
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
        <span>{t('Personen mit dem Konto verknüpfen, wenn der Name genau zu einem Konto passt')}</span>
      </label>
      <FormError error={error} />
      <div className="button-row">
        <button className="btn btn-secondary" disabled={busy || csv.trim() === ''} onClick={() => send(false)}>
          {t('Vorschau')}
        </button>
        {result && (
          <button className="btn" disabled={busy || errors > 0 || result.terms_new === 0} onClick={() => send(true)}>
            {t('Übernehmen')}
          </button>
        )}
      </div>
      {result && (
        <section>
          <p role="status">
            {t('Neu: {groups} Gruppen, {offices} Ämter, {terms} Amtszeiten.', {
              groups: result.groups_new,
              offices: result.offices_new,
              terms: result.terms_new,
            })}
            {errors > 0 &&
              ' ' +
                tn(
                  '{count} Zeile hat einen Fehler; bitte erst korrigieren.',
                  '{count} Zeilen haben einen Fehler; bitte erst korrigieren.',
                  errors,
                )}
          </p>
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.duplicate ? 'muted' : ''}>
                <strong>{t('Zeile {line}', { line: r.line })}</strong>
                {r.office && (
                  <>
                    {' '}
                    {r.group ? `${r.group} / ` : ''}
                    {r.office}: {r.kind && termYears({ kind: r.kind, start_year: r.start_year!, end_year: r.end_year ?? null })} ·{' '}
                    {(r.holders ?? []).map((h) => h.name + (h.user_id ? ' ' + t('(Konto)') : '')).join(', ')}
                    {r.note ? ` · ${r.note}` : ''}
                    {r.honor ? ' · ' + t('Ehrung') : ''}
                    {r.duplicate ? ' · ' + t('gibt es schon') : ''}
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
