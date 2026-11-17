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
import { ErrorBox, Loading, useAsync } from '../ui';
import { NewAccountRole } from './Konten';
import { confirmAsk } from '../ask';

/**
 * Verwaltung → Anfragen (Issue #314): Anfragen auf einen Zugang freigeben (Konto mit gewählter Rolle und dem Passwort aus
 * der Anfrage) oder ablehnen. Sehen mit „Konten sehen“, freigeben und ablehnen mit „Mitglieder anlegen“; Rollen nur, die
 * man vergeben darf (prüft der Server).
 * Passt die Anfrage zu einem Mitglied ohne Zugang (z. B. für Chroniken angelegt), lässt sie sich damit verbinden statt ein
 * zweites Konto anzulegen; Vorschläge macht der Server, wählen lässt sich jedes Mitglied ohne Zugang.
 * Mit dem Bereich Kompanien steht die Kompanie dabei, die die Person gewählt hat; die Freigabe ordnet sie ihr zu. Ändern
 * lässt sich die Wahl dabei nur mit „Kompanien verwalten“.
 */
export function Anfragen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.registrations(), [], 'registrations');
  if (loading && !data) return <Loading shape="people" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const editable = can('create_users');
  const done = (id: number, msg: string) => {
    const requests = list.requests.filter((r) => r.id !== id);
    setData({ ...list, requests });
    applyPendingRegistrations(requests.length);
    onToast(msg);
  };
  return (
    <div className="registrations">
      <h2 className="section-title">Anfragen auf einen Zugang</h2>
      {!config?.registration && (
        <p className="muted small">„Zugang beantragen“ ist ausgeschaltet. Offene Anfragen lassen sich trotzdem noch bearbeiten.</p>
      )}
      {list.requests.length === 0 ? (
        <p className="muted">Zurzeit wartet keine Anfrage auf Freigabe.</p>
      ) : (
        <ul className="card-list">
          {list.requests.map((r) => (
            <li key={r.id}>
              <RequestCard
                request={r}
                defaultRole={list.default_role}
                withMail={list.email_confirmation}
                noAccess={list.no_access_members ?? []}
                companies={list.companies}
                editable={editable}
                onDone={done}
              />
            </li>
          ))}
        </ul>
      )}
      <p className="muted small">
        Unbearbeitete Anfragen werden nach 30 Tagen gelöscht.
        {list.email_confirmation
          ? ' Die Person bekommt über Freigabe oder Ablehnung eine Mail.'
          : ' Ohne Mailversand erfährt die Person das Ergebnis, wenn sie sich anmeldet oder den Stand ihrer Anfrage prüft.'}
      </p>
    </div>
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

  const head = (
    <>
      <div className="row-title">{r.name}</div>
      <div className="row-sub">
        {r.email}
        {withMail ? (r.email_confirmed ? ' · Adresse bestätigt' : ' · Adresse nicht bestätigt') : ' · Adresse nicht geprüft'} · angefragt am{' '}
        {formatDate(r.created_at.slice(0, 10))}
      </div>
      {companies && (
        <div className="row-sub">
          Kompanie: {r.companies && r.companies.length > 0 ? r.companies.map((c) => c.name).join(', ') : 'keine angegeben'}
        </div>
      )}
      {r.message && <p className="registration-message">{r.message}</p>}
    </>
  );
  if (!editable) {
    return (
      <div className="card registration-request">
        {head}
        {matches.length > 0 && (
          <p className="muted small">Könnte passen zu: {matches.map((m) => `${m.name} (${MATCH_LABELS[m.match]})`).join(', ')}</p>
        )}
      </div>
    );
  }

  return (
    <div className="card registration-request">
      {head}
      {(matches.length > 0 || noAccess.length > 0) && (
        <fieldset className="fieldset registration-link">
          <legend>Mitglied ohne Zugang verbinden?</legend>
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
        </fieldset>
      )}
      {companyChoices && companyChoices.length > 0 && (
        <fieldset className="fieldset registration-companies">
          <legend>Kompanie zuordnen</legend>
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
        </fieldset>
      )}
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
        <div className="row-actions">
          <button type="button" className="btn btn-danger" disabled={busy} onClick={reject}>
            Ablehnen
          </button>
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setRejecting(false)}>
            Abbrechen
          </button>
        </div>
      ) : (
        <div className="row-actions">
          <button type="button" className="btn" disabled={busy || r.email_taken} onClick={approve}>
            {busy ? 'Gibt frei …' : linkTo ? 'Freigeben und verbinden' : 'Freigeben'}
          </button>
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setRejecting(true)}>
            Ablehnen …
          </button>
        </div>
      )}
    </div>
  );
}
