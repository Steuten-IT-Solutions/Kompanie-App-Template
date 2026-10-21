import { useState, type FormEvent } from 'react';
import { api, can, config, roleLabels } from '../api';
import { isStandalone } from '../install';
import { birthdaysVisible, OwnBirthdayCard } from '../birthdays';
import { navigate } from '../router';

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
        <h2 className="account-name">{config?.user?.name}</h2>
        <p className="muted small">
          {(config?.user?.extra_roles ?? []).length > 0 ? 'Rollen' : 'Rolle'}: {roleLabels(config?.user ?? { level: 'guest' })}
        </p>
      </div>

      {birthdaysVisible() && <OwnBirthdayCard onToast={onToast} />}

      {can('manage_settings') && (
        <div className="card account-card">
          <h3>Einstellungen</h3>
          <p className="muted small">Zustand der App, Updates, Datensicherung, Kalender, Galerie und Neuigkeiten.</p>
          <button className="btn btn-secondary btn-block" onClick={() => navigate('einstellungen')}>
            Einstellungen öffnen
          </button>
        </div>
      )}

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
