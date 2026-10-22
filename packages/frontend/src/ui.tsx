import { Component, lazy, useCallback, useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react';
import type { MemberSummary, PersonName } from './api';
import { cacheKey as keyOf, DATA_UPDATED, dataCache } from './dataCache';
import { noteError } from './lastError';

/**
 * Lädt Daten asynchron; reload() lädt neu.
 * Mit `key` zeigt die Ansicht beim nächsten Öffnen sofort die zuletzt geladenen Daten und
 * aktualisiert sie im Hintergrund (z. B. beim Wechsel zwischen den Tabs; siehe dataCache.ts).
 * `revision` zählt hoch, wenn die frischen Daten anders sind als die sofort gezeigten aus dem Speicher: Formulare, die
 * ihre Felder einmal aus den Daten übernehmen, damit als `key` neu aufbauen.
 * Gab es während des Ladens eine eigene Änderung, ist die Antwort vielleicht veraltet und überschriebe den gespeicherten
 * Stand (setData): Dann einmal neu laden.
 */
export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = [], key?: string) {
  const cacheKey = key === undefined ? null : keyOf(key, deps);
  const cached = () => (cacheKey !== null && dataCache.has(cacheKey) ? dataCache.get<T>(cacheKey) : null);
  const [data, setDataState] = useState<T | null>(cached);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);
  const [revision, setRevision] = useState(0);

  const setData = useCallback(
    (d: T | null) => {
      if (cacheKey !== null && d !== null) dataCache.set(cacheKey, d);
      setDataState(d);
    },
    [cacheKey],
  );

  useEffect(() => {
    let alive = true;
    setLoading(true);
    const hit = cached();
    if (hit !== null) setDataState(hit);
    const load = (retry: boolean) => {
      const since = dataCache.generation();
      fn()
        .then((d) => {
          if (alive && retry && since !== dataCache.generation()) return load(false);
          if (alive) {
            if (hit !== null && JSON.stringify(hit) !== JSON.stringify(d)) setRevision((r) => r + 1);
            setDataState(d);
            setError(null);
            setLoading(false);
          }
          // Auch wenn die Ansicht schon geschlossen ist: Beim nächsten Öffnen sind die Daten dann aktuell.
          if (cacheKey !== null && d !== null) dataCache.set(cacheKey, d, since);
        })
        .catch((e: Error) => {
          if (alive) {
            setError(e.message);
            setLoading(false);
          }
        });
    };
    load(true);
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  // Der Service Worker hat im Hintergrund neue Daten geholt (Issue #108): neu laden, die Antwort kommt dann aus seinem
  // Speicher.
  useEffect(() => {
    window.addEventListener(DATA_UPDATED, reload);
    return () => window.removeEventListener(DATA_UPDATED, reload);
  }, [reload]);
  return { data, error, loading, reload, setData, revision };
}

// Lädt …, mit dem Gerüst der Seite, sobald die App es kennt (skeleton.tsx)
export { Loading } from './skeleton';

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="error" role="alert">
      <p>{message}</p>
      {onRetry && (
        <button className="btn btn-secondary" onClick={onRetry}>
          Erneut versuchen
        </button>
      )}
    </div>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="empty">{children}</p>;
}

export function StatCard({ label, value, tone }: { label: string; value: string; tone?: 'good' | 'warn' | 'neutral' }) {
  return (
    <div className={`stat stat-${tone ?? 'neutral'}`}>
      <div className="stat-value">{value}</div>
      <div className="stat-label">{label}</div>
    </div>
  );
}

/** Mehrfachauswahl von Mitgliedern mit Suche. */
export function MemberPicker({
  members,
  selected,
  onChange,
  note,
}: {
  members: MemberSummary[];
  selected: number[];
  onChange: (ids: number[]) => void;
  /** kurzer Zusatz hinter dem Namen, z. B. der Punktefaktor „×0,5“ */
  note?: (m: MemberSummary) => string | null;
}) {
  const [query, setQuery] = useState('');
  const filtered = useMemo(() => members.filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())), [members, query]);
  const toggle = (id: number) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
  const allFilteredSelected = filtered.length > 0 && filtered.every((m) => selected.includes(m.id));
  const toggleAll = () => {
    const ids = filtered.map((m) => m.id);
    onChange(allFilteredSelected ? selected.filter((id) => !ids.includes(id)) : Array.from(new Set([...selected, ...ids])));
  };

  return (
    <div className="picker">
      <div className="picker-head">
        <input
          type="search"
          placeholder="Mitglied suchen …"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label="Mitglied suchen"
        />
        <button type="button" className="btn btn-link" onClick={toggleAll}>
          {allFilteredSelected ? 'Keine' : 'Alle'}
        </button>
      </div>
      <ul className="picker-list">
        {filtered.map((m) => (
          <li key={m.id}>
            <label>
              <input type="checkbox" checked={selected.includes(m.id)} onChange={() => toggle(m.id)} />
              <span>{m.name}</span>
              {note?.(m) && <span className="badge badge-role">{note(m)}</span>}
            </label>
          </li>
        ))}
        {filtered.length === 0 && <li className="muted">Niemand gefunden.</li>}
      </ul>
      <div className="picker-count">{selected.length} ausgewählt</div>
    </div>
  );
}

/**
 * Fängt Fehler beim Anzeigen einer Ansicht ab: Statt einer leeren Seite erscheint ein Hinweis,
 * Kopfzeile und Navigation bleiben bedienbar. Mit key={Ansicht} setzt ein Wechsel ihn zurück.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  componentDidCatch(error: unknown) {
    console.error(error);
    noteError(error instanceof Error ? error.message : 'Diese Ansicht konnte nicht angezeigt werden.');
  }

  render() {
    return this.state.failed ? (
      <ErrorBox message="Diese Ansicht konnte nicht angezeigt werden." onRetry={() => this.setState({ failed: false })} />
    ) : (
      this.props.children
    );
  }
}

const CHUNK_RELOAD = 'kompanie-chunk-reload';
/** Höchstens ein Neuladen je Minute: schützt vor einer Schleife, wenn ein Teil dauerhaft fehlt. */
const CHUNK_RELOAD_PAUSE_MS = 60_000;

/**
 * Ein Programmteil ließ sich nicht laden. Nach einem Update fehlen die Teile der alten Version: Dann einmal neu laden
 * und die aktuelle Version holen. true, wenn die Seite neu lädt.
 */
export function reloadAfterChunkError(): boolean {
  if (!navigator.onLine) return false;
  try {
    const last = Number(sessionStorage.getItem(CHUNK_RELOAD) ?? 0);
    if (Date.now() - last < CHUNK_RELOAD_PAUSE_MS) return false;
    sessionStorage.setItem(CHUNK_RELOAD, String(Date.now()));
  } catch {
    // Ohne sessionStorage ließe sich eine Schleife nicht erkennen: lieber die Meldung zeigen.
    return false;
  }
  window.location.reload();
  return true;
}

/**
 * Selten gebrauchte Ansicht erst bei Bedarf laden (kleinerer App-Start).
 * Fehlt ein Programmteil (z. B. nach einem Update), lädt die App einmal neu (reloadAfterChunkError). Schlägt es danach
 * wieder fehl (z. B. offline ohne gespeicherte Fassung), zeigt die ErrorBoundary eine Meldung.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function lazyView<T extends ComponentType<any>>(load: () => Promise<{ default: T }>) {
  return lazy(() =>
    load().catch((err: unknown) => {
      if (reloadAfterChunkError()) return new Promise<never>(() => undefined);
      throw err;
    }),
  );
}

/**
 * Vor- und Nachname nebeneinander; nur der Vorname ist Pflicht (z. B. Funktionskonten ohne Nachnamen). required=false,
 * wo leere Zeilen übersprungen werden (mehrere anlegen).
 */
export function NameFields({
  value,
  onChange,
  required = true,
}: {
  value: PersonName;
  onChange: (v: PersonName) => void;
  required?: boolean;
}) {
  return (
    <div className="field-row">
      <label className="field">
        <span>Vorname</span>
        <input
          type="text"
          autoComplete="off"
          value={value.first_name}
          onChange={(e) => onChange({ ...value, first_name: e.target.value })}
          required={required}
        />
      </label>
      <label className="field">
        <span>Nachname</span>
        <input type="text" autoComplete="off" value={value.last_name} onChange={(e) => onChange({ ...value, last_name: e.target.value })} />
      </label>
    </div>
  );
}

export const emptyName = (): PersonName => ({ first_name: '', last_name: '' });

/** Für die API: ohne Leerzeichen am Rand. */
export const trimName = (n: PersonName): PersonName => ({ first_name: n.first_name.trim(), last_name: n.last_name.trim() });
