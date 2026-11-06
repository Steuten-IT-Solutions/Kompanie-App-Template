import { useState } from 'react';
import { api, applyPendingRegistrations, config, newAccountRoles, type Level, type RegistrationRequest } from '../api';
import { formatDate } from '../format';
import { ErrorBox, Loading, useAsync } from '../ui';
import { NewAccountRole } from './Konten';

/**
 * Verwaltung → Anfragen (Issue #314): Anfragen auf einen Zugang freigeben (Konto mit gewählter Rolle und dem Passwort aus
 * der Anfrage) oder ablehnen. Für alle mit „Mitglieder anlegen“; Rollen nur, die man vergeben darf (prüft der Server).
 */
export function Anfragen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.registrations(), [], 'registrations');
  if (loading && !data) return <Loading shape="people" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
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
              <RequestCard request={r} defaultRole={list.default_role} withMail={list.email_confirmation} onDone={done} />
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

function RequestCard({
  request: r,
  defaultRole,
  withMail,
  onDone,
}: {
  request: RegistrationRequest;
  defaultRole: Level;
  withMail: boolean;
  onDone: (id: number, msg: string) => void;
}) {
  const roles = newAccountRoles();
  const [level, setLevel] = useState<Level>(roles.some((x) => x.key === defaultRole) ? defaultRole : 'member');
  const [username, setUsername] = useState(r.username);
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
  const approve = () =>
    run(async () => {
      const res = await api.approveRegistration(r.id, { level, ...(username.trim() !== r.username ? { username: username.trim() } : {}) });
      return `${res.member.name} ist freigegeben${res.mailed ? ' und per Mail informiert' : ''}`;
    });
  const reject = () =>
    run(async () => {
      const res = await api.rejectRegistration(r.id, reason.trim());
      return `Anfrage abgelehnt${res.mailed ? ', die Person ist per Mail informiert' : ''}`;
    });

  return (
    <div className="card registration-request">
      <div className="row-title">{r.name}</div>
      <div className="row-sub">
        {r.email}
        {withMail ? (r.email_confirmed ? ' · Adresse bestätigt' : ' · Adresse nicht bestätigt') : ' · Adresse nicht geprüft'} · angefragt am{' '}
        {formatDate(r.created_at.slice(0, 10))}
      </div>
      {r.message && <p className="registration-message">{r.message}</p>}
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
      <NewAccountRole label="Rolle" value={level} onChange={setLevel} />
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
            {busy ? 'Gibt frei …' : 'Freigeben'}
          </button>
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => setRejecting(true)}>
            Ablehnen …
          </button>
        </div>
      )}
    </div>
  );
}
