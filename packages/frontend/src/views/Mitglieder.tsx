import { useMemo, useRef, useState } from 'react';
import { api, config, type DirectoryBirthday, type DirectoryEntry, type DirectoryFormerEntry } from '../api';
import brand from '../generated/brand';
import { formatYearOrDate, groupCounts } from '../format';
import { MemberAvatar, preloadAvatar, ZoomableAvatar } from '../memberPhoto';
import { preloadBadge, RankStack } from '../RankBadge';
import { TitleBadges } from '../TitleBadges';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import {
  DEFAULT_MEMBER_SORT,
  defaultDir,
  dirLabel,
  listName,
  browseOrder,
  loadMemberOrder,
  MEMBER_SORT_LABELS,
  orderFor,
  saveMemberOrder,
  setBrowseOrder,
  sortMembers,
  type BrowseEntry,
  type MemberOrder,
  type MemberSort,
} from '../memberSort';
import { navigate, redirect } from '../router';
import { neighbors, usePrefetchChain, useSwipeDrag } from '../swipe';
import { OfficesView, PersonOffices, useOffices } from './Aemter';

/** Nach Namen oder Eintritt; Punkte stehen in dieser Liste nicht. */
const SORTS: MemberSort[] = ['last-name', 'first-name', 'joined'];
/** Ehemalige zusätzlich nach Austritt (wie unter dem Namen: Datum oder nur das Jahr). */
const FORMER_SORTS: MemberSort[] = [...SORTS, 'left'];

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

/** Eintrag der Liste bzw. der Ehemaligen, wie ihn die Seite einer Person zeigt. */
type Person = DirectoryEntry & Partial<DirectoryFormerEntry>;

/**
 * Reihenfolge zum Blättern auf der Seite einer Person: die der Liste, aus der sie geöffnet wurde (mit Suche und
 * Sortierung). Sonst (Link, Neustart, aus den Ämtern) die ganze Liste bzw. die Ehemaligen ohne Suche in der gemerkten
 * Sortierung, wie sie beim Öffnen erscheint. Steht die Person in keiner Liste, gibt es nichts zu blättern.
 */
function useDirectoryOrder(userId: number, list: DirectoryEntry[] | null, former: DirectoryFormerEntry[] | null): BrowseEntry[] {
  const stored = browseOrder('directory', userId) ?? browseOrder('directory-former', userId);
  return useMemo(() => {
    if (stored) return stored;
    const isFormer = !!former?.some((e) => e.id === userId);
    const source: Person[] = isFormer ? (former ?? []) : list?.some((e) => e.id === userId) ? list : [];
    return sortMembers(source, orderFor(loadMemberOrder(), isFormer ? FORMER_SORTS : SORTS)).map(({ id, name }) => ({ id, name }));
  }, [stored, list, former, userId]);
}

/**
 * Kopf der Seite einer Person: Foto, Name, bei Ehemaligen Eintritt und Ausscheiden, sonst Geburtstag und Eintritt
 * (soweit sie sie zeigt), Titel und Dienstgrad. peek: als Nachbar beim Wischen (nur zum Ansehen, eigene Klasse für den
 * Namen, Foto ohne Vergrößern).
 */
function PersonHead({ name, m, f, peek = false }: { name: string; m?: DirectoryEntry; f?: DirectoryFormerEntry; peek?: boolean }) {
  return (
    <div className="member-page-head">
      {m &&
        (peek ? (
          <MemberAvatar url={m.photo_url} name={m.name} size="large" />
        ) : (
          <ZoomableAvatar url={m.photo_url} name={m.name} size="large" />
        ))}
      <h2 className={`${peek ? 'member-peek-name' : 'member-name'} member-name-centered`}>{name}</h2>
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
  );
}

/** Kopf, solange die Liste noch lädt: der Name (aus der Reihenfolge) schon da, Platzhalter fürs Foto. */
function PersonHeadSkeleton({ name, peek = false }: { name?: string; peek?: boolean }) {
  return (
    <div className="member-page-head">
      <span className="skeleton-box" style={{ width: 128, height: 128, borderRadius: '50%' }} />
      <h2 className={`${peek ? 'member-peek-name' : 'member-name'} member-name-centered`}>
        {name ?? <span className="skeleton-text" style={{ width: '12ch' }} />}
      </h2>
    </div>
  );
}

/** Zurück und die Pfeile mit der Position („3 / 25“), auf der Seite und beim Nachbarn daneben. */
function PageNav({
  position,
  total,
  onStep,
}: {
  position: number;
  total: number;
  /** Ohne: beim Nachbarn daneben (nur zum Ansehen) */
  onStep?: (step: -1 | 1) => void;
}) {
  return (
    <div className="member-nav">
      <button type="button" className="btn btn-link back" onClick={onStep ? back : undefined}>
        ‹ Zurück
      </button>
      {position >= 1 && total > 1 && (
        <span className="member-step">
          <button
            type="button"
            className="btn btn-link"
            disabled={position === 1}
            onClick={onStep && (() => onStep(-1))}
            aria-label={onStep && 'Vorherige Person'}
          >
            ‹
          </button>
          <span className="muted small">
            {position} / {total}
          </span>
          <button
            type="button"
            className="btn btn-link"
            disabled={position === total}
            onClick={onStep && (() => onStep(1))}
            aria-label={onStep && 'Nächste Person'}
          >
            ›
          </button>
        </span>
      )}
    </div>
  );
}

/**
 * Seite einer Person (Issue #112): Foto, Name, Dienstgrad und Titel wie in der Liste (bei Ehemaligen dazu Eintritt und Ausscheiden,
 * bei Mitgliedern Geburtstag und Eintritt, soweit sie sie zeigen), darunter direkt ihre Ämter als Zeitstrahl. Ohne
 * Mitgliederliste (nur Ämter) kommt der Name aus den Ämtern.
 * Wischen (oder die Pfeile) blättert wie in der Verwaltung durch die Liste, aus der die Person geöffnet wurde, ohne
 * Verlaufseintrag: „‹ Zurück“ führt weiter zur Liste. Die App lädt dabei Fotos und Abzeichen aller Personen der Liste
 * nacheinander vor; die Angaben selbst stehen schon in der Liste.
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
  // Nur für den Namen ohne Mitgliederliste; die Ämter darunter laden selbst.
  const officeData = useOffices(offices && !directory);
  const findPerson = (id: number | null) => {
    const f = former.data?.find((e) => e.id === id);
    return { f, m: list.data?.find((e) => e.id === id) ?? f };
  };
  const { f, m } = findPerson(userId);
  const name =
    m?.name ?? officeData.data?.offices.flatMap((o) => o.terms.flatMap((t) => t.holders)).find((h) => h.user_id === userId)?.name;

  const order = useDirectoryOrder(userId, list.data, former.data);
  const ids = useMemo(() => order.map((e) => e.id), [order]);
  const nameOf = (id: number | null) => findPerson(id).m?.name ?? order.find((e) => e.id === id)?.name;
  const { prev, next, index } = neighbors(ids, userId);
  const track = useRef<HTMLDivElement>(null);
  const swipe = useSwipeDrag(track, {
    canPrev: prev !== null,
    canNext: next !== null,
    onCommit: (step) => {
      const other = step === 1 ? next : prev;
      if (other !== null) redirect(`mitglieder/${other}`);
    },
  });
  usePrefetchChain(
    ids,
    index,
    (id: number) => {
      const p = findPerson(id).m;
      return Promise.all([preloadAvatar(p?.photo_url), preloadBadge(p?.rank_badge_url, 'medium')]);
    },
    list.data ?? former.data,
  );

  const peek = (id: number | null, side: 'prev' | 'next', position: number) => {
    if (!swipe.peeking || id === null) return null;
    const other = findPerson(id);
    const otherName = nameOf(id);
    return (
      <div className={`member-peek member-peek-${side}`} aria-hidden="true" ref={(el) => el?.setAttribute('inert', '')}>
        <PageNav position={position} total={order.length} />
        {other.m && otherName !== undefined ? (
          <PersonHead name={otherName} m={other.m} f={other.f} peek />
        ) : (
          <PersonHeadSkeleton name={otherName} peek />
        )}
      </div>
    );
  };

  const waiting = (list.loading && !list.data) || (former.loading && !former.data) || (officeData.loading && !officeData.data);
  return (
    <div className="member-page" {...swipe.handlers}>
      <div className={`member-track${swipe.peeking ? ' peeking' : ''}`} ref={track}>
        {peek(prev, 'prev', index)}
        {peek(next, 'next', index + 2)}
        <PageNav position={index + 1} total={order.length} onStep={swipe.slide} />
        {name === undefined && waiting ? (
          <div role="status" data-loading="">
            <span className="visually-hidden">Lädt …</span>
            <PersonHeadSkeleton name={nameOf(userId)} />
          </div>
        ) : list.error && !list.data ? (
          <ErrorBox message={list.error} onRetry={list.reload} />
        ) : name === undefined ? (
          <Empty>Diese Person steht nicht (mehr) in der Liste.</Empty>
        ) : (
          <>
            <PersonHead name={name} m={m} f={f} />
            {offices && (
              <section>
                <h3 className="section-title">{sec.offices}</h3>
                <PersonOffices userId={userId} empty={`Keine ${sec.offices} eingetragen.`} />
              </section>
            )}
          </>
        )}
      </div>
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
  const sorts = former ? FORMER_SORTS : SORTS;
  const order: MemberOrder = sorts.includes(stored.sort) ? stored : { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
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

  // Auf der Seite der Person blättert man in dieser Reihenfolge (mit Suche und Sortierung) weiter.
  function open(id: number) {
    setBrowseOrder(former ? 'directory-former' : 'directory', list);
    navigate(`mitglieder/${id}`);
  }

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
            {sorts.map((s) => (
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
              <button type="button" className="row-title member-row-open" onClick={() => open(m.id)}>
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
