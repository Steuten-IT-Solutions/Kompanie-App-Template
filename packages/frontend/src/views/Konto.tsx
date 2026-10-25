import { useState, type FormEvent } from 'react';
import { api, config, roleLabels, type OwnAccount } from '../api';
import { isStandalone } from '../install';
import { birthdaysVisible, OwnBirthdayCard } from '../birthdays';
import { navigate } from '../router';
import { MemberAvatar } from '../memberPhoto';
import { ErrorBox, Loading, useAsync } from '../ui';

const MIN_LENGTH = 8;

export default function Konto({ onToast, onLogout }: { onToast: (msg: string) => void; onLogout: () => void }) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [repeat, setRepeat] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!current) return setFormError('Bitte das aktuelle Passwort eingeben.');
    if (next.length < MIN_LENGTH) return setFormError(`Das neue Passwort muss mindestens ${MIN_LENGTH} Zeichen lang sein.`);
    if (next !== repeat) return setFormError('Die beiden neuen Passwörter stimmen nicht überein.');
    if (next === current) return setFormError('Das neue Passwort muss sich vom aktuellen unterscheiden.');
    setBusy(true);
    try {
      await api.changePassword(current, next);
      setCurrent('');
      setNext('');
      setRepeat('');
      onToast('Passwort geändert – andere Geräte wurden abgemeldet');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="card account-card">
        {/* Eigenes Foto: hochladen und ändern nur, wer Mitglieder anlegt (in der Verwaltung). */}
        {config?.user?.photo_url && <MemberAvatar url={config.user.photo_url} name={config.user.name} size="large" />}
        <h2 className="account-name">{config?.user?.name}</h2>
        <p className="muted small">
          {(config?.user?.extra_roles ?? []).length > 0 ? 'Rollen' : 'Rolle'}: {roleLabels(config?.user ?? { level: 'guest' })}
        </p>
      </div>

      <ContactCard onToast={onToast} />

      {birthdaysVisible() && <OwnBirthdayCard onToast={onToast} />}

      <div className="card account-card">
        <h3>Benachrichtigungen</h3>
        <p className="muted small">Auf diesem Gerät benachrichtigt werden, wenn es Neues für dich gibt.</p>
        <button className="btn btn-secondary btn-block push-konto" onClick={() => navigate('benachrichtigungen')}>
          Benachrichtigungen einstellen
        </button>
      </div>

      {!isStandalone() && (
        <div className="card account-card">
          <h3>App aufs Handy holen</h3>
          <p className="muted small">Mit unserem Logo auf dem Startbildschirm – Schritt für Schritt erklärt.</p>
          <button className="btn btn-secondary btn-block install-konto" onClick={() => navigate('installieren')}>
            App installieren
          </button>
        </div>
      )}

      <form className="form card account-card" onSubmit={submit}>
        <h3>Passwort ändern</h3>
        {/* Verstecktes Benutzerfeld, damit Passwort-Manager (z. B. iCloud-Schlüsselbund) das Konto zuordnen. */}
        <input type="text" name="username" autoComplete="username" value={config?.user?.login ?? ''} readOnly hidden />
        <label className="field">
          <span>Aktuelles Passwort</span>
          <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
        </label>
        <label className="field">
          <span>Neues Passwort (mind. {MIN_LENGTH} Zeichen)</span>
          <input
            type="password"
            autoComplete="new-password"
            minLength={MIN_LENGTH}
            value={next}
            onChange={(e) => setNext(e.target.value)}
            required
          />
        </label>
        <label className="field">
          <span>Neues Passwort wiederholen</span>
          <input type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} required />
        </label>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Passwort ändern'}
        </button>
        <p className="muted small">Nach der Änderung bleibst du hier angemeldet; auf anderen Geräten musst du dich neu anmelden.</p>
      </form>

      <button className="btn btn-danger btn-block logout" onClick={onLogout}>
        Abmelden
      </button>
    </div>
  );
}

/** Eigene E-Mail-Adresse und Handynummer ansehen und ändern; eine neue E-Mail-Adresse nur mit dem aktuellen Passwort. */
function ContactCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.ownAccount, [], 'me/account');
  const [editing, setEditing] = useState<OwnAccount | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const account = data!;
  const emailChanged = editing !== null && editing.email.trim().toLowerCase() !== account.email.toLowerCase();

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setFormError(null);
    if (emailChanged && !password) return setFormError('Bitte zur Bestätigung das aktuelle Passwort eingeben.');
    setBusy(true);
    try {
      setData(
        await api.updateOwnAccount({
          email: editing.email.trim(),
          phone: editing.phone.trim(),
          ...(emailChanged ? { current_password: password } : {}),
        }),
      );
      setEditing(null);
      setPassword('');
      onToast('Gespeichert');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function cancel() {
    setEditing(null);
    setPassword('');
    setFormError(null);
  }

  return editing ? (
    <form className="form card account-card" onSubmit={save}>
      <h3>Kontakt</h3>
      <label className="field">
        <span>E-Mail-Adresse</span>
        <input
          type="email"
          autoComplete="email"
          value={editing.email}
          onChange={(e) => setEditing({ ...editing, email: e.target.value })}
        />
      </label>
      <label className="field">
        <span>Handynummer</span>
        <input
          type="tel"
          autoComplete="tel"
          placeholder="z. B. +49 171 1234567"
          value={editing.phone}
          onChange={(e) => setEditing({ ...editing, phone: e.target.value })}
        />
      </label>
      {emailChanged && (
        <label className="field">
          <span>Aktuelles Passwort (zur Bestätigung der neuen E-Mail-Adresse)</span>
          <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={cancel} disabled={busy}>
          Abbrechen
        </button>
      </div>
    </form>
  ) : (
    <div className="card account-card">
      <h3>Kontakt</h3>
      <dl className="contact-list">
        <dt>E-Mail-Adresse</dt>
        <dd>{account.email || <span className="muted">nicht hinterlegt</span>}</dd>
        <dt>Handynummer</dt>
        <dd>{account.phone || <span className="muted">nicht hinterlegt</span>}</dd>
      </dl>
      <button className="btn btn-secondary btn-block" onClick={() => setEditing(account)}>
        Ändern
      </button>
    </div>
  );
}
