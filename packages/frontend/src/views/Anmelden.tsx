import { useState, type FormEvent } from 'react';
import { api, config } from '../api';
import brand from '../generated/brand';
import logoUrl from '../generated/logo.png';

/** Anmeldeformular der eigenständigen Version (bei WordPress geht es zu wp-login.php). */
export default function Anmelden() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (!username.trim() || !password) return setError('Bitte Benutzername und Passwort eingeben.');
    setBusy(true);
    try {
      await api.login(username.trim(), password, remember);
      // Neu laden, damit die App mit der Anmeldung startet.
      window.location.href = config?.appUrl ?? './';
    } catch (err) {
      setError((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <form className="form card login" onSubmit={submit}>
      <img src={logoUrl} alt="" className="login-logo" />
      <h2>{brand.name}</h2>
      <label className="field">
        <span>Benutzername</span>
        <input
          type="text"
          name="username"
          autoComplete="username"
          autoCapitalize="none"
          autoCorrect="off"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          required
        />
      </label>
      <label className="field">
        <span>Passwort</span>
        <input
          type="password"
          name="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
      </label>
      <label className="toggle">
        <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
        Angemeldet bleiben
      </label>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Meldet an …' : 'Anmelden'}
      </button>
      <p className="muted small">Passwort vergessen? Bitte bei einem Admin melden – er kann ein neues Startpasswort erzeugen.</p>
    </form>
  );
}
