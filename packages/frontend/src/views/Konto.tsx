import { Suspense, useEffect, useRef, useState, type FormEvent } from 'react';
import { MyAdmission } from './Aufnahmen';
import { api, can, config, isMember, roleLabels, type OwnAccount } from '../api';
import { SIGNATURE_RETURN_KEY } from '../board';
import brand from '../generated/brand';
import { t } from '../i18n';
import { isStandalone } from '../install';
import { LanguageCard } from '../language';
import { OwnBirthdayCard, ownBirthday } from '../birthdays';
import { OwnJoinYearCard, profileChoice } from '../joinYear';
import { navigate, useRoute } from '../router';
import RichTextView from '../richtext/RichTextView';
import { AddressFields, EmailField, ErrorBox, lazyView, Loading, PhoneField, useAsync } from '../ui';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { addressInput, EMPTY_ADDRESS, formatAddress } from '../address';
import { LegalLinks } from '../legal';
import { MemberAvatar } from '../memberPhoto';
import { downloadPersonalData } from '../personalData';
import { previewRole } from '../preview';
import { saveTheme, THEME_LABELS, useTheme, type ThemeChoice } from '../theme';
import { sources } from '../viewData';
import { confirmAsk } from '../ask';
import { AiTokenList, McpUrl } from '../aiAccess';

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
  brett: t('‹ Zurück zur Nachricht ({section})', { section: brand.labels.sections.board }),
  'verwaltung/mail': t('‹ Zurück zur E-Mail'),
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
      onToast(r.signature ? t('Signatur gespeichert') : t('Signatur gelöscht'));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!(await confirmAsk(t('Signatur löschen? Nachrichten, in die du sie schon eingefügt hast, bleiben unverändert.')))) return;
    setBusy(true);
    try {
      await api.deleteSignature();
      setSaved(null);
      setDraft(null);
      onToast(t('Signatur gelöscht'));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card account-card signature-card" id="signatur" ref={ref}>
      <h3>{t('Signatur')}</h3>
      {saved === undefined && !error ? (
        <Loading shape="text" />
      ) : draft !== null ? (
        <>
          <Suspense fallback={<Loading shape="text" chunk />}>
            <RichTextEditor value={draft} onChange={setDraft} label={t('Signatur')} id="signature-text" />
          </Suspense>
          <div className="button-row">
            <button className="btn signature-save" disabled={busy} onClick={() => void save()}>
              {busy ? t('Speichert …') : t('Speichern')}
            </button>
            <button className="btn btn-secondary" disabled={busy} onClick={() => setDraft(null)}>
              {t('Abbrechen')}
            </button>
          </div>
        </>
      ) : saved ? (
        <>
          <span className="muted small">{t('Vorschau')}</span>
          <RichTextView html={saved} className="signature-preview" />
          <div className="button-row">
            <button className="btn btn-secondary signature-edit" onClick={() => setDraft(saved)}>
              {t('Ändern')}
            </button>
            <button className="btn btn-danger" disabled={busy} onClick={() => void remove()}>
              {t('Löschen')}
            </button>
          </div>
        </>
      ) : (
        <button className="btn btn-secondary btn-block signature-edit" onClick={() => setDraft('')}>
          {t('Signatur einrichten')}
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
    if (!current) return setFormError(t('Bitte das aktuelle Passwort eingeben.'));
    if (next.length < MIN_LENGTH) return setFormError(t('Das neue Passwort muss mindestens {min} Zeichen lang sein.', { min: MIN_LENGTH }));
    if (next !== repeat) return setFormError(t('Die beiden neuen Passwörter stimmen nicht überein.'));
    if (next === current) return setFormError(t('Das neue Passwort muss sich vom aktuellen unterscheiden.'));
    setBusy(true);
    try {
      await api.changePassword(current, next);
      setCurrent('');
      setNext('');
      setRepeat('');
      onToast(t('Passwort geändert – andere Geräte wurden abgemeldet'));
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
          {(config?.user?.extra_roles ?? []).length > 0
            ? t('Rollen: {roles}', { roles: roleLabels(config?.user ?? { level: 'guest' }) })
            : t('Rolle: {roles}', { roles: roleLabels(config?.user ?? { level: 'guest' }) })}
        </p>
      </div>

      {/* Anwärter sehen hier ihren Stand für die Aufnahme; mit Mitgliederbereich steht er auch unter Meins → Übersicht. */}
      {config?.features.attendance && !isMember() && <MyAdmission />}

      <ContactCard onToast={onToast} />

      {ownBirthday() && <OwnBirthdayCard onToast={onToast} />}
      {profileChoice() && <OwnJoinYearCard onToast={onToast} />}

      <div className="card account-card">
        <h3>{t('Benachrichtigungen')}</h3>
        <p className="muted small">{t('Auf diesem Gerät oder per E-Mail benachrichtigt werden, wenn es Neues für dich gibt.')}</p>
        <button className="btn btn-secondary btn-block push-konto" onClick={() => navigate('benachrichtigungen')}>
          {t('Benachrichtigungen einstellen')}
        </button>
      </div>

      {signatureAllowed() && <SignatureCard onToast={onToast} />}

      {/* In der Vorschau gibt es keinen Export (der Server lehnt ihn ab) */}
      {!previewRole() && <MyDataCard />}

      <div className="card account-card">
        <h3>{t('Darstellung')}</h3>
        <nav className="segmented theme-choice" aria-label={t('Darstellung')}>
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

      <LanguageCard />

      {!previewRole() && <AiAccessCard onToast={onToast} />}

      {!isStandalone() && (
        <div className="card account-card">
          <h3>{t('App aufs Handy holen')}</h3>
          <p className="muted small">{t('Mit unserem Logo auf dem Startbildschirm – Schritt für Schritt erklärt.')}</p>
          <button className="btn btn-secondary btn-block install-konto" onClick={() => navigate('installieren')}>
            {t('App installieren')}
          </button>
        </div>
      )}

      <form className="form card account-card" onSubmit={submit}>
        <h3>{t('Passwort ändern')}</h3>
        {/* Verstecktes Benutzerfeld, damit Passwort-Manager (z. B. iCloud-Schlüsselbund) das Konto zuordnen. */}
        <input type="text" name="username" autoComplete="username" value={config?.user?.login ?? ''} readOnly hidden />
        <label className="field">
          <span>{t('Aktuelles Passwort')}</span>
          <input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
        </label>
        <label className="field">
          <span>{t('Neues Passwort (mind. {min} Zeichen)', { min: MIN_LENGTH })}</span>
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
          <span>{t('Neues Passwort wiederholen')}</span>
          <input type="password" autoComplete="new-password" value={repeat} onChange={(e) => setRepeat(e.target.value)} required />
        </label>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? t('Speichert …') : t('Passwort ändern')}
        </button>
        <p className="muted small">{t('Nach der Änderung bleibst du hier angemeldet; auf anderen Geräten musst du dich neu anmelden.')}</p>
      </form>

      <button className="btn btn-danger btn-block logout" onClick={onLogout}>
        {t('Abmelden')}
      </button>
      <LegalLinks className="account-legal" />
    </div>
  );
}

/**
 * KI-Assistenten (Plan „MCP-Server“): Adresse zum Eintragen und die eigenen Zugänge zum Entfernen; nur, wenn der Zugang in
 * der App an ist oder es noch Zugänge gibt.
 */
function AiAccessCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, setData } = useAsync(() => api.myAiAccess(), [], 'me/ai-access');
  if (!data || (!data.enabled && data.tokens.length === 0)) return null;
  return (
    <section className="card account-card ai-access-card stack">
      <h3>{t('KI-Assistenten')}</h3>
      <p className="muted small">
        {data.enabled
          ? t('Verbinde z. B. Claude mit der App. Der KI-Assistent handelt dann mit deinen Rechten.')
          : t('Der Zugang für KI-Assistenten ist in dieser App ausgeschaltet.')}
      </p>
      {data.enabled && <McpUrl access={data} onToast={onToast} />}
      <AiTokenList
        tokens={data.tokens}
        onToast={onToast}
        onRevoke={async (id) => {
          await api.revokeMyAiAccess(id);
          setData({ ...data, tokens: data.tokens.filter((x) => x.id !== id) });
        }}
      />
    </section>
  );
}

/** Auskunft nach Art. 15 DSGVO: alle gespeicherten Daten der Person als JSON-Datei. */
function MyDataCard() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div className="card account-card">
      <h3>{t('Meine Daten')}</h3>
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
        {busy ? t('Wird zusammengestellt …') : t('Meine Daten herunterladen')}
      </button>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * Eigene E-Mail-Adresse, Handynummer und Adresse ansehen und ändern; eine neue E-Mail-Adresse nur mit dem aktuellen
 * Passwort. Andere sehen sie nur mit dem Recht „Persönliche Angaben sehen“.
 */
function ContactCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.ownAccount.load, sources.ownAccount.deps, sources.ownAccount.key);
  const [editing, setEditing] = useState<OwnAccount | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const account = data!;
  const emailChanged = editing !== null && normalizeEmail(editing.email).toLowerCase() !== account.email.toLowerCase();

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setFormError(null);
    if (emailChanged && !password) return setFormError(t('Bitte zur Bestätigung das aktuelle Passwort eingeben.'));
    setBusy(true);
    try {
      setData(
        await api.updateOwnAccount({
          email: normalizeEmail(editing.email),
          phone: normalizePhone(editing.phone),
          address: addressInput(editing.address),
          ...(emailChanged ? { current_password: password } : {}),
        }),
      );
      setEditing(null);
      setPassword('');
      onToast(t('Gespeichert'));
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
      <h3>{t('Kontakt')}</h3>
      <EmailField
        label={t('E-Mail-Adresse')}
        autoComplete="email"
        value={editing.email}
        onChange={(email) => setEditing({ ...editing, email })}
      />
      <PhoneField
        label={t('Handynummer')}
        autoComplete="tel"
        value={editing.phone}
        onChange={(phone) => setEditing({ ...editing, phone })}
      />
      <AddressFields
        label={t('Adresse')}
        own
        value={editing.address ?? EMPTY_ADDRESS}
        onChange={(address) => setEditing({ ...editing, address })}
      />
      {emailChanged && (
        <label className="field">
          <span>{t('Aktuelles Passwort (zur Bestätigung der neuen E-Mail-Adresse)')}</span>
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
          {busy ? t('Speichert …') : t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={cancel} disabled={busy}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  ) : (
    <div className="card account-card">
      <h3>{t('Kontakt')}</h3>
      <dl className="contact-list">
        <dt>{t('E-Mail-Adresse')}</dt>
        <dd>{account.email || <span className="muted">{t('nicht hinterlegt')}</span>}</dd>
        <dt>{t('Handynummer')}</dt>
        <dd>{account.phone || <span className="muted">{t('nicht hinterlegt')}</span>}</dd>
        <dt>{t('Adresse')}</dt>
        <dd className="address-lines">
          {formatAddress(account.address).join('\n') || <span className="muted">{t('nicht hinterlegt')}</span>}
        </dd>
      </dl>
      <p className="muted small">{t('Sehen können das nur du und wer in der Verwaltung persönliche Angaben sehen darf.')}</p>
      <button className="btn btn-secondary btn-block" onClick={() => setEditing(account)}>
        {t('Ändern')}
      </button>
    </div>
  );
}
