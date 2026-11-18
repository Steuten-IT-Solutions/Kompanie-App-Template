import { useState } from 'react';
import {
  api,
  applyPendingRegistrations,
  can,
  config,
  newAccountRoles,
  type Level,
  type RegistrationMatch,
  type RegistrationRequest,
} from '../api';
import { formatDate } from '../format';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { BrowseCard, BrowseList } from '../browseCard';
import { navigate } from '../router';
import { NewAccountRole } from './Konten';
import { confirmAsk } from '../ask';

/** Suche und Filter der Liste, gemerkt bis zum Neuladen (zurück von der Karte steht die Liste wie zuvor). */
let listState: { query: string; company: string; filtersOpen: boolean } = { query: '', company: 'all', filtersOpen: false };

/** Wahl der Kompanie für den Filter: Kompanie-Id, none, passive oder unknown (Anfragen von vor der Auswahl) */
function companyKeys(r: RegistrationRequest): string[] {
  if (r.company_choice === 'none') return ['none'];
  if (r.company_choice === 'passive') return ['passive'];
  const ids = (r.companies ?? []).map((c) => String(c.id));
  return ids.length > 0 ? ids : ['unknown'];
}

/** Reihenfolge der Liste und der Karten: nach Nachname, dann Vorname (wie bei den Passiven) */
function sorted(requests: RegistrationRequest[]): RegistrationRequest[] {
  return [...requests].sort(
    (a, b) => a.last_name.localeCompare(b.last_name, 'de') || a.first_name.localeCompare(b.first_name, 'de') || a.id - b.id,
  );
}

/** Hinweise unter der Liste: Frist und wie die Person vom Ergebnis erfährt */
function Footnote({ withMail }: { withMail: boolean }) {
  return (
    <p className="muted small registration-footnote">
      Unbearbeitete Anfragen werden nach 30 Tagen gelöscht.
      {withMail
        ? ' Die Person bekommt über Freigabe oder Ablehnung eine Mail.'
        : ' Ohne Mailversand erfährt die Person das Ergebnis, wenn sie sich anmeldet oder den Stand ihrer Anfrage prüft.'}
    </p>
  );
}

/**
 * Verwaltung → Anfragen (Issue #314): Liste nur mit Namen, mit Suche und (mit dem Bereich Kompanien) Filter nach der
 * gewählten Kompanie; Antippen öffnet die Karte der Anfrage (AnfrageDetail), wie bei den Passiven. Sehen mit „Konten
 * sehen“, freigeben und ablehnen mit „Mitglieder anlegen“.
 */
export function Anfragen() {
  const { data, error, loading, reload } = useAsync(() => api.registrations(), [], 'registrations');
  const [state, setState] = useState(listState);
  const update = (patch: Partial<typeof listState>) => {
    listState = { ...state, ...patch };
    setState(listState);
  };
  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Anfragen konnten nicht geladen werden.'} onRetry={reload} />;
  const requests = sorted(data.requests);
  const q = state.query.trim().toLowerCase();
  // Zur Wahl im Filter: die Kompanien, die in einer Anfrage vorkommen, dann Keine Kompanie, Passiv und keine Angabe
  const keys = new Set(requests.flatMap(companyKeys));
  const companyOptions = [
    ...(data.companies ?? []).filter((c) => keys.has(String(c.id))).map((c) => ({ key: String(c.id), label: c.name })),
    ...(keys.has('none') ? [{ key: 'none', label: 'Keine Kompanie' }] : []),
    ...(keys.has('passive') ? [{ key: 'passive', label: 'Passiv' }] : []),
    ...(keys.has('unknown') ? [{ key: 'unknown', label: 'keine Angabe' }] : []),
  ];
  const company = companyOptions.some((o) => o.key === state.company) ? state.company : 'all';
  const list = requests.filter(
    (r) =>
      (q === '' || `${r.first_name} ${r.last_name} ${r.email} ${r.username}`.toLowerCase().includes(q)) &&
      (company === 'all' || companyKeys(r).includes(company)),
  );
  return (
    <div className="registrations">
      <h2 className="section-title">Anfragen auf einen Zugang</h2>
      <p className="small muted member-counts" role="status">
        {requests.length === 1 ? '1 offene Anfrage' : `${requests.length} offene Anfragen`}
      </p>
      {!config?.registration && (
        <p className="muted small">„Zugang beantragen“ ist ausgeschaltet. Offene Anfragen lassen sich trotzdem noch bearbeiten.</p>
      )}
      {requests.length > 0 && (
        <>
          <input
            className="search"
            type="search"
            placeholder="Anfragen suchen …"
            value={state.query}
            onChange={(e) => update({ query: e.target.value })}
            aria-label="Anfragen suchen"
          />
          {data.companies && companyOptions.length > 0 && (
            <>
              <button
                type="button"
                className="member-filters-toggle"
                aria-expanded={state.filtersOpen}
                aria-controls="registration-filters"
                onClick={() => update({ filtersOpen: !state.filtersOpen })}
              >
                Filter nach Kompanie
                {company !== 'all' && <span className="badge badge-role">aktiv</span>}
                <span className="member-filters-arrow" aria-hidden="true">
                  ›
                </span>
              </button>
              <div id="registration-filters" className="member-filters registration-filters" hidden={!state.filtersOpen}>
                <label className="field">
                  <span>Kompanie</span>
                  <select value={company} onChange={(e) => update({ company: e.target.value })}>
                    <option value="all">alle</option>
                    {companyOptions.map((o) => (
                      <option key={o.key} value={o.key}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
            </>
          )}
        </>
      )}
      {requests.length === 0 ? (
        <Empty>Zurzeit wartet keine Anfrage auf Freigabe.</Empty>
      ) : list.length === 0 ? (
        <Empty>Keine Anfrage gefunden.</Empty>
      ) : (
        <BrowseList
          base="verwaltung/anfragen"
          items={list}
          className="registration-list"
          title={(r) => (
            <>
              {r.last_name}, {r.first_name}
            </>
          )}
        />
      )}
      <Footnote withMail={data.email_confirmation} />
    </div>
  );
}

/**
 * Verwaltung → Anfragen → Karte: alle Angaben der Anfrage, darunter (mit „Mitglieder anlegen“) Verbinden mit einem
 * Mitglied ohne Zugang, Kompanie, Benutzername, Rolle, Freigeben und Ablehnen. Blättern wie bei den Passiven. Nach dem
 * Freigeben oder Ablehnen geht es zurück zur Liste.
 */
export function AnfrageDetail({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.registrations(), [], 'registrations');
  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Anfragen konnten nicht geladen werden.'} onRetry={reload} />;
  const list = data;
  const editable = can('create_users');
  const done = (doneId: number, msg: string) => {
    const requests = list.requests.filter((r) => r.id !== doneId);
    setData({ ...list, requests });
    applyPendingRegistrations(requests.length);
    onToast(msg);
    navigate('verwaltung/anfragen');
  };
  return (
    <BrowseCard
      key={id}
      base="verwaltung/anfragen"
      id={id}
      items={sorted(list.requests)}
      className="registration-card"
      back="‹ Alle Anfragen"
      prevLabel="Vorherige Anfrage"
      nextLabel="Nächste Anfrage"
      missing="Diese Anfrage wartet nicht mehr auf Freigabe."
      render={(r) => (
        <RequestCard
          request={r}
          defaultRole={list.default_role}
          withMail={list.email_confirmation}
          noAccess={list.no_access_members ?? []}
          companies={list.companies}
          editable={editable}
          onDone={done}
        />
      )}
      peek={(r) => <RequestInfo r={r} withMail={list.email_confirmation} companies={!!list.companies} />}
    />
  );
}

const MATCH_LABELS: Record<RegistrationMatch['match'], string> = {
  email: 'gleiche E-Mail-Adresse',
  name: 'gleicher Name',
  similar: 'ähnlicher Name',
};

function RequestCard({
  request: r,
  defaultRole,
  withMail,
  noAccess,
  companies,
  editable,
  onDone,
}: {
  request: RegistrationRequest;
  defaultRole: Level;
  withMail: boolean;
  noAccess: { id: number; name: string }[];
  /** alle Kompanien (Bereich Kompanien an), sonst undefined */
  companies?: { id: number; name: string; active: boolean }[];
  /** freigeben und ablehnen (create_users); sonst nur sehen */
  editable: boolean;
  onDone: (id: number, msg: string) => void;
}) {
  const matches = r.matches ?? [];
  // Mit wem verbinden: '' = neues Konto. Vorgewählt nur bei gleicher E-Mail-Adresse oder gleichem Namen; verbunden wird
  // erst beim Tippen auf „Freigeben und verbinden“.
  const strong = matches.find((m) => m.match !== 'similar');
  const [linkTo, setLinkTo] = useState(strong ? String(strong.id) : '');
  const others = noAccess.filter((p) => !matches.some((m) => m.id === p.id));
  const linkName = noAccess.find((p) => String(p.id) === linkTo)?.name ?? matches.find((m) => String(m.id) === linkTo)?.name;
  // Admin nie über eine Anfrage (wie der Server)
  const roles = newAccountRoles().filter((x) => x.key !== 'admin');
  const [level, setLevel] = useState<Level>(roles.some((x) => x.key === defaultRole) ? defaultRole : 'member');
  const [username, setUsername] = useState(r.username);
  const chosen = (r.companies ?? []).map((c) => c.id);
  const [companyIds, setCompanyIds] = useState<number[]>(chosen);
  // Ändern nur mit „Kompanien verwalten“ (prüft der Server); zur Wahl stehen die aktiven und die gewählten.
  const companyChoices = companies && can('manage_companies') ? companies.filter((c) => c.active || chosen.includes(c.id)) : null;
  const companiesChanged = companyIds.length !== chosen.length || companyIds.some((id) => !chosen.includes(id));
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run(action: () => Promise<string>) {
    setBusy(true);
    setError(null);
    try {
      onDone(r.id, await action());
    } catch (err) {
      setError((err as Error).message);
      setBusy(false);
    }
  }
  const approve = async () => {
    if (
      linkTo &&
      !(await confirmAsk(
        `Anfrage von ${r.name} mit dem Mitglied ${linkName ?? ''} verbinden? Es bekommt Benutzername und Passwort aus der Anfrage.`,
      ))
    ) {
      return;
    }
    return run(async () => {
      const changed = {
        ...(username.trim() !== r.username ? { username: username.trim() } : {}),
        ...(companyChoices && companiesChanged ? { company_ids: companyIds } : {}),
      };
      const res = await api.approveRegistration(r.id, linkTo ? { user_id: Number(linkTo), ...changed } : { level, ...changed });
      const assigned = res.companies && res.companies.length > 0 ? `, ${res.companies.map((c) => c.name).join(', ')} zugeordnet` : '';
      return `${res.member.name} ist freigegeben${res.linked ? ' und verbunden' : ''}${assigned}${res.mailed ? ', per Mail informiert' : ''}`;
    });
  };
  const reject = () =>
    run(async () => {
      const res = await api.rejectRegistration(r.id, reason.trim());
      return `Anfrage abgelehnt${res.mailed ? ', die Person ist per Mail informiert' : ''}`;
    });

  const head = <RequestInfo r={r} withMail={withMail} companies={!!companies} />;
  if (!editable) {
    return (
      <>
        {head}
        {matches.length > 0 && (
          <section className="card registration-link" aria-label="Mitglieder ohne Zugang">
            <h3>Könnte passen zu</h3>
            <p className="small">{matches.map((m) => `${m.name} (${MATCH_LABELS[m.match]})`).join(', ')}</p>
          </section>
        )}
      </>
    );
  }

  return (
    <>
      {head}
      {(matches.length > 0 || noAccess.length > 0) && (
        <section className="card registration-link" aria-labelledby={`link-title-${r.id}`}>
          <h3 id={`link-title-${r.id}`}>Mitglied ohne Zugang verbinden?</h3>
          {matches.length > 0 && <p className="muted small">Diese Mitglieder ohne Zugang könnten passen:</p>}
          <label className="toggle">
            <input type="radio" name={`link-${r.id}`} checked={linkTo === ''} onChange={() => setLinkTo('')} />
            Nein, neues Konto anlegen
          </label>
          {matches.map((m) => (
            <label key={m.id} className="toggle">
              <input type="radio" name={`link-${r.id}`} checked={linkTo === String(m.id)} onChange={() => setLinkTo(String(m.id))} />
              {m.name} <span className="muted small">({MATCH_LABELS[m.match]})</span>
            </label>
          ))}
          {others.length > 0 && (
            <label className="field">
              <span>{matches.length > 0 ? 'Oder ein anderes Mitglied ohne Zugang' : 'Mitglied ohne Zugang wählen'}</span>
              <select value={others.some((p) => String(p.id) === linkTo) ? linkTo : ''} onChange={(e) => setLinkTo(e.target.value)}>
                <option value="">– keines –</option>
                {others.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </label>
          )}
        </section>
      )}
      {companyChoices && companyChoices.length > 0 && (
        <section className="card registration-companies" aria-label="Kompanie zuordnen">
          <h3>Kompanie zuordnen</h3>
          {companyChoices.map((c) => (
            <label key={c.id} className="toggle">
              <input
                type="checkbox"
                checked={companyIds.includes(c.id)}
                onChange={(e) => setCompanyIds((ids) => (e.target.checked ? [...ids, c.id] : ids.filter((x) => x !== c.id)))}
              />
              {c.name}
              {!c.active && <span className="muted small"> (ehemalig)</span>}
            </label>
          ))}
          <p className="muted small">
            {linkTo ? 'Zusätzlich zu den Kompanien des Mitglieds. ' : ''}Zugeordnet werden nur Personen mit einer Rolle für Mitglieder.
          </p>
        </section>
      )}
      <section className="card registration-approve" aria-label="Freigabe">
        <h3>Freigabe</h3>
        {!companyChoices && chosen.length > 0 && (
          <p className="muted small">Beim Freigeben wird die Person der gewählten Kompanie zugeordnet.</p>
        )}
        {r.email_taken && (
          <p className="form-error small">Zu dieser E-Mail-Adresse gibt es inzwischen ein Konto. Freigeben geht deshalb nicht.</p>
        )}
        <label className="field">
          <span>Benutzername</span>
          <input type="text" autoComplete="off" autoCapitalize="none" value={username} onChange={(e) => setUsername(e.target.value)} />
          {r.username_taken && username.trim() === r.username && (
            <span className="form-error small">Dieser Benutzername ist inzwischen vergeben. Bitte einen anderen eintragen.</span>
          )}
        </label>
        {linkTo ? (
          <p className="muted small">Das Mitglied behält seine Rolle und alle Einträge, z. B. in Chroniken und Listen.</p>
        ) : (
          <NewAccountRole label="Rolle" value={level} onChange={setLevel} roles={roles} />
        )}
        {rejecting && (
          <label className="field">
            <span>Begründung (freiwillig{withMail ? ', steht in der Mail' : ''})</span>
            <textarea rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          </label>
        )}
        {error && (
          <p className="form-error" role="alert">
            {error}
          </p>
        )}
        {rejecting ? (
          <div className="row-actions registration-card-actions">
            <button type="button" className="btn btn-danger" disabled={busy} onClick={reject}>
              Ablehnen
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setRejecting(false)}>
              Abbrechen
            </button>
          </div>
        ) : (
          <div className="row-actions registration-card-actions">
            <button type="button" className="btn" disabled={busy || r.email_taken} onClick={approve}>
              {busy ? 'Gibt frei …' : linkTo ? 'Freigeben und verbinden' : 'Freigeben'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setRejecting(true)}>
              Ablehnen …
            </button>
          </div>
        )}
      </section>
    </>
  );
}

/** Kopf der Karte: Name, Tag der Anfrage und die Angaben aus dem Formular (auch für die Nachbarn beim Blättern). */
function RequestInfo({ r, withMail, companies }: { r: RegistrationRequest; withMail: boolean; companies: boolean }) {
  return (
    <>
      <h2 className="member-name member-name-centered">{r.name}</h2>
      <p className="muted small member-level registration-date">angefragt am {formatDate(r.created_at.slice(0, 10))}</p>
      <section className="card registration-details" aria-label="Angaben">
        <h3>Angaben</h3>
        <dl className="contact-list">
          <dt>E-Mail-Adresse</dt>
          <dd>
            {r.email}
            <span className="muted small">
              {' · '}
              {withMail ? (r.email_confirmed ? 'Adresse bestätigt' : 'Adresse nicht bestätigt') : 'Adresse nicht geprüft'}
            </span>
          </dd>
          <dt>Gewünschter Benutzername</dt>
          <dd>{r.username}</dd>
          {companies && (
            <>
              <dt>Kompanie</dt>
              <dd>
                {r.company_choice === 'none'
                  ? 'Keine Kompanie'
                  : r.company_choice === 'passive'
                    ? 'Passiv'
                    : r.companies && r.companies.length > 0
                      ? r.companies.map((c) => c.name).join(', ')
                      : 'keine Angabe'}
              </dd>
            </>
          )}
          {r.message && (
            <>
              <dt>Nachricht</dt>
              <dd className="registration-message">{r.message}</dd>
            </>
          )}
        </dl>
      </section>
    </>
  );
}
