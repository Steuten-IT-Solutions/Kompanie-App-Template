import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { api, can, config, levelLabel, roleFactor, type CatalogItem, type CreatedUser, type Level, type MemberSummary } from '../api';
import brand from '../generated/brand';
import { caps, formatDate, formatEuro, formatPoints, parseEuroToCents, todayYmd } from '../format';
import { navigate } from '../router';
import { AdminNav, adminTabs, memberListAllowed } from '../adminNav';
import { Empty, ErrorBox, Loading, MemberPicker, StatCard, useAsync } from '../ui';
import Anwesenheit from './Anwesenheit';
import { RankLabel } from '../RankBadge';
import { MemberRank, Promotions, RankList, ranksUsePoints } from './DienstgradeVerwalten';
import { installUrl } from '../install';
import { PenaltyRow } from './Strafen';
import { AccountPanel, BulkNewMembers, Credentials } from './Konten';
import { TitleBadges } from '../TitleBadges';
import { MemberTitles, TitleList } from './TitelVerwalten';
import { BirthdayFields, birthdayString, canSetBirthdays, emptyBirthday, MemberBirthday, readBirthday } from '../birthdays';

type Sub = 'mitglieder' | 'punkte' | 'strafe' | 'katalog' | 'dienstgrade' | 'befoerderungen' | 'titel' | 'anwesenheit';

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
        <MemberDetailView id={Number(arg)} focus={path[2]} onToast={onToast} />
      ) : sub === 'neu' && can('create_users') ? (
        <NewMember />
      ) : sub === 'neu-mehrere' && can('create_users') ? (
        <BulkNewMembers canPoints={canPoints()} />
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
      ) : active === 'anwesenheit' ? (
        <Anwesenheit key={arg ?? ''} id={arg && /^\d+$/.test(arg) ? Number(arg) : undefined} onToast={onToast} />
      ) : (
        <Members />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

function Members() {
  const canPromote = can('promote_users');
  const [showGuests, setShowGuests] = useState(false);
  // Anwärter auf Wunsch; wer Mitglieder anlegt (und Anwärter aufnimmt), sieht sie von Anfang an.
  const [showCandidates, setShowCandidates] = useState(can('create_users'));
  const { data, error, loading, reload } = useAsync(() => api.members(showGuests, showCandidates), [showGuests, showCandidates]);
  const [query, setQuery] = useState('');
  const list = useMemo(() => (data ?? []).filter((m) => m.name.toLowerCase().includes(query.trim().toLowerCase())), [data, query]);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

  const totalOpen = (data ?? []).filter((m) => m.level !== 'guest').reduce((s, m) => s + (m.open_cents ?? 0), 0);

  return (
    <div>
      {canPenalties() && (
        <StatCard label={`Offen gesamt: ${sec.penalties}`} value={formatEuro(totalOpen)} tone={totalOpen > 0 ? 'warn' : 'good'} />
      )}
      {can('create_users') && (
        <button className="btn btn-block add-member" onClick={() => navigate('verwaltung/neu')}>
          + Mitglied anlegen
        </button>
      )}
      <input
        className="search"
        type="search"
        placeholder="Mitglied suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Mitglied suchen"
      />
      <label className="toggle">
        <input
          type="checkbox"
          checked={showCandidates || showGuests}
          disabled={showGuests}
          onChange={(e) => setShowCandidates(e.target.checked)}
        />
        Auch {levelLabel('candidate')} anzeigen
      </label>
      {canPromote && (
        <label className="toggle">
          <input type="checkbox" checked={showGuests} onChange={(e) => setShowGuests(e.target.checked)} />
          Auch Gast-Konten anzeigen (um ihnen eine Rolle zu geben)
        </label>
      )}
      <ul className="card-list">
        {list.map((m) => (
          <li key={m.id} className="card row clickable" onClick={() => navigate(`verwaltung/mitglied/${m.id}`)}>
            <div className="row-main">
              <div className="row-title">
                {m.name}{' '}
                {m.level !== 'member' && (
                  <span className={`badge ${m.member ? 'badge-role' : 'badge-level-guest'}`}>{caps(levelLabel(m.level))}</span>
                )}
                <TitleBadges titles={m.titles} />
              </div>
              <div className="row-sub">
                {m.rank_title !== undefined && (
                  <>
                    {m.rank_title ? <RankLabel url={m.rank_badge_url} title={m.rank_title} /> : `Kein ${brand.labels.rank}`}
                    {canPoints() ? ' · ' : ''}
                  </>
                )}
                {canPoints() && `${(m.points ?? 0).toLocaleString('de-DE')} ${sec.points}`}
                {canPoints() && (m.open_cents ?? 0) > 0 && ' · '}
                {(m.open_cents ?? 0) > 0 && <span className="neg">{formatEuro(m.open_cents ?? 0)} offen</span>}
              </div>
            </div>
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

function MemberDetailView({ id, focus, onToast }: { id: number; focus?: string; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.member(id), [id]);
  const [busy, setBusy] = useState(false);

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
  const m = data!;

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <h2 className="member-name">{m.name}</h2>
      <RoleControl id={m.id} level={m.level} onChanged={reload} onToast={onToast} />
      {m.level === 'candidate' && can('create_users') && (
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
      <ResetPassword id={m.id} name={m.name} level={m.level} onToast={onToast} />
      {can('create_users') && m.id !== config?.user?.id && <AccountPanel id={m.id} level={m.level} onChanged={reload} onToast={onToast} />}
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

      {m.titles && (m.member || m.titles.length > 0) && (
        <MemberTitles key={m.id} id={m.id} member={m.member} titles={m.titles} onChanged={reload} onToast={onToast} />
      )}

      {m.rank && (m.member || m.rank.rank) && (
        <MemberRank
          id={m.id}
          member={m.member}
          status={m.rank}
          history={m.rank_history ?? []}
          onChanged={reload}
          onToast={onToast}
          focus={focus === 'dienstgrad'}
        />
      )}

      {m.birthday !== undefined && (m.member || m.birthday !== null || canSetBirthdays()) && (
        <MemberBirthday key={JSON.stringify(m.birthday)} id={m.id} birthday={m.birthday} onChanged={reload} onToast={onToast} />
      )}

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
    </div>
  );
}

/** Admins legen hier die Rolle in der App fest. */
function RoleControl({
  id,
  level,
  onChanged,
  onToast,
}: {
  id: number;
  level: Level;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [busy, setBusy] = useState(false);
  // Admins ernennen geht nur in der eigenständigen Version; bei WordPress im WP-Backend.
  const adminsHere = config?.variant === 'standalone';
  if (!can('promote_users')) {
    return <p className="muted small member-level">Rolle: {levelLabel(level)}</p>;
  }
  if ((level === 'admin' && !adminsHere) || id === config?.user?.id) {
    return (
      <p className="muted small member-level">
        Rolle: {levelLabel(level)}
        {level === 'admin' && !adminsHere && ' (wird im WordPress-Backend verwaltet)'}
      </p>
    );
  }
  async function change(next: Level) {
    if (next === level) return;
    if (
      next === 'admin' &&
      !window.confirm(`Wirklich zum ${levelLabel('admin')} machen? Admins dürfen alles, auch Einstellungen ändern.`)
    ) {
      return;
    }
    setBusy(true);
    try {
      await api.setRole(id, next);
      onToast(`Rolle geändert: ${levelLabel(next)}`);
      onChanged();
    } catch (e) {
      onToast((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  // Rollen der Installation (Einstellungen → Rollen); Admin nur, wo Admins in der App ernannt werden.
  const levels: Level[] = (config?.roles ?? []).map((r) => r.key).filter((k) => k !== 'admin' || adminsHere);
  return (
    <label className="field role-control">
      <span className="field-label">Rolle in der App</span>
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
  const [name, setName] = useState('');
  const [level, setLevel] = useState<'member' | 'candidate'>('member');
  // Übernahme bestehender Mitglieder: Anfangspunkte (mit Punkte verwalten) und Dienstgrad (mit Dienstgrade verwalten).
  const [initialPoints, setInitialPoints] = useState('');
  const [initialDate, setInitialDate] = useState(todayYmd());
  const [rankId, setRankId] = useState('');
  const ranks = useAsync(() => (canRanks() ? api.ranks() : Promise.resolve([])), []);
  const extras = level === 'member';
  const pointsNum = Number(initialPoints || 0);
  const rankOptions = (ranks.data ?? []).filter((r) => !ranksUsePoints() || r.min_points === null || r.min_points <= pointsNum);
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [birthday, setBirthday] = useState(emptyBirthday());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedUser | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.trim()) return setFormError('Bitte einen Namen angeben.');
    const bday = canSetBirthdays() ? readBirthday(birthday) : null;
    if (typeof bday === 'string') return setFormError(bday);
    setBusy(true);
    try {
      setCreated(
        await api.createUser({
          name: name.trim(),
          ...(username.trim() ? { username: username.trim() } : {}),
          ...(email.trim() ? { email: email.trim() } : {}),
          level,
          ...(extras && canPoints() && pointsNum > 0 ? { initial_points: pointsNum, initial_points_date: initialDate } : {}),
          ...(extras && canRanks() && rankOptions.some((r) => String(r.id) === rankId) ? { rank_id: Number(rankId) } : {}),
          ...(bday ? { birthday: birthdayString(bday) } : {}),
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
            setName('');
            setUsername('');
            setEmail('');
            setInitialPoints('');
            setRankId('');
            setBirthday(emptyBirthday());
          }}
        >
          Weiteres Mitglied
        </button>
      </div>
    );
  }

  return (
    <form className="form" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <button type="button" className="btn btn-secondary btn-block bulk-link" onClick={() => navigate('verwaltung/neu-mehrere')}>
        Mehrere auf einmal anlegen
      </button>
      <label className="field">
        <span>Name</span>
        <input
          type="text"
          autoComplete="off"
          placeholder="Vorname Nachname"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
        />
      </label>
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
      <label className="field">
        <span>Rolle</span>
        <select value={level} onChange={(e) => setLevel(e.target.value as 'member' | 'candidate')}>
          <option value="member">{levelLabel('member')}</option>
          <option value="candidate">{levelLabel('candidate')} (nur interner Kalender)</option>
        </select>
      </label>
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
        <p className="muted small">Für die Übernahme bestehender Mitglieder: aktuellen Punktestand und {brand.labels.rank} eintragen.</p>
      )}
      {canSetBirthdays() && (
        <fieldset className="fieldset new-member-birthday">
          <legend>Geburtstag (optional)</legend>
          <BirthdayFields value={birthday} onChange={setBirthday} />
        </fieldset>
      )}
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
  return useAsync(() => Promise.all([api.members(), api.catalog()]));
}

/** Aus einer Anwesenheitsliste: Liste laden, dann das Formular mit den Anwesenden bzw. Fehlenden. */
function FromAttendance({ listId, kind, onToast }: { listId: number; kind: 'punkte' | 'strafe'; onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.attendance(listId), [listId]);
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
  // Punktefaktor der Rolle (z. B. 0,5 für junge Mitglieder): gilt beim Buchen, lässt sich übergehen.
  const withFactor = selected.filter((id) => roleFactor(members.find((m) => m.id === id)?.level ?? 'member') !== 1);
  const factorNote = (m: MemberSummary) => {
    const f = roleFactor(m.level);
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
            Punktefaktor der Rolle anwenden
            <span className="small muted feature-desc">
              {withFactor.length === 1 ? 'Ein ausgewähltes Mitglied hat' : `${withFactor.length} ausgewählte Mitglieder haben`} einen
              anderen Faktor als 1
              {Number(points) && applyFactor
                ? `, z. B. ×${roleFactor(members.find((m) => m.id === withFactor[0])?.level ?? 'member').toLocaleString('de-DE')} → ${(Number(points) * roleFactor(members.find((m) => m.id === withFactor[0])?.level ?? 'member')).toLocaleString('de-DE')} ${sec.points}`
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
  const { data, error, loading, reload } = useAsync(api.members);
  const [selected, setSelected] = useState<number[]>(preselect);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState(attendance ? `Gefehlt: ${attendance.title}` : '');
  const [date, setDate] = useState(attendance?.date ?? todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;

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
      onDone?.();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={submit}>
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
  const { data, error, loading, reload } = useAsync(() => api.catalog(true));
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
