import { useMemo, useState, type FormEvent } from 'react';
import { api, can, type InventoryInput, type InventoryItem, type InventoryLogEntry } from '../api';
import brand from '../generated/brand';
import { formatNumber, formatShortTime } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { promptAsk } from '../ask';
import { IconButton } from '../icons';
import { t } from '../i18n';

// Inventar (Issue #6): Liste nach Material gruppiert (Summe über alle Orte, je Einheit), aufklappbar zu den Einträgen je
// Ort; anlegen, ändern, −/+, umlagern, löschen; Verlauf je Eintrag und gesamt. Sehen mit view_inventory, alles zum
// Ändern nur mit manage_inventory.

const sec = brand.labels.sections;

const numberFmt = { format: (n: number) => formatNumber(n, { maximumFractionDigits: 3 }) };

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
  const editable = can('manage_inventory');

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
        {distinct([t('Stück'), ...items.map((i) => i.unit)]).map((u) => (
          <option key={u} value={u} />
        ))}
      </datalist>
      <datalist id={CATEGORIES}>
        {distinct(items.map((i) => i.category)).map((c) => (
          <option key={c} value={c} />
        ))}
      </datalist>

      <div className="button-row">
        {editable && (
          <button className="btn inventory-add" onClick={() => setCreating((c) => !c)} aria-expanded={creating}>
            {t('+ Eintrag anlegen')}
          </button>
        )}
        <button className="btn btn-secondary inventory-history-all" onClick={() => navigate('inventar/verlauf')}>
          {t('Gesamter Verlauf')}
        </button>
      </div>

      {creating && (
        <ItemForm
          heading={t('Neuer Eintrag')}
          submitLabel={t('Anlegen')}
          onCancel={() => setCreating(false)}
          onSubmit={async (data) => {
            const item = await api.createInventory(data);
            setCreating(false);
            setOpen((prev) => new Set(prev).add(key(item.title)));
            onToast(t('Eintrag angelegt'));
            reload();
          }}
        />
      )}

      <input
        className="search"
        type="search"
        placeholder={t('{inventory} durchsuchen …', { inventory: sec.inventory })}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label={t('{inventory} durchsuchen', { inventory: sec.inventory })}
      />

      {list.loading && !list.data ? (
        <Loading />
      ) : list.error && !list.data ? (
        <ErrorBox message={list.error} onRetry={list.reload} />
      ) : groups.length === 0 ? (
        <Empty>{t('Noch keine Einträge. Lege den ersten mit „+ Eintrag anlegen“ an.')}</Empty>
      ) : shown.length === 0 ? (
        <Empty>{t('Nichts gefunden.')}</Empty>
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
                      {g.items.length === 1 ? g.items[0].location : t('an {count} Orten', { count: g.items.length })}
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
  const editable = can('manage_inventory');
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

  async function remove() {
    const reason = await promptAsk(
      t('„{title}“ in {location} löschen? Grund (optional):', { title: item.title, location: item.location }),
      '',
    );
    if (reason === null) return;
    void run(() => api.deleteInventory(item.id, reason), t('Eintrag gelöscht'));
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
          {editable && (
            <button
              className="btn btn-secondary btn-small inventory-minus"
              aria-label={t('{title} in {location}: eins weniger', { title: item.title, location: item.location })}
              disabled={busy || item.quantity < 1}
              onClick={() => run(() => api.updateInventory(item.id, { adjust: -1 }), t('Menge geändert'))}
            >
              −
            </button>
          )}
          <span className="inventory-qty-value">{formatQuantity(item.quantity, item.unit)}</span>
          {editable && (
            <button
              className="btn btn-secondary btn-small inventory-plus"
              aria-label={t('{title} in {location}: eins mehr', { title: item.title, location: item.location })}
              disabled={busy}
              onClick={() => run(() => api.updateInventory(item.id, { adjust: 1 }), t('Menge geändert'))}
            >
              +
            </button>
          )}
        </div>
      </div>
      {error && <p className="form-error small">{error}</p>}
      {mode === 'view' && (
        <div className="row-actions inventory-actions">
          {editable && (
            <button className="btn btn-secondary btn-small" disabled={busy} onClick={() => setMode('move')}>
              {t('Umlagern')}
            </button>
          )}
          {editable && <IconButton icon="edit" label={t('Bearbeiten')} disabled={busy} onClick={() => setMode('edit')} />}
          <IconButton icon="history" label={t('Verlauf')} onClick={() => navigate(`inventar/verlauf/${item.id}`)} />
          {editable && <IconButton icon="delete" label={t('Löschen')} variant="danger" disabled={busy} onClick={remove} />}
        </div>
      )}
      {mode === 'edit' && (
        <ItemForm
          heading={t('Eintrag bearbeiten')}
          submitLabel={t('Speichern')}
          item={item}
          onCancel={() => setMode('view')}
          onSubmit={(data) => run(() => api.updateInventory(item.id, data), t('Eintrag gespeichert'))}
        />
      )}
      {mode === 'move' && (
        <MoveForm
          item={item}
          onCancel={() => setMode('view')}
          onSubmit={(data) => run(() => api.moveInventory(item.id, data), t('Umgelagert'))}
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
    if (title.trim() === '') return setError(t('Bitte eine Bezeichnung eingeben.'));
    if (location.trim() === '') return setError(t('Bitte einen Ort eingeben.'));
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
        <span>{t('Bezeichnung')}</span>
        <input type="text" maxLength={100} value={title} onChange={(e) => setTitle(e.target.value)} required />
      </label>
      <div className="field-row">
        <label className="field">
          <span>{t('Menge')}</span>
          <input type="text" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} placeholder="0" />
        </label>
        <label className="field">
          <span>{t('Einheit')}</span>
          <input
            type="text"
            maxLength={20}
            list={UNITS}
            value={unit}
            onChange={(e) => setUnit(e.target.value)}
            placeholder={t('z. B. Stück')}
          />
        </label>
      </div>
      <label className="field">
        <span>{t('Ort')}</span>
        <input type="text" maxLength={100} list={LOCATIONS} value={location} onChange={(e) => setLocation(e.target.value)} required />
      </label>
      <label className="field">
        <span>{t('Kategorie (optional)')}</span>
        <input type="text" maxLength={100} list={CATEGORIES} value={category} onChange={(e) => setCategory(e.target.value)} />
      </label>
      <label className="field">
        <span>{t('Notiz (optional)')}</span>
        <textarea rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
      </label>
      <label className="field">
        <span>{t('Grund (optional, steht im Verlauf)')}</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>
      {error && <p className="form-error small">{error}</p>}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? t('Speichert …') : submitLabel}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
          {t('Abbrechen')}
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
      <h3>{t('Umlagern')}</h3>
      <p className="muted small">
        {t('Vorhanden in {location}: {quantity}. Gibt es am Zielort schon „{title}“ mit derselben Einheit, kommt die Menge dazu.', {
          location: item.location,
          quantity: formatQuantity(item.quantity, item.unit),
          title: item.title,
        })}
      </p>
      <div className="field-row">
        <label className="field">
          <span>{t('Menge')}</span>
          <input type="text" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} required />
        </label>
        <label className="field">
          <span>{t('Zielort')}</span>
          <input type="text" maxLength={100} list={LOCATIONS} value={location} onChange={(e) => setLocation(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>{t('Grund (optional)')}</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || location.trim() === ''}>
          {busy ? t('Lagert um …') : t('Umlagern')}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

/* ------------------------------------------------------------------ */

const FIELD_LABELS: Record<string, string> = {
  title: t('Bezeichnung'),
  quantity: t('Menge'),
  unit: t('Einheit'),
  location: t('Ort'),
  category: t('Kategorie'),
  note: t('Notiz'),
};

/** Was ein Verlaufseintrag bedeutet, in Sätzen. */
export function describeLog(e: InventoryLogEntry): string[] {
  const q = (v: string | number | null | undefined) =>
    v === null || v === undefined || v === '' ? '–' : formatQuantity(Number(v), e.unit);
  const text = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? '–' : String(v));
  const c = e.changes;
  switch (e.action) {
    case 'create':
      return [t('Angelegt mit {quantity} in {location}', { quantity: q(c.quantity?.new), location: text(c.location?.new ?? e.location) })];
    case 'delete':
      return [t('Gelöscht (zuletzt {quantity})', { quantity: q(c.quantity?.old) })];
    case 'move': {
      const m = c.move;
      const lines = m
        ? [t('Umgelagert: {quantity} von {from} nach {to}', { quantity: q(m.quantity), from: m.from, to: m.to })]
        : [t('Umgelagert')];
      if (c.quantity) {
        lines.push(
          c.quantity.old === null
            ? t('Neu angelegt in {location} mit {quantity}', { location: e.location, quantity: q(c.quantity.new) })
            : t('Menge hier: {old} → {new}', { old: q(c.quantity.old), new: q(c.quantity.new) }),
        );
      }
      if (m?.source_removed && e.item_id === m.source_id) lines.push(t('Eintrag in {location} entfällt', { location: m.from }));
      return lines;
    }
    default:
      return Object.entries(c)
        .filter(([field]) => field in FIELD_LABELS)
        .map(([field, ch]) => {
          const change = ch as { old: string | number | null; new: string | number | null };
          const fmt = field === 'quantity' ? q : text;
          return t('{field}: {old} → {new}', { field: FIELD_LABELS[field], old: fmt(change.old), new: fmt(change.new) });
        });
  }
}

const ACTION_LABELS: Record<InventoryLogEntry['action'], string> = {
  create: t('Angelegt'),
  update: t('Geändert'),
  move: t('Umgelagert'),
  delete: t('Gelöscht'),
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
        ‹ {t('Zurück zum {inventory}', { inventory: sec.inventory })}
      </button>
      <h2 className="inventory-history-title">
        {itemId === undefined
          ? t('Gesamter Verlauf')
          : latest
            ? t('Verlauf: {title} · {location}', { title: latest.title, location: latest.location })
            : t('Verlauf')}
      </h2>
      {log.loading && !log.data ? (
        <Loading shape="text" />
      ) : log.error ? (
        <ErrorBox message={log.error} onRetry={log.reload} />
      ) : entries.length === 0 ? (
        <Empty>{t('Noch keine Änderungen.')}</Empty>
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
              {e.reason && <p className="muted small inventory-log-reason">{t('Grund: {reason}', { reason: e.reason })}</p>}
            </li>
          ))}
        </ul>
      )}
      {moreError && <p className="form-error small">{moreError}</p>}
      {hasMore && entries.length > 0 && (
        <button className="btn btn-secondary btn-block inventory-log-more" disabled={loadingMore} onClick={loadMore}>
          {loadingMore ? t('Lädt …') : t('Ältere Einträge laden')}
        </button>
      )}
    </div>
  );
}
