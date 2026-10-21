import { useState, type FormEvent } from 'react';
import { api, config, levelLabel, type AccountProfile, type BulkResult, type CreatedUser, type Level, type NewPerson } from '../api';
import brand from '../generated/brand';
import { todayYmd } from '../format';
import { installUrl } from '../install';
import { navigate } from '../router';
import { Loading, NameFields, trimName, useAsync } from '../ui';
import { BirthdayFields, birthdayString, canSetBirthdays, emptyBirthday, readBirthday, type BirthdayValue } from '../birthdays';
import { PhotoPicker, type PreparedPhoto } from '../memberPhoto';

/** Zugangsdaten als Text zum Weitergeben, mit dem Link zur Installationsanleitung (Issue #24). */
export function credentialsText(c: { name: string; username: string; password: string }): string {
  return [
    `${brand.name} – Zugang für ${c.name}`,
    `App installieren: ${installUrl()}`,
    `Benutzername: ${c.username}`,
    `Passwort: ${c.password}`,
    'Bitte nach der ersten Anmeldung unter „Konto“ ein eigenes Passwort setzen.',
  ].join('\n');
}

/** Zugangsdaten anzeigen, kopieren, teilen und als Zettel ausdrucken. */
export function Credentials({ people, onDone }: { people: CreatedUser[]; onDone?: () => void }) {
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
      await navigator.share({ title: `${brand.shortName}: Zugangsdaten`, text });
    } catch {
      /* abgebrochen */
    }
  }
  return (
    <div className="credentials-block">
      <p className="muted small">
        Die Startpasswörter werden <strong>nur jetzt</strong> angezeigt. Bitte ausdrucken, kopieren oder teilen und persönlich weitergeben.
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
              <dt>App installieren</dt>
              <dd className="credentials-url">{installUrl()}</dd>
              <dt>Benutzername</dt>
              <dd data-testid="new-username">{c.username}</dd>
              <dt>Passwort</dt>
              <dd data-testid="new-password">{c.password}</dd>
            </dl>
            <p className="small print-only">Bitte nach der ersten Anmeldung unter „Konto“ ein eigenes Passwort setzen.</p>
          </section>
        ))}
      </div>
      {showText && (
        <label className="field no-print">
          <span>Zugangsdaten zum Kopieren</span>
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
          {copied ? 'Kopiert ✓' : 'Zugangsdaten kopieren'}
        </button>
        <button className="btn btn-secondary" onClick={() => window.print()}>
          Ausdrucken
        </button>
      </div>
      {typeof navigator.share === 'function' && (
        <button className="btn btn-secondary btn-block no-print" onClick={share}>
          Teilen …
        </button>
      )}
      {onDone && (
        <button className="btn btn-link no-print" onClick={onDone}>
          ‹ Zur Mitgliederliste
        </button>
      )}
    </div>
  );
}

type Row = NewPerson & { points: string; bday: BirthdayValue; picture: PreparedPhoto | null };

const emptyRow = (): Row => ({ first_name: '', last_name: '', username: '', email: '', points: '', bday: emptyBirthday(), picture: null });

/**
 * Mehrere Mitglieder auf einmal anlegen (Issue #24): je Zeile Vor- und Nachname, optional Benutzername, E-Mail, Anfangspunkte,
 * Geburtstag und Foto; Rolle für alle. Der Server prüft erst alles; bei einem Fehler wird keiner angelegt. Die Fotos (im
 * Browser schon gelesen und verkleinert) kommen danach einzeln: Alle in einer Anfrage würden zu groß für viele Server.
 */
export function BulkNewMembers({ canPoints }: { canPoints: boolean }) {
  const [rows, setRows] = useState([emptyRow(), emptyRow(), emptyRow()]);
  const [level, setLevel] = useState<'member' | 'candidate'>('member');
  const [date, setDate] = useState(todayYmd());
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [results, setResults] = useState<BulkResult[] | null>(null);
  const points = canPoints && level === 'member';

  // Mit dem aktuellen Stand: Ein Foto wird erst nach dem Lesen übernommen, inzwischen kann sich anderes geändert haben.
  const update = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const birthdays = canSetBirthdays();

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const filled = rows.filter((r) => `${r.first_name}${r.last_name}`.trim() !== '');
    const people: NewPerson[] = [];
    const pictures = filled.map((r) => r.picture);
    for (const [i, r] of filled.entries()) {
      const name = trimName(r);
      const bday = birthdays ? readBirthday(r.bday) : null;
      if (typeof bday === 'string') return setFormError(`Zeile ${i + 1} (${`${name.first_name} ${name.last_name}`.trim()}): ${bday}`);
      people.push({
        ...name,
        ...(r.username?.trim() ? { username: r.username.trim() } : {}),
        ...(r.email?.trim() ? { email: r.email.trim() } : {}),
        ...(points && Number(r.points) > 0 ? { initial_points: Number(r.points), initial_points_date: date } : {}),
        ...(bday ? { birthday: birthdayString(bday) } : {}),
      });
    }
    if (people.length === 0) return setFormError('Bitte mindestens einen Namen eintragen.');
    setBusy(true);
    try {
      const out = await api.createUsers(level, people);
      // Ein Ergebnis je Person, in derselben Reihenfolge (angelegt oder mit Grund nicht).
      for (const [i, r] of out.entries()) {
        const picture = pictures[i];
        if (!picture || !('password' in r)) continue;
        try {
          r.photo_url = (await api.uploadMemberPhoto(r.id, picture.file)).photo_url;
        } catch (err) {
          r.warning = `${r.warning ? `${r.warning} ` : ''}Das Foto fehlt noch (${(err as Error).message}). Bitte in den Mitgliedsdetails nachtragen.`;
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
    const failed = results.filter((r): r is Extract<BulkResult, { error: string }> => 'error' in r);
    return (
      <div>
        <h2 className="member-name no-print">{created.length === 1 ? '1 Konto angelegt' : `${created.length} Konten angelegt`}</h2>
        {failed.length > 0 && (
          <div className="form-error no-print" role="alert">
            Nicht angelegt:
            <ul>
              {failed.map((f) => (
                <li key={f.username}>
                  {f.name}: {f.error}
                </li>
              ))}
            </ul>
          </div>
        )}
        <Credentials people={created} onDone={() => navigate('verwaltung/mitglieder')} />
      </div>
    );
  }

  return (
    <form className="form bulk-new" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/neu')}>
        ‹ Einzeln anlegen
      </button>
      <label className="field">
        <span>Rolle für alle</span>
        <select value={level} onChange={(e) => setLevel(e.target.value as 'member' | 'candidate')}>
          <option value="member">{levelLabel('member')}</option>
          <option value="candidate">{levelLabel('candidate')} (nur interner Kalender)</option>
        </select>
      </label>
      {rows.map((r, i) => (
        <fieldset key={i} className="fieldset bulk-row">
          <legend>Person {i + 1}</legend>
          <NameFields value={r} onChange={(n) => update(i, n)} required={false} />
          <div className="field-row">
            <label className="field">
              <span>Benutzername (optional)</span>
              <input
                type="text"
                autoComplete="off"
                autoCapitalize="none"
                value={r.username}
                onChange={(e) => update(i, { username: e.target.value })}
              />
            </label>
            <label className="field">
              <span>E-Mail (optional)</span>
              <input type="email" autoComplete="off" value={r.email} onChange={(e) => update(i, { email: e.target.value })} />
            </label>
          </div>
          {points && (
            <label className="field">
              <span>{brand.labels.sections.points} (Anfangsbestand, optional)</span>
              <input type="number" inputMode="numeric" min={0} value={r.points} onChange={(e) => update(i, { points: e.target.value })} />
            </label>
          )}
          {birthdays && (
            <div className="bulk-birthday">
              <span className="field-label">Geburtstag (optional)</span>
              <BirthdayFields value={r.bday} onChange={(bday) => update(i, { bday })} />
            </div>
          )}
          <div className="bulk-photo">
            <span className="field-label">Foto (optional)</span>
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
        + Weitere Person
      </button>
      {points && (
        <label className="field">
          <span>Stand der Anfangspunkte</span>
          <input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
      )}
      <p className="muted small">Leere Zeilen werden übersprungen. Erst wird alles geprüft; bei einem Fehler wird niemand angelegt.</p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Legt an …' : 'Alle anlegen'}
      </button>
    </form>
  );
}

/** Name ohne HTML-Entitäten (WordPress speichert z. B. „&“ als „&amp;“). */
function plainName(s: string): string {
  const t = document.createElement('textarea');
  t.innerHTML = s;
  return t.value;
}

/** Mitgliedsdetails: Vor- und Nachname, Benutzername und E-Mail ändern, Konto löschen (Issue #24). */
export function AccountPanel({
  id,
  level,
  onChanged,
  onToast,
}: {
  id: number;
  level: Level;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const { data, error, loading, setData } = useAsync(() => api.account(id), [id]);
  const [editing, setEditing] = useState<AccountProfile | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [typed, setTyped] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const wordpress = config?.variant === 'wordpress';

  if (loading && !data) return <Loading />;
  // Keine Rechte für dieses Konto (z. B. ein Admin): kein Bereich „Konto“.
  if (error) return null;
  const p = data!;

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setBusy(true);
    setFormError(null);
    try {
      setData(
        await api.updateAccount(id, {
          ...trimName(editing),
          email: editing.email.trim(),
          ...(p.can_change_login ? { username: editing.login.trim() } : {}),
        }),
      );
      setEditing(null);
      onToast('Gespeichert');
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

  async function remove() {
    if (!nameMatches) return;
    setBusy(true);
    try {
      await api.deleteAccount(id);
      onToast(wordpress ? `${plainName(p.name)} aus der App entfernt` : `Konto von ${plainName(p.name)} gelöscht`);
      navigate('verwaltung/mitglieder');
    } catch (err) {
      onToast((err as Error).message);
      setBusy(false);
    }
  }

  return (
    <section className="account-panel">
      <h3 className="section-title">Konto</h3>
      {editing ? (
        <form className="form card account-edit" onSubmit={save}>
          <NameFields value={editing} onChange={(n) => setEditing({ ...editing, ...n })} />
          <label className="field">
            <span>Benutzername</span>
            <input
              type="text"
              autoCapitalize="none"
              value={editing.login}
              disabled={!p.can_change_login}
              onChange={(e) => setEditing({ ...editing, login: e.target.value })}
            />
          </label>
          {p.can_change_login ? (
            <p className="muted small">Ein neuer Benutzername meldet die Person auf allen Geräten ab.</p>
          ) : (
            <p className="muted small">Benutzernamen lassen sich hier nicht ändern.</p>
          )}
          <label className="field">
            <span>E-Mail (optional)</span>
            <input type="email" value={editing.email} onChange={(e) => setEditing({ ...editing, email: e.target.value })} />
          </label>
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              Speichern
            </button>
            <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : (
        <>
          <p className="small">
            Benutzername: <strong>{p.login}</strong>
            {p.email && (
              <>
                {' · '}E-Mail: {p.email}
              </>
            )}
          </p>
          <div className="button-row">
            <button className="btn btn-secondary" disabled={busy} onClick={() => setEditing(p)}>
              Angaben ändern
            </button>
            {level !== 'admin' && (
              <button className="btn btn-danger" disabled={busy || confirming} onClick={() => setConfirming(true)}>
                {wordpress ? 'Aus der App entfernen' : 'Konto löschen'}
              </button>
            )}
          </div>
          {confirming && (
            <div className="card account-delete" role="alertdialog" aria-labelledby="account-delete-title">
              <p id="account-delete-title">
                <strong>{wordpress ? `${plainName(p.name)} aus der App entfernen?` : `Konto von ${plainName(p.name)} löschen?`}</strong>{' '}
                Alle {brand.labels.sections.points}, {brand.labels.sections.penalties}, Anwesenheiten, {brand.labels.rank} und Titel dieser
                Person werden gelöscht
                {wordpress ? '; der Benutzer der Webseite bleibt (ohne Rolle in der App).' : ', das Konto auch.'} Das lässt sich nicht
                rückgängig machen.
              </p>
              <label className="field">
                <span>Zur Bestätigung den Namen eintippen</span>
                <input type="text" autoComplete="off" value={typed} onChange={(e) => setTyped(e.target.value)} />
              </label>
              <div className="button-row">
                <button className="btn btn-danger" disabled={busy || !nameMatches} onClick={remove}>
                  Endgültig {wordpress ? 'entfernen' : 'löschen'}
                </button>
                <button
                  className="btn btn-secondary"
                  onClick={() => {
                    setConfirming(false);
                    setTyped('');
                  }}
                >
                  Abbrechen
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </section>
  );
}
