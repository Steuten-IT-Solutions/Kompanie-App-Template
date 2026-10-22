import { useEffect, useLayoutEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import {
  api,
  can,
  config,
  levelLabel,
  personFactor,
  roleInfo,
  roleLabels,
  type CatalogItem,
  type CreatedUser,
  type Level,
  type MemberSummary,
} from '../api';
import brand from '../generated/brand';
import { formatDate, formatEuro, formatPoints, parseEuroToCents, groupCounts as countLabels, todayYmd } from '../format';
import { navigate, redirect as redirectTo } from '../router';
import { AdminNav, adminTabs, memberListAllowed, MenuNav } from '../adminNav';
import { emptyName, Empty, ErrorBox, Loading, MemberPicker, NameFields, StatCard, trimName, useAsync } from '../ui';
import Anwesenheit from './Anwesenheit';
import { RankBadge, RankStack } from '../RankBadge';
import { MemberRank, Promotions, RankList, ranksUsePoints } from './DienstgradeVerwalten';
import { installUrl } from '../install';
import { PenaltyRow } from './Strafen';
import { AccountPanel, BulkNewMembers, Credentials, NewAccountRole } from './Konten';
import { TitleBadges } from '../TitleBadges';
import { MemberTitles, TitleList } from './TitelVerwalten';
import { OfficeAdmin } from './AemterVerwalten';
import { ListAdmin } from './ListenVerwalten';
import { PersonOffices } from './Aemter';
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
import { BirthdayFields, birthdayString, canSetBirthdays, emptyBirthday, MemberBirthday, readBirthday } from '../birthdays';
import { MemberJoinYear } from '../joinYear';
import { hasOverrides, MemberRightsPanel } from './Rechte';
import { MemberAvatar, MemberPhotoPanel, PhotoPicker, ZoomableAvatar, type PreparedPhoto } from '../memberPhoto';
import { FormerImport, FormerList, FormerPanel, formerDate, formerSummary } from './Ehemalige';

type Sub =
  | 'mitglieder'
  | 'ehemalige'
  | 'punkte'
  | 'strafe'
  | 'katalog'
  | 'dienstgrade'
  | 'befoerderungen'
  | 'titel'
  | 'aemter'
  | 'anwesenheit'
  | 'listen';

const sec = brand.labels.sections;

/** Bereich an und das Recht dazu (Punkte bzw. Strafen vergeben sind getrennte Rechte). */
const canPoints = (): boolean => !!config?.features.points && can('manage_points');
const canPenalties = (): boolean => !!config?.features.penalties && can('manage_penalties');
const canRanks = (): boolean => !!config?.features.ranks && can('manage_ranks');

export default function Verwaltung({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const [sub, arg] = [path[0] as Sub | 'mitglied' | 'neu' | 'neu-mehrere' | undefined, path[1]];
  const preselect = arg && /^\d+$/.test(arg) ? [Number(arg)] : [];
  // Vorschlag aus einer Anwesenheitsliste: verwaltung/punkte/anwesenheit-<id> bzw. verwaltung/strafe/anwesenheit-<id>
  const fromList = arg && /^anwesenheit-\d+$/.test(arg) ? Number(arg.slice(12)) : null;

  const subs = adminTabs().filter((t) => !t.route);
  const active: Sub = subs.some((t) => t.id === sub) ? (sub as Sub) : 'mitglieder';
  // Ohne Mitgliederliste (z. B. nur Schwarzes Brett): gleich den ersten Bereich öffnen.
  const first = adminTabs()[0];
  const redirect = !memberListAllowed() && !sub && first?.route ? first.route : null;
  useEffect(() => {
    if (redirect) navigate(redirect);
  }, [redirect]);
  if (redirect) return null;

  return (
    <div>
      <AdminNav active={active} />
      {sub === 'mitglied' && arg ? (
        <MemberDetailView base="mitglied" id={Number(arg)} page={path[2]} onToast={onToast} />
      ) : active === 'ehemalige' && arg && /^\d+$/.test(arg) ? (
        <MemberDetailView base="ehemalige" id={Number(arg)} page={path[2]} onToast={onToast} />
      ) : sub === 'neu' && can('create_users') ? (
        <NewMember />
      ) : sub === 'neu-mehrere' && can('create_users') ? (
        <BulkNewMembers canPoints={canPoints()} />
      ) : active === 'ehemalige' && arg === 'import' ? (
        <FormerImport onToast={onToast} />
      ) : active === 'ehemalige' ? (
        <FormerList />
      ) : (active === 'punkte' || active === 'strafe') && fromList !== null ? (
        <FromAttendance key={path.join('/')} listId={fromList} kind={active} onToast={onToast} />
      ) : active === 'punkte' ? (
        <GivePoints key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'strafe' ? (
        <GivePenalty key={path.join('/')} preselect={preselect} onToast={onToast} />
      ) : active === 'katalog' ? (
        <Catalog onToast={onToast} />
      ) : active === 'dienstgrade' ? (
        <RankList onToast={onToast} />
      ) : active === 'befoerderungen' ? (
        <Promotions />
      ) : active === 'titel' ? (
        <TitleList onToast={onToast} />
      ) : active === 'aemter' ? (
        <OfficeAdmin path={path.slice(1)} onToast={onToast} />
      ) : active === 'anwesenheit' ? (
        <Anwesenheit key={arg ?? ''} id={arg && /^\d+$/.test(arg) ? Number(arg) : undefined} onToast={onToast} />
      ) : active === 'listen' ? (
        <ListAdmin path={path.slice(1)} onToast={onToast} />
      ) : (
        <Members />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

/** Stand der Mitgliederliste beim Öffnen eines Mitglieds: Beim Zurückkommen steht die Liste wieder an derselben Stelle. */
type MembersState = {
  query: string;
  role: Level;
  title: number | null;
  showGuests: boolean;
  filtersOpen: boolean;
  scrollY: number;
};
let savedMembers: MembersState | null = null;

function Members() {
  const canPromote = can('promote_users');
  // Nur einmal übernehmen: Wer die Liste danach neu öffnet, fängt wieder oben an.
  const [restore] = useState(() => {
    const s = savedMembers;
    savedMembers = null;
    return s;
  });
  const [showGuests, setShowGuests] = useState(restore?.showGuests ?? false);
  // Filter und Sortierung zunächst eingeklappt; beim Zurückkommen wie verlassen.
  const [filtersOpen, setFiltersOpen] = useState(restore?.filtersOpen ?? false);
  // Filter nach Rolle: Grundrolle oder Zusatzrolle (Issue #66). Gäste lassen sich auch ohne Häkchen wählen.
  const [role, setRole] = useState<Level>(restore?.role ?? '');
  const loadGuests = showGuests || (canPromote && role === 'guest');
  // Rollen nur mit dem Kalender (z. B. Anwärter) immer: Sie stehen in der Liste und zählen im Zähler oben mit.
  const { data, error, loading, reload } = useAsync(() => api.members(loadGuests, true), [loadGuests], 'members');
  // Filter nach Titel (nur, wer Titel sieht)
  const [title, setTitle] = useState<number | null>(restore?.title ?? null);
  const [query, setQuery] = useState(restore?.query ?? '');
  const [stored, setStored] = useState<MemberOrder>(loadMemberOrder);
  // Nach Punkten nur, wer sie sieht, nach Eintritt nur, wer die Jahre pflegt (create_users); sonst nach Nachname (die
  // gemerkte Wahl bleibt).
  const sortOptions = (Object.keys(MEMBER_SORT_LABELS) as MemberSort[]).filter(
    (s) => (s !== 'points' || canPoints()) && (s !== 'joined' || can('create_users')),
  );
  const order: MemberOrder = sortOptions.includes(stored.sort)
    ? stored
    : { sort: DEFAULT_MEMBER_SORT, dir: defaultDir(DEFAULT_MEMBER_SORT) };
  const changeOrder = (next: MemberOrder) => {
    setStored(next);
    saveMemberOrder(next);
  };
  // Gemerkter Titel, den niemand mehr trägt: nicht still weiterfiltern.
  const activeTitle = title !== null && (data ?? []).some((m) => (m.titles ?? []).some((t) => t.id === title)) ? title : null;
  // Hinweis am eingeklappten Bereich, damit ein gesetzter Filter nicht übersehen wird (die Sortierung zählt nicht).
  const filtersActive = role !== '' || activeTitle !== null || showGuests;
  const list = useMemo(
    () =>
      sortMembers(
        (data ?? []).filter(
          (m) =>
            m.name.toLowerCase().includes(query.trim().toLowerCase()) &&
            (role === '' || m.level === role || (m.extra_roles ?? []).includes(role)) &&
            (activeTitle === null || (m.titles ?? []).some((t) => t.id === activeTitle)),
        ),
        order,
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, query, role, activeTitle, order.sort, order.dir],
  );
  // Rollen, die in der Liste vorkommen, dazu (wer Rollen vergibt) Gäste
  const present = new Set((data ?? []).flatMap((m) => [m.level, ...(m.extra_roles ?? [])]));
  if (canPromote) present.add('guest');
  const roleOptions = (config?.roles ?? []).filter((r) => present.has(r.key));
  // Zähler je Gruppe wie in der Anwesenheit: alle mit Rolle (ohne Gäste), unabhängig von Suche und Filter.
  // In umgekehrter Reihenfolge der Rollen (Einstellungen → Rollen, von unten nach oben).
  const groupCounts = useMemo(() => {
    const n = new Map<Level, number>();
    for (const m of data ?? []) if (m.group) n.set(m.group, (n.get(m.group) ?? 0) + 1);
    return countLabels(config?.roles ?? [], n);
  }, [data]);
  // Fotos nur, wenn es welche gibt: sonst bliebe die Liste wie bisher, ohne Spalte nur mit Anfangsbuchstaben.
  const withPhotos = (data ?? []).some((m) => m.photo_url);
  // Titel, die in der Liste vorkommen, alphabetisch
  const titleOptions = [...new Map((data ?? []).flatMap((m) => m.titles ?? []).map((t) => [t.id, t])).values()].sort((a, b) =>
    a.title.localeCompare(b.title, 'de'),
  );

  // Scrollen, sobald die Liste steht (aus dem Speicher sofort, sonst nach dem Laden).
  const pendingScroll = useRef(restore?.scrollY ?? null);
  useLayoutEffect(() => {
    if (pendingScroll.current === null || !data) return;
    window.scrollTo(0, pendingScroll.current);
    pendingScroll.current = null;
  }, [data]);

  function open(id: number) {
    savedMembers = { query, role, title, showGuests, filtersOpen, scrollY: window.scrollY };
    navigate(`verwaltung/mitglied/${id}`);
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      {groupCounts.length > 0 && (
        <p className="member-counts small muted" role="status">
          {groupCounts.join(' · ')}
        </p>
      )}
      {can('create_users') && (
        <div className="button-row add-member">
          <button className="btn" onClick={() => navigate('verwaltung/neu')}>
            + Mitglied anlegen
          </button>
        </div>
      )}
      <input
        className="search"
        type="search"
        placeholder="Mitglied suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Mitglied suchen"
      />
      <button
        type="button"
        className="member-filters-toggle"
        aria-expanded={filtersOpen}
        aria-controls="member-filters"
        onClick={() => setFiltersOpen(!filtersOpen)}
      >
        Filter und Sortierung
        {filtersActive && <span className="badge badge-role">aktiv</span>}
        <span className="member-filters-arrow" aria-hidden="true">
          ›
        </span>
      </button>
      <div id="member-filters" className="member-filters" hidden={!filtersOpen}>
        {roleOptions.length > 1 && (
          <select className="role-filter" value={role} onChange={(e) => setRole(e.target.value)} aria-label="Nach Rolle filtern">
            <option value="">Alle Rollen</option>
            {roleOptions.map((r) => (
              <option key={r.key} value={r.key}>
                {r.label}
              </option>
            ))}
          </select>
        )}
        {titleOptions.length > 0 && (
          <select
            className="role-filter"
            value={activeTitle ?? ''}
            onChange={(e) => setTitle(e.target.value === '' ? null : Number(e.target.value))}
            aria-label={`Nach ${sec.titles} filtern`}
          >
            <option value="">Alle {sec.titles}</option>
            {titleOptions.map((t) => (
              <option key={t.id} value={t.id}>
                {t.title}
              </option>
            ))}
          </select>
        )}
        <div className="field-row member-sort">
          {/* Kein umschließendes <label>: dessen Text enthielte sonst alle Optionen. */}
          <div className="field">
            <span id="member-sort-label">Sortierung</span>
            <select
              aria-labelledby="member-sort-label"
              value={order.sort}
              // Neue Sortierung beginnt in ihrer üblichen Richtung (Namen A → Z, Eintritt früheste, Punkte die meisten zuerst).
              onChange={(e) => {
                const sort = e.target.value as MemberSort;
                changeOrder({ sort, dir: defaultDir(sort) });
              }}
            >
              {sortOptions.map((s) => (
                <option key={s} value={s}>
                  {s === 'points' ? sec.points : MEMBER_SORT_LABELS[s]}
                </option>
              ))}
            </select>
          </div>
          <div className="field member-sort-dir">
            <span id="member-sort-dir-label">Reihenfolge</span>
            <button
              type="button"
              className="btn btn-secondary"
              aria-labelledby="member-sort-dir-label member-sort-dir-value"
              title="Reihenfolge umkehren"
              onClick={() => changeOrder({ ...order, dir: order.dir === 'asc' ? 'desc' : 'asc' })}
            >
              <span id="member-sort-dir-value">{dirLabel(order.sort, order.dir)}</span>
            </button>
          </div>
        </div>
        {canPromote && (
          <label className="toggle">
            <input type="checkbox" checked={showGuests} onChange={(e) => setShowGuests(e.target.checked)} />
            Auch Gast-Konten anzeigen (um ihnen eine Rolle zu geben)
          </label>
        )}
      </div>
      <ul className="card-list">
        {list.map((m) => (
          <li key={m.id} className="card row clickable member-row" onClick={() => open(m.id)}>
            {withPhotos && <MemberAvatar url={m.photo_url} name={m.name} size="medium" />}
            <div className="row-main">
              <div className="row-title">
                {listName(m, order.sort)} <TitleBadges titles={m.titles} />
              </div>
              <div className="row-sub">
                {/* Nach Eintritt sortiert: das Jahr vorn, damit die Reihenfolge nachvollziehbar ist. */}
                {order.sort === 'joined' && m.joined_on && `Eintritt ${formerDate(m.joined_on)}`}
                {order.sort === 'joined' && m.joined_on && (canPoints() || (m.open_cents ?? 0) > 0) && ' · '}
                {canPoints() && `${(m.points ?? 0).toLocaleString('de-DE')} ${sec.points}`}
                {canPoints() && (m.open_cents ?? 0) > 0 && ' · '}
                {(m.open_cents ?? 0) > 0 && <span className="neg">{formatEuro(m.open_cents ?? 0)} offen</span>}
              </div>
            </div>
            {/* Dienstgrad rechts, gegenüber dem Foto: Abzeichen, darunter die Bezeichnung. Ohne Dienstgrad bleibt die
                Spalte leer, damit der Text in allen Zeilen gleich mittig steht. */}
            {m.rank_title !== undefined && (
              <div className="member-row-rank">{m.rank_title && <RankStack url={m.rank_badge_url} title={m.rank_title} />}</div>
            )}
            <span className="chevron" aria-hidden="true">
              ›
            </span>
          </li>
        ))}
        {list.length === 0 && <Empty>Niemand gefunden.</Empty>}
      </ul>
    </div>
  );
}

/* ------------------------------------------------------------------ */

/**
 * Mitglied in der Verwaltung: Name oben, darunter ein Menü mit je einer Seite pro Bereich
 * (verwaltung/mitglied/<id>/<seite>), damit nicht alles untereinander steht. Ehemalige haben dieselbe Seite unter
 * verwaltung/ehemalige/<id>/<seite>; steht eine Person unter der falschen Adresse (alter Link, gerade als ehemalig
 * eingetragen oder wieder aufgenommen), leitet die Seite um.
 */
function MemberDetailView({
  base,
  id,
  page,
  onToast,
}: {
  base: 'mitglied' | 'ehemalige';
  id: number;
  page?: string;
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, reload } = useAsync(() => api.member(id), [id], 'member');
  const [busy, setBusy] = useState(false);
  // Erst nach dem Laden entscheiden: Daten aus dem Speicher könnten veraltet sein.
  const target = !loading && data?.id === id ? (data.former ? 'ehemalige' : 'mitglied') : base;
  useEffect(() => {
    if (target !== base) redirectTo(`verwaltung/${target}/${id}${page ? `/${page}` : ''}`);
  }, [target, base, id, page]);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      reload();
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (target !== base) return null;
  const m = data!;

  const pages = [
    { id: 'uebersicht', label: 'Übersicht', on: true },
    { id: 'konto', label: 'Konto und Rolle', on: true },
    { id: 'punkte', label: sec.points, on: canPoints() },
    { id: 'strafen', label: sec.penalties, on: canPenalties() },
    { id: 'dienstgrad', label: brand.labels.rank, on: !!m.rank && (m.member || !!m.rank.rank) },
    { id: 'titel', label: sec.titles, on: !!m.titles && (m.member || m.titles.length > 0) },
    // Ämter (Issue #112): Konten in Amtszeiten sieht, wer Ämter pflegt, sonst nur bei öffentlicher Mitgliederliste.
    { id: 'aemter', label: sec.offices, on: !!config?.features.offices && (!!config.features.directory || can('manage_offices')) },
    {
      id: 'geburtstag',
      label: 'Geburtstag',
      on: m.birthday !== undefined && (m.member || m.birthday !== null || canSetBirthdays()),
    },
    // Eintrittsjahr aktiver Mitglieder (create_users); Ehemalige haben ihren Eintritt unter „Ehemalig“, Gäste keinen.
    { id: 'eintritt', label: 'Eintritt', on: m.join_year !== undefined && !m.former && m.level !== 'guest' },
  ].filter((p) => p.on);
  const active = pages.some((p) => p.id === page) ? page! : 'uebersicht';
  // Foto steht oben neben dem Dienstgrad; ohne Foto die Anfangsbuchstaben, wenn es hochgeladen werden darf.
  const avatar = !!m.photo_url || !!m.photo_editable;

  return (
    <div>
      {m.former ? (
        <button className="btn btn-link back" onClick={() => navigate('verwaltung/ehemalige')}>
          ‹ Ehemalige
        </button>
      ) : (
        <button className="btn btn-link back" onClick={() => navigate('verwaltung/mitglieder')}>
          ‹ Alle Mitglieder
        </button>
      )}
      <h2 className={`member-name member-name-centered${m.rank_title || avatar ? ' with-rank' : ''}`}>{m.name}</h2>
      {(m.rank_title || avatar) && (
        <p className="member-rank-line">
          {/* Abzeichen mit dem Dienstgrad darunter, rechts daneben das Foto (gleich hoch wie das Abzeichen) */}
          {m.rank_title && (
            <span className="member-rank-badge">
              <RankBadge url={m.rank_badge_url} title={m.rank_title} size="large" />
              <span>{m.rank_title}</span>
            </span>
          )}
          {avatar && <ZoomableAvatar url={m.photo_url} name={m.name} />}
        </p>
      )}
      <MenuNav
        label="Mitglied"
        className="member-menu"
        active={active}
        items={pages.map((p) => ({ id: p.id, label: p.label, onSelect: () => navigate(`verwaltung/${base}/${m.id}/${p.id}`) }))}
      />

      {active === 'uebersicht' && (
        <>
          <MemberPhotoPanel
            id={m.id}
            name={m.name}
            url={m.photo_url ?? null}
            editable={!!m.photo_editable}
            showAvatar={false}
            onChanged={reload}
            onToast={onToast}
          />
          <p className="muted small member-level">
            {m.former
              ? `Ehemalig (zuvor ${levelLabel(m.former.previous_level)}): ${formerSummary(m.former)}`
              : (m.extra_roles ?? []).length > 0
                ? `Rollen: ${roleLabels(m)}`
                : `Rolle: ${levelLabel(m.level)}`}
            {hasOverrides(m.rights) && ' · mit Ausnahmen bei den Rechten'}
          </p>
          <div className="stats">
            {canPoints() && <StatCard label={sec.points} value={(m.points ?? 0).toLocaleString('de-DE')} tone="good" />}
            {canPenalties() && (
              <StatCard label="Offen" value={formatEuro(m.open_cents ?? 0)} tone={(m.open_cents ?? 0) > 0 ? 'warn' : 'good'} />
            )}
          </div>
          {m.member && (
            <div className="button-row">
              {canPoints() && (
                <button className="btn" onClick={() => navigate(`verwaltung/punkte/${m.id}`)}>
                  {sec.points} vergeben
                </button>
              )}
              {canPenalties() && (
                <button className="btn btn-warn" onClick={() => navigate(`verwaltung/strafe/${m.id}`)}>
                  {sec.penalties}: Eintrag
                </button>
              )}
            </div>
          )}
        </>
      )}

      {active === 'konto' && (
        <>
          {/* Ehemalige: Rolle, Rechte und Passwort erst nach „Wieder aufnehmen“ */}
          {!m.former && (
            <RoleControl id={m.id} level={m.level} extra={m.extra_roles ?? []} member={m.member} onChanged={reload} onToast={onToast} />
          )}
          {m.rights && (
            <MemberRightsPanel
              key={`${roleLabels(m)}:${JSON.stringify(m.rights)}`}
              id={m.id}
              name={m.name}
              roles={roleLabels(m)}
              plural={(m.extra_roles ?? []).length > 0}
              rights={m.rights}
              onChanged={reload}
              onToast={onToast}
            />
          )}
          {roleInfo(m.level)?.promotable && can('create_users') && (
            <button
              className="btn btn-block promote-candidate"
              disabled={busy}
              onClick={() =>
                window.confirm(`${m.name} als ${levelLabel('member')} aufnehmen?`) &&
                run(() => api.promote(m.id), `${m.name} ist jetzt ${levelLabel('member')}`)
              }
            >
              Als {levelLabel('member')} aufnehmen
            </button>
          )}
          {!m.former && <ResetPassword id={m.id} name={m.name} level={m.level} onToast={onToast} />}
          {/* Gäste gehören nicht zur Kompanie; m.former fehlt bei älteren Servern (dann kein Bereich). */}
          {can('create_users') && m.id !== config?.user?.id && m.former !== undefined && (m.former !== null || m.level !== 'guest') && (
            <FormerPanel
              id={m.id}
              name={m.name}
              former={m.former}
              joinYear={m.join_year?.year ?? null}
              openCents={m.open_cents}
              onChanged={reload}
              onToast={onToast}
            />
          )}
          {can('create_users') && m.id !== config?.user?.id && (
            <AccountPanel id={m.id} level={m.level} former={!!m.former} onChanged={reload} onToast={onToast} />
          )}
        </>
      )}

      {active === 'punkte' && (
        <>
          {canPoints() && <h3 className="section-title">{sec.points}</h3>}
          {!canPoints() ? null : m.point_list.length === 0 ? (
            <Empty>Keine Einträge.</Empty>
          ) : (
            <ul className="card-list">
              {m.point_list.map((p) => (
                <li key={p.id} className="card row">
                  <div className="row-main">
                    <div className="row-title">{p.reason}</div>
                    <div className="row-sub">
                      {formatDate(p.date)}
                      {p.created_by_name && ` · von ${p.created_by_name}`}
                    </div>
                    <div className="row-actions">
                      <button
                        className="btn btn-small btn-danger"
                        disabled={busy}
                        onClick={() =>
                          window.confirm(`Eintrag „${p.reason}“ (${formatPoints(p.points)}) wirklich stornieren?`) &&
                          run(() => api.deletePoints(p.id), 'Storniert')
                        }
                      >
                        Stornieren
                      </button>
                    </div>
                  </div>
                  <div className={`row-value ${p.points < 0 ? 'neg' : 'pos'}`}>{formatPoints(p.points)}</div>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {active === 'strafen' && (
        <>
          {canPenalties() && <h3 className="section-title">{sec.penalties}</h3>}
          {!canPenalties() ? null : m.penalties.length === 0 ? (
            <Empty>Keine Einträge.</Empty>
          ) : (
            <ul className="card-list">
              {m.penalties.map((p) => (
                <PenaltyRow
                  key={p.id}
                  p={p}
                  actions={
                    <>
                      {p.status === 'open' ? (
                        <button
                          className="btn btn-small"
                          disabled={busy}
                          onClick={() => run(() => api.setPenaltyStatus(p.id, 'paid'), 'Als bezahlt markiert')}
                        >
                          Bezahlt
                        </button>
                      ) : (
                        <button
                          className="btn btn-small btn-secondary"
                          disabled={busy}
                          onClick={() => run(() => api.setPenaltyStatus(p.id, 'open'), 'Wieder auf offen gesetzt')}
                        >
                          Doch offen
                        </button>
                      )}
                      <button
                        className="btn btn-small btn-danger"
                        disabled={busy}
                        onClick={() =>
                          window.confirm(`Eintrag „${p.reason}“ (${formatEuro(p.amount_cents)}) wirklich stornieren?`) &&
                          run(() => api.deletePenalty(p.id), 'Storniert')
                        }
                      >
                        Stornieren
                      </button>
                    </>
                  }
                />
              ))}
            </ul>
          )}
        </>
      )}

      {active === 'titel' && m.titles && (
        <MemberTitles
          key={`${m.id}:${m.titles.map((t) => t.id).join(',')}`}
          id={m.id}
          member={m.member}
          titles={m.titles}
          onChanged={reload}
          onToast={onToast}
        />
      )}

      {active === 'aemter' && <PersonOffices userId={m.id} empty={`Keine ${sec.offices} eingetragen.`} />}

      {active === 'dienstgrad' && m.rank && (
        <MemberRank
          id={m.id}
          member={m.member}
          status={m.rank}
          history={m.rank_history ?? []}
          onChanged={reload}
          onToast={onToast}
          focus={page === 'dienstgrad'}
        />
      )}

      {active === 'geburtstag' && m.birthday !== undefined && (
        <MemberBirthday key={JSON.stringify(m.birthday)} id={m.id} birthday={m.birthday} onChanged={reload} onToast={onToast} />
      )}

      {active === 'eintritt' && m.join_year !== undefined && (
        <MemberJoinYear key={JSON.stringify(m.join_year)} id={m.id} joinYear={m.join_year} onChanged={reload} onToast={onToast} />
      )}
    </div>
  );
}

/**
 * Wer Rollen vergeben darf, legt hier die Grundrolle und die Zusatzrollen fest (Issue #66): genau eine Grundrolle,
 * dazu beliebig viele eigene Rollen zum Ankreuzen; die Rechte sind die aller Rollen zusammen. Zusatzrollen gibt es nur
 * zu einer Grundrolle mit Mitgliederbereich und nicht für Admins.
 */
function RoleControl({
  id,
  level,
  extra,
  member,
  onChanged,
  onToast,
}: {
  id: number;
  level: Level;
  extra: Level[];
  member: boolean;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  // Angekreuzte Zusatzrollen gleich zeigen, nicht erst nach dem Neuladen; bei einem Fehler wieder der Stand des Servers.
  const [checked, setChecked] = useState<Level[]>(extra);
  const saved = extra.join(',');
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setChecked(extra), [saved]);
  // Admins ernennen geht nur in der eigenständigen Version; bei WordPress im WP-Backend.
  const adminsHere = config?.variant === 'standalone';
  const readOnly = (
    <>
      <p className="muted small member-level">
        {extra.length > 0 ? 'Grundrolle' : 'Rolle'}: {levelLabel(level)}
        {level === 'admin' && !adminsHere && ' (wird im WordPress-Backend verwaltet)'}
      </p>
      {extra.length > 0 && <p className="muted small member-level">Zusatzrollen: {extra.map(levelLabel).join(', ')}</p>}
    </>
  );
  if (!can('promote_users') || (level === 'admin' && !adminsHere) || id === config?.user?.id) {
    return readOnly;
  }
  async function save(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      onChanged();
    } catch (e) {
      setChecked(extra);
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function change(next: Level) {
    if (next === level) return;
    if (
      next === 'admin' &&
      !window.confirm(`Wirklich zum ${levelLabel('admin')} machen? Admins dürfen alles, auch Einstellungen ändern.`)
    ) {
      return;
    }
    void save(() => api.setRole(id, next), `Grundrolle geändert: ${levelLabel(next)}`);
  }
  function toggle(key: Level, on: boolean) {
    // In der Reihenfolge der Rollen speichern, wie der Server sie zurückgibt.
    const next = (config?.roles ?? []).map((r) => r.key).filter((k) => (k === key ? on : checked.includes(k)));
    setChecked(next);
    void save(
      () => api.setExtraRoles(id, next),
      on ? `Zusatzrolle vergeben: ${levelLabel(key)}` : `Zusatzrolle entfernt: ${levelLabel(key)}`,
    );
  }
  // Rollen der Installation (Einstellungen → Rollen); Admin nur, wo Admins in der App ernannt werden.
  const levels: Level[] = (config?.roles ?? []).map((r) => r.key).filter((k) => k !== 'admin' || adminsHere);
  // Zusatzrollen: nur eigene Rollen, nicht die Grundrolle selbst.
  const extras = (config?.roles ?? []).filter((r) => r.extra && r.key !== level);
  return (
    <>
      <label className="field role-control">
        <span className="field-label">Grundrolle</span>
        <select value={level} disabled={busy} onChange={(e) => change(e.target.value)}>
          {!levels.includes(level) && (
            <option value={level} disabled>
              Unbekannte Rolle – bitte wählen
            </option>
          )}
          {levels.map((l) => (
            <option key={l} value={l}>
              {levelLabel(l)}
            </option>
          ))}
        </select>
      </label>
      {member && level !== 'admin' && extras.length > 0 && (
        <fieldset className="field role-control extra-roles">
          <legend className="field-label">Zusatzrollen</legend>
          <p className="muted small">Die Person hat die Rechte aller ihrer Rollen zusammen.</p>
          {extras.map((r) => (
            <label key={r.key} className="toggle">
              <input type="checkbox" checked={checked.includes(r.key)} disabled={busy} onChange={(e) => toggle(r.key, e.target.checked)} />
              {r.label}
            </label>
          ))}
        </fieldset>
      )}
      {!member && extra.length === 0 && level !== 'admin' && level !== 'guest' && (
        <p className="muted small">Zusatzrollen gibt es nur zu einer Grundrolle mit Mitgliederbereich.</p>
      )}
    </>
  );
}

/** Admins erzeugen ein neues Startpasswort, z. B. wenn jemand sein Passwort vergessen hat. */
function ResetPassword({ id, name, level, onToast }: { id: number; name: string; level: Level; onToast: (msg: string) => void }) {
  const [password, setPassword] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!can('create_users') || id === config?.user?.id || level === 'guest') return null;
  async function reset() {
    if (
      !window.confirm(
        `Neues Startpasswort für ${name} erzeugen? Das bisherige Passwort gilt danach nicht mehr, alle Geräte werden abgemeldet.`,
      )
    ) {
      return;
    }
    setBusy(true);
    try {
      setPassword((await api.resetPassword(id)).password);
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  if (password) {
    return (
      <div className="card created">
        <p className="muted small">
          Neues Startpasswort – wird <strong>nur jetzt</strong> angezeigt. Bitte persönlich weitergeben.
        </p>
        <p className="credentials-password" data-testid="reset-password">
          {password}
        </p>
        <p className="small">
          App noch nicht installiert? Anleitung: <span className="credentials-url">{installUrl()}</span>
        </p>
      </div>
    );
  }
  return (
    <button className="btn btn-link small reset-password" disabled={busy} onClick={reset}>
      Passwort vergessen? Neues Startpasswort erzeugen
    </button>
  );
}

/* ------------------------------------------------------------------ */

function NewMember() {
  const [name, setName] = useState(emptyName());
  const [level, setLevel] = useState<Level>('member');
  // Übernahme bestehender Mitglieder: Anfangspunkte (mit Punkte verwalten) und Dienstgrad (mit Dienstgrade verwalten).
  const [initialPoints, setInitialPoints] = useState('');
  const [initialDate, setInitialDate] = useState(todayYmd());
  const [rankId, setRankId] = useState('');
  const ranks = useAsync(() => (canRanks() ? api.ranks() : Promise.resolve([])), [], 'new-member-ranks');
  const extras = level === 'member';
  const pointsNum = Number(initialPoints || 0);
  const rankOptions = (ranks.data ?? []).filter((r) => !ranksUsePoints() || r.min_points === null || r.min_points <= pointsNum);
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [birthday, setBirthday] = useState(emptyBirthday());
  const [photo, setPhoto] = useState<PreparedPhoto | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedUser | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.first_name.trim()) return setFormError('Bitte einen Vornamen angeben.');
    const bday = canSetBirthdays() ? readBirthday(birthday) : null;
    if (typeof bday === 'string') return setFormError(bday);
    setBusy(true);
    try {
      setCreated(
        await api.createUser({
          ...trimName(name),
          ...(username.trim() ? { username: username.trim() } : {}),
          ...(email.trim() ? { email: email.trim() } : {}),
          level,
          ...(extras && canPoints() && pointsNum > 0 ? { initial_points: pointsNum, initial_points_date: initialDate } : {}),
          ...(extras && canRanks() && rankOptions.some((r) => String(r.id) === rankId) ? { rank_id: Number(rankId) } : {}),
          ...(bday ? { birthday: birthdayString(bday) } : {}),
          ...(photo ? { photo: photo.file } : {}),
        }),
      );
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (created) {
    return (
      <div>
        <h2 className="member-name no-print">{created.name} ist angelegt</h2>
        <Credentials people={[created]} onDone={() => navigate('verwaltung/mitglieder')} />
        <button
          className="btn btn-secondary btn-block no-print"
          onClick={() => {
            setCreated(null);
            setName(emptyName());
            setUsername('');
            setEmail('');
            setInitialPoints('');
            setRankId('');
            setBirthday(emptyBirthday());
            setPhoto(null);
          }}
        >
          Weiteres Mitglied
        </button>
      </div>
    );
  }
  // Erst mit den Dienstgraden zeigen: Sonst taucht das Feld dafür später auf und schiebt den Rest des Formulars nach unten.
  if (canRanks() && ranks.loading && !ranks.data) return <Loading />;

  return (
    <form className="form" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <button type="button" className="btn btn-secondary btn-block bulk-link" onClick={() => navigate('verwaltung/neu-mehrere')}>
        Mehrere auf einmal anlegen
      </button>
      <NameFields value={name} onChange={setName} />
      <label className="field">
        <span>Benutzername (optional)</span>
        <input
          type="text"
          autoComplete="off"
          autoCapitalize="none"
          placeholder="wird sonst aus dem Namen gebildet"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
        />
      </label>
      <label className="field">
        <span>E-Mail (optional)</span>
        <input type="email" autoComplete="off" value={email} onChange={(e) => setEmail(e.target.value)} />
      </label>
      <NewAccountRole label="Rolle" value={level} onChange={setLevel} />
      {extras && canPoints() && (
        <>
          <label className="field">
            <span>{sec.points} (Anfangsbestand, optional)</span>
            <input type="number" inputMode="numeric" min={0} value={initialPoints} onChange={(e) => setInitialPoints(e.target.value)} />
          </label>
          <label className="field">
            <span>Stand vom</span>
            <input type="date" value={initialDate} onChange={(e) => setInitialDate(e.target.value)} />
          </label>
        </>
      )}
      {extras && canRanks() && (ranks.data ?? []).length > 0 && (
        <label className="field">
          <span>{brand.labels.rank} (optional)</span>
          <select value={rankOptions.some((r) => String(r.id) === rankId) ? rankId : ''} onChange={(e) => setRankId(e.target.value)}>
            <option value="">Kein {brand.labels.rank}</option>
            {rankOptions.map((r) => (
              <option key={r.id} value={r.id}>
                {r.title}
              </option>
            ))}
          </select>
        </label>
      )}
      {extras && (canPoints() || canRanks()) && (
        <p className="muted small">
          Für die Übernahme bestehender Mitglieder: aktuellen Stand an {sec.points} und {brand.labels.rank} eintragen.
        </p>
      )}
      {canSetBirthdays() && (
        <fieldset className="fieldset new-member-birthday">
          <legend>Geburtstag (optional)</legend>
          <BirthdayFields value={birthday} onChange={setBirthday} />
        </fieldset>
      )}
      <fieldset className="fieldset new-member-photo">
        <legend>Foto (optional)</legend>
        <PhotoPicker name={`${name.first_name} ${name.last_name}`.trim()} value={photo} onChange={setPhoto} onError={setFormError} />
      </fieldset>
      <p className="muted small">
        Das neue Konto bekommt ein zufälliges Startpasswort. Andere Rollen vergibt danach, wer Rollen vergeben darf.
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Legt an …' : 'Mitglied anlegen'}
      </button>
    </form>
  );
}

/* ------------------------------------------------------------------ */

function useMembersAndCatalog() {
  return useAsync(() => Promise.all([api.members(), api.catalog()]), [], 'members-catalog');
}

/** Aus einer Anwesenheitsliste: Liste laden, dann das Formular mit den Anwesenden bzw. Fehlenden. */
function FromAttendance({ listId, kind, onToast }: { listId: number; kind: 'punkte' | 'strafe'; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.attendance(listId), [listId], 'attendance');
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const back = () => navigate(`verwaltung/anwesenheit/${list.id}`);
  const ids = list.people
    .filter((p) => p.member && (kind === 'punkte' ? p.status === 'present' && !p.booked : p.status === 'absent'))
    .map((p) => p.id);
  return (
    <div>
      <button className="btn btn-link back" onClick={back}>
        ‹ {list.title}
      </button>
      <p className="muted small">
        {kind === 'punkte'
          ? `Vorschlag: alle Anwesenden, die für „${list.title}“ noch keine ${sec.points} haben.`
          : `Vorschlag: alle, die bei „${list.title}“ gefehlt haben. Anders als bei ${sec.points} merkt sich die Liste nicht, wer schon einen Eintrag hat.`}
      </p>
      {kind === 'punkte' ? (
        <GivePoints preselect={ids} attendance={list} onToast={onToast} onDone={back} />
      ) : (
        <GivePenalty preselect={ids} attendance={list} onToast={onToast} onDone={back} />
      )}
    </div>
  );
}

function GivePoints({
  preselect,
  attendance,
  onToast,
  onDone,
}: {
  preselect: number[];
  attendance?: { id: number; title: string; date: string };
  onToast: (msg: string) => void;
  onDone?: () => void;
}) {
  const { data, error, loading, reload } = useMembersAndCatalog();
  const [selected, setSelected] = useState<number[]>(preselect);
  const [catalogId, setCatalogId] = useState<string>('');
  const [points, setPoints] = useState('');
  const [reason, setReason] = useState(attendance?.title ?? '');
  const [date, setDate] = useState(attendance?.date ?? todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [applyFactor, setApplyFactor] = useState(true);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const [members, catalog] = data as [MemberSummary[], CatalogItem[]];
  // Punktefaktor der Rollen (z. B. 0,5 für junge Mitglieder; bei Zusatzrollen der kleinste): gilt beim Buchen, lässt
  // sich übergehen.
  const factorOf = (id: number): number => {
    const m = members.find((x) => x.id === id);
    return m ? personFactor(m) : 1;
  };
  const withFactor = selected.filter((id) => factorOf(id) !== 1);
  const factorNote = (m: MemberSummary) => {
    const f = personFactor(m);
    return f !== 1 ? `×${f.toLocaleString('de-DE')}` : null;
  };

  function chooseCatalog(id: string) {
    setCatalogId(id);
    const item = catalog.find((c) => String(c.id) === id);
    if (item) {
      setPoints(String(item.points));
      setReason(attendance ? `${item.title}: ${attendance.title}` : item.title);
    }
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const n = Number(points);
    if (selected.length === 0) return setFormError('Bitte mindestens ein Mitglied auswählen.');
    if (!Number.isInteger(n) || n === 0) return setFormError('Bitte eine ganze Punktzahl ungleich 0 angeben.');
    if (!reason.trim()) return setFormError('Bitte einen Grund angeben.');
    setBusy(true);
    try {
      const rows = await api.givePoints({
        user_ids: selected,
        points: n,
        reason: reason.trim(),
        date,
        apply_factor: applyFactor,
        ...(catalogId ? { catalog_id: Number(catalogId) } : {}),
        ...(attendance ? { attendance_id: attendance.id } : {}),
      });
      // Aus einer Anwesenheitsliste bucht der Server nur für, wer noch keine Punkte für sie hat.
      onToast(`${formatPoints(n)} ${sec.points} an ${rows.length} ${rows.length === 1 ? 'Mitglied' : 'Mitglieder'} vergeben`);
      setSelected([]);
      onDone?.();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit}>
      <label className="field">
        <span>Vorlage aus dem Katalog</span>
        <select value={catalogId} onChange={(e) => chooseCatalog(e.target.value)}>
          <option value="">– freie Eingabe –</option>
          {catalog.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title} ({formatPoints(c.points)})
            </option>
          ))}
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>{sec.points}</span>
          <input type="number" inputMode="numeric" step="1" value={points} onChange={(e) => setPoints(e.target.value)} required />
        </label>
        <label className="field">
          <span>Datum</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Grund</span>
        <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} required />
      </label>
      <div className="field">
        <span>Mitglieder</span>
        <MemberPicker members={members} selected={selected} onChange={setSelected} note={factorNote} />
      </div>
      {withFactor.length > 0 && (
        <label className="toggle">
          <input type="checkbox" checked={applyFactor} onChange={(e) => setApplyFactor(e.target.checked)} />
          <span>
            {sec.points}faktor der Rolle anwenden
            <span className="small muted feature-desc">
              {withFactor.length === 1 ? 'Ein ausgewähltes Mitglied hat' : `${withFactor.length} ausgewählte Mitglieder haben`} einen
              anderen Faktor als 1
              {Number(points) && applyFactor
                ? `, z. B. ×${factorOf(withFactor[0]).toLocaleString('de-DE')} → ${(Number(points) * factorOf(withFactor[0])).toLocaleString('de-DE')} ${sec.points}`
                : ''}
              .
            </span>
          </span>
        </label>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Speichert …' : `${sec.points} vergeben`}
      </button>
    </form>
  );
}

function GivePenalty({
  preselect,
  attendance,
  onToast,
  onDone,
}: {
  preselect: number[];
  attendance?: { title: string; date: string };
  onToast: (msg: string) => void;
  onDone?: () => void;
}) {
  const { data, error, loading, reload } = useAsync(api.members, [], 'penalty-members');
  // Summe aller offenen Strafen (Mitglieder und Anwärter, ohne Gast-Konten): gleichzeitig mit der Auswahl geladen und
  // gemerkt, damit die Karte zusammen mit dem Formular erscheint. Beim Eintragen aus einer Anwesenheitsliste nicht.
  const total = useAsync(() => (attendance ? Promise.resolve(null) : api.members(false, true)), [], 'penalty-total');
  const [selected, setSelected] = useState<number[]>(preselect);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState(attendance ? `Gefehlt: ${attendance.title}` : '');
  const [date, setDate] = useState(attendance?.date ?? todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if ((loading && !data) || (total.loading && !total.data && !attendance)) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const totalOpen = total.data?.filter((m) => m.level !== 'guest').reduce((s, m) => s + (m.open_cents ?? 0), 0);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const cents = parseEuroToCents(amount);
    if (selected.length === 0) return setFormError('Bitte mindestens ein Mitglied auswählen.');
    if (cents === null || cents <= 0) return setFormError('Bitte einen gültigen Betrag angeben, z. B. 2,50.');
    if (cents > 100000) return setFormError('Ein einzelner Eintrag darf höchstens 1.000 € betragen.');
    if (!reason.trim()) return setFormError('Bitte einen Grund angeben.');
    setBusy(true);
    try {
      await api.givePenalty({ user_ids: selected, amount_cents: cents, reason: reason.trim(), date });
      onToast(`${formatEuro(cents)} an ${selected.length} ${selected.length === 1 ? 'Mitglied' : 'Mitglieder'} eingetragen`);
      setSelected([]);
      setAmount('');
      setReason('');
      total.reload();
      onDone?.();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit}>
      {!attendance && totalOpen !== undefined && (
        <StatCard label={`Offen gesamt: ${sec.penalties}`} value={formatEuro(totalOpen)} tone={totalOpen > 0 ? 'warn' : 'good'} />
      )}
      <div className="field-row">
        <label className="field">
          <span>Betrag in €</span>
          <input type="text" inputMode="decimal" placeholder="2,50" value={amount} onChange={(e) => setAmount(e.target.value)} required />
        </label>
        <label className="field">
          <span>Datum</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Grund</span>
        <input
          type="text"
          maxLength={255}
          placeholder="z. B. Zu spät gekommen"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          required
        />
      </label>
      <div className="field">
        <span>Mitglieder</span>
        <MemberPicker members={data ?? []} selected={selected} onChange={setSelected} />
      </div>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-warn btn-block" type="submit" disabled={busy}>
        {busy ? 'Speichert …' : 'Eintragen'}
      </button>
    </form>
  );
}

/* ------------------------------------------------------------------ */

function Catalog({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.catalog(true), [], 'catalog-all');
  const [title, setTitle] = useState('');
  const [points, setPoints] = useState('');
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      onToast((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    const n = Number(points);
    if (!title.trim() || !Number.isInteger(n)) {
      onToast('Bitte Titel und ganze Punktzahl angeben.');
      return;
    }
    if (await run(() => api.createCatalogItem(title.trim(), n), 'Vorlage angelegt')) {
      setTitle('');
      setPoints('');
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  return (
    <div>
      <form className="form card" onSubmit={add}>
        <div className="field-row">
          <label className="field grow">
            <span>Neue Vorlage</span>
            <input type="text" placeholder="z. B. Teilnahme am Sommerfest" value={title} onChange={(e) => setTitle(e.target.value)} />
          </label>
          <label className="field narrow">
            <span>{sec.points}</span>
            <input type="number" inputMode="numeric" step="1" value={points} onChange={(e) => setPoints(e.target.value)} />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Hinzufügen
        </button>
      </form>
      <ul className="card-list">
        {(data ?? []).map((c) => (
          <li key={c.id} className={`card row ${c.active ? '' : 'inactive'}`}>
            <div className="row-main">
              <div className="row-title">{c.title}</div>
              <div className="row-sub">{c.active ? 'aktiv' : 'deaktiviert'}</div>
              <div className="row-actions">
                <button
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => {
                    const next = window.prompt(`${sec.points} für „${c.title}“`, String(c.points));
                    if (next === null) return;
                    const n = Number(next);
                    if (!Number.isInteger(n)) return onToast('Bitte eine ganze Zahl angeben.');
                    run(() => api.updateCatalogItem(c.id, { points: n }), 'Gespeichert');
                  }}
                >
                  Wert ändern
                </button>
                <button
                  className="btn btn-small btn-secondary"
                  disabled={busy}
                  onClick={() => run(() => api.updateCatalogItem(c.id, { active: !c.active }), c.active ? 'Deaktiviert' : 'Aktiviert')}
                >
                  {c.active ? 'Deaktivieren' : 'Aktivieren'}
                </button>
              </div>
            </div>
            <div className="row-value pos">{formatPoints(c.points)}</div>
          </li>
        ))}
        {(data ?? []).length === 0 && <Empty>Noch keine Vorlagen. Lege oben die erste an.</Empty>}
      </ul>
    </div>
  );
}
