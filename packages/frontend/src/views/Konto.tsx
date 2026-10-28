import { Suspense, useEffect, useRef, useState, type FormEvent } from 'react';
import { api, can, config, roleLabels, type MailPreference, type OwnAccount } from '../api';
import { boardVisible, SIGNATURE_RETURN_KEY } from '../board';
import brand from '../generated/brand';
import { isStandalone } from '../install';
import { OwnBirthdayCard, ownBirthday } from '../birthdays';
import { OwnJoinYearCard, profileChoice } from '../joinYear';
import { navigate, useRoute } from '../router';
import RichTextView from '../richtext/RichTextView';
import { EmailField, ErrorBox, lazyView, Loading, PhoneField, useAsync } from '../ui';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { MemberAvatar } from '../memberPhoto';
import { downloadPersonalData } from '../personalData';
import { saveTheme, THEME_LABELS, useTheme, type ThemeChoice } from '../theme';

const MIN_LENGTH = 8;

// Editor (TipTap): erst laden, wenn jemand die Signatur bearbeitet.
const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/**
 * Eine Signatur einrichten können Mitglieder und Anwärter sowie alle, die am Brett schreiben oder E-Mails versenden (wie
 * SignatureService::allowed); genutzt wird sie am Brett und bei E-Mails, deshalb nur mit einem dieser Bereiche.
 */
const signatureAllowed = () =>
  (!!config?.features.board && (can('member') || can('calendar') || can('post_board'))) || (!!config?.features.mail && can('send_mail'));

/** Rückwege von der Signatur-Einrichtung zum Entwurf (composeEditor.tsx): Seite => Beschriftung des Knopfs. */
const RETURN_LABELS: Record<string, string> = {
  brett: `‹ Zurück zur Nachricht (${brand.labels.sections.board})`,
  'verwaltung/mail': '‹ Zurück zur E-Mail',
};

function returnTarget(): string | null {
  try {
    return sessionStorage.getItem(SIGNATURE_RETURN_KEY);
  } catch {
    return null;
  }
}

/**
 * Signatur (Issue #172): formatiert mit Text und Bildern, mit Vorschau; einrichten, ändern, löschen. Kommt man vom
 * Schreiben am Brett oder einer E-Mail (Signatur noch nicht eingerichtet), führt ein Knopf zurück; der Entwurf dort bleibt
 * erhalten.
 */
function SignatureCard({ onToast }: { onToast: (msg: string) => void }) {
  const [saved, setSaved] = useState<string | null | undefined>(undefined);
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [back, setBack] = useState(returnTarget);
  const ref = useRef<HTMLElement>(null);
  const focus = useRoute()[2] === 'signatur';

  useEffect(() => {
    api.signature().then(
      (r) => {
        setSaved(r.signature?.content ?? null);
        if (focus && !r.signature) setDraft('');
      },
      (err: Error) => setError(err.message),
    );
  }, [focus]);
  useEffect(() => {
    if (focus) ref.current?.scrollIntoView({ block: 'start' });
  }, [focus, saved]);

  const goBack = () => {
    try {
      sessionStorage.removeItem(SIGNATURE_RETURN_KEY);
    } catch {
      // egal
    }
    setBack(null);
    if (back) navigate(back);
  };

  async function save() {
    if (draft === null) return;
    setBusy(true);
    setError(null);
    try {
      const r = await api.saveSignature(draft);
      setSaved(r.signature?.content ?? null);
      setDraft(null);
      onToast(r.signature ? 'Signatur gespeichert' : 'Signatur gelöscht');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!window.confirm('Signatur löschen? Nachrichten, in die du sie schon eingefügt hast, bleiben unverändert.')) return;
    setBusy(true);
    try {
      await api.deleteSignature();
      setSaved(null);
      setDraft(null);
      onToast('Signatur gelöscht');
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card account-card signature-card" id="signatur" ref={ref}>
      <h3>Signatur</h3>
      <p className="muted small">
        Frei gestaltet wie in einem Mailprogramm, mit Text und Bildern. Beim Schreiben einer Nachricht (Bereich „
        {brand.labels.sections.board}“) fügst du sie mit „Signatur einfügen“ am Ende ein; spätere Änderungen hier ändern schon geschriebene
        Nachrichten nicht.
      </p>
      {saved === undefined && !error ? (
        <Loading />
      ) : draft !== null ? (
        <>
          <Suspense fallback={<Loading />}>
            <RichTextEditor value={draft} onChange={setDraft} label="Signatur" id="signature-text" />
          </Suspense>
          <div className="button-row">
            <button className="btn signature-save" disabled={busy} onClick={() => void save()}>
              {busy ? 'Speichert …' : 'Speichern'}
            </button>
            <button className="btn btn-secondary" disabled={busy} onClick={() => setDraft(null)}>
              Abbrechen
            </button>
          </div>
        </>
      ) : saved ? (
        <>
          <span className="muted small">Vorschau</span>
          <RichTextView html={saved} className="signature-preview" />
          <div className="button-row">
            <button className="btn btn-secondary signature-edit" onClick={() => setDraft(saved)}>
              Ändern
            </button>
            <button className="btn btn-danger" disabled={busy} onClick={() => void remove()}>
              Löschen
            </button>
          </div>
        </>
      ) : (
        <button className="btn btn-secondary btn-block signature-edit" onClick={() => setDraft('')}>
          Signatur einrichten
        </button>
      )}
      {error && <p className="form-error small">{error}</p>}
      {back && RETURN_LABELS[back] && draft === null && (
        <button className="btn btn-block signature-back" onClick={goBack}>
          {RETURN_LABELS[back]}
        </button>
      )}
    </section>
  );
}

/** Nachrichten vom Brett per E-Mail (Issue #172): Standard an, für Mitglieder und Anwärter. */
function BoardMailToggle({ onToast }: { onToast: (msg: string) => void }) {
  const [pref, setPref] = useState<MailPreference | null>(null);
  useEffect(() => {
    api.mailPreference().then(setPref, () => setPref(null));
  }, []);
  if (!pref || !pref.available) return null;
  return (
    <>
      <label className="toggle board-mail-pref">
        <input
          type="checkbox"
          checked={pref.board}
          onChange={(e) =>
            void api.setMailPreference(e.target.checked).then(
              (p) => {
                setPref(p);
                onToast(p.board ? 'Mails vom Brett: an' : 'Mails vom Brett: aus');
              },
              (err: Error) => onToast(err.message),
            )
          }
        />
        <span>Nachrichten aus „{brand.labels.sections.board}“ per E-Mail</span>
      </label>
      {pref.board && !pref.email && (
        <p className="muted small">Für dein Konto ist keine Mailadresse hinterlegt; bitte beim Admin melden.</p>
      )}
    </>
  );
}

export default function Konto({ onToast, onLogout }: { onToast: (msg: string) => void; onLogout: () => void }) {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [repeat, setRepeat] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const theme = useTheme().choice;

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

      {ownBirthday() && <OwnBirthdayCard onToast={onToast} />}
      {profileChoice() && <OwnJoinYearCard onToast={onToast} />}

      <div className="card account-card">
        <h3>Benachrichtigungen</h3>
        <p className="muted small">Auf diesem Gerät benachrichtigt werden, wenn es Neues für dich gibt.</p>
        <button className="btn btn-secondary btn-block push-konto" onClick={() => navigate('benachrichtigungen')}>
          Benachrichtigungen einstellen
        </button>
        {boardVisible() && <BoardMailToggle onToast={onToast} />}
      </div>

      {signatureAllowed() && <SignatureCard onToast={onToast} />}

      <MyDataCard />

      <div className="card account-card">
        <h3>Darstellung</h3>
        <p className="muted small">
          Hell, dunkel oder wie auf dem Gerät eingestellt. Gilt nur auf diesem Gerät; umschalten geht auch oben mit dem Knopf neben „?“.
        </p>
        <nav className="segmented theme-choice" aria-label="Darstellung">
          {(Object.keys(THEME_LABELS) as ThemeChoice[]).map((choice) => (
            <button
              key={choice}
              className={theme === choice ? 'active' : ''}
              onClick={() => saveTheme(choice)}
              aria-pressed={theme === choice}
            >
              {THEME_LABELS[choice]}
            </button>
          ))}
        </nav>
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
/** Auskunft nach Art. 15 DSGVO: alle gespeicherten Daten der Person als JSON-Datei. */
function MyDataCard() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="card account-card">
      <h3>Meine Daten</h3>
      <p className="muted small">
        Alles, was die App über dich gespeichert hat, als Datei (JSON) zum Herunterladen, z. B. für eine Auskunft nach der DSGVO.
      </p>
      <button
        className="btn btn-secondary btn-block"
        disabled={busy}
        onClick={() => {
          setBusy(true);
          setError(null);
          downloadPersonalData()
            .catch((err: Error) => setError(err.message))
            .finally(() => setBusy(false));
        }}
      >
        {busy ? 'Wird zusammengestellt …' : 'Meine Daten herunterladen'}
      </button>
      {error && <p className="form-error small">{error}</p>}
    </div>
  );
}

function ContactCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.ownAccount, [], 'me/account');
  const [editing, setEditing] = useState<OwnAccount | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const account = data!;
  const emailChanged = editing !== null && normalizeEmail(editing.email).toLowerCase() !== account.email.toLowerCase();

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setFormError(null);
    if (emailChanged && !password) return setFormError('Bitte zur Bestätigung das aktuelle Passwort eingeben.');
    setBusy(true);
    try {
      setData(
        await api.updateOwnAccount({
          email: normalizeEmail(editing.email),
          phone: normalizePhone(editing.phone),
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
      <EmailField
        label="E-Mail-Adresse"
        autoComplete="email"
        value={editing.email}
        onChange={(email) => setEditing({ ...editing, email })}
      />
      <PhoneField label="Handynummer" autoComplete="tel" value={editing.phone} onChange={(phone) => setEditing({ ...editing, phone })} />
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
