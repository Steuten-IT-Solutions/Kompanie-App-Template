import { useEffect, useState, type FormEvent } from 'react';
import { api, type BoardItem } from '../api';
import { BOARD_TEXT_MAX, boardLoaded, canPostBoard, refreshBoard, useBoard } from '../board';
import { BoardMeta, BoardText } from '../BoardBanner';
import { formatShortTime } from '../format';
import { Empty, ErrorBox, Loading } from '../ui';

function Counter({ text }: { text: string }) {
  return (
    <span className="muted small board-counter">
      {text.length}/{BOARD_TEXT_MAX} Zeichen
    </span>
  );
}

/** Neue Nachricht: mit Hinweis auf Benachrichtigung und 24 Stunden, Bestätigung vor dem Senden. */
function NewMessage({ onToast }: { onToast: (msg: string) => void }) {
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (text.trim() === '') return setError('Bitte einen Text eingeben.');
    if (!window.confirm('Nachricht jetzt veröffentlichen? Alle Mitglieder und Anwärter werden sofort benachrichtigt.')) return;
    setBusy(true);
    try {
      await api.postBoard(text);
      setText('');
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
        <span>Text</span>
        <textarea rows={4} maxLength={BOARD_TEXT_MAX} value={text} onChange={(e) => setText(e.target.value)} />
        <Counter text={text} />
      </label>
      <p className="muted small">
        Alle Mitglieder und Anwärter bekommen sofort eine Benachrichtigung. Die Nachricht steht 24 Stunden oben auf jeder Seite der App und
        verschwindet dann von selbst. Nur für Dringendes, nur reiner Text.
      </p>
      {error && <p className="form-error small">{error}</p>}
      <button className="btn btn-block" type="submit" disabled={busy || text.trim() === ''}>
        {busy ? 'Sendet …' : 'Veröffentlichen'}
      </button>
    </form>
  );
}

function Entry({ item, onToast }: { item: BoardItem; onToast: (msg: string) => void }) {
  const [draft, setDraft] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canPost = canPostBoard();

  async function run(action: () => Promise<unknown>, message: string) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onToast(message);
      setDraft(null);
      await refreshBoard();
    } catch (err) {
      setError((err as Error).message);
      await refreshBoard(); // z. B. inzwischen abgelaufen oder gelöscht
    } finally {
      setBusy(false);
    }
  }

  return (
    <article className="card board-card">
      <BoardMeta item={item} />
      {draft === null ? (
        <BoardText text={item.text} />
      ) : (
        <label className="field">
          <span>Text</span>
          <textarea rows={4} maxLength={BOARD_TEXT_MAX} value={draft} onChange={(e) => setDraft(e.target.value)} />
          <Counter text={draft} />
        </label>
      )}
      <p className="muted small">Sichtbar bis {formatShortTime(item.expires_at)}</p>
      {error && <p className="form-error small">{error}</p>}
      {canPost &&
        (draft === null ? (
          <div className="button-row">
            <button className="btn btn-secondary" disabled={busy} onClick={() => setDraft(item.text)}>
              Bearbeiten
            </button>
            <button
              className="btn btn-danger"
              disabled={busy}
              onClick={() => window.confirm('Nachricht jetzt löschen?') && run(() => api.deleteBoard(item.id), 'Nachricht gelöscht')}
            >
              Löschen
            </button>
          </div>
        ) : (
          <>
            <p className="muted small">Ändern verschickt keine neue Benachrichtigung und verlängert die 24 Stunden nicht.</p>
            <div className="button-row">
              <button
                className="btn"
                disabled={busy || draft.trim() === ''}
                onClick={() => run(() => api.updateBoard(item.id, draft), 'Nachricht geändert')}
              >
                Speichern
              </button>
              <button className="btn btn-secondary" disabled={busy} onClick={() => setDraft(null)}>
                Abbrechen
              </button>
            </div>
          </>
        ))}
    </article>
  );
}

/** Seite #/brett: alle gültigen Nachrichten (auch ausgeblendete); mit post_board schreiben, ändern, löschen. */
export default function Brett({ onToast }: { onToast: (msg: string) => void }) {
  const { items } = useBoard();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const messages = items.filter((i) => i.kind === 'message');

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
    </div>
  );
}
