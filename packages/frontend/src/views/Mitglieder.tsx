import { useMemo, useState } from 'react';
import { api } from '../api';
import { MemberAvatar } from '../memberPhoto';
import { RankStack } from '../RankBadge';
import { TitleBadges } from '../TitleBadges';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import {
  DEFAULT_MEMBER_SORT,
  defaultDir,
  dirLabel,
  listName,
  loadMemberOrder,
  MEMBER_SORT_LABELS,
  saveMemberOrder,
  sortMembers,
  type MemberOrder,
  type MemberSort,
} from '../memberSort';

/** Nach Namen; Punkte stehen in dieser Liste nicht. */
const SORTS: MemberSort[] = ['last-name', 'first-name'];

/**
 * Mitgliederliste für alle Mitglieder und Anwärter (Bereich directory): Namen, Fotos, Dienstgrade und Titel, mit Suche
 * und Sortierung wie in der Verwaltung. Keine Rollen, Punkte oder Strafen und keine Seite je Person.
 */
export default function Mitglieder() {
  const { data, error, loading, reload } = useAsync(() => api.directory(), [], 'directory');
  const [query, setQuery] = useState('');
  const [stored, setStored] = useState<MemberOrder>(loadMemberOrder);
  // Gemerkt ist vielleicht die Sortierung nach Punkten aus der Verwaltung: hier dann nach Nachname.
  const order: MemberOrder = SORTS.includes(stored.sort) ? stored : { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
  const changeOrder = (next: MemberOrder) => {
    setStored(next);
    saveMemberOrder(next);
  };
  const list = useMemo(
    () =>
      sortMembers(
        (data ?? []).filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())),
        order,
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, query, order.sort, order.dir],
  );

  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;

  const total = data?.length ?? 0;
  return (
    <div className="directory">
      <p className="member-counts small muted" role="status">
        {total === 1 ? '1 Person' : `${total} Personen`}
      </p>
      <input
        className="search"
        type="search"
        placeholder="Mitglied suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Mitglied suchen"
      />
      <div className="field-row member-sort">
        {/* Kein umschließendes <label>: dessen Text enthielte sonst alle Optionen. */}
        <div className="field">
          <span id="directory-sort-label">Sortierung</span>
          <select
            aria-labelledby="directory-sort-label"
            value={order.sort}
            onChange={(e) => {
              const sort = e.target.value as MemberSort;
              changeOrder({ sort, dir: defaultDir(sort) });
            }}
          >
            {SORTS.map((s) => (
              <option key={s} value={s}>
                {MEMBER_SORT_LABELS[s]}
              </option>
            ))}
          </select>
        </div>
        <div className="field member-sort-dir">
          <span id="directory-sort-dir-label">Reihenfolge</span>
          <button
            type="button"
            className="btn btn-secondary"
            aria-labelledby="directory-sort-dir-label directory-sort-dir-value"
            title="Reihenfolge umkehren"
            onClick={() => changeOrder({ ...order, dir: order.dir === 'asc' ? 'desc' : 'asc' })}
          >
            <span id="directory-sort-dir-value">{dirLabel(order.sort, order.dir)}</span>
          </button>
        </div>
      </div>
      <ul className="card-list">
        {list.map((m) => (
          <li key={m.id} className="card row member-row">
            <MemberAvatar url={m.photo_url} name={m.name} size="medium" />
            <div className="row-main">
              <div className="row-title">
                {listName(m, order.sort)} <TitleBadges titles={m.titles} />
              </div>
            </div>
            {/* Dienstgrad rechts, gegenüber dem Foto; ohne Dienstgrad bleibt die Spalte leer (Text in allen Zeilen mittig). */}
            {m.rank_title !== undefined && (
              <div className="member-row-rank">{m.rank_title && <RankStack url={m.rank_badge_url} title={m.rank_title} />}</div>
            )}
          </li>
        ))}
        {list.length === 0 && <Empty>Niemand gefunden.</Empty>}
      </ul>
    </div>
  );
}
