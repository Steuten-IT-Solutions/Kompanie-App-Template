import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import type { MemberSummary } from './api';

/** Lädt Daten asynchron; reload() lädt neu. */
export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    let alive = true;
    setLoading(true);
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
  return { data, error, loading, reload };
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
}: {
  members: MemberSummary[];
  selected: number[];
  onChange: (ids: number[]) => void;
}) {
  const [query, setQuery] = useState('');
  const filtered = useMemo(
    () => members.filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())),
    [members, query],
  );
  const toggle = (id: number) =>
    onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
  const allFilteredSelected = filtered.length > 0 && filtered.every((m) => selected.includes(m.id));
  const toggleAll = () => {
    const ids = filtered.map((m) => m.id);
    onChange(
      allFilteredSelected ? selected.filter((id) => !ids.includes(id)) : Array.from(new Set([...selected, ...ids])),
    );
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
            </label>
          </li>
        ))}
        {filtered.length === 0 && <li className="muted">Niemand gefunden.</li>}
      </ul>
      <div className="picker-count">{selected.length} ausgewählt</div>
    </div>
  );
}
