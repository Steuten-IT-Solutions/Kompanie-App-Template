import { useEffect, useRef, useState, type FormEvent } from 'react';
import { api, config, toLogin, type RegistrationStatus } from '../api';
import { normalizeEmail } from '../email';
import { t } from '../i18n';
import { navigate } from '../router';
import { EmailField, ErrorBox, Loading, useAsync } from '../ui';

/** Wie Passwords::loginBase auf dem Server: „Max Müller“ → „max.mueller“ (nur ein Vorschlag, änderbar). */
export function loginSuggestion(first: string, last: string): string {
  const s = `${first} ${last}`
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '.')
    .replace(/^\.+|\.+$/g, '')
    .slice(0, 50);
  return s;
}

const MESSAGE_MAX = 500;
const MIN_PASSWORD = 8;

/**
 * Zugang beantragen (Issue #314): #/registrieren (Formular), #/registrieren/bestaetigen/<token> (Link aus der Mail),
 * #/registrieren/stand (Stand der eigenen Anfrage, z. B. bei WordPress, wo die Anmeldung nicht in der App ist).
 */
export default function Registrieren({ path }: { path: string[] }) {
  if (path[0] === 'bestaetigen' && path[1]) return <Confirm token={path[1]} />;
  if (path[0] === 'stand') return <StatusCheck />;
  return <RegistrationForm />;
}

function RegistrationForm() {
  const form = useAsync(() => api.registrationForm(), [], 'registration-form');
  const opened = useRef(Date.now());
  const [first, setFirst] = useState('');
  const [last, setLast] = useState('');
  const [email, setEmail] = useState('');
  const [username, setUsername] = useState('');
  const [usernameEdited, setUsernameEdited] = useState(false);
  const [password, setPassword] = useState('');
  const [password2, setPassword2] = useState('');
  const [message, setMessage] = useState('');
  const [companyIds, setCompanyIds] = useState<number[]>([]);
  // Pflicht, sobald es Kompanien zur Wahl gibt; vorgewählt ist nichts, damit jede Wahl bewusst ist.
  const [noCompany, setNoCompany] = useState<'none' | 'passive' | null>(null);
  const [consent, setConsent] = useState(false);
  const [website, setWebsite] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<'confirm_email' | 'pending' | null>(null);

  if (config?.user) {
    return (
      <div className="card">
        <p>{t('Du bist schon angemeldet. Fehlt dir etwas in der App, wende dich bitte an die Admins.')}</p>
      </div>
    );
  }
  if (!config?.registration) return <ErrorBox message={t('In dieser App kann man zurzeit keinen Zugang beantragen.')} />;
  if (form.loading && !form.data) return <Loading shape="form" />;
  if (form.error || !form.data)
    return <ErrorBox message={form.error ?? t('Das Formular konnte nicht geladen werden.')} onRetry={form.reload} />;
  const f = form.data;
  const shownUsername = usernameEdited ? username : loginSuggestion(first, last);
  const companies = f.companies ?? [];

  if (done) {
    // Adresse und Benutzername fett, der Satz drumherum übersetzt.
    const mailSent = t(
      'Wir haben dir eine Mail an {email} geschickt. Tippe auf den Link darin. Danach geht deine Anfrage an die Admins zur Freigabe. Über das Ergebnis bekommst du wieder eine Mail.',
    )
      .split('{email}')
      .concat('');
    const waiting = t(
      'Sie wartet jetzt auf Freigabe durch die Admins. Danach meldest du dich mit dem Benutzernamen {username} und deinem Passwort an.',
    )
      .split('{username}')
      .concat('');
    return (
      <div className="card registration-done" role="status">
        {done === 'confirm_email' ? (
          <>
            <h2 className="section-title">{t('Bitte bestätige deine E-Mail-Adresse')}</h2>
            <p>
              {mailSent[0]}
              <strong>{normalizeEmail(email)}</strong>
              {mailSent[1]}
            </p>
            <p className="muted small">{t('Keine Mail bekommen? Schau bitte auch im Spam-Ordner nach.')}</p>
          </>
        ) : (
          <>
            <h2 className="section-title">{t('Danke, deine Anfrage ist angekommen')}</h2>
            <p>
              {waiting[0]}
              <strong>{shownUsername}</strong>
              {waiting[1]}
            </p>
            <button type="button" className="btn btn-secondary btn-block" onClick={() => navigate('registrieren/stand')}>
              {t('Stand meiner Anfrage')}
            </button>
          </>
        )}
      </div>
    );
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (!first.trim() || !last.trim()) return setError(t('Bitte Vor- und Nachnamen angeben.'));
    if (!normalizeEmail(email)) return setError(t('Bitte deine E-Mail-Adresse angeben.'));
    if (!shownUsername.trim()) return setError(t('Bitte einen Benutzernamen wählen.'));
    if (password.length < MIN_PASSWORD) return setError(t('Das Passwort muss mindestens {min} Zeichen lang sein.', { min: MIN_PASSWORD }));
    if (password !== password2) return setError(t('Die beiden Passwörter stimmen nicht überein.'));
    const chosenIds = companyIds.filter((id) => companies.some((c) => c.id === id));
    if (companies.length > 0 && !noCompany && chosenIds.length === 0) {
      return setError(t('Bitte wähle deine Kompanie, „Keine Kompanie“ oder „Passiv“.'));
    }
    if (!consent) return setError(t('Bitte bestätige, dass du die Datenschutzerklärung gelesen hast.'));
    setBusy(true);
    try {
      // Zeitfalle des Servers: Wer sehr schnell ist, wartet den Rest ab.
      const wait = f.minSeconds * 1000 + 300 - (Date.now() - opened.current);
      if (wait > 0) await new Promise((r) => setTimeout(r, wait));
      const res = await api.register({
        token: f.token,
        consent,
        first_name: first.trim(),
        last_name: last.trim(),
        email: normalizeEmail(email),
        username: shownUsername.trim(),
        password,
        message: message.trim(),
        ...(companies.length > 0
          ? noCompany
            ? { company_choice: noCompany }
            : { company_choice: 'company' as const, company_ids: chosenIds }
          : {}),
        website,
      });
      setDone(res.status);
      window.scrollTo(0, 0);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  // Link zur Datenschutzerklärung mitten im Satz.
  const consentText = t(
    'Ich habe die {privacy} gelesen. Meine Angaben werden nur für die Freigabe gespeichert und gelöscht, wenn die Anfrage abgelehnt wird oder abläuft.',
  )
    .split('{privacy}')
    .concat('');
  return (
    <form className="form card registration-form" onSubmit={submit} noValidate>
      <h2 className="news-headline">{t('Zugang beantragen')}</h2>
      <div className="field-row">
        <label className="field">
          <span>{t('Vorname')}</span>
          <input
            type="text"
            name="given-name"
            autoComplete="given-name"
            value={first}
            onChange={(e) => setFirst(e.target.value)}
            required
          />
        </label>
        <label className="field">
          <span>{t('Nachname')}</span>
          <input
            type="text"
            name="family-name"
            autoComplete="family-name"
            value={last}
            onChange={(e) => setLast(e.target.value)}
            required
          />
        </label>
      </div>
      <EmailField label={t('E-Mail-Adresse')} value={email} onChange={setEmail} autoComplete="email" />
      <label className="field">
        <span>{t('Benutzername')}</span>
        <input
          type="text"
          name="username"
          autoComplete="username"
          autoCapitalize="none"
          autoCorrect="off"
          value={shownUsername}
          onChange={(e) => {
            setUsernameEdited(true);
            setUsername(e.target.value);
          }}
          required
        />
        <span className="muted small">
          {t('Buchstaben (a–z), Ziffern sowie . _ - @. Anmelden kannst du dich auch mit deiner E-Mail-Adresse.')}
        </span>
      </label>
      <label className="field">
        <span>{t('Passwort (mind. {min} Zeichen)', { min: MIN_PASSWORD })}</span>
        <input
          type="password"
          name="new-password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
      </label>
      <label className="field">
        <span>{t('Passwort wiederholen')}</span>
        <input type="password" autoComplete="new-password" value={password2} onChange={(e) => setPassword2(e.target.value)} required />
      </label>
      {companies.length > 0 && (
        <fieldset className="fieldset registration-companies">
          <legend>{t('Deine Kompanie')}</legend>
          <p className="muted small">
            {t('Bitte wähle, zu welcher Kompanie du gehörst (auch mehrere), oder „Keine Kompanie“ bzw. „Passiv“.')}
          </p>
          {companies.map((c) => (
            <label key={c.id} className="toggle">
              <input
                type="checkbox"
                checked={companyIds.includes(c.id)}
                onChange={(e) => {
                  setNoCompany(null);
                  setCompanyIds((ids) => (e.target.checked ? [...ids, c.id] : ids.filter((x) => x !== c.id)));
                }}
              />
              {c.name}
            </label>
          ))}
          {(
            [
              ['none', t('Keine Kompanie')],
              ['passive', t('Passiv')],
            ] as const
          ).map(([key, label]) => (
            <label key={key} className="toggle">
              <input
                type="checkbox"
                checked={noCompany === key}
                onChange={(e) => {
                  setCompanyIds([]);
                  setNoCompany(e.target.checked ? key : null);
                }}
              />
              {label}
            </label>
          ))}
        </fieldset>
      )}
      <label className="field">
        <span>{t('Nachricht an die Admins (freiwillig)')}</span>
        <textarea rows={3} maxLength={MESSAGE_MAX} value={message} onChange={(e) => setMessage(e.target.value)} />
        <span className="muted small">{t('Zum Beispiel, wer du bist oder wer dich kennt. Das hilft bei der Freigabe.')}</span>
      </label>
      {/* Gegen Programme: Menschen sehen dieses Feld nicht und lassen es leer. */}
      <label className="report-hp" aria-hidden="true">
        {t('Webseite')}
        <input type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
      </label>
      <label className="toggle">
        <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
        <span>
          {consentText[0]}
          {f.privacy_url ? (
            <a href={f.privacy_url} target="_blank" rel="noopener noreferrer">
              {t('Datenschutzerklärung')}
            </a>
          ) : (
            t('Datenschutzerklärung')
          )}
          {consentText[1]}
        </span>
      </label>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? t('Sendet …') : t('Anfrage senden')}
      </button>
      <p className="muted small">
        {t('Schon eine Anfrage gestellt?')}{' '}
        <button type="button" className="btn btn-link inline-link" onClick={() => navigate('registrieren/stand')}>
          {t('Stand meiner Anfrage')}
        </button>
      </p>
    </form>
  );
}

/** Link aus der Mail: E-Mail-Adresse bestätigen (einmal, beim Öffnen). */
function Confirm({ token }: { token: string }) {
  const [state, setState] = useState<'busy' | 'done' | string>('busy');
  const started = useRef(false);
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    api.confirmRegistration(token).then(
      () => setState('done'),
      (err: Error) => setState(err.message),
    );
  }, [token]);
  if (state === 'busy') return <Loading />;
  if (state !== 'done') {
    return (
      <div className="card">
        <p className="form-error" role="alert">
          {state}
        </p>
        {config?.registration && (
          <button type="button" className="btn btn-block" onClick={() => navigate('registrieren')}>
            {t('Neue Anfrage stellen')}
          </button>
        )}
      </div>
    );
  }
  return (
    <div className="card registration-done" role="status">
      <h2 className="section-title">{t('Danke, deine E-Mail-Adresse ist bestätigt')}</h2>
      <p>{t('Deine Anfrage wartet jetzt auf Freigabe durch die Admins. Über das Ergebnis bekommst du eine Mail.')}</p>
    </div>
  );
}

const STATUS_TEXT: Record<RegistrationStatus['status'], string> = {
  unconfirmed: t('Du hast deine E-Mail-Adresse noch nicht bestätigt. Bitte tippe auf den Link in der Mail, die wir dir geschickt haben.'),
  pending: t('Deine Anfrage wartet noch auf Freigabe durch die Admins.'),
  rejected: t('Deine Anfrage wurde leider abgelehnt.'),
  none: t('Zu diesen Angaben gibt es keine offene Anfrage. Wurde sie freigegeben, melde dich einfach an.'),
};

/** Stand der eigenen Anfrage: nur mit Benutzername (oder E-Mail-Adresse) und Passwort aus der Anfrage. */
function StatusCheck() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<RegistrationStatus | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setResult(null);
    if (!username.trim() || !password) return setError(t('Bitte Benutzername und Passwort eingeben.'));
    setBusy(true);
    try {
      setResult(await api.registrationStatus(username.trim(), password));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card registration-status" onSubmit={submit}>
      <h2 className="news-headline">{t('Stand meiner Anfrage')}</h2>
      <p className="muted small">{t('Gib den Benutzernamen (oder die E-Mail-Adresse) und das Passwort aus deiner Anfrage ein.')}</p>
      <label className="field">
        <span>{t('Benutzername oder E-Mail-Adresse')}</span>
        <input
          type="text"
          autoComplete="username"
          autoCapitalize="none"
          autoCorrect="off"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          required
        />
      </label>
      <label className="field">
        <span>{t('Passwort')}</span>
        <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
      </label>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {result && (
        <div className="card registration-result" role="status">
          <p>{STATUS_TEXT[result.status]}</p>
          {result.status === 'rejected' && result.reason && <p>{t('Begründung: {reason}', { reason: result.reason })}</p>}
          {result.status === 'none' && (
            <button type="button" className="btn btn-block" onClick={toLogin}>
              {t('Anmelden')}
            </button>
          )}
        </div>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? t('Prüft …') : t('Stand prüfen')}
      </button>
    </form>
  );
}
