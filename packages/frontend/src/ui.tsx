import { Component, lazy, useCallback, useEffect, useMemo, useState, type ComponentType, type ReactNode } from 'react';
import type { MemberSummary } from './api';

/** Zuletzt geladene Daten je Schlüssel (nur im Speicher; beim Abmelden geleert). */
const asyncCache = new Map<string, unknown>();

export function clearAsyncCache() {
  asyncCache.clear();
}

/**
 * Lädt Daten asynchron; reload() lädt neu.
 * Mit `key` zeigt die Ansicht beim nächsten Öffnen sofort die zuletzt geladenen Daten und
 * aktualisiert sie im Hintergrund (z. B. beim Wechsel zwischen den Tabs).
 */
export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = [], key?: string) {
  const cacheKey = key === undefined ? null : `${key}|${JSON.stringify(deps)}`;
  const cached = () => (cacheKey !== null && asyncCache.has(cacheKey) ? (asyncCache.get(cacheKey) as T) : null);
  const [data, setDataState] = useState<T | null>(cached);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);

  const setData = useCallback(
    (d: T | null) => {
      if (cacheKey !== null && d !== null) asyncCache.set(cacheKey, d);
      setDataState(d);
    },
    [cacheKey],
  );

  useEffect(() => {
    let alive = true;
    setLoading(true);
    const hit = cached();
    if (hit !== null) setDataState(hit);
    fn()
      .then((d) => {
        if (alive) {
          setData(d);
          setError(null);
        }
      })
      .catch((e: Error) => alive && setError(e.message))
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  return { data, error, loading, reload, setData };
}

export function Loading() {
  return (
    <div className="loading" role="status" aria-live="polite">
      <span className="spinner" aria-hidden="true" /> Lädt …
    </div>
  );
}

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

/**
 * Selten gebrauchte Ansicht erst bei Bedarf laden (kleinerer App-Start).
 * Nach einem Update kann ein Programmteil der alten Version fehlen: Dann lädt die App einmal neu
 * und holt die aktuelle Version. Schlägt es danach wieder fehl (z. B. offline), zeigt die
 * ErrorBoundary eine Meldung.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function lazyView<T extends ComponentType<any>>(load: () => Promise<{ default: T }>) {
  return lazy(() =>
    load().then(
      (mod) => {
        try {
          sessionStorage.removeItem(CHUNK_RELOAD);
        } catch {
          // ohne sessionStorage: nichts zu merken
        }
        return mod;
      },
      (err: unknown) => {
        let again = true;
        try {
          again = sessionStorage.getItem(CHUNK_RELOAD) === null;
          sessionStorage.setItem(CHUNK_RELOAD, '1');
        } catch {
          again = false;
        }
        if (again && navigator.onLine) {
          window.location.reload();
          return new Promise<never>(() => undefined);
        }
        throw err;
      },
    ),
  );
}
