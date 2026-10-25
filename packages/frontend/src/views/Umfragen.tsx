import { useEffect, useState, type FormEvent } from 'react';
import { api, type Poll, type PollInput, type PollResults, type PollType } from '../api';
import { canManagePolls, pollsVisible, refreshBoard } from '../board';
import { formatShortTime } from '../format';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { prefetchOnTap, sources } from '../viewData';

// Umfragen (Issue #4): #/umfragen (alle, auch beendete), #/umfragen/<id> (antworten, ändern, zurückziehen, Ergebnisse)
// und #/umfragen/verwalten (anlegen, ändern, beenden, löschen; nur mit manage_polls). Laufende Umfragen stehen am
// Schwarzen Brett; diese Seiten erreicht man von dort (auch über das Banner), über die Benachrichtigung und mit
// manage_polls über das Menü der Verwaltung.

const sec = brand.labels.sections;
/** Grenzen wie im Kern (PollService). */
const TITLE_MAX = 150;
const DESCRIPTION_MAX = 1000;
const OPTION_MAX = 100;
const OPTIONS_MAX = 20;
const TEXT_MAX = 1000;

const TYPE_LABELS: Record<PollType, string> = { single: 'Eine Antwort', multiple: 'Mehrere Antworten möglich', text: 'Freitext' };
const RESULT_LABELS: Record<PollResults, string> = {
  after_vote: 'Alle, sobald sie geantwortet haben',
  after_end: 'Alle nach dem Ende',
  managers: 'Nur die Verwalter der Umfragen',
};

/** Wann die Ergebnisse sichtbar werden – vor dem Antworten deutlich gezeigt. */
function resultsHint(poll: Poll): string {
  switch (poll.results) {
    case 'after_vote':
      return 'Die Ergebnisse siehst du, sobald du geantwortet hast; nach dem Ende sehen sie alle Mitglieder.';
    case 'after_end':
      return 'Die Ergebnisse sehen alle Mitglieder erst nach dem Ende der Umfrage.';
    default:
      return 'Die Ergebnisse sehen nur die Verwalter der Umfragen.';
  }
}

function endText(poll: Poll): string {
  if (poll.status === 'ended') return `Beendet ${formatShortTime(poll.closed_at ?? poll.ends_at ?? poll.updated_at)}`;
  return poll.ends_at ? `Läuft bis ${formatShortTime(poll.ends_at)}` : 'Läuft, bis sie beendet wird';
}

/** Hinweis: namentlich oder anonym, und wer die Ergebnisse sieht. */
function PrivacyNote({ poll }: { poll: Poll }) {
  return (
    <div className={`poll-privacy ${poll.anonymous ? 'poll-anonymous' : 'poll-named'}`}>
      <strong>{poll.anonymous ? 'Anonym' : 'Namentlich'}</strong>
      <p className="small">
        {poll.anonymous
          ? 'Deine Antwort wird niemandem angezeigt. Gezählt wird sie nur in den Ergebnissen, ohne Namen.'
          : poll.results === 'managers'
            ? 'Die Verwalter der Umfragen sehen, wer was geantwortet hat.'
            : 'Wer die Ergebnisse sieht, sieht auch, wer was geantwortet hat.'}
      </p>
      <p className="small">{resultsHint(poll)}</p>
    </div>
  );
}

/** Ergebnisse: Balken mit Anzahl und Prozent, Freitexte als Liste; Namen nur bei namentlichen Umfragen. */
export function PollResultsView({ poll }: { poll: Poll }) {
  const data = poll.results_data;
  if (!data) return null;
  const people = data.voters === 1 ? '1 Teilnahme' : `${data.voters} Teilnahmen`;
  return (
    <section className="poll-results" aria-label="Ergebnisse">
      <h3>Ergebnisse</h3>
      <p className="muted small">{people}</p>
      {data.options?.map((o) => (
        <div className="poll-bar" key={o.id}>
          <div className="poll-bar-head">
            <span className="poll-bar-title">{o.title}</span>
            <span className="poll-bar-value">
              {o.count} · {o.percent} %
            </span>
          </div>
          <div className="poll-bar-track" role="presentation">
            <progress className="poll-bar-fill" max={100} value={o.percent} aria-label={`${o.title}: ${o.count}, ${o.percent} %`} />
          </div>
          {o.names && o.names.length > 0 && <p className="muted small poll-names">{o.names.join(', ')}</p>}
        </div>
      ))}
      {data.texts &&
        (data.texts.length === 0 ? (
          <p className="muted small">Noch keine Antworten.</p>
        ) : (
          <ul className="poll-texts">
            {data.texts.map((t, i) => (
              <li key={i}>
                <span className="board-text">{t.text}</span>
                {t.name && <span className="muted small"> – {t.name}</span>}
              </li>
            ))}
          </ul>
        ))}
    </section>
  );
}

/** Antworten, ändern und zurückziehen, solange die Umfrage läuft. */
function AnswerForm({ poll, onChanged, onToast }: { poll: Poll; onChanged: (p: Poll) => void; onToast: (msg: string) => void }) {
  const [chosen, setChosen] = useState<number[]>(poll.my_answer?.option_ids ?? []);
  const [text, setText] = useState(poll.my_answer?.text ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const running = poll.status === 'running';

  async function run(action: () => Promise<Poll>, message: string) {
    setBusy(true);
    setError(null);
    try {
      const next = await action();
      onChanged(next);
      onToast(message);
      void refreshBoard(); // Banner: beantwortete Umfragen verschwinden dort
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    if (poll.type === 'text') {
      if (text.trim() === '') return setError('Bitte eine Antwort eingeben.');
      return run(() => api.vote(poll.id, { text }), poll.has_voted ? 'Antwort geändert' : 'Antwort gespeichert');
    }
    if (chosen.length === 0) return setError('Bitte eine Antwort auswählen.');
    return run(() => api.vote(poll.id, { option_ids: chosen }), poll.has_voted ? 'Antwort geändert' : 'Antwort gespeichert');
  }

  const toggle = (id: number, on: boolean) =>
    setChosen(poll.type === 'single' ? [id] : on ? [...chosen.filter((c) => c !== id), id] : chosen.filter((c) => c !== id));

  return (
    <form className="form poll-answer" onSubmit={submit}>
      <fieldset disabled={!running || busy}>
        <legend>{poll.type === 'multiple' ? 'Deine Antwort (mehrere möglich)' : 'Deine Antwort'}</legend>
        {poll.type === 'text' ? (
          <label className="field">
            <span>Antwort</span>
            <textarea rows={3} maxLength={TEXT_MAX} value={text} onChange={(e) => setText(e.target.value)} />
          </label>
        ) : (
          poll.options.map((o) => (
            <label className="poll-option" key={o.id}>
              <input
                type={poll.type === 'single' ? 'radio' : 'checkbox'}
                name={`poll-${poll.id}`}
                checked={chosen.includes(o.id)}
                onChange={(e) => toggle(o.id, e.target.checked)}
              />
              <span>{o.title}</span>
            </label>
          ))
        )}
      </fieldset>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      {running ? (
        <div className="button-row">
          <button className="btn" type="submit" disabled={busy}>
            {poll.has_voted ? 'Antwort ändern' : 'Antwort senden'}
          </button>
          {poll.has_voted && (
            <button
              className="btn btn-secondary"
              type="button"
              disabled={busy}
              onClick={() =>
                window.confirm('Antwort wirklich zurückziehen?') && run(() => api.withdrawVote(poll.id), 'Antwort zurückgezogen')
              }
            >
              Zurückziehen
            </button>
          )}
        </div>
      ) : (
        <p className="muted small poll-closed-note">Die Umfrage ist beendet. Antworten lassen sich nicht mehr ändern.</p>
      )}
    </form>
  );
}

function PollHead({ poll }: { poll: Poll }) {
  return (
    <>
      <h2 className="poll-title">{poll.title}</h2>
      <p className="muted small">
        {TYPE_LABELS[poll.type]} · {endText(poll)}
        {poll.has_voted && ' · beantwortet'}
      </p>
      {poll.description && <p className="board-text">{poll.description}</p>}
    </>
  );
}

/** #/umfragen/<id> */
function PollPage({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const s = sources.poll(id);
  const { data, error, loading, reload, setData } = useAsync(s.load, s.deps, s.key);
  if (!data && loading) return <Loading />;
  if (!data) return <ErrorBox message={error ?? 'Die Umfrage gibt es nicht (mehr).'} onRetry={reload} />;
  const poll = data;
  return (
    <div className="poll-page">
      <article className="card poll-card">
        <PollHead poll={poll} />
        <PrivacyNote poll={poll} />
        {pollsVisible() && (
          <AnswerForm key={`${poll.id}-${poll.updated_at}-${poll.status}`} poll={poll} onChanged={setData} onToast={onToast} />
        )}
        {poll.results_visible ? (
          <PollResultsView poll={poll} />
        ) : (
          <p className="muted small poll-results-hidden">Ergebnisse: {resultsHint(poll)}</p>
        )}
      </article>
      <div className="button-row">
        <button className="btn btn-secondary" onClick={() => navigate('brett')}>
          Zum {sec.board}
        </button>
        <button className="btn btn-secondary" onClick={() => navigate('umfragen')}>
          Alle {sec.polls}
        </button>
      </div>
    </div>
  );
}

/** #/umfragen: laufende und beendete Umfragen (beendete mit Ergebnis, soweit sichtbar). */
function PollList() {
  const { data, error, loading, reload } = useAsync(() => api.polls(), [], 'polls');
  if (!data && loading) return <Loading />;
  if (!data) return <ErrorBox message={error ?? 'Fehler'} onRetry={reload} />;
  const running = data.filter((p) => p.status === 'running');
  const ended = data.filter((p) => p.status === 'ended');
  const item = (p: Poll) => (
    <button className="card poll-list-item" key={p.id} onClick={() => navigate(`umfragen/${p.id}`)} {...prefetchOnTap(sources.poll(p.id))}>
      <strong>{p.title}</strong>
      <span className="muted small">
        {endText(p)} · {p.has_voted ? 'beantwortet' : p.status === 'running' ? 'offen' : 'nicht teilgenommen'}
        {p.results_visible && p.results_data ? ` · ${p.results_data.voters} Teilnahmen` : ''}
      </span>
    </button>
  );
  return (
    <div className="poll-list">
      {canManagePolls() && (
        <button className="btn btn-block poll-manage-link" onClick={() => navigate('umfragen/verwalten')}>
          {sec.polls} verwalten
        </button>
      )}
      <h2 className="board-page-title">Laufend</h2>
      {running.length === 0 ? <p className="muted small">Zurzeit läuft keine Umfrage.</p> : running.map(item)}
      <h2 className="board-page-title">Beendete {sec.polls}</h2>
      {ended.length === 0 ? <Empty>Noch keine beendeten Umfragen.</Empty> : ended.map(item)}
    </div>
  );
}

/** Lokale Zeit fürs Feld datetime-local („JJJJ-MM-TTTHH:MM“). */
function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Eingabe des Felds als Zeitpunkt mit Zeitzone (Ortszeit des Geräts); leer = ohne Ende. */
function fromLocalInput(value: string): string | null {
  if (!value) return null;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toISOString();
}

/** Anlegen und Ändern; Art, Möglichkeiten, Anonymität und Sichtbarkeit sind nach der ersten Antwort fest. */
function PollForm({ poll, onSaved, onCancel }: { poll?: Poll; onSaved: (p: Poll, created: boolean) => void; onCancel?: () => void }) {
  const [title, setTitle] = useState(poll?.title ?? '');
  const [description, setDescription] = useState(poll?.description ?? '');
  const [type, setType] = useState<PollType>(poll?.type ?? 'single');
  const [options, setOptions] = useState<string[]>(poll ? poll.options.map((o) => o.title) : ['', '']);
  const [anonymous, setAnonymous] = useState(poll?.anonymous ?? false);
  const [results, setResults] = useState<PollResults>(poll?.results ?? 'after_vote');
  const [endsAt, setEndsAt] = useState(toLocalInput(poll?.ends_at ?? null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const locked = !!poll?.locked;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (title.trim() === '') return setError('Bitte eine Frage eingeben.');
    if (type !== 'text' && options.filter((o) => o.trim() !== '').length < 2)
      return setError('Bitte mindestens zwei Antwortmöglichkeiten eingeben.');
    if (!poll && !window.confirm('Umfrage jetzt veröffentlichen? Alle Mitglieder werden benachrichtigt.')) return;
    const data: PollInput = { title, description, ends_at: fromLocalInput(endsAt) };
    if (!locked) Object.assign(data, { type, anonymous, results, ...(type !== 'text' ? { options } : {}) });
    setBusy(true);
    try {
      const saved = poll ? await api.updatePoll(poll.id, data) : await api.createPoll(data);
      onSaved(saved, !poll);
      if (!poll) {
        setTitle('');
        setDescription('');
        setOptions(['', '']);
        setEndsAt('');
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card poll-form" onSubmit={submit}>
      <h3>{poll ? 'Umfrage bearbeiten' : 'Neue Umfrage'}</h3>
      <label className="field">
        <span>Frage</span>
        <input type="text" value={title} maxLength={TITLE_MAX} onChange={(e) => setTitle(e.target.value)} />
      </label>
      <label className="field">
        <span>Beschreibung (optional)</span>
        <textarea rows={2} maxLength={DESCRIPTION_MAX} value={description} onChange={(e) => setDescription(e.target.value)} />
      </label>
      {locked && <p className="muted small">Es gibt schon Antworten: Art, Antwortmöglichkeiten, Anonymität und Sichtbarkeit sind fest.</p>}
      <fieldset disabled={locked}>
        <label className="field">
          <span>Art</span>
          <select value={type} onChange={(e) => setType(e.target.value as PollType)}>
            {(Object.keys(TYPE_LABELS) as PollType[]).map((t) => (
              <option key={t} value={t}>
                {TYPE_LABELS[t]}
              </option>
            ))}
          </select>
        </label>
        {type !== 'text' && (
          <div className="field poll-options-edit">
            <span>Antwortmöglichkeiten</span>
            {options.map((o, i) => (
              <div className="poll-option-edit" key={i}>
                <input
                  type="text"
                  aria-label={`Möglichkeit ${i + 1}`}
                  value={o}
                  maxLength={OPTION_MAX}
                  onChange={(e) => setOptions(options.map((x, j) => (j === i ? e.target.value : x)))}
                />
                {options.length > 2 && (
                  <button
                    type="button"
                    className="btn btn-link"
                    aria-label={`Möglichkeit ${i + 1} entfernen`}
                    onClick={() => setOptions(options.filter((_, j) => j !== i))}
                  >
                    ×
                  </button>
                )}
              </div>
            ))}
            {options.length < OPTIONS_MAX && (
              <button type="button" className="btn btn-link poll-add-option" onClick={() => setOptions([...options, ''])}>
                + Möglichkeit
              </button>
            )}
          </div>
        )}
        <div className="field">
          <span>Antworten</span>
          <label className="poll-option">
            <input type="radio" name="poll-anon" checked={!anonymous} onChange={() => setAnonymous(false)} />
            <span>Namentlich</span>
          </label>
          <label className="poll-option">
            <input type="radio" name="poll-anon" checked={anonymous} onChange={() => setAnonymous(true)} />
            <span>Anonym (niemand sieht, wer was geantwortet hat)</span>
          </label>
        </div>
        <label className="field">
          <span>Wer sieht die Ergebnisse?</span>
          <select value={results} onChange={(e) => setResults(e.target.value as PollResults)}>
            {(Object.keys(RESULT_LABELS) as PollResults[]).map((r) => (
              <option key={r} value={r}>
                {RESULT_LABELS[r]}
              </option>
            ))}
          </select>
        </label>
      </fieldset>
      <label className="field">
        <span>Ende (optional)</span>
        <input type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} />
      </label>
      <p className="muted small">
        {poll
          ? 'Ändern verschickt keine neue Benachrichtigung.'
          : 'Alle Mitglieder bekommen eine Benachrichtigung; 24 Stunden vor dem Ende werden alle erinnert, die noch nicht geantwortet haben. Anonymität und Sichtbarkeit lassen sich nach der ersten Antwort nicht mehr ändern.'}
      </p>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : poll ? 'Speichern' : 'Veröffentlichen'}
        </button>
        {onCancel && (
          <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
            Abbrechen
          </button>
        )}
      </div>
    </form>
  );
}

function ManagedPoll({ poll, onChanged, onToast }: { poll: Poll; onChanged: () => void; onToast: (msg: string) => void }) {
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, message: string) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onToast(message);
      onChanged();
      void refreshBoard();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (editing) {
    return (
      <PollForm
        poll={poll}
        onCancel={() => setEditing(false)}
        onSaved={() => {
          setEditing(false);
          onToast('Umfrage geändert');
          onChanged();
          void refreshBoard();
        }}
      />
    );
  }
  return (
    <article className="card poll-card poll-managed">
      <PollHead poll={poll} />
      <p className="muted small">
        {poll.anonymous ? 'Anonym' : 'Namentlich'} · Ergebnisse: {RESULT_LABELS[poll.results]}
      </p>
      <PollResultsView poll={poll} />
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        {poll.status === 'running' && (
          <>
            <button className="btn btn-secondary" disabled={busy} onClick={() => setEditing(true)}>
              Bearbeiten
            </button>
            <button
              className="btn btn-secondary"
              disabled={busy}
              onClick={() =>
                window.confirm('Umfrage jetzt beenden? Danach kann niemand mehr antworten.') &&
                run(() => api.closePoll(poll.id), 'Umfrage beendet')
              }
            >
              Beenden
            </button>
          </>
        )}
        <button
          className="btn btn-danger"
          disabled={busy}
          onClick={() => window.confirm('Umfrage mit allen Antworten löschen?') && run(() => api.deletePoll(poll.id), 'Umfrage gelöscht')}
        >
          Löschen
        </button>
      </div>
    </article>
  );
}

/** #/umfragen/verwalten (manage_polls): anlegen, ändern, beenden, löschen; alle Umfragen mit Ergebnissen. */
function PollAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(() => api.polls(), [], 'polls');
  return (
    <div className="poll-admin">
      <PollForm
        onSaved={() => {
          onToast('Umfrage veröffentlicht');
          reload();
          void refreshBoard();
        }}
      />
      {!data && loading ? (
        <Loading />
      ) : !data ? (
        <ErrorBox message={error ?? 'Fehler'} onRetry={reload} />
      ) : data.length === 0 ? (
        <Empty>Noch keine Umfragen.</Empty>
      ) : (
        <>
          <h2 className="board-page-title">Alle {sec.polls}</h2>
          {data.map((p) => (
            <ManagedPoll key={`${p.id}-${p.updated_at}`} poll={p} onChanged={reload} onToast={onToast} />
          ))}
        </>
      )}
    </div>
  );
}

export default function Umfragen({ path, onToast }: { path: string[]; onToast: (msg: string) => void }) {
  const sub = path[0];
  // Beim Öffnen einer Umfrage aus der Benachrichtigung auch das Brett (Banner) auffrischen.
  useEffect(() => {
    void refreshBoard();
  }, [sub]);
  if (sub === 'verwalten') return canManagePolls() ? <PollAdmin onToast={onToast} /> : <PollList />;
  if (sub && /^\d+$/.test(sub)) return <PollPage id={Number(sub)} onToast={onToast} />;
  return <PollList />;
}
