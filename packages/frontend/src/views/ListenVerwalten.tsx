import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { api, type ListColumn, type ListField, type ListRow, type ListSyncResult, type PrintList, type PrintListInput } from '../api';
import { useDragSort } from '../dragSort';
import { formatDate, formatEuro, todayYmd } from '../format';
import brand from '../generated/brand';
import logoUrl from '../generated/logo-small.webp';
import type { ListPdfInput } from '../listPdf';
import { columnWidths, csvFileName, csvPhone, listFileBase, longBirthday, sortRowsBy, toCsv, type SortValue } from '../lists';
import { sortRowsByName, splitName } from '../memberSort';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

const sec = brand.labels.sections;

type Toast = (msg: string) => void;

const COLUMNS_MAX = 20;
const ROWS_MAX = 500;

/** Standardbezeichnungen der Datenspalten (Überschrift, wenn die Spalte keine eigene hat). */
const FIELD_LABELS: Record<ListField, string> = {
  nr: 'Nr.',
  name: 'Name',
  first_name: 'Vorname',
  last_name: 'Nachname',
  role: 'Rolle',
  rank: brand.labels.rank,
  titles: sec.titles,
  birthday: 'Geburtstag',
  address: 'Adresse',
  street: 'Straße und Nr.',
  postal_code: 'PLZ',
  city: 'Ort',
  country: 'Land',
  phone: 'Handynummer',
  email: 'E-Mail',
  points: sec.points,
  penalties: `${sec.penalties} (offen)`,
};

/** Breite einer neuen Datenspalte (sonst normal) */
const FIELD_WIDTHS: Partial<Record<ListField, ListColumn['width']>> = {
  nr: 'narrow',
  postal_code: 'narrow',
  address: 'wide',
  email: 'wide',
};

const WIDTH_LABELS: Record<ListColumn['width'], string> = { narrow: 'schmal', normal: 'normal', wide: 'breit', auto: 'automatisch' };
/** Anteil an der Breite der Tabelle (automatisch: nur, wenn der Platz für den Inhalt nicht reicht, siehe columnWidths) */
const WIDTH_WEIGHT: Record<ListColumn['width'], number> = { narrow: 1, normal: 2.5, wide: 5, auto: 2.5 };

export const columnLabel = (c: Pick<ListColumn, 'kind' | 'field' | 'label'>): string =>
  c.label.trim() !== '' ? c.label : c.kind === 'field' && c.field ? FIELD_LABELS[c.field] : '';

/** Zeile im Entwurf: dazu ein Schlüssel für React, da Zeilen keine eigene ID haben. */
type DraftRow = ListRow & { key: number };
let nextKey = 1;
const withKey = (r: ListRow): DraftRow => ({ ...r, key: nextKey++ });

/**
 * Wert einer Zelle: Zahl (für CSV, in der Tabelle formatiert) oder Text; leer bei leeren Spalten, freien Zeilen ohne
 * passenden Wert, gelöschten Konten und Spalten, deren Werte die Person nicht sieht.
 */
function cell(c: ListColumn, r: ListRow, index: number): string | number {
  if (c.kind === 'blank' || c.field === null) return '';
  if (c.field === 'nr') return index + 1;
  if (c.available === false) return '';
  if (r.kind === 'free') {
    const { first, last } = splitName(r.name);
    return c.field === 'name' ? r.name : c.field === 'first_name' ? first : c.field === 'last_name' ? last : '';
  }
  if (r.deleted) return '';
  const v: unknown = (r.values as Record<string, unknown>)[c.field];
  if (c.field === 'birthday') return isBirthday(v) ? longBirthday(v) : '';
  if (Array.isArray(v)) return v.join(', ');
  // Offene Strafen in Euro (CSV: als Zahl)
  if (typeof v === 'number') return c.field === 'penalties' ? v / 100 : v;
  return typeof v === 'string' ? v : '';
}

type BirthdayValue = { day: number; month: number; year: number | null };
const isBirthday = (v: unknown): v is BirthdayValue => typeof v === 'object' && v !== null && 'day' in v && 'month' in v;

/** Wert zum Sortieren: Geburtstage nach Tag im Jahr (Monat, Tag), sonst wie in der Zelle. */
function sortValue(c: ListColumn, r: ListRow, index: number): SortValue {
  if (c.field === 'birthday' && r.kind === 'member' && !r.deleted) {
    const v: unknown = (r.values as Record<string, unknown>).birthday;
    return isBirthday(v) ? v.month * 100 + v.day : null;
  }
  const v = cell(c, r, index);
  return v === '' ? null : v;
}

function cellText(c: ListColumn, r: ListRow, index: number): string {
  const v = cell(c, r, index);
  if (typeof v !== 'number') return v;
  return c.field === 'penalties' ? formatEuro(Math.round(v * 100)) : v.toLocaleString('de-DE');
}

/**
 * Verwaltung → Listen (Issue #121, manage_lists): gespeicherte Listen mit eigenem Titel, Spalten und Zeilen zum Drucken
 * (auch als PDF) und als CSV. verwaltung/listen, verwaltung/listen/<id>
 */
export function ListAdmin({ path, onToast }: { path: string[]; onToast: Toast }) {
  if (/^\d+$/.test(path[0] ?? '')) return <ListEditorLoader key={path[0]} id={Number(path[0])} onToast={onToast} />;
  return <ListOverview onToast={onToast} />;
}

function ListOverview({ onToast }: { onToast: Toast }) {
  const { data, error, loading, reload } = useAsync(sources.lists.load, sources.lists.deps, sources.lists.key);
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function run<T>(action: () => Promise<T>, msg: string): Promise<T | null> {
    setBusy(true);
    setFormError(null);
    try {
      const result = await action();
      onToast(msg);
      reload();
      return result;
    } catch (e) {
      setFormError((e as Error).message);
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    const list = await run(() => api.createList({ title: title.trim() }), 'Liste angelegt');
    if (list) navigate(`verwaltung/listen/${list.id}`);
  }

  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const lists = data!;

  return (
    <div>
      <p className="muted small">
        Listen zum Ausdrucken oder als CSV für Excel, z. B. Unterschriften- oder Teilnehmerlisten. Titel und Spalten wählst du selbst; die
        Mitglieder kommen mit „Mitglieder aktualisieren“ in die Liste.
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {lists.length === 0 ? (
        <Empty>Noch keine {sec.lists}.</Empty>
      ) : (
        <ul className="card-list">
          {lists.map((l) => (
            <li key={l.id} className="card row clickable" onClick={() => navigate(`verwaltung/listen/${l.id}`)}>
              <div className="row-main">
                <div className="row-title">{l.title}</div>
                <div className="row-sub">
                  {[
                    l.date ? formatDate(l.date) : null,
                    `${l.rows} ${l.rows === 1 ? 'Zeile' : 'Zeilen'}`,
                    l.updated_by_name ? `zuletzt: ${l.updated_by_name}` : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </div>
                <div className="row-actions" onClick={(e) => e.stopPropagation()}>
                  <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => navigate(`verwaltung/listen/${l.id}`)}>
                    Öffnen
                  </button>
                  <button
                    className="btn btn-small btn-secondary"
                    disabled={busy}
                    onClick={() => run(() => api.copyList(l.id), 'Liste kopiert')}
                  >
                    Kopieren
                  </button>
                  <button
                    className="btn btn-small btn-danger"
                    disabled={busy}
                    onClick={() => window.confirm(`Liste „${l.title}“ löschen?`) && run(() => api.deleteList(l.id), 'Gelöscht')}
                  >
                    Löschen
                  </button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      <form className="form card" onSubmit={add}>
        <h3>Neue Liste</h3>
        <label className="field">
          <span>Titel</span>
          <input
            type="text"
            maxLength={120}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="z. B. Ausflug – Anmeldung"
            required
          />
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}

function ListEditorLoader({ id, onToast }: { id: number; onToast: Toast }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.list(id), [id]);
  if (loading && !data) return <Loading shape="form" />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  return <ListEditor list={data!} onSaved={setData} onToast={onToast} />;
}

interface Draft {
  title: string;
  subtitle: string;
  date: string;
  orientation: 'portrait' | 'landscape';
  groups: string[];
  columns: ListColumn[];
  rows: DraftRow[];
}

const toDraft = (l: PrintList): Draft => ({
  title: l.title,
  subtitle: l.subtitle ?? '',
  date: l.date ?? '',
  orientation: l.orientation,
  groups: l.groups,
  columns: l.columns,
  rows: l.rows.map(withKey),
});

const toInput = (d: Draft): PrintListInput => ({
  title: d.title.trim(),
  subtitle: d.subtitle.trim(),
  date: d.date || null,
  orientation: d.orientation,
  groups: d.groups,
  columns: d.columns.map(({ kind, field, label, width, checkbox }) => ({ kind, field, label, width, checkbox })),
  rows: d.rows.map((r) => (r.kind === 'free' ? { kind: 'free', name: r.name } : { kind: 'member', user_id: r.user_id })),
});

function ListEditor({ list, onSaved, onToast }: { list: PrintList; onSaved: (l: PrintList) => void; onToast: Toast }) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(list));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [sync, setSync] = useState<ListSyncResult | null>(null);
  const saved = useMemo(() => JSON.stringify(toInput(toDraft(list))), [list]);
  const dirty = JSON.stringify(toInput(draft)) !== saved;
  const update = (patch: Partial<Draft>) => setDraft((d) => ({ ...d, ...patch }));
  // Stand, auf dem der Entwurf beruht: Beim Speichern prüft der Server, dass niemand die Liste inzwischen geändert hat.
  const [basis, setBasis] = useState(list.updated_at);
  /** Stand vom Server übernehmen (nach Speichern bzw. Aktualisieren): Entwurf neu, mit den aktuellen Werten. */
  function adopt(l: PrintList) {
    setDraft(toDraft(l));
    setBasis(l.updated_at);
    onSaved(l);
  }

  async function run<T>(action: () => Promise<T>): Promise<T | null> {
    setBusy(true);
    setError(null);
    try {
      return await action();
    } catch (e) {
      setError((e as Error).message);
      return null;
    } finally {
      setBusy(false);
    }
  }

  async function save(): Promise<boolean> {
    const result = await run(() => api.saveList(list.id, { ...toInput(draft), updated_at: basis }));
    if (!result) return false;
    onToast('Gespeichert');
    adopt(result);
    return true;
  }

  /** „Mitglieder aktualisieren“: erst speichern, dann zeigen, was sich ändert. */
  async function startSync() {
    if (dirty && !(await save())) return;
    const result = await run(() => api.syncListMembers(list.id, false));
    if (!result) return;
    if (result.added.length === 0 && result.removed.length === 0) onToast('Die Liste ist aktuell');
    else setSync(result);
  }

  /** Übernehmen: Änderungen, die während der Übersicht dazukamen, erst speichern, damit sie nicht verloren gehen. */
  async function applySync() {
    if (dirty && !(await save())) return;
    const result = await run(() => api.syncListMembers(list.id, true));
    setSync(null);
    if (result?.list) {
      onToast('Mitglieder aktualisiert');
      adopt(result.list);
    } else if (result) {
      // Inzwischen schon aktualisiert (z. B. von jemand anderem)
      onToast('Die Liste ist aktuell');
    }
  }

  const [pdfBusy, setPdfBusy] = useState(false);
  /** PDF als Datei, ohne Druckdialog (jsPDF wird erst jetzt geladen). */
  async function downloadPdf() {
    setPdfBusy(true);
    setError(null);
    try {
      const { downloadListPdf } = await import('../listPdf');
      await downloadListPdf(pdfInput(draft));
    } catch (e) {
      setError(`Das PDF ließ sich nicht erstellen: ${(e as Error).message}`);
    } finally {
      setPdfBusy(false);
    }
  }

  function leave() {
    if (!dirty || window.confirm('Änderungen verwerfen?')) navigate('verwaltung/listen');
  }

  const deleted = draft.rows.filter((r) => r.kind === 'member' && r.deleted).length;
  const hidden = draft.columns.filter((c) => c.available === false);

  return (
    <div className="list-editor">
      <button className="btn btn-link no-print" onClick={leave}>
        ‹ Alle {sec.lists}
      </button>

      <section className="card form">
        <h3>Liste</h3>
        <label className="field">
          <span>Titel</span>
          <input type="text" maxLength={120} value={draft.title} onChange={(e) => update({ title: e.target.value })} required />
        </label>
        <label className="field">
          <span>Untertitel / Hinweis (optional)</span>
          <input type="text" maxLength={200} value={draft.subtitle} onChange={(e) => update({ subtitle: e.target.value })} />
        </label>
        <div className="field-row">
          <label className="field">
            <span>Datum (optional)</span>
            <input type="date" value={draft.date} onChange={(e) => update({ date: e.target.value })} />
          </label>
          <label className="field">
            <span>Druck</span>
            <select value={draft.orientation} onChange={(e) => update({ orientation: e.target.value as Draft['orientation'] })}>
              <option value="portrait">Hochformat</option>
              <option value="landscape">Querformat</option>
            </select>
          </label>
        </div>
      </section>

      <ColumnEditor columns={draft.columns} fields={list.fields} onChange={(columns) => update({ columns })} />

      <RowEditor
        rows={draft.rows}
        columns={draft.columns}
        groups={draft.groups}
        groupOptions={list.group_options}
        onGroups={(groups) => update({ groups })}
        deleted={deleted}
        busy={busy}
        sync={sync}
        onChange={(rows) => update({ rows })}
        onSync={startSync}
        onApplySync={applySync}
        onCancelSync={() => setSync(null)}
      />

      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {dirty && (
        <div className="list-save no-print">
          <button className="btn" disabled={busy || draft.title.trim() === ''} onClick={save}>
            Speichern
          </button>
        </div>
      )}

      <section className="card list-output">
        <h3>Ausgabe</h3>
        {hidden.length > 0 && (
          <p className="muted small">Ohne die Rechte dazu bleiben diese Spalten leer: {hidden.map((c) => columnLabel(c)).join(', ')}.</p>
        )}
        <div className="button-row">
          <button className="btn" disabled={pdfBusy} onClick={downloadPdf}>
            {pdfBusy ? 'PDF wird erstellt …' : 'PDF herunterladen'}
          </button>
          <button className="btn btn-secondary" onClick={() => window.print()}>
            Drucken
          </button>
          <button className="btn btn-secondary" onClick={() => downloadCsv(draft)}>
            CSV herunterladen
          </button>
        </div>
        <p className="muted small">Die Liste enthält personenbezogene Daten. Bitte nach Gebrauch vernichten bzw. löschen.</p>
        <div className="list-preview" aria-label="Vorschau">
          <PrintSheet draft={draft} />
        </div>
      </section>

      <PrintPortal draft={draft} />
    </div>
  );
}

/** Liste für das PDF: dieselben Spalten und Texte wie im Ausdruck. */
function pdfInput(d: Draft): ListPdfInput {
  return {
    title: d.title || 'Ohne Titel',
    subtitle: d.subtitle,
    metaLeft: brand.name,
    metaRight: metaText(d),
    orientation: d.orientation,
    color: brand.themeColor,
    logoUrl,
    columns: d.columns.map((c) => ({
      label: columnLabel(c),
      weight: WIDTH_WEIGHT[c.width],
      auto: c.width === 'auto',
      name: c.kind === 'field' && c.field !== null && NAME_FIELDS.has(c.field),
      rotated: rotated(c),
      blank: c.kind === 'blank',
      checkbox: c.kind === 'blank' && c.checkbox,
    })),
    rows: d.rows.map((r, i) =>
      d.columns.map((c) => ({
        text: c.kind === 'blank' ? '' : cellText(c, r, i),
        num: c.field === 'nr' || typeof cell(c, r, i) === 'number',
      })),
    ),
  };
}

/** Unter dem Balken rechts: Datum der Liste und Stand (heute) */
const metaText = (d: Draft): string => [d.date ? formatDate(d.date) : null, `Stand: ${formatDate(todayYmd())}`].filter(Boolean).join(' · ');

function downloadCsv(d: Draft) {
  const header = d.columns.map((c) => columnLabel(c));
  const rows = d.rows.map((r, i) =>
    d.columns.map((c) => {
      const v = cell(c, r, i);
      return c.field === 'phone' && typeof v === 'string' ? csvPhone(v) : v;
    }),
  );
  const blob = new Blob([toCsv(header, rows)], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = csvFileName(d.title);
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/* ------------------------------------------------------------------ */

function ColumnEditor({
  columns,
  fields,
  onChange,
}: {
  columns: ListColumn[];
  fields: PrintList['fields'];
  onChange: (c: ListColumn[]) => void;
}) {
  const sort = useDragSort(columns, onChange);
  const [field, setField] = useState<ListField>(fields.find((f) => f.available && !columns.some((c) => c.field === f.key))?.key ?? 'name');
  const set = (i: number, patch: Partial<ListColumn>) => onChange(columns.map((c, j) => (j === i ? { ...c, ...patch } : c)));
  const full = columns.length >= COLUMNS_MAX;

  return (
    <section className="card form">
      <h3>Spalten</h3>
      <p className="muted small">
        Datenspalten füllt die App mit den Angaben der Mitglieder, leere Spalten bleiben zum Ausfüllen auf Papier. Zum Umsortieren eine
        Spalte am Griff ziehen.
      </p>
      <ul className="card-list list-columns">
        {columns.map((c, i) => (
          <li key={i} {...sort.itemProps(i, 'card row list-column')}>
            {columns.length > 1 && sort.handle(i, columnLabel(c) || 'Spalte')}
            <div className="row-main">
              <div className="row-sub">
                {c.kind === 'field' ? `Datenspalte: ${c.field ? FIELD_LABELS[c.field] : ''}` : 'Leere Spalte'}
                {c.available === false && ' (für dich nicht sichtbar)'}
              </div>
              <div className="field-row">
                <label className="field">
                  <span>Überschrift</span>
                  <input
                    type="text"
                    maxLength={60}
                    value={c.label}
                    placeholder={c.kind === 'field' && c.field ? FIELD_LABELS[c.field] : 'ohne Überschrift'}
                    onChange={(e) => set(i, { label: e.target.value })}
                  />
                </label>
                <label className="field narrow">
                  <span>Breite</span>
                  <select value={c.width} onChange={(e) => set(i, { width: e.target.value as ListColumn['width'] })}>
                    {(Object.keys(WIDTH_LABELS) as ListColumn['width'][]).map((w) => (
                      <option key={w} value={w}>
                        {WIDTH_LABELS[w]}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
              <div className="row-actions">
                {c.kind === 'blank' && (
                  <label className="toggle">
                    <input type="checkbox" checked={c.checkbox} onChange={(e) => set(i, { checkbox: e.target.checked })} />
                    <span>Kästchen zum Abhaken</span>
                  </label>
                )}
                {columns.length > 1 && (
                  <button type="button" className="btn btn-small btn-secondary" onClick={() => onChange(columns.filter((_, j) => j !== i))}>
                    Entfernen
                  </button>
                )}
              </div>
            </div>
          </li>
        ))}
      </ul>
      <div className="field-row list-add-column">
        <label className="field">
          <span>Datenspalte</span>
          <select value={field} onChange={(e) => setField(e.target.value as ListField)}>
            {fields
              .filter((f) => f.available)
              .map((f) => (
                <option key={f.key} value={f.key}>
                  {FIELD_LABELS[f.key]}
                </option>
              ))}
          </select>
        </label>
        <button
          type="button"
          className="btn btn-secondary"
          disabled={full}
          onClick={() =>
            onChange([
              ...columns,
              { kind: 'field', field, label: '', width: FIELD_WIDTHS[field] ?? 'normal', checkbox: false, available: true },
            ])
          }
        >
          + Datenspalte
        </button>
      </div>
      <div className="button-row">
        <button
          type="button"
          className="btn btn-secondary"
          disabled={full}
          onClick={() =>
            onChange([...columns, { kind: 'blank', field: null, label: '', width: 'normal', checkbox: false, available: true }])
          }
        >
          + Leere Spalte
        </button>
      </div>
      {full && <p className="muted small">Höchstens {COLUMNS_MAX} Spalten.</p>}
    </section>
  );
}

/** Sortieren nach: Name (Nachname, Vorname) oder eine Datenspalte (ihre Stelle in der Liste) */
type RowSort = 'name' | number;

function RowEditor({
  rows,
  columns,
  groups,
  groupOptions,
  onGroups,
  deleted,
  busy,
  sync,
  onChange,
  onSync,
  onApplySync,
  onCancelSync,
}: {
  rows: DraftRow[];
  columns: ListColumn[];
  groups: string[];
  groupOptions: PrintList['group_options'];
  onGroups: (groups: string[]) => void;
  deleted: number;
  busy: boolean;
  sync: ListSyncResult | null;
  onChange: (rows: DraftRow[]) => void;
  onSync: () => void;
  onApplySync: () => void;
  onCancelSync: () => void;
}) {
  const sort = useDragSort(rows, onChange);
  const members = rows.filter((r) => r.kind === 'member').length;
  const addFree = (n: number) =>
    onChange([...rows, ...Array.from({ length: Math.min(n, ROWS_MAX - rows.length) }, () => withKey({ kind: 'free', name: '' }))]);
  // Sortieren nach jeder Datenspalte außer Nr. (zählt nur die Zeilen); Nach Namen wie bisher nach Nachname, Vorname
  const sortable = columns.map((c, i) => ({ c, i })).filter(({ c }) => c.kind === 'field' && c.field !== null && c.field !== 'nr');
  const [by, setBy] = useState<RowSort>('name');
  const [dir, setDir] = useState<'asc' | 'desc'>('asc');
  const sortBy: RowSort = typeof by === 'number' && !sortable.some(({ i }) => i === by) ? 'name' : by;
  function applySort() {
    if (sortBy === 'name') {
      const sorted = sortRowsByName(rows);
      const named = sorted.filter((r) => !(r.kind === 'member' && r.deleted) && r.name.trim() !== '');
      onChange(dir === 'asc' ? sorted : [...named.reverse(), ...sorted.slice(named.length)]);
    } else {
      const c = columns[sortBy];
      onChange(sortRowsBy(rows, (r, i) => sortValue(c, r, i), dir));
    }
  }
  const onlyMembers = groups.every((g) => g === 'members');

  return (
    <section className="card form">
      <h3>Zeilen</h3>
      <p className="muted small">
        {rows.length} {rows.length === 1 ? 'Zeile' : 'Zeilen'}, davon {members}{' '}
        {onlyMembers ? (members === 1 ? 'Mitglied' : 'Mitglieder') : members === 1 ? 'Person aus der App' : 'Personen aus der App'}.
        „Mitglieder aktualisieren“ legt Zeilen für alle an, die in die Liste gehören, und löscht die von Konten, die es nicht mehr gibt oder
        die nicht mehr dazugehören; eigene Zeilen bleiben.
      </p>
      {groupOptions.length > 1 && (
        <fieldset className="list-groups">
          <legend>In die Liste kommen</legend>
          {groupOptions.map((o) => {
            const checked = groups.includes(o.key);
            return (
              <label key={o.key} className="toggle">
                <input
                  type="checkbox"
                  checked={checked}
                  // Mindestens eine Gruppe bleibt gewählt
                  disabled={checked && groups.length === 1}
                  onChange={(e) =>
                    onGroups(groupOptions.map((g) => g.key).filter((k) => (k === o.key ? e.target.checked : groups.includes(k))))
                  }
                />
                <span>{o.label}</span>
              </label>
            );
          })}
        </fieldset>
      )}
      {deleted > 0 && (
        <p className="form-error">
          {deleted === 1 ? 'Eine Zeile gehört' : `${deleted} Zeilen gehören`} zu einem gelöschten Konto. „Mitglieder aktualisieren“ entfernt
          sie.
        </p>
      )}
      <div className="button-row">
        <button type="button" className="btn" disabled={busy} onClick={onSync}>
          Mitglieder aktualisieren
        </button>
        <button type="button" className="btn btn-secondary" disabled={rows.length >= ROWS_MAX} onClick={() => addFree(1)}>
          + Leere Zeile
        </button>
        <button type="button" className="btn btn-secondary" disabled={rows.length >= ROWS_MAX} onClick={() => addFree(5)}>
          + 5 leere Zeilen
        </button>
      </div>
      <div className="field-row list-sort">
        <label className="field">
          <span>Sortieren nach</span>
          <select value={String(sortBy)} onChange={(e) => setBy(e.target.value === 'name' ? 'name' : Number(e.target.value))}>
            <option value="name">Name (Nachname, Vorname)</option>
            {sortable.map(({ c, i }) => (
              <option key={i} value={i}>
                {columnLabel(c) || FIELD_LABELS[c.field as ListField]}
              </option>
            ))}
          </select>
        </label>
        <label className="field narrow">
          <span>Richtung</span>
          <select value={dir} onChange={(e) => setDir(e.target.value as 'asc' | 'desc')}>
            <option value="asc">aufsteigend</option>
            <option value="desc">absteigend</option>
          </select>
        </label>
        <button type="button" className="btn btn-secondary" disabled={rows.length < 2} onClick={applySort}>
          Sortieren
        </button>
      </div>

      {sync && (
        <div className="card list-sync" role="dialog" aria-label="Mitglieder aktualisieren">
          {sync.added.length > 0 && (
            <p>
              <strong>
                {sync.added.length} {sync.added.length === 1 ? 'Zeile kommt' : 'Zeilen kommen'} dazu:
              </strong>{' '}
              {sync.added.map((a) => a.name).join(', ')}
            </p>
          )}
          {sync.removed.length > 0 && (
            <p>
              <strong>
                {sync.removed.length} {sync.removed.length === 1 ? 'Zeile wird' : 'Zeilen werden'} gelöscht:
              </strong>{' '}
              {sync.removed.map((r) => r.name ?? 'gelöschtes Konto').join(', ')}
            </p>
          )}
          <div className="button-row">
            <button type="button" className="btn" disabled={busy} onClick={onApplySync}>
              Übernehmen
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={onCancelSync}>
              Abbrechen
            </button>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p className="muted small">Noch keine Zeilen.</p>
      ) : (
        <ol className="card-list list-rows">
          {rows.map((r, i) => (
            <li key={r.key} {...sort.itemProps(i, 'card row list-row')}>
              {rows.length > 1 && sort.handle(i, r.name || `Zeile ${i + 1}`)}
              <span className="list-row-nr">{i + 1}</span>
              <div className="row-main">
                {r.kind === 'free' ? (
                  <input
                    type="text"
                    maxLength={100}
                    value={r.name}
                    placeholder="leer (zum Eintragen auf Papier)"
                    aria-label={`Zeile ${i + 1}: Name`}
                    onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))}
                  />
                ) : r.deleted ? (
                  <span className="muted">(Konto gelöscht)</span>
                ) : (
                  <span>{r.name}</span>
                )}
              </div>
              <button
                type="button"
                className="btn btn-small btn-secondary"
                aria-label={`Zeile ${i + 1} entfernen`}
                onClick={() => onChange(rows.filter((_, j) => j !== i))}
              >
                ✕
              </button>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

/* ------------------------------------------------------------------ */

/**
 * Gedrehte Überschrift (45°, wie in Tabellenkalkulationen): in schmalen Spalten außer Nr., damit auch längere
 * Überschriften wie „Anzahl Kinder“ über eine schmale Spalte zum Ankreuzen passen. Wie dort ist die Zelle im Kopf mit
 * gekippt (Parallelogramm mit schrägen Linien), damit keine Linie den Text kreuzt.
 */
const rotated = (c: ListColumn): boolean => c.width === 'narrow' && c.field !== 'nr' && columnLabel(c).trim() !== '';
/**
 * Abstände der gedrehten Überschrift im Kopf (Millimeter): Sie beginnt 3 mm über der Unterkante (.list-th-label), oben
 * bleibt etwas Luft bis zur Linie.
 */
const ROTATED_BOTTOM_MM = 3;
const ROTATED_TOP_MM = 1.5;
/** Druckbreite auf A4 mit 12 mm Rand (@page in PrintPortal) */
const PAGE_WIDTH_MM = { portrait: 186, landscape: 273 };
/** CSS-Pixel je Millimeter (96 dpi, in allen Browsern fest) */
const PX_PER_MM = 96 / 25.4;

/**
 * Kopf ab der Spalte vor der ersten gedrehten bis zum Ende der Tabelle, als eine einzige Zelle: Chrome schneidet beim
 * Drucken alles ab, was über eine Zelle im Kopf hinausragt (der Kopf steht auf jeder Seite), die gekippten Felder ragen
 * aber oben um die Höhe des Kopfs nach rechts. In dieser Zelle bleibt alles innerhalb; gerade Spalten zeichnet sie mit.
 * Maße in CSS-Pixeln wie die Spaltenbreiten; die Grafik wird auf die tatsächliche Breite der Zelle gestreckt (der
 * Browser verteilt Bruchteile eines Pixels auf die Spalten). Die gekippten Felder sind fertig berechnete Polygone, keine
 * SVG-Transformation: Die lässt Chrome im Druck im wiederholten Tabellenkopf weg. Die senkrechten Rahmen darunter liegen
 * mit ihrer Mitte 0,5 px links der Spaltenkante (im PDF nachgemessen), dort beginnen auch die schrägen Linien. Vor der
 * ersten gedrehten Spalte füllt ein Dreieck die Lücke zur geraden Spalte davor; die äußeren Linien sind der Rahmen der
 * Zelle.
 */
function SlantedHead({ cols, widths, spare, height }: { cols: ListColumn[]; widths: number[]; spare: number; height: number }) {
  const h = height;
  const x = widths.reduce((acc, w) => [...acc, acc[acc.length - 1] + w], [0]);
  const total = x[x.length - 1] + spare;
  const pct = (v: number) => `${(v / total) * 100}%`;
  // Linien und gekippte Felder beginnen auf der Mitte des senkrechten Rahmens darunter
  const e = (i: number) => x[i] - 0.5;
  // Wie weit der gekippte Kopf der gedrehten Spalten davor oben in eine gerade Spalte ragt (an ihrer linken Kante)
  const cover = (i: number) => {
    const r = cols.slice(0, i).map(rotated).lastIndexOf(true);
    return r < 0 ? 0 : Math.max(0, h - (x[i] - x[r + 1]));
  };
  const line = { stroke: '#555', strokeWidth: 1, vectorEffect: 'non-scaling-stroke' as const };
  const color = brand.themeColor;
  return (
    <div className="list-th-slanted-inner">
      <svg viewBox={`0 0 ${total} ${h}`} preserveAspectRatio="none" width="100%" height="100%" aria-hidden="true">
        {cols.map((c, i) => !rotated(c) && <rect key={i} x={x[i]} y={0} width={widths[i]} height={h} fill={color} />)}
        {cols.map((c, i) => !rotated(c) && i > 0 && !rotated(cols[i - 1]) && <line key={i} x1={e(i)} y1={0} x2={e(i)} y2={h} {...line} />)}
        {/* Rechter Rand, wenn hinter der letzten (geraden) Spalte noch Platz für den gekippten Kopf ist */}
        {spare > 0 && !rotated(cols[cols.length - 1]) && <line x1={e(cols.length)} y1={0} x2={e(cols.length)} y2={h} {...line} />}
        {cols.map(
          (c, i) =>
            rotated(c) &&
            !(i > 0 && rotated(cols[i - 1])) && (
              <polygon key={i} points={`${e(i) - 1},0 ${e(i) + h},0 ${e(i)},${h} ${e(i) - 1},${h}`} fill={color} />
            ),
        )}
        {cols.map(
          (c, i) => rotated(c) && <polygon key={i} points={`${e(i)},${h} ${e(i + 1)},${h} ${e(i + 1) + h},0 ${e(i) + h},0`} fill={color} />,
        )}
        {cols.map(
          (c, i) =>
            rotated(c) && (
              <g key={i}>
                <line x1={e(i)} y1={h} x2={e(i) + h} y2={0} {...line} />
                {!(cols[i + 1] && rotated(cols[i + 1])) && <line x1={e(i + 1)} y1={h} x2={e(i + 1) + h} y2={0} {...line} />}
              </g>
            ),
        )}
      </svg>
      {cols.map((c, i) =>
        rotated(c) ? (
          <span key={i} className="list-th-label" style={{ left: `calc(${pct((x[i] + x[i + 1]) / 2)} + 3mm)` }}>
            {columnLabel(c)}
          </span>
        ) : (
          <StraightLabel key={i} left={pct(x[i])} width={pct(widths[i])} cover={cover(i)} height={h}>
            {columnLabel(c)}
          </StraightLabel>
        ),
      )}
    </div>
  );
}

/** Zeilenhöhe der Überschriften in der Kopfzeile (fett, 10,5 pt, Zeilenabstand 1,2) */
const HEAD_LINE_PX = 17;
/** Innenabstand links und rechts der Überschriften (wie die Kopfzellen, styles.css) */
const STRAIGHT_PAD_PX = 6;

/**
 * Überschrift einer geraden Spalte im gekippten Kopf. Ragt ein gekippter Kopf davor oben hinein (cover > 0), weicht der
 * Text der schrägen Kante aus: ein unsichtbares Dreieck links (shape-outside), um das der Text fließt. Er beginnt auf
 * halber Höhe wie in den anderen Kopfzellen, tiefer nur, wo die Zeile neben der Kante erst Platz hat.
 */
function StraightLabel({
  left,
  width,
  cover,
  height,
  children,
}: {
  left: string;
  width: string;
  cover: number;
  height: number;
  children: string;
}) {
  if (cover <= 0)
    return (
      <span className="list-th-straight" style={{ left, width }}>
        {children}
      </span>
    );
  const top = Math.max(0, Math.round(height / 2 - HEAD_LINE_PX / 2));
  // Bis zur Höhe des Textanfangs ist die Kante schon um so viel nach links gewandert; dazu der Innenabstand links
  const shape = Math.max(0, cover - top - STRAIGHT_PAD_PX);
  return (
    <span className="list-th-straight list-th-straight-under" style={{ left, width, paddingTop: `${top}px` }}>
      {shape > 0 && <span className="list-th-shape" style={{ width: `${shape}px`, height: `${shape}px` }} />}
      {children}
    </span>
  );
}

/** Felder mit Namen: Diese Spalten brechen nicht um (columnWidths). */
const NAME_FIELDS = new Set<ListField>(['name', 'first_name', 'last_name']);
/** Zur Textbreite: Innenabstand der Zelle (2 × 6 px), Rahmen und etwas Luft für Rundungen beim Messen */
const CELL_EXTRA_PX = 16;

let measureCanvas: HTMLCanvasElement | null = null;

/** Breite des längsten Texts in CSS-Pixeln */
function textWidth(texts: string[], font: string): number {
  measureCanvas ??= document.createElement('canvas');
  const ctx = measureCanvas.getContext('2d');
  if (!ctx) return 0;
  ctx.font = font;
  return Math.max(0, ...texts.map((t) => ctx.measureText(t).width));
}

/**
 * Schrift der Liste zum Messen (Schrift der App): normal für die Zellen, fett für die Überschriften, beide 10,5 pt =
 * 14 px. Ist sie noch nicht geladen, misst die Zeichenfläche mit einer Ersatzschrift: dann nach dem Laden noch einmal.
 */
function useListFonts(): { cell: string; head: string } {
  const family = getComputedStyle(document.body).fontFamily;
  const cell = `400 14px ${family}`;
  const head = `700 14px ${family}`;
  const [, setLoaded] = useState(false);
  useEffect(() => {
    let active = true;
    Promise.all([document.fonts?.load(cell), document.fonts?.load(head)])
      .then(() => active && setLoaded(true))
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [cell, head]);
  return { cell, head };
}

/**
 * Die Tabelle, wie sie gedruckt wird: Titelbalken in der Farbe der Kompanie, Kopfzeile ebenso (weiß auf Farbe, schmale
 * Spalten mit gedrehter Überschrift), darunter die Zeilen mit dünnen Rahmen, abwechselnd leicht hinterlegt; der
 * Tabellenkopf steht auf jeder Seite.
 */
function PrintSheet({ draft }: { draft: Draft }) {
  const cols = draft.columns;
  const fonts = useListFonts();
  // Höhe des Kopfs aus der längsten gedrehten Überschrift: Sie läuft um 45° nach oben (Länge / √2), dazu die halbe
  // Zeilenhöhe (Zeilenabstand 1) an ihrem oberen Ende und die Abstände unten und oben. In ganzen Pixeln: Sonst rundet
  // der Browser die Zeile und unter den gekippten Feldern bleibt ein Spalt.
  const labels = cols.filter(rotated).map(columnLabel);
  const rise = (textWidth(labels, fonts.head) + 7) / Math.SQRT2;
  const headMm = rise / PX_PER_MM + ROTATED_BOTTOM_MM + ROTATED_TOP_MM;
  const headPx = labels.length > 0 ? Math.round(Math.min(50, Math.max(12, headMm)) * PX_PER_MM) : 0;
  // Der gekippte Kopf beginnt eine Spalte vor der ersten gedrehten: Deren Fläche und die gerade davor überlappen sich so
  // in einer Grafik (zwei Flächen nebeneinander zeigen in manchen PDF-Anzeigen eine helle Naht).
  const firstRotated = cols.findIndex(rotated);
  const slantFrom = firstRotated < 0 ? -1 : Math.max(0, firstRotated - 1);
  const lastRotated = cols.map(rotated).lastIndexOf(true);
  const pageWidth = PAGE_WIDTH_MM[draft.orientation];
  // Namen brechen nicht um: Ihre Spalten sind mindestens so breit wie der längste Name (Schrift der Zellen, 10,5 pt).
  const minimums = cols.map((c) =>
    c.kind === 'field' && c.field !== null && NAME_FIELDS.has(c.field)
      ? Math.ceil(
          textWidth(
            draft.rows.map((r, i) => cellText(c, r, i)),
            fonts.cell,
          ),
        ) + CELL_EXTRA_PX
      : 0,
  );
  // Breite „automatisch“: so breit wie der längste Inhalt oder die Überschrift (Kästchen: wie ein Zeichen in 13 pt)
  const natural = cols.map((c) =>
    c.width === 'auto'
      ? Math.ceil(
          Math.max(
            textWidth(
              draft.rows.map((r, i) => (c.kind === 'blank' ? '' : cellText(c, r, i))),
              fonts.cell,
            ),
            textWidth([columnLabel(c)], fonts.head),
            c.kind === 'blank' && c.checkbox ? 18 : 0,
          ),
        ) + CELL_EXTRA_PX
      : null,
  );
  // Spaltenbreiten in ganzen Pixeln: Der Browser rastet die senkrechten Rahmen auf ganze Pixel ein, die gekippten Felder
  // nicht; nur so beginnen die schrägen Linien genau über den senkrechten. Der gekippte Kopf der letzten gedrehten
  // Spalte ragt oben um die Höhe des Kopfs nach rechts hinaus: Reichen die Spalten dahinter dafür nicht, kommt eine leere
  // Spalte ohne Rahmen ans Ende, damit die Tabelle den Platz mitrechnet (hängt von den Breiten ab, daher in Runden).
  let sparePx = 0;
  let widths: number[] = [];
  for (let round = 0; round < 4; round++) {
    widths = columnWidths(
      cols.map((c) => WIDTH_WEIGHT[c.width]),
      minimums,
      Math.floor(pageWidth * PX_PER_MM) - sparePx,
      natural,
    );
    const tail = widths.slice(lastRotated + 1).reduce((a, b) => a + b, 0);
    const spare = lastRotated < 0 ? 0 : Math.max(0, headPx - tail);
    if (spare === sparePx) break;
    sparePx = spare;
  }
  return (
    <div className="list-sheet" style={{ ['--list-color' as string]: brand.themeColor, width: `${pageWidth}mm` }}>
      <header className="list-sheet-head">
        <img src={logoUrl} alt="" className="list-sheet-logo" />
        <div className="list-sheet-heading">
          <h1 className="list-sheet-title">{draft.title || 'Ohne Titel'}</h1>
          {draft.subtitle.trim() !== '' && <p className="list-sheet-subtitle">{draft.subtitle}</p>}
        </div>
      </header>
      <div className="list-sheet-meta">
        <span>{brand.name}</span>
        <span>{metaText(draft)}</span>
      </div>
      <table className="list-table">
        <colgroup>
          {widths.map((w, i) => (
            <col key={i} style={{ width: `${w}px` }} />
          ))}
          {sparePx > 0 && <col style={{ width: `${sparePx}px` }} />}
        </colgroup>
        <thead>
          <tr style={headPx ? { height: `${headPx}px` } : undefined}>
            {cols.map((c, i) => (slantFrom < 0 || i < slantFrom) && <th key={i}>{columnLabel(c)}</th>)}
            {slantFrom >= 0 && (
              <th
                colSpan={cols.length - slantFrom + (sparePx > 0 ? 1 : 0)}
                className={[
                  'list-th-slanted',
                  !rotated(cols[slantFrom]) ? 'list-th-slanted-left' : '',
                  sparePx === 0 ? 'list-th-slanted-right' : '',
                ].join(' ')}
              >
                <SlantedHead cols={cols.slice(slantFrom)} widths={widths.slice(slantFrom)} spare={sparePx} height={headPx} />
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {draft.rows.map((r, i) => (
            <tr key={r.key}>
              {cols.map((c, j) => (
                <td
                  key={j}
                  className={
                    c.kind === 'blank' ? 'list-blank' : c.field === 'nr' || typeof cell(c, r, i) === 'number' ? 'list-num' : undefined
                  }
                >
                  {c.kind === 'blank' && c.checkbox ? '☐' : cellText(c, r, i)}
                </td>
              ))}
              {sparePx > 0 && <td className="list-spare" aria-hidden="true" />}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Für den Druck liegt die Tabelle direkt unter <body>; beim Drucken ist nur sie zu sehen (styles.css, body.list-printing).
 * Ausrichtung und Seitenzahlen kommen als @page-Regel dazu, der Titel der Liste als Titel der Seite (Name der PDF-Datei).
 */
function PrintPortal({ draft }: { draft: Draft }) {
  const [root, setRoot] = useState<HTMLElement | null>(null);
  const style = useRef<HTMLStyleElement | null>(null);
  useEffect(() => {
    const el = document.createElement('div');
    el.className = 'list-print-root';
    document.body.appendChild(el);
    document.body.classList.add('list-printing');
    const s = document.createElement('style');
    document.head.appendChild(s);
    style.current = s;
    setRoot(el);
    return () => {
      el.remove();
      s.remove();
      document.body.classList.remove('list-printing');
    };
  }, []);
  // Name der PDF-Datei: Browser schlagen beim Speichern den Titel der Seite vor, solange die Liste offen ist der der Liste.
  useEffect(() => {
    const before = document.title;
    return () => {
      document.title = before;
    };
  }, []);
  useEffect(() => {
    document.title = listFileBase(draft.title) || 'Liste';
  }, [draft.title]);
  useEffect(() => {
    // Seitenzahlen unten rechts nur beim Drucken einer Liste (die Regel gibt es nur, solange die Liste offen ist)
    if (style.current)
      style.current.textContent = `@page { size: A4 ${draft.orientation}; margin: 12mm; @bottom-right { content: 'Seite ' counter(page) ' von ' counter(pages); font-size: 8pt; } }`;
  }, [draft.orientation, root]);
  return root ? createPortal(<PrintSheet draft={draft} />, root) : null;
}
