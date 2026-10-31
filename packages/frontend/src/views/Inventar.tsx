import { useMemo, useState, type FormEvent } from 'react';
import { api, type InventoryInput, type InventoryItem, type InventoryLogEntry } from '../api';
import brand from '../generated/brand';
import { formatShortTime } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';

// Inventar (Issue #6): Liste nach Material gruppiert (Summe über alle Orte, je Einheit), aufklappbar zu den Einträgen je
// Ort; anlegen, ändern, −/+, umlagern, löschen; Verlauf je Eintrag und gesamt. Nur mit manage_inventory.

const sec = brand.labels.sections;

const numberFmt = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 3 });

/** Menge mit Einheit, z. B. „2,5 kg“. */
export function formatQuantity(quantity: number, unit: string | null): string {
  const n = numberFmt.format(quantity);
  return unit ? `${n} ${unit}` : n;
}

const key = (s: string | null) => (s ?? '').trim().toLocaleLowerCase('de-DE');

export interface InventoryGroup {
  key: string;
  title: string;
  items: InventoryItem[];
  /** Summen je Einheit (Einheiten ohne Unterschied in Groß- und Kleinschreibung), in der Reihenfolge des ersten Auftretens */
  sums: { unit: string | null; quantity: number }[];
}

/** Gruppiert nach Bezeichnung (ohne Unterschied in Groß- und Kleinschreibung); verschiedene Einheiten werden nicht addiert. */
export function groupInventory(items: InventoryItem[]): InventoryGroup[] {
  const groups = new Map<string, InventoryGroup>();
  for (const item of items) {
    const k = key(item.title);
    let g = groups.get(k);
    if (!g) {
      g = { key: k, title: item.title.trim(), items: [], sums: [] };
      groups.set(k, g);
    }
    g.items.push(item);
    const sum = g.sums.find((s) => key(s.unit) === key(item.unit));
    // In Tausendsteln rechnen: keine Rundungsfehler wie 0,1 + 0,2.
    if (sum) sum.quantity = (Math.round(sum.quantity * 1000) + Math.round(item.quantity * 1000)) / 1000;
    else g.sums.push({ unit: item.unit, quantity: item.quantity });
  }
  const list = [...groups.values()];
  list.sort((a, b) => a.title.localeCompare(b.title, 'de'));
  for (const g of list) g.items.sort((a, b) => a.location.localeCompare(b.location, 'de'));
  return list;
}

export const sumText = (g: InventoryGroup) => g.sums.map((s) => formatQuantity(s.quantity, s.unit)).join(' · ');

/** Vorschläge ohne Dubletten in anderer Schreibweise. */
function distinct(values: (string | null)[]): string[] {
  const seen = new Map<string, string>();
  for (const v of values) if (v && !seen.has(key(v))) seen.set(key(v), v);
  return [...seen.values()].sort((a, b) => a.localeCompare(b, 'de'));
}

const LOCATIONS = 'inventory-locations';
const UNITS = 'inventory-units';
const CATEGORIES = 'inventory-categories';

export default function Inventar({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  if (path[0] === 'verlauf') {
    const id = /^\d+$/.test(path[1] ?? '') ? Number(path[1]) : undefined;
    return <History key={id ?? 'alle'} itemId={id} />;
  }
  return <InventoryList onToast={onToast} />;
}

function InventoryList({ onToast }: { onToast: (msg: string) => void }) {
  const list = useAsync(sources.inventory.load, sources.inventory.deps, sources.inventory.key);
  const locations = useAsync(sources.inventoryLocations.load, sources.inventoryLocations.deps, sources.inventoryLocations.key);
  const [query, setQuery] = useState('');
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<Set<string>>(new Set());

  const items = list.data ?? [];
  const groups = useMemo(() => groupInventory(items), [items]);
  const shown = useMemo(() => {
    const q = key(query);
    if (!q) return groups;
    return groups.filter((g) => g.items.some((i) => [i.title, i.location, i.category, i.note].some((v) => key(v).includes(q))));
  }, [groups, query]);

  const reload = () => {
    list.reload();
    locations.reload();
  };
  const toggle = (k: string) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(k)) next.delete(k);
      else next.add(k);
      return next;
    });

  return (
    <div className="inventory-page">
      <datalist id={LOCATIONS}>
        {(locations.data ?? distinct(items.map((i) => i.location))).map((l) => (
          <option key={l} value={l} />
        ))}
      </datalist>
      <datalist id={UNITS}>
        {distinct(['Stück', ...items.map((i) => i.unit)]).map((u) => (
          <option key={u} value={u} />
        ))}
      </datalist>
      <datalist id={CATEGORIES}>
        {distinct(items.map((i) => i.category)).map((c) => (
          <option key={c} value={c} />
        ))}
      </datalist>

      <div className="button-row">
        <button className="btn inventory-add" onClick={() => setCreating((c) => !c)} aria-expanded={creating}>
          + Eintrag anlegen
        </button>
        <button className="btn btn-secondary inventory-history-all" onClick={() => navigate('inventar/verlauf')}>
          Gesamter Verlauf
        </button>
      </div>

      {creating && (
        <ItemForm
          heading="Neuer Eintrag"
          submitLabel="Anlegen"
          onCancel={() => setCreating(false)}
          onSubmit={async (data) => {
            const item = await api.createInventory(data);
            setCreating(false);
            setOpen((prev) => new Set(prev).add(key(item.title)));
            onToast('Eintrag angelegt');
            reload();
          }}
        />
      )}

      <input
        className="search"
        type="search"
        placeholder={`${sec.inventory} durchsuchen …`}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label={`${sec.inventory} durchsuchen`}
      />

      {list.loading && !list.data ? (
        <Loading />
      ) : list.error && !list.data ? (
        <ErrorBox message={list.error} onRetry={list.reload} />
      ) : groups.length === 0 ? (
        <Empty>Noch keine Einträge. Lege den ersten mit „+ Eintrag anlegen“ an.</Empty>
      ) : shown.length === 0 ? (
        <Empty>Nichts gefunden.</Empty>
      ) : (
        <ul className="card-list inventory-groups">
          {shown.map((g) => {
            const expanded = open.has(g.key) || key(query) !== '';
            return (
              <li key={g.key} className="card inventory-group">
                <button className="inventory-group-head" aria-expanded={expanded} onClick={() => toggle(g.key)}>
                  <span className="inventory-group-arrow" aria-hidden="true">
                    ›
                  </span>
                  <span className="inventory-group-main">
                    <span className="inventory-group-title">{g.title}</span>
                    <span className="inventory-group-places">
                      {g.items.length === 1 ? g.items[0].location : `an ${g.items.length} Orten`}
                    </span>
                  </span>
                  <span className="inventory-sum">{sumText(g)}</span>
                </button>
                {expanded && (
                  <ul className="inventory-entries">
                    {g.items.map((item) => (
                      <Entry key={`${item.id}-${item.updated_at}`} item={item} onToast={onToast} onChanged={reload} />
                    ))}
                  </ul>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

type Mode = 'view' | 'edit' | 'move';

function Entry({ item, onToast, onChanged }: { item: InventoryItem; onToast: (msg: string) => void; onChanged: () => void }) {
  const [mode, setMode] = useState<Mode>('view');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, message: string) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onToast(message);
      setMode('view');
      onChanged();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function remove() {
    const reason = window.prompt(`„${item.title}“ in ${item.location} löschen? Grund (optional):`, '');
    if (reason === null) return;
    void run(() => api.deleteInventory(item.id, reason), 'Eintrag gelöscht');
  }

  return (
    <li className="inventory-entry" data-location={item.location}>
      <div className="inventory-entry-head">
        <div className="inventory-entry-main">
          <div className="inventory-location">{item.location}</div>
          {(item.category || item.note) && (
            <div className="muted small inventory-entry-meta">{[item.category, item.note].filter(Boolean).join(' · ')}</div>
          )}
        </div>
        <div className="inventory-qty">
          <button
            className="btn btn-secondary btn-small inventory-minus"
            aria-label={`${item.title} in ${item.location}: eins weniger`}
            disabled={busy || item.quantity < 1}
            onClick={() => run(() => api.updateInventory(item.id, { adjust: -1 }), 'Menge geändert')}
          >
            −
          </button>
          <span className="inventory-qty-value">{formatQuantity(item.quantity, item.unit)}</span>
          <button
            className="btn btn-secondary btn-small inventory-plus"
            aria-label={`${item.title} in ${item.location}: eins mehr`}
            disabled={busy}
            onClick={() => run(() => api.updateInventory(item.id, { adjust: 1 }), 'Menge geändert')}
          >
            +
          </button>
        </div>
      </div>
      {error && <p className="form-error small">{error}</p>}
      {mode === 'view' && (
        <div className="row-actions inventory-actions">
          <button className="btn btn-secondary btn-small" disabled={busy} onClick={() => setMode('move')}>
            Umlagern
          </button>
          <button className="btn btn-secondary btn-small" disabled={busy} onClick={() => setMode('edit')}>
            Bearbeiten
          </button>
          <button className="btn btn-secondary btn-small" onClick={() => navigate(`inventar/verlauf/${item.id}`)}>
            Verlauf
          </button>
          <button className="btn btn-danger btn-small" disabled={busy} onClick={remove}>
            Löschen
          </button>
        </div>
      )}
      {mode === 'edit' && (
        <ItemForm
          heading="Eintrag bearbeiten"
          submitLabel="Speichern"
          item={item}
          onCancel={() => setMode('view')}
          onSubmit={(data) => run(() => api.updateInventory(item.id, data), 'Eintrag gespeichert')}
        />
      )}
      {mode === 'move' && (
        <MoveForm
          item={item}
          onCancel={() => setMode('view')}
          onSubmit={(data) => run(() => api.moveInventory(item.id, data), 'Umgelagert')}
        />
      )}
    </li>
  );
}

const asText = (n: number) => numberFmt.format(n).replace(/\./g, '');

function ItemForm({
  heading,
  submitLabel,
  item,
  onSubmit,
  onCancel,
}: {
  heading: string;
  submitLabel: string;
  item?: InventoryItem;
  onSubmit: (data: InventoryInput) => Promise<unknown>;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState(item?.title ?? '');
  const [quantity, setQuantity] = useState(item ? asText(item.quantity) : '');
  const [unit, setUnit] = useState(item?.unit ?? '');
  const [location, setLocation] = useState(item?.location ?? '');
  const [category, setCategory] = useState(item?.category ?? '');
  const [note, setNote] = useState(item?.note ?? '');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (title.trim() === '') return setError('Bitte eine Bezeichnung eingeben.');
    if (location.trim() === '') return setError('Bitte einen Ort eingeben.');
    setBusy(true);
    try {
      await onSubmit({ title, quantity: quantity.trim() === '' ? 0 : quantity, unit, location, category, note, reason });
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card inventory-form" onSubmit={submit}>
      <h3>{heading}</h3>
      <label className="field">
        <span>Bezeichnung</span>
        <input type="text" maxLength={100} value={title} onChange={(e) => setTitle(e.target.value)} required />
      </label>
      <div className="field-row">
        <label className="field">
          <span>Menge</span>
          <input type="text" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} placeholder="0" />
        </label>
        <label className="field">
          <span>Einheit</span>
          <input type="text" maxLength={20} list={UNITS} value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="z. B. Stück" />
        </label>
      </div>
      <label className="field">
        <span>Ort</span>
        <input type="text" maxLength={100} list={LOCATIONS} value={location} onChange={(e) => setLocation(e.target.value)} required />
      </label>
      <label className="field">
        <span>Kategorie (optional)</span>
        <input type="text" maxLength={100} list={CATEGORIES} value={category} onChange={(e) => setCategory(e.target.value)} />
      </label>
      <label className="field">
        <span>Notiz (optional)</span>
        <textarea rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
      <label className="field">
        <span>Grund (optional, steht im Verlauf)</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>
      {error && <p className="form-error small">{error}</p>}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : submitLabel}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

function MoveForm({
  item,
  onSubmit,
  onCancel,
}: {
  item: InventoryItem;
  onSubmit: (data: { quantity: string; location: string; reason: string }) => Promise<unknown>;
  onCancel: () => void;
}) {
  const [quantity, setQuantity] = useState(asText(item.quantity));
  const [location, setLocation] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await onSubmit({ quantity, location, reason });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card inventory-form inventory-move" onSubmit={submit}>
      <h3>Umlagern</h3>
      <p className="muted small">
        Vorhanden in {item.location}: {formatQuantity(item.quantity, item.unit)}. Gibt es am Zielort schon „{item.title}“ mit derselben
        Einheit, kommt die Menge dazu.
      </p>
      <div className="field-row">
        <label className="field">
          <span>Menge</span>
          <input type="text" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} required />
        </label>
        <label className="field">
          <span>Zielort</span>
          <input type="text" maxLength={100} list={LOCATIONS} value={location} onChange={(e) => setLocation(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Grund (optional)</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || location.trim() === ''}>
          {busy ? 'Lagert um …' : 'Umlagern'}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/* ------------------------------------------------------------------ */

const FIELD_LABELS: Record<string, string> = {
  title: 'Bezeichnung',
  quantity: 'Menge',
  unit: 'Einheit',
  location: 'Ort',
  category: 'Kategorie',
  note: 'Notiz',
};

/** Was ein Verlaufseintrag bedeutet, in Sätzen. */
export function describeLog(e: InventoryLogEntry): string[] {
  const q = (v: string | number | null | undefined) =>
    v === null || v === undefined || v === '' ? '–' : formatQuantity(Number(v), e.unit);
  const text = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? '–' : String(v));
  const c = e.changes;
  switch (e.action) {
    case 'create':
      return [`Angelegt mit ${q(c.quantity?.new)} in ${text(c.location?.new ?? e.location)}`];
    case 'delete':
      return [`Gelöscht (zuletzt ${q(c.quantity?.old)})`];
    case 'move': {
      const m = c.move;
      const lines = m ? [`Umgelagert: ${q(m.quantity)} von ${m.from} nach ${m.to}`] : ['Umgelagert'];
      if (c.quantity) {
        lines.push(
          c.quantity.old === null
            ? `Neu angelegt in ${e.location} mit ${q(c.quantity.new)}`
            : `Menge hier: ${q(c.quantity.old)} → ${q(c.quantity.new)}`,
        );
      }
      if (m?.source_removed && e.item_id === m.source_id) lines.push(`Eintrag in ${m.from} entfällt`);
      return lines;
    }
    default:
      return Object.entries(c)
        .filter(([field]) => field in FIELD_LABELS)
        .map(([field, ch]) => {
          const change = ch as { old: string | number | null; new: string | number | null };
          const fmt = field === 'quantity' ? q : text;
          return `${FIELD_LABELS[field]}: ${fmt(change.old)} → ${fmt(change.new)}`;
        });
  }
}

const ACTION_LABELS: Record<InventoryLogEntry['action'], string> = {
  create: 'Angelegt',
  update: 'Geändert',
  move: 'Umgelagert',
  delete: 'Gelöscht',
};

/** So viele Einträge liefert die API je Seite (InventoryService::LOG_LIMIT). */
const LOG_PAGE = 100;

function History({ itemId }: { itemId?: number }) {
  const log = useAsync(() => api.inventoryLog(itemId), [itemId], 'inventory-log');
  const [older, setOlder] = useState<InventoryLogEntry[]>([]);
  const [more, setMore] = useState<boolean | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);
  const entries = [...(log.data ?? []), ...older];
  const latest = entries[0];
  const hasMore = more ?? (log.data?.length ?? 0) >= LOG_PAGE;

  async function loadMore() {
    const last = entries[entries.length - 1];
    if (!last) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const page = await api.inventoryLog(itemId, last.id);
      setOlder((prev) => [...prev, ...page]);
      setMore(page.length >= LOG_PAGE);
    } catch (err) {
      setMoreError((err as Error).message);
    } finally {
      setLoadingMore(false);
    }
  }
  return (
    <div className="inventory-history">
      <button className="btn btn-link back" onClick={() => navigate('inventar')}>
        ‹ Zurück zum {sec.inventory}
      </button>
      <h2 className="inventory-history-title">
        {itemId === undefined ? 'Gesamter Verlauf' : latest ? `Verlauf: ${latest.title} · ${latest.location}` : 'Verlauf'}
      </h2>
      {log.loading && !log.data ? (
        <Loading shape="text" />
      ) : log.error ? (
        <ErrorBox message={log.error} onRetry={log.reload} />
      ) : entries.length === 0 ? (
        <Empty>Noch keine Änderungen.</Empty>
      ) : (
        <ul className="card-list inventory-log">
          {entries.map((e) => (
            <li key={e.id} className={`card inventory-log-entry inventory-log-${e.action}`}>
              <div className="inventory-log-head">
                <span className="badge badge-role">{ACTION_LABELS[e.action]}</span>
                <span className="muted small">
                  {formatShortTime(e.created_at)} · <span className="inventory-log-user">{e.user_name ?? '–'}</span>
                </span>
              </div>
              {itemId === undefined && (
                <div className="inventory-log-item">
                  {e.title} · {e.location}
                </div>
              )}
              {describeLog(e).map((line) => (
                <p key={line} className="inventory-log-line">
                  {line}
                </p>
              ))}
              {e.reason && <p className="muted small inventory-log-reason">Grund: {e.reason}</p>}
            </li>
          ))}
        </ul>
      )}
      {moreError && <p className="form-error small">{moreError}</p>}
      {hasMore && entries.length > 0 && (
        <button className="btn btn-secondary btn-block inventory-log-more" disabled={loadingMore} onClick={loadMore}>
          {loadingMore ? 'Lädt …' : 'Ältere Einträge laden'}
        </button>
      )}
    </div>
  );
}
