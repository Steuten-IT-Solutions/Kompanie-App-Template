import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { api, type ListColumn, type ListField, type ListRow, type ListSyncResult, type PrintList, type PrintListInput } from '../api';
import { useDragSort } from '../dragSort';
import { formatDate, formatEuro, todayYmd } from '../format';
import brand from '../generated/brand';
import logoUrl from '../generated/logo-small.webp';
import { csvFileName, listFileBase, toCsv } from '../lists';
import { sortRowsByName, splitName } from '../memberSort';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

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
  points: sec.points,
  penalties: `${sec.penalties} (offen)`,
};

const WIDTH_LABELS: Record<ListColumn['width'], string> = { narrow: 'schmal', normal: 'normal', wide: 'breit' };
/** Anteil an der Breite der Tabelle */
const WIDTH_WEIGHT: Record<ListColumn['width'], number> = { narrow: 1, normal: 2.5, wide: 5 };

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
  if (Array.isArray(v)) return v.join(', ');
  // Offene Strafen in Euro (CSV: als Zahl)
  if (typeof v === 'number') return c.field === 'penalties' ? v / 100 : v;
  return typeof v === 'string' ? v : '';
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
  const { data, error, loading, reload } = useAsync(api.lists, [], 'lists');
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
  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  return <ListEditor list={data!} onSaved={setData} onToast={onToast} />;
}

interface Draft {
  title: string;
  subtitle: string;
  date: string;
  orientation: 'portrait' | 'landscape';
  columns: ListColumn[];
  rows: DraftRow[];
}

const toDraft = (l: PrintList): Draft => ({
  title: l.title,
  subtitle: l.subtitle ?? '',
  date: l.date ?? '',
  orientation: l.orientation,
  columns: l.columns,
  rows: l.rows.map(withKey),
});

const toInput = (d: Draft): PrintListInput => ({
  title: d.title.trim(),
  subtitle: d.subtitle.trim(),
  date: d.date || null,
  orientation: d.orientation,
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
          <button className="btn" onClick={() => window.print()}>
            Drucken / PDF
          </button>
          <button className="btn btn-secondary" onClick={() => downloadCsv(draft)}>
            CSV herunterladen
          </button>
        </div>
        <p className="muted small">
          Die Liste enthält personenbezogene Daten. Bitte nach Gebrauch vernichten bzw. löschen. Als PDF speichern: im Druckdialog „Als PDF
          speichern“ wählen.
        </p>
        <div className="list-preview" aria-label="Vorschau">
          <PrintSheet draft={draft} />
        </div>
      </section>

      <PrintPortal draft={draft} />
    </div>
  );
}

function downloadCsv(d: Draft) {
  const header = d.columns.map((c) => columnLabel(c));
  const rows = d.rows.map((r, i) => d.columns.map((c) => cell(c, r, i)));
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
              { kind: 'field', field, label: '', width: field === 'nr' ? 'narrow' : 'normal', checkbox: false, available: true },
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

function RowEditor({
  rows,
  deleted,
  busy,
  sync,
  onChange,
  onSync,
  onApplySync,
  onCancelSync,
}: {
  rows: DraftRow[];
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

  return (
    <section className="card form">
      <h3>Zeilen</h3>
      <p className="muted small">
        {rows.length} {rows.length === 1 ? 'Zeile' : 'Zeilen'}, davon {members} {members === 1 ? 'Mitglied' : 'Mitglieder'}. „Mitglieder
        aktualisieren“ legt Zeilen für alle Mitglieder an und löscht die von Konten, die es nicht mehr gibt oder die kein Mitglied mehr
        sind; eigene Zeilen bleiben.
      </p>
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
        <button type="button" className="btn btn-secondary" disabled={rows.length < 2} onClick={() => onChange(sortRowsByName(rows))}>
          Nach Namen sortieren
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
/** Ungefähr: Millimeter je Zeichen in fetter Schrift, um 45° gedreht (Höhe) */
const ROTATED_MM_PER_CHAR = 1.8;
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
  const line = { stroke: '#555', strokeWidth: 1, vectorEffect: 'non-scaling-stroke' as const };
  const color = brand.themeColor;
  return (
    <div className="list-th-slanted-inner">
      <svg viewBox={`0 0 ${total} ${h}`} preserveAspectRatio="none" width="100%" height="100%" aria-hidden="true">
        {cols.map((c, i) => !rotated(c) && <rect key={i} x={x[i]} y={0} width={widths[i]} height={h} fill={color} />)}
        {cols.map((c, i) => !rotated(c) && i > 0 && !rotated(cols[i - 1]) && <line key={i} x1={e(i)} y1={0} x2={e(i)} y2={h} {...line} />)}
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
          <span key={i} className="list-th-straight" style={{ left: pct(x[i]), width: pct(widths[i]) }}>
            {columnLabel(c)}
          </span>
        ),
      )}
    </div>
  );
}

/**
 * Die Tabelle, wie sie gedruckt wird: Titelbalken in der Farbe der Kompanie, Kopfzeile ebenso (weiß auf Farbe, schmale
 * Spalten mit gedrehter Überschrift), darunter die Zeilen mit dünnen Rahmen, abwechselnd leicht hinterlegt; der
 * Tabellenkopf steht auf jeder Seite.
 */
function PrintSheet({ draft }: { draft: Draft }) {
  const cols = draft.columns;
  const total = cols.reduce((s, c) => s + WIDTH_WEIGHT[c.width], 0) || 1;
  const longest = Math.max(0, ...cols.filter(rotated).map((c) => columnLabel(c).length));
  // Höhe des Kopfs in ganzen Pixeln: Sonst rundet der Browser die Zeile und unter den gekippten Feldern bleibt ein Spalt.
  const headPx = longest > 0 ? Math.round(Math.min(50, Math.max(12, longest * ROTATED_MM_PER_CHAR + 8)) * PX_PER_MM) : 0;
  // Der gekippte Kopf der letzten Spalte ragt oben um die Höhe des Kopfs nach rechts hinaus: dafür eine leere Spalte
  // ohne Rahmen ans Ende, damit die Tabelle den Platz mitrechnet.
  const last = cols[cols.length - 1];
  const sparePx = last && rotated(last) ? headPx : 0;
  // Der gekippte Kopf beginnt eine Spalte vor der ersten gedrehten: Deren Fläche und die gerade davor überlappen sich so
  // in einer Grafik (zwei Flächen nebeneinander zeigen in manchen PDF-Anzeigen eine helle Naht).
  const firstRotated = cols.findIndex(rotated);
  const slantFrom = firstRotated < 0 ? -1 : Math.max(0, firstRotated - 1);
  const pageWidth = PAGE_WIDTH_MM[draft.orientation];
  // Spaltenbreiten in ganzen Pixeln: Der Browser rastet die senkrechten Rahmen auf ganze Pixel ein, die gekippten Felder
  // nicht; nur so beginnen die schrägen Linien genau über den senkrechten. Die letzte Spalte nimmt den Rundungsrest.
  const available = Math.floor(pageWidth * PX_PER_MM) - sparePx;
  const widths = cols.map((c) => Math.floor((available * WIDTH_WEIGHT[c.width]) / total));
  if (widths.length > 0) widths[widths.length - 1] += available - widths.reduce((a, b) => a + b, 0);
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
        <span>{[draft.date ? formatDate(draft.date) : null, `Stand: ${formatDate(todayYmd())}`].filter(Boolean).join(' · ')}</span>
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
