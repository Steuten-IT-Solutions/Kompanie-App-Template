import { Suspense, useEffect, useState, type FormEvent } from 'react';
import {
  api,
  type BoardEnded,
  type BoardHistory,
  type BoardMailInfo,
  type BoardMailSummary,
  type BoardMessage,
  type BoardPoll,
  type BoardRevision,
} from '../api';
import { boardLoaded, canManagePolls, canPostBoard, pollsVisible, refreshBoard, SIGNATURE_RETURN_KEY, useBoard } from '../board';
import { BoardMeta, pollEndText } from '../BoardBanner';
import brand from '../generated/brand';
import { navigate } from '../router';
import { formatShortTime, formatUntil, fromLocalInput, toLocalInput } from '../format';
import RichTextView from '../richtext/RichTextView';
import { Empty, ErrorBox, lazyView, Loading } from '../ui';

// Editor für formatierte Nachrichten (TipTap): erst laden, wenn jemand schreibt oder bearbeitet.
const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/** Entwurf der neuen Nachricht (auf diesem Gerät): geht beim Sprung zur Signatur-Einrichtung nicht verloren. */
const DRAFT_KEY = 'kompanie-board-draft';
const DAY = 24 * 60 * 60 * 1000;

interface Draft {
  title: string;
  text: string;
  /** datetime-local (Ortszeit); leer = jetzt + 24 Stunden beim Öffnen */
  expires: string;
  push: boolean;
  mail: boolean;
}

function readDraft(): Draft | null {
  try {
    const raw = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null');
    return raw && typeof raw === 'object' && typeof raw.title === 'string' && typeof raw.text === 'string' ? (raw as Draft) : null;
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

const defaultExpires = () => toLocalInput(new Date(Date.now() + DAY).toISOString());

/** Eigene Signatur (für „Signatur einfügen“); undefined, solange sie lädt. */
function useSignature(): string | null | undefined {
  const [html, setHtml] = useState<string | null | undefined>(undefined);
  useEffect(() => {
    let alive = true;
    api
      .signature()
      .then((r) => alive && setHtml(r.signature?.content ?? null))
      .catch(() => alive && setHtml(null));
    return () => {
      alive = false;
    };
  }, []);
  return html;
}

function setupSignature(draft?: Draft): void {
  if (draft) saveDraft(draft);
  try {
    sessionStorage.setItem(SIGNATURE_RETURN_KEY, 'brett');
  } catch {
    // ohne Speicher: kein Weg zurück, der Entwurf bleibt trotzdem
  }
  navigate('meins/konto/signatur');
}

function Editor(props: { value: string; onChange: (html: string) => void; label: string; id: string; draft?: Draft }) {
  const signature = useSignature();
  return (
    <Suspense fallback={<Loading />}>
      <RichTextEditor
        value={props.value}
        onChange={props.onChange}
        label={props.label}
        id={props.id}
        signature={signature === undefined ? undefined : { html: signature, onSetup: () => setupSignature(props.draft) }}
      />
    </Suspense>
  );
}

/** „Sichtbar bis“: Ortszeit des Geräts, nicht in der Vergangenheit, ohne Obergrenze. */
function ExpiresField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <label className="field">
      <span>Sichtbar bis</span>
      <input
        type="datetime-local"
        className="board-expires"
        value={value}
        min={toLocalInput(new Date().toISOString())}
        onChange={(e) => onChange(e.target.value)}
        required
      />
    </label>
  );
}

function mailText(mail: BoardMailSummary): string {
  const parts = [`${mail.sent} von ${mail.recipients} gesendet`];
  if (mail.pending > 0) parts.push(`${mail.pending} ausstehend`);
  if (mail.failed > 0) parts.push(`${mail.failed} fehlgeschlagen`);
  if (mail.cancelled > 0) parts.push(`${mail.cancelled} nicht mehr gesendet`);
  return 'E-Mail: ' + parts.join(', ');
}

/** Neue Nachricht: Titel, formatierter Text, Ende, Benachrichtigung und Mail; Bestätigung vor dem Senden. */
function NewMessage({ onToast }: { onToast: (msg: string) => void }) {
  const [draft, setDraft] = useState<Draft>(() => {
    const saved = readDraft();
    return saved
      ? { ...saved, expires: saved.expires || defaultExpires() }
      : { title: '', text: '', expires: defaultExpires(), push: true, mail: true };
  });
  const [editorKey, setEditorKey] = useState(0);
  const [mailInfo, setMailInfo] = useState<BoardMailInfo | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.boardMailInfo().then(setMailInfo, () => setMailInfo(null));
  }, []);

  const update = (changes: Partial<Draft>) => {
    setDraft((d) => {
      const next = { ...d, ...changes };
      saveDraft(next);
      return next;
    });
  };
  const mailOn = !!mailInfo?.available && draft.mail;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (draft.title.trim() === '') return setError('Bitte einen Titel eingeben.');
    if (draft.text.trim() === '') return setError('Bitte einen Text eingeben.');
    const expires = fromLocalInput(draft.expires);
    if (!expires || Date.parse(expires) <= Date.now()) return setError('„Sichtbar bis“ muss in der Zukunft liegen.');
    const lines = [
      `Nachricht „${draft.title.trim()}“ jetzt veröffentlichen?`,
      '',
      `Sichtbar bis: ${formatUntil(expires)}`,
      `Benachrichtigung: ${draft.push ? 'an alle Mitglieder und alle mit dem internen Kalender (z. B. Anwärter)' : 'keine'}`,
      `E-Mail: ${mailOn ? `an ${mailInfo?.recipients ?? 0} Empfänger` : 'keine'}`,
    ];
    if (!window.confirm(lines.join('\n'))) return;
    setBusy(true);
    try {
      await api.postBoard({ title: draft.title, text: draft.text, expires_at: expires, push: draft.push, mail: mailOn });
      saveDraft(null);
      setDraft({ title: '', text: '', expires: defaultExpires(), push: true, mail: true });
      setEditorKey((k) => k + 1);
      onToast('Nachricht veröffentlicht');
      await refreshBoard();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card board-form" onSubmit={submit}>
      <h3>Neue Nachricht</h3>
      <label className="field">
        <span>Titel</span>
        <input className="board-title-input" value={draft.title} onChange={(e) => update({ title: e.target.value })} required />
      </label>
      <div className="field">
        <span>Text</span>
        <Editor
          key={editorKey}
          value={draft.text}
          onChange={(text) => update({ text })}
          label="Text der Nachricht"
          id="board-new-text"
          draft={draft}
        />
      </div>
      <ExpiresField value={draft.expires} onChange={(expires) => update({ expires })} />
      <label className="toggle">
        <input type="checkbox" className="board-push" checked={draft.push} onChange={(e) => update({ push: e.target.checked })} />
        <span>Benachrichtigung senden</span>
      </label>
      <label className="toggle">
        <input
          type="checkbox"
          className="board-mail"
          checked={mailOn}
          disabled={!mailInfo?.available}
          onChange={(e) => update({ mail: e.target.checked })}
        />
        <span>E-Mail senden{mailInfo?.available ? ` (an ${mailInfo.recipients} Empfänger)` : ''}</span>
      </label>
      {mailInfo && !mailInfo.available && <p className="muted small">{mailInfo.reason}</p>}
      <p className="muted small">
        {draft.push
          ? 'Alle Mitglieder und alle mit dem internen Kalender (z. B. Anwärter) bekommen sofort eine Benachrichtigung mit dem Titel.'
          : 'Ohne Benachrichtigung: Die Nachricht erscheint nur in der App.'}{' '}
        Der Titel steht oben auf jeder Seite der App, bis zum gewählten Ende (standardmäßig 24 Stunden); danach verschwindet die Nachricht
        von selbst.
      </p>
      {error && <p className="form-error small">{error}</p>}
      <button className="btn btn-block" type="submit" disabled={busy || draft.title.trim() === '' || draft.text.trim() === ''}>
        {busy ? 'Sendet …' : 'Veröffentlichen'}
      </button>
    </form>
  );
}

const ACTIONS: Record<BoardRevision['action'], string> = {
  created: 'erstellt',
  title: 'Titel geändert',
  content: 'Inhalt geändert',
  expires: 'Enddatum geändert',
  deleted: 'gelöscht',
  mailed: 'per E-Mail versendet',
};

/** Verlauf einer Nachricht (nur post_board): wer wann was geändert hat, bei Inhalten vorher/nachher. */
function History({ id }: { id: number }) {
  const [data, setData] = useState<BoardHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.boardHistory(id).then(setData, (err: Error) => setError(err.message));
  }, [id]);
  if (error) return <p className="form-error small">{error}</p>;
  if (!data) return <Loading />;
  const value = (r: BoardRevision, v: unknown) => {
    if (v === null || v === undefined) return null;
    if (r.action === 'expires') return <span>{formatUntil(String(v))}</span>;
    if (r.action === 'title') return <span>{String(v)}</span>;
    if (r.action === 'content') return <RichTextView html={String(v)} className="board-rev-content" />;
    return null;
  };
  return (
    <div className="board-history">
      <ol className="board-revisions">
        {data.revisions.map((r) => (
          <li key={r.id} className="board-revision">
            <span className="board-meta">
              {formatShortTime(r.at)} · {r.user_name ?? 'Unbekannt'}: {ACTIONS[r.action]}
              {r.action === 'mailed' &&
                typeof r.after === 'object' &&
                r.after !== null &&
                ` (an ${(r.after as { recipients?: number }).recipients ?? 0} Empfänger)`}
            </span>
            {(r.action === 'title' || r.action === 'content' || r.action === 'expires') && (
              <div className="board-diff">
                <div className="board-before">
                  <span className="muted small">vorher</span>
                  {value(r, r.before)}
                </div>
                <div className="board-after">
                  <span className="muted small">nachher</span>
                  {value(r, r.after)}
                </div>
              </div>
            )}
          </li>
        ))}
      </ol>
      {data.mail_log.length > 0 && (
        <details className="board-mail-log">
          <summary>Mailversand je Empfänger</summary>
          <ul>
            {data.mail_log.map((l, i) => (
              <li key={i} className={`board-mail-${l.status}`}>
                {l.name ?? l.email}:{' '}
                {{ pending: 'ausstehend', sent: 'gesendet', failed: 'fehlgeschlagen', cancelled: 'nicht mehr gesendet' }[l.status]}
                {l.error && <span className="muted small"> – {l.error}</span>}
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  );
}

function Entry({ item, onToast }: { item: BoardMessage; onToast: (msg: string) => void }) {
  const [edit, setEdit] = useState<{ title: string; text: string; expires: string } | null>(null);
  const [history, setHistory] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canPost = canPostBoard();

  async function run(action: () => Promise<unknown>, message: string) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onToast(message);
      setEdit(null);
      await refreshBoard();
    } catch (err) {
      setError((err as Error).message);
      await refreshBoard(); // z. B. inzwischen abgelaufen oder gelöscht
    } finally {
      setBusy(false);
    }
  }

  function save() {
    if (!edit) return;
    const changes: { title?: string; text?: string; expires_at?: string | null } = {};
    if (edit.title !== item.title) changes.title = edit.title;
    if (edit.text !== item.text) changes.text = edit.text;
    const expires = fromLocalInput(edit.expires);
    if (expires && toLocalInput(expires) !== toLocalInput(item.expires_at)) changes.expires_at = expires;
    if (Object.keys(changes).length === 0) return setEdit(null);
    void run(() => api.updateBoard(item.id, changes), 'Nachricht geändert');
  }

  return (
    <article className="card board-card" data-focus-id={item.id}>
      <BoardMeta item={item} />
      {edit === null ? (
        <>
          <h3 className="board-title">{item.title}</h3>
          <RichTextView html={item.text} className="board-text" />
        </>
      ) : (
        <div className="form board-edit">
          <label className="field">
            <span>Titel</span>
            <input
              className="board-title-input"
              value={edit.title}
              onChange={(e) => setEdit({ ...edit, title: e.target.value })}
              required
            />
          </label>
          <div className="field">
            <span>Text</span>
            <Editor
              value={item.text}
              onChange={(text) => setEdit((d) => (d ? { ...d, text } : d))}
              label="Text der Nachricht"
              id={`board-edit-${item.id}`}
            />
          </div>
          <ExpiresField value={edit.expires} onChange={(expires) => setEdit({ ...edit, expires })} />
        </div>
      )}
      <p className="muted small board-until">Sichtbar bis {formatUntil(item.expires_at)}</p>
      {canPost && item.last_edit && (
        <p className="muted small board-last-edit">
          Zuletzt geändert von {item.last_edit.name ?? 'Unbekannt'} · {formatShortTime(item.last_edit.at)}
        </p>
      )}
      {canPost && item.mail && <p className="muted small board-mail-status">{mailText(item.mail)}</p>}
      {error && <p className="form-error small">{error}</p>}
      {canPost &&
        (edit === null ? (
          <div className="button-row">
            <button
              className="btn btn-secondary"
              disabled={busy}
              onClick={() => setEdit({ title: item.title, text: item.text, expires: toLocalInput(item.expires_at) })}
            >
              Bearbeiten
            </button>
            <button
              className="btn btn-danger"
              disabled={busy}
              onClick={() =>
                window.confirm(`Nachricht „${item.title}“ jetzt löschen?`) && run(() => api.deleteBoard(item.id), 'Nachricht gelöscht')
              }
            >
              Löschen
            </button>
            <button className="btn btn-link board-history-toggle" aria-expanded={history} onClick={() => setHistory(!history)}>
              {history ? 'Verlauf ausblenden' : 'Verlauf'}
            </button>
          </div>
        ) : (
          <>
            <p className="muted small">
              Ändern verschickt keine neue Benachrichtigung und keine neue E-Mail. Alle Änderungen stehen im Verlauf.
            </p>
            <div className="button-row">
              <button className="btn" disabled={busy || edit.title.trim() === '' || edit.text.trim() === ''} onClick={save}>
                Speichern
              </button>
              <button className="btn btn-secondary" disabled={busy} onClick={() => setEdit(null)}>
                Abbrechen
              </button>
            </div>
          </>
        ))}
      {canPost && history && edit === null && <History id={item.id} />}
    </article>
  );
}

/** Bereich „Verlauf“ (nur post_board): abgelaufene und gelöschte Nachrichten der letzten 30 Tage samt Verlauf. */
function Ended() {
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<{ items: BoardEnded[]; keep_days: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [shown, setShown] = useState<number | null>(null);
  useEffect(() => {
    if (open && data === null) api.boardEnded().then(setData, (err: Error) => setError(err.message));
  }, [open, data]);
  return (
    <section className="board-ended">
      <button className="btn btn-link board-ended-toggle" aria-expanded={open} onClick={() => setOpen(!open)}>
        {open ? 'Verlauf ausblenden' : 'Verlauf: abgelaufene und gelöschte Nachrichten'}
      </button>
      {open &&
        (error ? (
          <p className="form-error small">{error}</p>
        ) : data === null ? (
          <Loading />
        ) : (
          <>
            <p className="muted small">
              Abgelaufene und gelöschte Nachrichten der letzten {data.keep_days} Tage; danach werden sie endgültig gelöscht.
            </p>
            {data.items.length === 0 ? (
              <p className="muted small">Keine.</p>
            ) : (
              data.items.map((m) => (
                <article key={m.id} className="card board-card board-ended-card">
                  <span className="board-meta">
                    {m.author_name ?? 'Unbekannt'} · {formatShortTime(m.created_at)} ·{' '}
                    {m.deleted_at
                      ? `gelöscht von ${m.deleted_by_name ?? 'Unbekannt'} · ${formatShortTime(m.deleted_at)}`
                      : `abgelaufen · ${formatShortTime(m.expires_at)}`}
                  </span>
                  <h3 className="board-title">{m.title}</h3>
                  <RichTextView html={m.text} className="board-text" />
                  <button className="btn btn-link" aria-expanded={shown === m.id} onClick={() => setShown(shown === m.id ? null : m.id)}>
                    {shown === m.id ? 'Verlauf ausblenden' : 'Verlauf'}
                  </button>
                  {shown === m.id && <History id={m.id} />}
                </article>
              ))
            )}
          </>
        ))}
    </section>
  );
}

/** Laufende Umfrage am Brett (Issue #4): mit Status „beantwortet“ bzw. „offen“. */
function PollEntry({ poll }: { poll: BoardPoll }) {
  return (
    <article className="card board-card board-poll-card">
      <span className="board-meta">
        {brand.labels.sections.polls} · {pollEndText(poll)}
      </span>
      <p className="board-text">{poll.title}</p>
      <div className="button-row">
        <span className={`badge ${poll.has_voted ? 'badge-paid' : 'badge-open'}`}>{poll.has_voted ? 'beantwortet' : 'offen'}</span>
        <button className="btn btn-secondary" onClick={() => navigate(`umfragen/${poll.id}`)}>
          {poll.has_voted ? 'Antwort ansehen' : 'Teilnehmen'}
        </button>
      </div>
    </article>
  );
}

/**
 * Seite #/brett: alle gültigen Nachrichten (auch ausgeblendete) mit Titel und formatiertem Inhalt; mit post_board
 * schreiben, ändern, löschen, Verlauf. Darunter für Mitglieder alle laufenden Umfragen mit Status und der Weg zu den
 * beendeten (Issue #4).
 */
export default function Brett({ onToast }: { onToast: (msg: string) => void }) {
  const { items } = useBoard();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const messages = items.filter((i): i is BoardMessage => i.kind === 'message');
  const polls = items.filter((i): i is BoardPoll => i.kind === 'poll');

  const load = () => {
    setLoading(true);
    void refreshBoard().then((e) => {
      setError(e);
      setLoading(false);
    });
  };
  useEffect(load, []);

  return (
    <div className="board-page">
      {canPostBoard() && <NewMessage onToast={onToast} />}
      {!boardLoaded() && loading ? (
        <Loading />
      ) : !boardLoaded() && error ? (
        <ErrorBox message={error} onRetry={load} />
      ) : messages.length === 0 ? (
        <Empty>Zurzeit gibt es keine Nachrichten.</Empty>
      ) : (
        <>
          <h2 className="board-page-title">Aktuelle Nachrichten</h2>
          {messages.map((item) => (
            <Entry key={`${item.id}-${item.updated_at}`} item={item} onToast={onToast} />
          ))}
        </>
      )}
      {canPostBoard() && <Ended />}
      {(pollsVisible() || canManagePolls()) && boardLoaded() && (
        <section className="board-polls">
          <h2 className="board-page-title">Laufende {brand.labels.sections.polls}</h2>
          {polls.length === 0 ? (
            <p className="muted small">Zurzeit läuft keine Umfrage.</p>
          ) : (
            polls.map((p) => <PollEntry key={p.id} poll={p} />)
          )}
          <div className="button-row">
            <button className="btn btn-link board-polls-ended" onClick={() => navigate('umfragen')}>
              Beendete {brand.labels.sections.polls}
            </button>
            {canManagePolls() && (
              <button className="btn btn-secondary board-polls-manage" onClick={() => navigate('umfragen/verwalten')}>
                {brand.labels.sections.polls} verwalten
              </button>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
