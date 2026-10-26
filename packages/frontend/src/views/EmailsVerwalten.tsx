import { useEffect, useMemo, useRef, useState, type FormEvent, type KeyboardEvent } from 'react';
import {
  api,
  type BoardMailSummary,
  type MailDetail,
  type MailField,
  type MailListItem,
  type MailOptions,
  type MailSelection,
  type Attachment,
} from '../api';
import { AttachmentList, AttachmentsField } from '../attachments';
import { ComposeEditor } from '../composeEditor';
import { formatShortTime } from '../format';
import RichTextView from '../richtext/RichTextView';
import { navigate } from '../router';
import { ErrorBox, Loading } from '../ui';

/** Entwurf der E-Mail (auf diesem Gerät): geht beim Sprung zur Signatur-Einrichtung und beim Verlassen nicht verloren. */
const DRAFT_KEY = 'kompanie-mail-draft';
const FIELDS: MailField[] = ['to', 'cc', 'bcc'];
const FIELD_LABELS: Record<MailField, string> = { to: 'An', cc: 'Cc', bcc: 'Bcc' };
const EMAIL = /^[^\s@,;<>]+@[^\s@,;<>]+\.[^\s@,;<>]+$/;

interface Draft extends Record<MailField, MailSelection> {
  subject: string;
  text: string;
  /** Cc und Bcc aufgeklappt */
  copies: boolean;
  /** schon hochgeladene Anhänge (Entwürfe auf dem Server, 7 Tage gültig) */
  attachments: Attachment[];
}

const emptySelection = (): MailSelection => ({ roles: [], users: [], emails: [] });
const emptyDraft = (): Draft => ({
  subject: '',
  text: '',
  to: emptySelection(),
  cc: emptySelection(),
  bcc: emptySelection(),
  copies: false,
  attachments: [],
});
const isEmpty = (s: MailSelection) => s.roles.length === 0 && s.users.length === 0 && s.emails.length === 0;

function readDraft(): Draft | null {
  try {
    const raw = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null');
    if (!raw || typeof raw !== 'object' || typeof raw.subject !== 'string' || typeof raw.text !== 'string') return null;
    const sel = (v: unknown): MailSelection => {
      const o = (v && typeof v === 'object' ? v : {}) as Partial<MailSelection>;
      return {
        roles: Array.isArray(o.roles) ? o.roles.filter((x) => typeof x === 'string') : [],
        users: Array.isArray(o.users) ? o.users.filter((x) => typeof x === 'number') : [],
        emails: Array.isArray(o.emails) ? o.emails.filter((x) => typeof x === 'string') : [],
      };
    };
    return {
      subject: raw.subject,
      text: raw.text,
      to: sel(raw.to),
      cc: sel(raw.cc),
      bcc: sel(raw.bcc),
      copies: !!raw.copies,
      attachments: Array.isArray(raw.attachments) ? raw.attachments : [],
    };
  } catch {
    return null;
  }
}

function saveDraft(draft: Draft | null): void {
  try {
    if (draft === null) localStorage.removeItem(DRAFT_KEY);
    else localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
  } catch {
    // privater Modus o. ä.: Entwurf nur bis zum Verlassen der Seite
  }
}

/** Entwurf speichern und zurückgeben (für setDraft mit Funktion). */
function withSaved(draft: Draft): Draft {
  saveDraft(draft);
  return draft;
}

export function mailSummaryText(mail: BoardMailSummary): string {
  const parts = [`${mail.sent} von ${mail.recipients} gesendet`];
  if (mail.pending > 0) parts.push(`${mail.pending} ausstehend`);
  if (mail.failed > 0) parts.push(`${mail.failed} fehlgeschlagen`);
  if (mail.cancelled > 0) parts.push(`${mail.cancelled} nicht mehr gesendet`);
  return parts.join(', ');
}

type Suggestion = { kind: 'role'; key: string; label: string; hint: string } | { kind: 'user'; id: number; label: string; hint: string };

/**
 * Empfänger eines Felds wie in einem Mailprogramm: ausgewählte Rollen, Mitglieder und Adressen als Kärtchen; Eingabe sucht
 * Rollen und Mitglieder, eine Adresse übernimmt Enter, Komma oder Verlassen des Felds (auch mehrere eingefügte auf einmal).
 */
function RecipientField({
  field,
  value,
  options,
  onChange,
}: {
  field: MailField;
  value: MailSelection;
  options: MailOptions;
  onChange: (value: MailSelection) => void;
}) {
  const [query, setQuery] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const id = `mail-${field}`;
  const listId = `${id}-suggestions`;

  const suggestions = useMemo<Suggestion[]>(() => {
    const q = query.trim().toLowerCase();
    if (q === '' || q.includes('@')) return [];
    const roles: Suggestion[] = options.roles
      .filter((r) => !value.roles.includes(r.key) && r.label.toLowerCase().includes(q))
      .map((r) => ({ kind: 'role', key: r.key, label: r.label, hint: `Rolle · ${r.with_email} von ${r.members} mit Adresse` }));
    const users: Suggestion[] = options.members
      .filter((m) => !value.users.includes(m.id) && m.name.toLowerCase().includes(q))
      .map((m) => ({ kind: 'user', id: m.id, label: m.name, hint: m.email ? 'Mitglied' : 'ohne Mailadresse' }));
    return [...roles, ...users].slice(0, 8);
  }, [query, options, value]);

  const pick = (s: Suggestion) => {
    if (s.kind === 'role') onChange({ ...value, roles: [...value.roles, s.key] });
    else onChange({ ...value, users: [...value.users, s.id] });
    setQuery('');
    setActive(0);
    setError(null);
    inputRef.current?.focus();
  };

  /** Adressen aus der Eingabe übernehmen; ungültige bleiben stehen. */
  const takeEmails = (): boolean => {
    const parts = query.split(/[\s,;]+/).filter((p) => p !== '');
    if (parts.length === 0) return false;
    const valid = parts.filter((p) => EMAIL.test(p));
    const invalid = parts.filter((p) => !EMAIL.test(p));
    if (valid.length > 0) {
      const known = new Set(value.emails.map((e) => e.toLowerCase()));
      const add = valid.filter((e) => !known.has(e.toLowerCase()) && known.add(e.toLowerCase()));
      onChange({ ...value, emails: [...value.emails, ...add] });
    }
    setQuery(invalid.join(' '));
    setError(invalid.length > 0 ? `Keine gültige Adresse: ${invalid.join(', ')}` : null);
    return valid.length > 0;
  };

  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown' && suggestions.length > 0) {
      e.preventDefault();
      setActive((a) => (a + 1) % suggestions.length);
    } else if (e.key === 'ArrowUp' && suggestions.length > 0) {
      e.preventDefault();
      setActive((a) => (a - 1 + suggestions.length) % suggestions.length);
    } else if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
      if (query.trim() === '') return;
      e.preventDefault();
      if (suggestions.length > 0) pick(suggestions[Math.min(active, suggestions.length - 1)]);
      else if (query.includes('@')) takeEmails();
      else setError('Keine Rolle und kein Mitglied gefunden. Oder eine Mailadresse eingeben.');
    } else if (e.key === 'Backspace' && query === '') {
      if (value.emails.length > 0) onChange({ ...value, emails: value.emails.slice(0, -1) });
      else if (value.users.length > 0) onChange({ ...value, users: value.users.slice(0, -1) });
      else if (value.roles.length > 0) onChange({ ...value, roles: value.roles.slice(0, -1) });
    }
  };

  const role = (key: string) => options.roles.find((r) => r.key === key);
  const member = (uid: number) => options.members.find((m) => m.id === uid);

  return (
    <div className="field mail-recipients">
      <span id={`${id}-label`}>{FIELD_LABELS[field]}</span>
      <div className="mail-recipient-box" onClick={() => inputRef.current?.focus()}>
        <ul className="mail-chips" aria-labelledby={`${id}-label`}>
          {value.roles.map((key) => (
            <li key={`r-${key}`} className="mail-chip mail-chip-role">
              <span>
                {role(key)?.label ?? key}
                <span className="mail-chip-note"> · Rolle ({role(key)?.with_email ?? 0})</span>
              </span>
              <button
                type="button"
                aria-label={`${role(key)?.label ?? key} entfernen`}
                onClick={() => onChange({ ...value, roles: value.roles.filter((r) => r !== key) })}
              >
                ×
              </button>
            </li>
          ))}
          {value.users.map((uid) => (
            <li key={`u-${uid}`} className={`mail-chip ${member(uid)?.email === false ? 'mail-chip-missing' : ''}`}>
              <span>
                {member(uid)?.name ?? 'Unbekannt'}
                {member(uid)?.email === false && <span className="mail-chip-note"> · ohne Adresse</span>}
              </span>
              <button
                type="button"
                aria-label={`${member(uid)?.name ?? 'Mitglied'} entfernen`}
                onClick={() => onChange({ ...value, users: value.users.filter((u) => u !== uid) })}
              >
                ×
              </button>
            </li>
          ))}
          {value.emails.map((email) => (
            <li key={`e-${email}`} className="mail-chip mail-chip-email">
              <span>{email}</span>
              <button
                type="button"
                aria-label={`${email} entfernen`}
                onClick={() => onChange({ ...value, emails: value.emails.filter((x) => x !== email) })}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
        <input
          ref={inputRef}
          id={id}
          type="text"
          className={`mail-recipient-input mail-${field}-input`}
          value={query}
          placeholder={isEmpty(value) ? 'Rolle, Mitglied oder Adresse …' : ''}
          aria-label={`${FIELD_LABELS[field]}: Rolle, Mitglied oder Adresse`}
          aria-autocomplete="list"
          aria-controls={listId}
          aria-expanded={suggestions.length > 0}
          autoComplete="off"
          onChange={(e) => {
            setQuery(e.target.value);
            setActive(0);
            setError(null);
          }}
          onKeyDown={onKey}
          onBlur={() => query.includes('@') && takeEmails()}
          onPaste={(e) => {
            const text = e.clipboardData.getData('text');
            if (/[\s,;]/.test(text.trim()) && text.includes('@')) {
              e.preventDefault();
              const parts = (query + ' ' + text).split(/[\s,;<>]+/).filter((p) => p !== '');
              const valid = parts.filter((p) => EMAIL.test(p));
              const known = new Set(value.emails.map((x) => x.toLowerCase()));
              onChange({
                ...value,
                emails: [...value.emails, ...valid.filter((x) => !known.has(x.toLowerCase()) && known.add(x.toLowerCase()))],
              });
              const rest = parts.filter((p) => !EMAIL.test(p) && p.includes('@'));
              setQuery(rest.join(' '));
              setError(rest.length > 0 ? `Keine gültige Adresse: ${rest.join(', ')}` : null);
            }
          }}
        />
      </div>
      {suggestions.length > 0 && (
        <ul id={listId} className="card mail-suggestions" role="listbox">
          {suggestions.map((s, i) => (
            <li key={s.kind === 'role' ? `r-${s.key}` : `u-${s.id}`} role="option" aria-selected={i === active}>
              <button
                type="button"
                className={i === active ? 'active' : ''}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => pick(s)}
              >
                <span>{s.label}</span>
                <span className="muted small">{s.hint}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {error && <p className="form-error small">{error}</p>}
    </div>
  );
}

/** E-Mail schreiben: Absender, Empfänger (An, Cc, Bcc), Betreff, formatierter Text wie am Brett; Bestätigung vor dem Senden. */
function Compose({ options, onSent, onToast }: { options: MailOptions; onSent: () => void; onToast: (msg: string) => void }) {
  const [draft, setDraft] = useState<Draft>(() => readDraft() ?? emptyDraft());
  const [editorKey, setEditorKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const update = (changes: Partial<Draft>) =>
    setDraft((d) => {
      const next = { ...d, ...changes };
      saveDraft(next);
      return next;
    });
  const noRecipients = FIELDS.every((f) => isEmpty(draft[f]));
  const showCopies = draft.copies || !isEmpty(draft.cc) || !isEmpty(draft.bcc);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (noRecipients) return setError('Bitte mindestens einen Empfänger auswählen.');
    if (draft.subject.trim() === '') return setError('Bitte einen Betreff eingeben.');
    if (draft.text.trim() === '') return setError('Bitte einen Text eingeben.');
    const selection = { to: draft.to, cc: draft.cc, bcc: draft.bcc };
    setBusy(true);
    try {
      const p = await api.mailPreview(selection);
      if (p.recipients === 0) {
        setError('Keiner der gewählten Empfänger hat eine Mailadresse.');
        return;
      }
      const lines = [
        `E-Mail „${draft.subject.trim()}“ jetzt an ${p.recipients} Empfänger senden?`,
        '',
        `An: ${p.to} · Cc: ${p.cc} · Bcc: ${p.bcc}`,
        `Absender: ${options.from_name} <${options.from}>`,
      ];
      if (draft.attachments.length > 0) lines.push(`Anhänge: ${draft.attachments.map((a) => a.name).join(', ')}`);
      if (p.skipped.length > 0) lines.push('', `Ohne Mailadresse (bekommen nichts): ${p.skipped.join(', ')}`);
      if (!window.confirm(lines.join('\n'))) return;
      await api.sendMail({ subject: draft.subject, text: draft.text, attachments: draft.attachments.map((a) => a.uid), ...selection });
      saveDraft(null);
      setDraft(emptyDraft());
      setEditorKey((k) => k + 1);
      onToast('E-Mail wird versendet');
      onSent();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card mail-form" onSubmit={submit}>
      <h3>E-Mail schreiben</h3>
      <p className="muted small mail-from">
        Von: {options.from_name} &lt;{options.from}&gt; · Antworten gehen an diese Adresse.
      </p>
      <RecipientField field="to" value={draft.to} options={options} onChange={(to) => update({ to })} />
      {showCopies ? (
        <>
          <RecipientField field="cc" value={draft.cc} options={options} onChange={(cc) => update({ cc })} />
          <RecipientField field="bcc" value={draft.bcc} options={options} onChange={(bcc) => update({ bcc })} />
        </>
      ) : (
        <button type="button" className="btn btn-link mail-copies-toggle" onClick={() => update({ copies: true })}>
          Cc und Bcc hinzufügen
        </button>
      )}
      <p className="muted small">
        Alle Empfänger in An und Cc sehen sich gegenseitig, wie in einem Mailprogramm. Wer in Bcc steht, ist für niemanden sichtbar; für
        große Gruppen deshalb besser Bcc verwenden. Jede Adresse bekommt die Mail nur einmal.
      </p>
      <label className="field">
        <span>Betreff</span>
        <input
          type="text"
          className="mail-subject-input"
          value={draft.subject}
          maxLength={255}
          onChange={(e) => update({ subject: e.target.value })}
          required
        />
      </label>
      <div className="field">
        <span>Text</span>
        <ComposeEditor
          key={editorKey}
          value={draft.text}
          onChange={(text) => update({ text })}
          label="Text der E-Mail"
          id="mail-new-text"
          returnTo="verwaltung/mail"
          saveDraft={() => saveDraft(draft)}
        />
      </div>
      <AttachmentsField
        id="mail-attachments"
        items={draft.attachments}
        onAdd={(a) => setDraft((d) => withSaved({ ...d, attachments: [...d.attachments, a] }))}
        onRemove={(uid) => setDraft((d) => withSaved({ ...d, attachments: d.attachments.filter((a) => a.uid !== uid) }))}
      />
      {error && <p className="form-error small">{error}</p>}
      <button
        className="btn btn-block"
        type="submit"
        disabled={busy || noRecipients || draft.subject.trim() === '' || draft.text.trim() === ''}
      >
        {busy ? 'Sendet …' : 'Senden'}
      </button>
    </form>
  );
}

const STATUS: Record<MailDetail['log'][number]['status'], string> = {
  pending: 'ausstehend',
  sent: 'gesendet',
  failed: 'fehlgeschlagen',
  cancelled: 'nicht mehr gesendet',
};

/** Eine versendete E-Mail: Empfänger wie ausgewählt, Inhalt und Versand je Empfänger. */
function Detail({ id }: { id: number }) {
  const [data, setData] = useState<MailDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = () => {
    setError(null);
    api.mail(id).then(setData, (err: Error) => setError(err.message));
  };
  useEffect(load, [id]);
  return (
    <div>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/mail')}>
        ‹ E-Mails
      </button>
      {error ? (
        <ErrorBox message={error} onRetry={load} />
      ) : !data ? (
        <Loading />
      ) : (
        <article className="card board-card mail-detail">
          <span className="board-meta">
            {data.author_name ?? 'Unbekannt'} · {formatShortTime(data.created_at)}
          </span>
          <h3 className="board-title">{data.subject}</h3>
          <dl className="mail-selection">
            {FIELDS.filter((f) => {
              const s = data.selection[f];
              return s && (s.roles.length > 0 || s.users.length > 0 || s.emails.length > 0);
            }).map((f) => (
              <div key={f}>
                <dt>{FIELD_LABELS[f]}:</dt>
                <dd>
                  {[
                    ...data.selection[f].roles.map((r) => `${r.label} (Rolle)`),
                    ...data.selection[f].users.map((u) => u.name ?? 'gelöschtes Konto'),
                    ...data.selection[f].emails,
                  ].join(', ')}
                </dd>
              </div>
            ))}
          </dl>
          <RichTextView html={data.text} className="board-text" />
          <AttachmentList items={data.attachments} />
          <p className="muted small mail-status">Versand: {mailSummaryText(data.mail)}</p>
          {data.mail.pending > 0 && (
            <button type="button" className="btn btn-link" onClick={load}>
              Stand aktualisieren
            </button>
          )}
          <details className="board-mail-log">
            <summary>Versand je Empfänger</summary>
            <ul>
              {data.log.map((l, i) => (
                <li key={i} className={`board-mail-${l.status}`}>
                  {FIELD_LABELS[l.field]}: {l.name ? `${l.name} <${l.email}>` : l.email} – {STATUS[l.status]}
                  {l.error && <span className="muted small"> – {l.error}</span>}
                </li>
              ))}
            </ul>
          </details>
        </article>
      )}
    </div>
  );
}

/** Verlauf: versendete E-Mails, neueste zuerst. */
function History({ items, keepDays }: { items: MailListItem[]; keepDays: number }) {
  return (
    <section className="mail-history">
      <h2 className="board-page-title">Versendete E-Mails</h2>
      {items.length === 0 ? (
        <p className="muted small">Noch keine.</p>
      ) : (
        <ul className="card-list mail-history-list">
          {items.map((m) => (
            <li key={m.id} className="card row clickable mail-history-item" onClick={() => navigate(`verwaltung/mail/${m.id}`)}>
              <div className="row-main">
                <div className="row-title">{m.subject}</div>
                <div className="row-sub">
                  {m.author_name ?? 'Unbekannt'} · {formatShortTime(m.created_at)} · {mailSummaryText(m.mail)}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      <p className="muted small">Versendete E-Mails bleiben {keepDays} Tage im Verlauf; danach werden sie samt Protokoll gelöscht.</p>
    </section>
  );
}

/**
 * Verwaltung → E-Mails (Recht send_mail): E-Mail schreiben an Rollen, Mitglieder und beliebige Adressen (An, Cc, Bcc) mit
 * demselben Editor wie am Schwarzen Brett, darunter der Verlauf; verwaltung/mail/<id> zeigt eine versendete E-Mail.
 */
export function MailAdmin({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const detail = path[0] && /^\d+$/.test(path[0]) ? Number(path[0]) : null;
  const [options, setOptions] = useState<MailOptions | null>(null);
  const [history, setHistory] = useState<{ items: MailListItem[]; keep_days: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    setError(null);
    Promise.all([api.mailOptions(), api.mails()]).then(
      ([o, h]) => {
        setOptions(o);
        setHistory(h);
      },
      (err: Error) => setError(err.message),
    );
  };
  useEffect(() => {
    if (detail === null) load();
  }, [detail]);

  if (detail !== null) return <Detail id={detail} />;
  if (error) return <ErrorBox message={error} onRetry={load} />;
  if (!options || !history) return <Loading />;
  return (
    <div className="mail-page">
      {options.available ? (
        <Compose options={options} onSent={() => api.mails().then(setHistory, () => undefined)} onToast={onToast} />
      ) : (
        <div className="card mail-unavailable">
          <p>{options.reason ?? 'Der Mailversand ist noch nicht eingerichtet.'}</p>
        </div>
      )}
      <History items={history.items} keepDays={history.keep_days} />
    </div>
  );
}
