import { useState, type FormEvent } from 'react';
import {
  api,
  config,
  levelLabel,
  newAccountExtraRoles,
  newAccountRoles,
  type AccountProfile,
  type BulkResult,
  type CreatedUser,
  type Level,
  type NewPerson,
  type RoleInfo,
} from '../api';
import brand from '../generated/brand';
import { todayYmd } from '../format';
import { installUrl } from '../install';
import { navigate } from '../router';
import { AddressFields, bold, Caps, EmailField, Loading, NameFields, PhoneField, trimName, useAsync } from '../ui';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { addressInput, EMPTY_ADDRESS, isEmptyAddress, type Address } from '../address';
import { BirthdayFields, birthdayString, canEnterBirthdays, emptyBirthday, readBirthday, type BirthdayValue } from '../birthdays';
import { PhotoPicker, type PreparedPhoto } from '../memberPhoto';
import { downloadPersonalData } from '../personalData';
import { confirmAsk } from '../ask';
import { t, tn } from '../i18n';

/** Zugangsdaten als Text zum Weitergeben, mit dem Link zur Installationsanleitung (Issue #24). */
export function credentialsText(c: { name: string; username: string; password?: string }): string {
  return [
    t('{app} – Zugang für {name}', { app: brand.name, name: c.name }),
    t('App installieren: {url}', { url: installUrl() }),
    t('Benutzername: {username}', { username: c.username }),
    t('Passwort: {password}', { password: c.password ?? '' }),
    t('Bitte nach der ersten Anmeldung unter „Meins → Konto“ ein eigenes Passwort setzen.'),
  ].join('\n');
}

/** Zugangsdaten anzeigen, kopieren, teilen und als Zettel ausdrucken. */
export function Credentials({
  people,
  onDone,
  doneLabel = t('‹ Zur Mitgliederliste'),
}: {
  people: CreatedUser[];
  onDone?: () => void;
  doneLabel?: string;
}) {
  const [copied, setCopied] = useState(false);
  // Ohne Zugriff auf die Zwischenablage (z. B. ohne HTTPS): Text zum Markieren zeigen (mehrzeilig, anders als prompt()).
  const [showText, setShowText] = useState(false);
  const text = people.map(credentialsText).join('\n\n');
  async function copy() {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
    } catch {
      setShowText(true);
    }
  }
  async function share() {
    try {
      await navigator.share({ title: t('{app}: Zugangsdaten', { app: brand.shortName }), text });
    } catch {
      /* abgebrochen */
    }
  }
  return (
    <div className="credentials-block">
      <p className="muted small">
        {bold(
          t('Die Startpasswörter werden <b>nur jetzt</b> angezeigt. Bitte ausdrucken, kopieren oder teilen und persönlich weitergeben.'),
        )}
      </p>
      <div className="print-sheets">
        {people.map((c) => (
          <section key={c.id} className="card created print-sheet">
            <h3>{c.name}</h3>
            {c.warning && (
              <p className="form-error" role="alert">
                {c.warning}
              </p>
            )}
            <dl className="credentials">
              <dt>{t('App installieren')}</dt>
              <dd className="credentials-url">{installUrl()}</dd>
              <dt>{t('Benutzername')}</dt>
              <dd data-testid="new-username">{c.username}</dd>
              <dt>{t('Passwort')}</dt>
              <dd data-testid="new-password">{c.password}</dd>
            </dl>
            <p className="small print-only">{t('Bitte nach der ersten Anmeldung unter „Meins → Konto“ ein eigenes Passwort setzen.')}</p>
          </section>
        ))}
      </div>
      {showText && (
        <label className="field no-print">
          <span>{t('Zugangsdaten zum Kopieren')}</span>
          <textarea
            className="credentials-text"
            readOnly
            rows={Math.min(12, people.length * 6)}
            value={text}
            onFocus={(e) => e.target.select()}
            autoFocus
          />
        </label>
      )}
      <div className="button-row no-print">
        <button className="btn" onClick={copy}>
          {copied ? t('Kopiert ✓') : t('Zugangsdaten kopieren')}
        </button>
        <button className="btn btn-secondary" onClick={() => window.print()}>
          {t('Ausdrucken')}
        </button>
      </div>
      {typeof navigator.share === 'function' && (
        <button className="btn btn-secondary btn-block no-print" onClick={share}>
          {t('Teilen …')}
        </button>
      )}
      {onDone && (
        <button className="btn btn-link no-print" onClick={onDone}>
          {doneLabel}
        </button>
      )}
    </div>
  );
}

type Row = NewPerson & { points: string; bday: BirthdayValue; picture: PreparedPhoto | null; postal: Address; noAccess: boolean };

/**
 * Rolle neuer Konten: die Rollen, die der Anlegende vergeben darf (newAccountRoles: Mitglied, die Rollen „für neue Konten“,
 * z. B. Anwärter, und mit „Rollen vergeben“ jede Rolle, die er auch danach vergeben dürfte). Nur eine Rolle: keine
 * Auswahl, neue Konten sind dann Mitglieder.
 */
export function NewAccountRole({
  label,
  value,
  onChange,
  roles = newAccountRoles(),
}: {
  label: string;
  value: Level;
  onChange: (level: Level) => void;
  /** Statt aller Rollen, die der Benutzer vergeben darf (z. B. ohne Admin beim Freigeben) */
  roles?: RoleInfo[];
}) {
  if (roles.length < 2) return null;
  return (
    <label className="field">
      <span>{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        {roles.map((r) => (
          <option key={r.key} value={r.key}>
            {r.label}
          </option>
        ))}
      </select>
    </label>
  );
}

/**
 * Zusatzrollen neuer Konten (Issue #66): nur für alle, die Rollen vergeben dürfen, nur Rollen, die sie vergeben dürfen,
 * und nur zu einer Grundrolle, die zur Kompanie gehört (newAccountExtraRoles). Sonst nichts.
 */
export function NewAccountExtraRoles({ base, value, onChange }: { base: Level; value: Level[]; onChange: (keys: Level[]) => void }) {
  const roles = newAccountExtraRoles(base);
  if (roles.length === 0) return null;
  const toggle = (key: Level, on: boolean) => onChange(roles.map((r) => r.key).filter((k) => (k === key ? on : value.includes(k))));
  return (
    <fieldset className="field role-control extra-roles">
      <legend className="field-label">{t('Zusatzrollen (optional)')}</legend>
      <p className="muted small">{t('Die Person hat die Rechte aller ihrer Rollen zusammen.')}</p>
      {roles.map((r) => (
        <label key={r.key} className="toggle">
          <input type="checkbox" checked={value.includes(r.key)} onChange={(e) => toggle(r.key, e.target.checked)} />
          {r.label}
        </label>
      ))}
    </fieldset>
  );
}

/** Nur die Zusatzrollen, die zur Grundrolle passen (nach einem Wechsel der Grundrolle). */
export const validExtraRoles = (base: Level, keys: Level[]): Level[] => {
  const allowed = newAccountExtraRoles(base).map((r) => r.key);
  return keys.filter((k) => allowed.includes(k));
};

/** Vor dem Anlegen als Admin nachfragen (wie bei „Rollen vergeben“): Admins dürfen alles. */
export async function confirmNewAdmin(level: Level): Promise<boolean> {
  return (
    level !== 'admin' ||
    (await confirmAsk(t('Wirklich als {admin} anlegen? Admins dürfen alles, auch Einstellungen ändern.', { admin: levelLabel('admin') })))
  );
}

const emptyRow = (): Row => ({
  first_name: '',
  last_name: '',
  username: '',
  email: '',
  phone: '',
  postal: EMPTY_ADDRESS,
  points: '',
  bday: emptyBirthday(),
  picture: null,
  noAccess: false,
});

/**
 * Mehrere Mitglieder auf einmal anlegen (Issue #24): je Zeile Vor- und Nachname, „Ohne Zugang“, optional Benutzername,
 * E-Mail, Handynummer, Adresse (aufklappbar), Anfangspunkte, Geburtstag und Foto; Rolle für alle. Der Server prüft erst alles; bei einem Fehler wird keiner angelegt. Die Fotos (im
 * Browser schon gelesen und verkleinert) kommen danach einzeln: Alle in einer Anfrage würden zu groß für viele Server.
 */
export function BulkNewMembers({ canPoints }: { canPoints: boolean }) {
  const [rows, setRows] = useState([emptyRow(), emptyRow(), emptyRow()]);
  const [level, setLevel] = useState<Level>('member');
  const [extraRoles, setExtraRoles] = useState<Level[]>([]);
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [results, setResults] = useState<BulkResult[] | null>(null);
  const points = canPoints && level === 'member';

  // Mit dem aktuellen Stand: Ein Foto wird erst nach dem Lesen übernommen, inzwischen kann sich anderes geändert haben.
  const update = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const birthdays = canEnterBirthdays();

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const filled = rows.filter((r) => `${r.first_name}${r.last_name}`.trim() !== '');
    const people: NewPerson[] = [];
    const pictures = filled.map((r) => r.picture);
    for (const [i, r] of filled.entries()) {
      const name = trimName(r);
      const bday = birthdays ? readBirthday(r.bday) : null;
      if (typeof bday === 'string')
        return setFormError(
          t('Zeile {row} ({name}): {error}', { row: i + 1, name: `${name.first_name} ${name.last_name}`.trim(), error: bday }),
        );
      people.push({
        ...name,
        ...(r.username?.trim() && !r.noAccess ? { username: r.username.trim() } : {}),
        ...(r.email && normalizeEmail(r.email) ? { email: normalizeEmail(r.email) } : {}),
        ...(r.phone && normalizePhone(r.phone) ? { phone: normalizePhone(r.phone) } : {}),
        ...(addressInput(r.postal) ? { address: addressInput(r.postal) } : {}),
        ...(points && Number(r.points) > 0 ? { initial_points: Number(r.points), initial_points_date: date } : {}),
        ...(bday ? { birthday: birthdayString(bday) } : {}),
        ...(r.noAccess ? { no_access: true } : {}),
      });
    }
    if (people.length === 0) return setFormError(t('Bitte mindestens einen Namen eintragen.'));
    if (!(await confirmNewAdmin(level))) return;
    setBusy(true);
    try {
      const out = await api.createUsers(level, people, validExtraRoles(level, extraRoles));
      // Ein Ergebnis je Person, in derselben Reihenfolge (angelegt oder mit Grund nicht).
      for (const [i, r] of out.entries()) {
        const picture = pictures[i];
        if (!picture || 'error' in r) continue;
        try {
          r.photo_url = (await api.uploadMemberPhoto(r.id, picture.file)).photo_url;
        } catch (err) {
          const missing = t('Das Foto fehlt noch ({error}). Bitte in den Mitgliedsdetails nachtragen.', { error: (err as Error).message });
          r.warning = r.warning ? `${r.warning} ${missing}` : missing;
        }
      }
      setResults(out);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (results) {
    const created = results.filter((r): r is CreatedUser => 'password' in r);
    const withoutAccess = results.filter((r): r is CreatedUser => !('error' in r) && !!r.no_access);
    const failed = results.filter((r): r is Extract<BulkResult, { error: string }> => 'error' in r);
    const count = created.length + withoutAccess.length;
    return (
      <div>
        <h2 className="member-name no-print">{tn('{count} Konto angelegt', '{count} Konten angelegt', count)}</h2>
        {failed.length > 0 && (
          <div className="form-error no-print" role="alert">
            {t('Nicht angelegt:')}
            <ul>
              {failed.map((f) => (
                <li key={f.username}>
                  {f.name}: {f.error}
                </li>
              ))}
            </ul>
          </div>
        )}
        {withoutAccess.length > 0 && (
          <div className="card created no-access-created no-print">
            <p>
              {bold(
                t(
                  'Ohne Zugang: {names}. Sie melden sich noch nicht an; einen Zugang gibt es später beim Mitglied mit <b>Zugang einrichten</b> oder beim Freigeben einer Anfrage.',
                  { names: withoutAccess.map((p) => p.name).join(', ') },
                ),
              )}
            </p>
            {withoutAccess
              .filter((p) => p.warning)
              .map((p) => (
                <p key={p.id} className="form-error" role="alert">
                  {p.name}: {p.warning}
                </p>
              ))}
            {created.length === 0 && (
              <button className="btn btn-block" onClick={() => navigate('verwaltung/mitglieder')}>
                {t('‹ Zur Mitgliederliste')}
              </button>
            )}
          </div>
        )}
        {created.length > 0 && <Credentials people={created} onDone={() => navigate('verwaltung/mitglieder')} />}
      </div>
    );
  }

  return (
    <form className="form bulk-new" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/neu')}>
        {t('‹ Einzeln anlegen')}
      </button>
      <NewAccountRole label={t('Rolle für alle')} value={level} onChange={setLevel} />
      <NewAccountExtraRoles base={level} value={validExtraRoles(level, extraRoles)} onChange={setExtraRoles} />
      {rows.map((r, i) => (
        <fieldset key={i} className="fieldset bulk-row">
          <legend>{t('Person {number}', { number: i + 1 })}</legend>
          <NameFields value={r} onChange={(n) => update(i, n)} required={false} />
          <label className="toggle no-access-toggle">
            <input type="checkbox" checked={r.noAccess} onChange={(e) => update(i, { noAccess: e.target.checked })} />
            {t('Ohne Zugang (meldet sich noch nicht an)')}
          </label>
          <div className="field-row">
            {!r.noAccess && (
              <label className="field">
                <span>{t('Benutzername (optional)')}</span>
                <input
                  type="text"
                  autoComplete="off"
                  autoCapitalize="none"
                  value={r.username}
                  onChange={(e) => update(i, { username: e.target.value })}
                />
              </label>
            )}
            <EmailField value={r.email ?? ''} onChange={(email) => update(i, { email })} />
          </div>
          <PhoneField value={r.phone ?? ''} onChange={(phone) => update(i, { phone })} />
          {/* Mit Eintrag offen: Sonst hielte ein Fehler im verborgenen Feld das Formular ohne sichtbaren Grund an. */}
          <details className="bulk-address" open={!isEmptyAddress(r.postal) || undefined}>
            <summary>{t('Adresse (optional)')}</summary>
            <AddressFields label={t('Adresse')} value={r.postal} onChange={(postal) => update(i, { postal })} />
          </details>
          {points && (
            <label className="field">
              <span>
                <Caps text={t('{points} (Anfangsbestand, optional)', { points: brand.labels.sections.points })} />
              </span>
              <input type="number" inputMode="numeric" min={0} value={r.points} onChange={(e) => update(i, { points: e.target.value })} />
            </label>
          )}
          {birthdays && (
            <div className="bulk-birthday">
              <span className="field-label">{t('Geburtstag (optional)')}</span>
              <BirthdayFields value={r.bday} onChange={(bday) => update(i, { bday })} />
            </div>
          )}
          <div className="bulk-photo">
            <span className="field-label">{t('Foto (optional)')}</span>
            <PhotoPicker
              name={`${r.first_name} ${r.last_name}`.trim()}
              value={r.picture}
              onChange={(picture) => update(i, { picture })}
              onError={setFormError}
            />
          </div>
        </fieldset>
      ))}
      <button
        type="button"
        className="btn btn-secondary btn-block"
        onClick={() => setRows([...rows, emptyRow()])}
        disabled={rows.length >= 50}
      >
        {t('+ Weitere Person')}
      </button>
      {points && (
        <label className="field">
          <span>{t('Stand der Anfangspunkte')}</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
      )}
      <p className="muted small">
        {t('Leere Zeilen werden übersprungen. Erst wird alles geprüft; bei einem Fehler wird niemand angelegt.')}
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? t('Legt an …') : t('Alle anlegen')}
      </button>
    </form>
  );
}

/** Name ohne HTML-Entitäten (WordPress speichert z. B. „&“ als „&amp;“). */
function plainName(s: string): string {
  const el = document.createElement('textarea');
  el.innerHTML = s;
  return el.value;
}

/**
 * Mitgliedsdetails: Vor- und Nachname, Benutzername und E-Mail ändern, Konto löschen (Issue #24). Das eigene Konto ohne
 * Löschen; eine neue eigene E-Mail-Adresse nur mit dem aktuellen Passwort (wie unter Meins → Konto). Handynummer, Adresse
 * und Geburtsdatum stehen bei den persönlichen Angaben (view_personal, PersonalPanel).
 */
export function AccountPanel({
  id,
  level,
  former = false,
  self = false,
  readOnly = false,
  onChanged,
  onToast,
}: {
  id: number;
  level: Level;
  /** ehemalig: Der Hinweis auf „Als ehemalig eintragen“ entfällt */
  former?: boolean;
  /** das eigene Konto */
  self?: boolean;
  /** nur sehen (view_accounts ohne create_users): ohne Ändern und Löschen */
  readOnly?: boolean;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, setData } = useAsync(() => api.account(id), [id], 'account');
  const [editing, setEditing] = useState<AccountProfile | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [typed, setTyped] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const wordpress = config?.variant === 'wordpress';

  if (loading && !data) return <Loading shape="form" />;
  // Keine Rechte für dieses Konto (z. B. ein Admin): kein Bereich „Konto“.
  if (error) return null;
  const p = data!;
  const ownEmailChanged = self && editing !== null && normalizeEmail(editing.email).toLowerCase() !== p.email.toLowerCase();

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setFormError(null);
    if (ownEmailChanged && !password) return setFormError(t('Bitte zur Bestätigung das aktuelle Passwort eingeben.'));
    setBusy(true);
    try {
      setData(
        await api.updateAccount(id, {
          ...trimName(editing),
          email: normalizeEmail(editing.email),
          ...(p.can_change_login ? { username: editing.login.trim() } : {}),
          ...(ownEmailChanged ? { current_password: password } : {}),
        }),
      );
      setEditing(null);
      setPassword('');
      onToast(t('Gespeichert'));
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  // Name wie angezeigt (WordPress speichert z. B. „&“ als „&amp;“), Unicode einheitlich, ohne Leerzeichen außen.
  const plain = (s: string) => plainName(s).normalize('NFC').trim().toLowerCase();
  const nameMatches = plain(typed) !== '' && plain(typed) === plain(p.name);
  const deleted = {
    points: brand.labels.sections.points,
    penalties: brand.labels.sections.penalties,
    rank: brand.labels.rank,
  };

  async function remove() {
    if (!nameMatches) return;
    setBusy(true);
    try {
      await api.deleteAccount(id);
      onToast(
        wordpress
          ? t('{name} aus der App entfernt', { name: plainName(p.name) })
          : t('Konto von {name} gelöscht', { name: plainName(p.name) }),
      );
      navigate('verwaltung/mitglieder');
    } catch (err) {
      onToast((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <section className="account-panel">
      <h3 className="section-title">{t('Konto')}</h3>
      {editing ? (
        <form className="form card account-edit" onSubmit={save}>
          <NameFields value={editing} onChange={(n) => setEditing({ ...editing, ...n })} />
          <label className="field">
            <span>{t('Benutzername')}</span>
            <input
              type="text"
              autoCapitalize="none"
              value={editing.login}
              disabled={!p.can_change_login}
              onChange={(e) => setEditing({ ...editing, login: e.target.value })}
            />
          </label>
          {p.can_change_login ? (
            <p className="muted small">
              {self
                ? t('Ein neuer Benutzername meldet dich auf deinen anderen Geräten ab.')
                : t('Ein neuer Benutzername meldet die Person auf allen Geräten ab.')}
            </p>
          ) : (
            <p className="muted small">{t('Benutzernamen lassen sich hier nicht ändern.')}</p>
          )}
          <EmailField value={editing.email} onChange={(email) => setEditing({ ...editing, email })} />
          {ownEmailChanged && (
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
              {t('Speichern')}
            </button>
            <button
              className="btn btn-secondary"
              type="button"
              onClick={() => {
                setEditing(null);
                setPassword('');
                setFormError(null);
              }}
            >
              {t('Abbrechen')}
            </button>
          </div>
        </form>
      ) : (
        <>
          <p className="small">
            {t('Benutzername:')} <strong>{p.login}</strong>
            {p.email && (
              <>
                {' · '}
                {t('E-Mail: {email}', { email: p.email })}
              </>
            )}
          </p>
          <div className="button-row">
            {!readOnly && (
              <button
                className="btn btn-secondary"
                disabled={busy}
                onClick={() => {
                  setFormError(null); // z. B. vom Export
                  setEditing(p);
                }}
              >
                {t('Angaben ändern')}
              </button>
            )}
            {p.can_export && (
              <button
                className="btn btn-link"
                disabled={busy}
                title={t('Alle gespeicherten Daten dieser Person als Datei, z. B. für eine Auskunft nach der DSGVO')}
                onClick={() => {
                  setBusy(true);
                  setFormError(null);
                  downloadPersonalData(id)
                    .catch((err: Error) => setFormError(err.message))
                    .finally(() => setBusy(false));
                }}
              >
                {t('Daten exportieren (Auskunft)')}
              </button>
            )}
            {!readOnly && level !== 'admin' && !self && (
              <button className="btn btn-danger" disabled={busy || confirming} onClick={() => setConfirming(true)}>
                {wordpress ? t('Aus der App entfernen') : t('Konto löschen')}
              </button>
            )}
          </div>
          {formError && !editing && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          {confirming && (
            <div className="card account-delete" role="alertdialog" aria-labelledby="account-delete-title">
              <p id="account-delete-title">
                <strong>
                  {wordpress
                    ? t('{name} aus der App entfernen?', { name: plainName(p.name) })
                    : t('Konto von {name} löschen?', { name: plainName(p.name) })}
                </strong>{' '}
                {wordpress
                  ? t(
                      'Alle {points}, {penalties}, Anwesenheiten, {rank} und Titel dieser Person werden gelöscht; der Benutzer der Webseite bleibt (ohne Rolle in der App). Name und E-Mail-Adresse löscht ein Administrator der Webseite im WordPress-Backend unter Benutzer.',
                      deleted,
                    )
                  : t(
                      'Alle {points}, {penalties}, Anwesenheiten, {rank} und Titel dieser Person werden gelöscht, das Konto auch.',
                      deleted,
                    )}{' '}
                {t('Das lässt sich nicht rückgängig machen.')}
                {!former &&
                  ` ${t('Wer nur ausgetreten oder verstorben ist, lässt sich stattdessen als ehemalig eintragen; dann bleiben die Daten.')}`}
              </p>
              <label className="field">
                <span>{t('Zur Bestätigung den Namen eintippen')}</span>
                <input type="text" autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} />
              </label>
              <div className="button-row">
                <button className="btn btn-danger" disabled={busy || !nameMatches} onClick={remove}>
                  {wordpress ? t('Endgültig entfernen') : t('Endgültig löschen')}
                </button>
                <button
                  className="btn btn-secondary"
                  onClick={() => {
                    setConfirming(false);
                    setTyped('');
                  }}
                >
                  {t('Abbrechen')}
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  );
}
