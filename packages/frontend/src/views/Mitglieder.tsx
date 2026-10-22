import { useMemo, useState } from 'react';
import { api, config, type DirectoryBirthday, type DirectoryEntry, type DirectoryFormerEntry } from '../api';
import brand from '../generated/brand';
import { formatYearOrDate, groupCounts } from '../format';
import { ZoomableAvatar } from '../memberPhoto';
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
import { navigate } from '../router';
import { OfficesView, PersonOffices, useOffices } from './Aemter';

/** Nach Namen oder Eintritt; Punkte stehen in dieser Liste nicht. */
const SORTS: MemberSort[] = ['last-name', 'first-name', 'joined'];

const sec = brand.labels.sections;
const SECTION_KEY = 'kompanie-members-section';

function storedSection(): string | null {
  try {
    return localStorage.getItem(SECTION_KEY);
  } catch {
    return null;
  }
}

type Section = 'liste' | 'aemter' | 'ehemalige';

function storeSection(section: Section): void {
  try {
    localStorage.setItem(SECTION_KEY, section);
  } catch {
    // ohne Speicher: beim nächsten Öffnen wieder die Liste
  }
}

/**
 * Eintritt und Ausscheiden einer ehemaligen Person, wie eingetragen (ganzes Datum oder nur das Jahr): „Gründungsmitglied ·
 * 01.04.1985 – 03.05.2010“, „1985–2010“, „Eintritt 1985“, „bis 2010“; leer ohne Angaben. Ältere Server: nur die Jahre.
 */
export function formerYears(
  f: Partial<Pick<DirectoryFormerEntry, 'founder' | 'joined_year' | 'left_year' | 'joined_on' | 'left_on'>>,
): string {
  const show = (v: string | undefined) => (v ? formatYearOrDate(v) : '');
  const joined = show(f.joined_on || f.joined_year);
  const left = show(f.left_on || f.left_year);
  const years =
    joined && left
      ? joined.length > 4 || left.length > 4
        ? `${joined} – ${left}`
        : `${joined}–${left}`
      : joined
        ? `Eintritt ${joined}`
        : left
          ? `bis ${left}`
          : '';
  return [f.founder ? 'Gründungsmitglied' : '', years].filter(Boolean).join(' · ');
}

/** Eintritt eines Mitglieds, wie eingetragen: „01.04.2005“ oder „2005“ (ältere Server: nur das Jahr); leer ohne. */
export const joinedText = (m: { join_year?: number | null; join_date?: string | null }): string =>
  m.join_date ? formatYearOrDate(m.join_date) : m.join_year ? String(m.join_year) : '';

const dayMonth = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long' });
const fullDate = new Intl.DateTimeFormat('de-DE', { day: 'numeric', month: 'long', year: 'numeric' });

/**
 * Geburtstag und Eintritt auf der Seite einer Person, soweit sie sie zeigt: „Geburtstag 12. März 1988 (38 Jahre) ·
 * Eintritt 01.04.2005“ (bzw. „Eintritt 2005“ mit nur dem Jahr); ohne „Alter anzeigen“ nur Tag und Monat. Leer ohne Angaben.
 */
export function personFacts(m: { birthday?: DirectoryBirthday | null; join_year?: number | null; join_date?: string | null }): string {
  const b = m.birthday;
  // Ohne Jahr: 2000 als Maßstab (Schaltjahr, damit der 29.02. bleibt)
  const date = b && (b.year ? fullDate : dayMonth).format(new Date(b.year ?? 2000, b.month - 1, b.day));
  const birthday = b ? `Geburtstag ${date}${b.age !== null ? ` (${b.age} Jahre)` : ''}` : '';
  const joined = joinedText(m);
  return [birthday, joined ? `Eintritt ${joined}` : ''].filter(Boolean).join(' · ');
}

/**
 * Tab „Mitglieder“ (öffentlich, Issue #112): Mitgliederliste (Bereich directory), Zeitstrahl der Ämter (Bereich
 * offices) und die Ehemaligen (mit der Mitgliederliste), umschaltbar; der zuletzt gewählte Abschnitt bleibt auf dem
 * Gerät gemerkt. Ist nur der Bereich Ämter an, steht nur dieser da. mitglieder/<id>: Seite einer Person mit ihren
 * Ämtern als Zeitstrahl (ältere Adresse mitglieder/aemter/<id>).
 */
export default function Mitglieder({ path = [] }: { path?: string[] }) {
  const directory = !!config?.features.directory;
  const offices = !!config?.features.offices;
  const id = /^\d+$/.test(path[0] ?? '') ? path[0] : path[0] === 'aemter' && /^\d+$/.test(path[1] ?? '') ? path[1] : null;
  const person = id !== null ? Number(id) : null;
  const wanted = ['liste', 'aemter', 'ehemalige'].includes(path[0] ?? '') ? path[0] : storedSection();
  // Auf der Seite einer ehemaligen Person ist „Ehemalige“ markiert, nicht die Mitgliederliste. Die Seite der Person
  // nutzt dieselben Daten mit (meist schon aus der Liste geladen). Ein anderer Pfad baut die Ansicht neu auf (viewKey).
  const showsFormer = directory && person !== null;
  const former = useAsync<DirectoryFormerEntry[] | null>(
    () => (showsFormer ? api.directoryFormer() : Promise.resolve(null)),
    [],
    showsFormer ? 'directory-former' : undefined,
  );
  const section: Section = !directory
    ? 'aemter'
    : person !== null
      ? former.data?.some((e) => e.id === person)
        ? 'ehemalige'
        : 'liste'
      : (offices && wanted === 'aemter') || wanted === 'ehemalige'
        ? (wanted as Section)
        : 'liste';
  const open = (next: Section) => {
    storeSection(next);
    navigate(`mitglieder/${next}`);
  };
  const tab = (key: Section, label: string) => (
    <button className={section === key ? 'active' : ''} aria-pressed={section === key} onClick={() => open(key)}>
      {label}
    </button>
  );
  return (
    <div>
      {directory && (
        <nav className="segmented members-switch" aria-label="Abschnitt">
          {tab('liste', sec.directory)}
          {offices && tab('aemter', sec.offices)}
          {tab('ehemalige', 'Ehemalige')}
        </nav>
      )}
      {person !== null ? (
        <MemberPage userId={person} directory={directory} offices={offices} former={former} />
      ) : section === 'liste' ? (
        <MemberList key="liste" />
      ) : section === 'ehemalige' ? (
        <MemberList key="ehemalige" former />
      ) : (
        <OfficesView />
      )}
    </div>
  );
}

/** Zurück, wo die Person geöffnet wurde (Liste oder Zeitstrahl); ohne Verlauf (direkt geöffnet) zur Liste. */
function back(): void {
  if (window.history.length > 1) window.history.back();
  else navigate('mitglieder/liste');
}

/**
 * Seite einer Person (Issue #112): Foto, Name, Dienstgrad und Titel wie in der Liste (bei Ehemaligen dazu Eintritt und Ausscheiden,
 * bei Mitgliedern Geburtstag und Eintritt, soweit sie sie zeigen), darunter direkt ihre Ämter als Zeitstrahl. Ohne
 * Mitgliederliste (nur Ämter) kommt der Name aus den Ämtern.
 */
function MemberPage({
  userId,
  directory,
  offices,
  former,
}: {
  userId: number;
  directory: boolean;
  offices: boolean;
  /** Ehemalige, geladen von Mitglieder (dort für die Markierung „Ehemalige“ gebraucht) */
  former: ReturnType<typeof useAsync<DirectoryFormerEntry[] | null>>;
}) {
  const list = useAsync(() => (directory ? api.directory() : Promise.resolve(null)), [], directory ? 'directory' : undefined);
  const officeData = useOffices(offices);
  const f = former.data?.find((e) => e.id === userId);
  const m: DirectoryEntry | undefined = list.data?.find((e) => e.id === userId) ?? f;
  const name =
    m?.name ?? officeData.data?.offices.flatMap((o) => o.terms.flatMap((t) => t.holders)).find((h) => h.user_id === userId)?.name;
  if ((list.loading && !list.data) || (former.loading && !former.data) || (officeData.loading && !officeData.data)) return <Loading />;
  if (list.error && !list.data) return <ErrorBox message={list.error} onRetry={list.reload} />;
  return (
    <div className="member-page">
      <button type="button" className="btn btn-link back" onClick={back}>
        ‹ Zurück
      </button>
      {name === undefined ? (
        <Empty>Diese Person steht nicht (mehr) in der Liste.</Empty>
      ) : (
        <>
          <div className="member-page-head">
            {m && <ZoomableAvatar url={m.photo_url} name={m.name} size="large" />}
            <h2 className="member-name member-name-centered">{name}</h2>
            {f && <p className="small muted">{['Ehemalig', formerYears(f)].filter(Boolean).join(' · ')}</p>}
            {m && !f && personFacts(m) && <p className="small muted member-page-facts">{personFacts(m)}</p>}
            {m?.titles && m.titles.length > 0 && (
              <p className="member-page-titles">
                <TitleBadges titles={m.titles} />
              </p>
            )}
            {m?.rank_title && (
              <div className="member-page-rank">
                <RankStack url={m.rank_badge_url} title={m.rank_title} />
              </div>
            )}
          </div>
          {offices && (
            <section>
              <h3 className="section-title">{sec.offices}</h3>
              <PersonOffices userId={userId} empty={`Keine ${sec.offices} eingetragen.`} />
            </section>
          )}
        </>
      )}
    </div>
  );
}

/**
 * Mitgliederliste (Bereich directory): Namen, Fotos, Dienstgrade und Titel, mit Suche und Sortierung wie in der
 * Verwaltung. Keine Rollen, Punkte oder Strafen. Antippen öffnet die Seite der Person (mit ihren Ämtern).
 * former: dieselbe Liste für die Ehemaligen, je Person dazu Gründungsmitglied, Eintritt und Ausscheiden (kein Grund).
 */
function MemberList({ former = false }: { former?: boolean }) {
  const { data, error, loading, reload } = useAsync<(DirectoryEntry & Partial<DirectoryFormerEntry>)[]>(
    () => (former ? api.directoryFormer() : api.directory()),
    [],
    former ? 'directory-former' : 'directory',
  );
  // Zähler je Gruppe wie in der Verwaltung; ohne (ältere Server, Fehler) bleibt die Gesamtzahl. Ehemalige ohne Gruppen.
  const counts = useAsync(() => (former ? Promise.resolve([]) : api.directoryCounts()), [], former ? undefined : 'directory-counts');
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
  const groups = groupCounts(config?.roles ?? [], new Map((counts.data ?? []).map((c) => [c.group, c.count])));
  return (
    <div className="directory">
      <p className="member-counts small muted" role="status">
        {groups.length > 0
          ? groups.join(' · ')
          : total === 1
            ? `1 ${former ? 'ehemalige ' : ''}Person`
            : `${total} ${former ? 'ehemalige ' : ''}Personen`}
      </p>
      <input
        className="search"
        type="search"
        placeholder={former ? 'Ehemalige suchen …' : 'Mitglied suchen …'}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label={former ? 'Ehemalige suchen' : 'Mitglied suchen'}
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
          <li key={m.id} className="card row member-row member-row-link">
            <ZoomableAvatar url={m.photo_url} name={m.name} size="medium" />
            <div className="row-main">
              {/* Die ganze Zeile öffnet die Seite der Person; das Foto vergrößert weiterhin (liegt darüber). */}
              <button type="button" className="row-title member-row-open" onClick={() => navigate(`mitglieder/${m.id}`)}>
                {listName(m, order.sort)} <TitleBadges titles={m.titles} />
              </button>
              {former && formerYears(m) && <div className="row-sub">{formerYears(m)}</div>}
              {/* Nach Eintritt sortiert: das Jahr, soweit die Person es zeigt (Ehemalige haben ihre Jahre oben). */}
              {!former && order.sort === 'joined' && joinedText(m) && <div className="row-sub">Eintritt {joinedText(m)}</div>}
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
