import { useEffect, useState, type FormEvent } from 'react';
import { api, type Poll, type PollInput, type PollResults, type PollType } from '../api';
import { canManagePolls, pollsVisible, refreshBoard } from '../board';
import { formatShortTime, fromLocalInput, toLocalInput } from '../format';
import brand from '../generated/brand';
import { t, tn } from '../i18n';
import { navigate } from '../router';
import { Caps, Empty, ErrorBox, Loading, useAsync } from '../ui';
import { prefetchOnTap, sources } from '../viewData';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { LANGUAGE_NAMES, UntranslatedNote } from '../language';
import type { Locale } from '../i18n';
import { extraLocales, FieldTranslationsEditor, type FieldTranslations, type TranslatedField } from '../translationFields';

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

const TYPE_LABELS: Record<PollType, string> = {
  single: t('Eine Antwort'),
  multiple: t('Mehrere Antworten möglich'),
  text: t('Freitext'),
};
const RESULT_LABELS: Record<PollResults, string> = {
  after_vote: t('Alle, sobald sie geantwortet haben'),
  after_end: t('Alle nach dem Ende'),
  managers: t('Nur wer alle Ergebnisse sehen darf'),
};

/** Frage und Beschreibung je weitere Sprache (Plan „Mehrsprachigkeit“, PR 9d); die Möglichkeiten darunter. */
const TRANSLATED: TranslatedField[] = [
  { key: 'title', label: (language) => t('Frage auf {language}', { language }), maxLength: TITLE_MAX },
  { key: 'description', label: (language) => t('Beschreibung auf {language}', { language }), multiline: true },
];
type OptionTranslations = Partial<Record<Locale, string[]>>;

/**
 * Fassungen für die Anfrage: beim Anlegen nur Sprachen mit Eintrag, beim Ändern nur geänderte; Möglichkeiten gleich
 * viele wie auf Deutsch (leer = deutsch).
 */
function pollTranslationsInput(
  fields: FieldTranslations,
  options: OptionTranslations,
  count: number,
  poll?: Poll,
): PollInput['translations'] {
  const pad = (list: string[] | undefined) => Array.from({ length: count }, (_, i) => (list?.[i] ?? '').trim());
  const out: NonNullable<PollInput['translations']> = {};
  for (const lang of extraLocales()) {
    const next = {
      title: (fields[lang]?.title ?? '').trim(),
      description: (fields[lang]?.description ?? '').trim(),
      options: pad(options[lang]),
    };
    const old = poll?.translations?.[lang];
    const changed = poll
      ? JSON.stringify(next) !==
        JSON.stringify({ title: old?.title ?? '', description: old?.description ?? '', options: pad(old?.options) })
      : next.title !== '' || next.description !== '' || next.options.some((o) => o !== '');
    if (changed) out[lang] = count > 0 ? next : { title: next.title, description: next.description };
  }
  return Object.keys(out).length > 0 ? out : undefined;
}

/** Wann die Ergebnisse sichtbar werden – vor dem Antworten deutlich gezeigt. */
function resultsHint(poll: Poll): string {
  switch (poll.results) {
    case 'after_vote':
      return t('Die Ergebnisse siehst du, sobald du geantwortet hast; nach dem Ende sehen sie alle Mitglieder.');
    case 'after_end':
      return t('Die Ergebnisse sehen alle Mitglieder erst nach dem Ende der Umfrage.');
    default:
      return t('Die Ergebnisse sehen nur, wer alle Ergebnisse sehen darf.');
  }
}

function endText(poll: Poll): string {
  if (poll.status === 'ended') return t('Beendet {time}', { time: formatShortTime(poll.closed_at ?? poll.ends_at ?? poll.updated_at) });
  return poll.ends_at ? t('Läuft bis {time}', { time: formatShortTime(poll.ends_at) }) : t('Läuft, bis sie beendet wird');
}

/** Hinweis: namentlich oder anonym, und wer die Ergebnisse sieht. */
function PrivacyNote({ poll }: { poll: Poll }) {
  return (
    <div className={`poll-privacy ${poll.anonymous ? 'poll-anonymous' : 'poll-named'}`}>
      <strong>{poll.anonymous ? t('Anonym') : t('Namentlich')}</strong>
      <p className="small">
        {poll.anonymous
          ? t('Deine Antwort wird niemandem angezeigt. Gezählt wird sie nur in den Ergebnissen, ohne Namen.')
          : poll.results === 'managers'
            ? t('Wer alle Ergebnisse sehen darf, sieht, wer was geantwortet hat.')
            : t('Wer die Ergebnisse sieht, sieht auch, wer was geantwortet hat.')}
      </p>
      <p className="small">{resultsHint(poll)}</p>
    </div>
  );
}

/** Ergebnisse: Balken mit Anzahl und Prozent, Freitexte als Liste; Namen nur bei namentlichen Umfragen. */
export function PollResultsView({ poll }: { poll: Poll }) {
  const data = poll.results_data;
  if (!data) return null;
  const people = tn('{count} Teilnahme', '{count} Teilnahmen', data.voters);
  return (
    <section className="poll-results" aria-label={t('Ergebnisse')}>
      <h3>{t('Ergebnisse')}</h3>
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
            <progress
              className="poll-bar-fill"
              max={100}
              value={o.percent}
              aria-label={t('{option}: {count}, {percent} %', { option: o.title, count: o.count, percent: o.percent })}
            />
          </div>
          {o.names && o.names.length > 0 && <p className="muted small poll-names">{o.names.join(', ')}</p>}
        </div>
      ))}
      {data.texts &&
        (data.texts.length === 0 ? (
          <p className="muted small">{t('Noch keine Antworten.')}</p>
        ) : (
          <ul className="poll-texts">
            {data.texts.map((x, i) => (
              <li key={i}>
                <span className="board-text">{x.text}</span>
                {x.name && <span className="muted small"> – {x.name}</span>}
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
      if (text.trim() === '') return setError(t('Bitte eine Antwort eingeben.'));
      return run(() => api.vote(poll.id, { text }), poll.has_voted ? t('Antwort geändert') : t('Antwort gespeichert'));
    }
    if (chosen.length === 0) return setError(t('Bitte eine Antwort auswählen.'));
    return run(() => api.vote(poll.id, { option_ids: chosen }), poll.has_voted ? t('Antwort geändert') : t('Antwort gespeichert'));
  }

  const toggle = (id: number, on: boolean) =>
    setChosen(poll.type === 'single' ? [id] : on ? [...chosen.filter((c) => c !== id), id] : chosen.filter((c) => c !== id));

  return (
    <form className="form poll-answer" onSubmit={submit}>
      <fieldset disabled={!running || busy}>
        <legend>{poll.type === 'multiple' ? t('Deine Antwort (mehrere möglich)') : t('Deine Antwort')}</legend>
        {poll.type === 'text' ? (
          <label className="field">
            <span>{t('Antwort')}</span>
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
            {poll.has_voted ? t('Antwort ändern') : t('Antwort senden')}
          </button>
          {poll.has_voted && (
            <button
              className="btn btn-secondary"
              type="button"
              disabled={busy}
              onClick={async () =>
                (await confirmAsk(t('Antwort wirklich zurückziehen?'))) && run(() => api.withdrawVote(poll.id), t('Antwort zurückgezogen'))
              }
            >
              {t('Zurückziehen')}
            </button>
          )}
        </div>
      ) : (
        <p className="muted small poll-closed-note">{t('Die Umfrage ist beendet. Antworten lassen sich nicht mehr ändern.')}</p>
      )}
    </form>
  );
}

function PollHead({ poll }: { poll: Poll }) {
  return (
    <>
      <h2 className="poll-title">{poll.title}</h2>
      <UntranslatedNote untranslated={poll.untranslated} />
      <p className="muted small">
        {TYPE_LABELS[poll.type]} · {endText(poll)}
        {poll.has_voted && ` · ${t('beantwortet')}`}
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
  if (!data) return <ErrorBox message={error ?? t('Die Umfrage gibt es nicht (mehr).')} onRetry={reload} />;
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
          <p className="muted small poll-results-hidden">{t('Ergebnisse: {hint}', { hint: resultsHint(poll) })}</p>
        )}
      </article>
      {/* Als Verweise statt großer Knöpfe; ohne „Zum …“, weil der Name des Bereichs frei wählbar ist (Beugung) */}
      <div className="poll-nav">
        <button className="btn btn-link" onClick={() => navigate('brett')}>
          ‹ {sec.board}
        </button>
        <button className="btn btn-link" onClick={() => navigate('umfragen')}>
          {t('Alle {section}', { section: sec.polls })} ›
        </button>
      </div>
    </div>
  );
}

/** #/umfragen: laufende und beendete Umfragen (beendete mit Ergebnis, soweit sichtbar). */
function PollList() {
  const { data, error, loading, reload } = useAsync(sources.polls.load, sources.polls.deps, sources.polls.key);
  if (!data && loading) return <Loading />;
  if (!data) return <ErrorBox message={error ?? t('Fehler')} onRetry={reload} />;
  const running = data.filter((p) => p.status === 'running');
  const ended = data.filter((p) => p.status === 'ended');
  const item = (p: Poll) => (
    <button className="card poll-list-item" key={p.id} onClick={() => navigate(`umfragen/${p.id}`)} {...prefetchOnTap(sources.poll(p.id))}>
      <strong>{p.title}</strong>
      <span className="muted small">
        {endText(p)} · {p.has_voted ? t('beantwortet') : p.status === 'running' ? t('offen') : t('nicht teilgenommen')}
        {p.results_visible && p.results_data ? ` · ${tn('{count} Teilnahme', '{count} Teilnahmen', p.results_data.voters)}` : ''}
      </span>
    </button>
  );
  return (
    <div className="poll-list">
      {canManagePolls() && (
        <button className="btn btn-secondary btn-block poll-manage-link" onClick={() => navigate('umfragen/verwalten')}>
          <Caps text={t('{section} verwalten', { section: sec.polls })} />
        </button>
      )}
      <h2 className="board-page-title">{t('Laufend')}</h2>
      {running.length === 0 ? <p className="muted small">{t('Zurzeit läuft keine Umfrage.')}</p> : running.map(item)}
      <h2 className="board-page-title">{t('Beendete {section}', { section: sec.polls })}</h2>
      {ended.length === 0 ? <Empty>{t('Noch keine beendeten Umfragen.')}</Empty> : ended.map(item)}
    </div>
  );
}

/** Anlegen und Ändern; Art, Möglichkeiten, Anonymität und Sichtbarkeit sind nach der ersten Antwort fest. */
function PollForm({ poll, onSaved, onCancel }: { poll?: Poll; onSaved: (p: Poll, created: boolean) => void; onCancel?: () => void }) {
  // In einer anderen Sprache als Deutsch bearbeitet die Verwaltung die deutsche Fassung (source).
  const german = poll?.source;
  const [title, setTitle] = useState(german?.title ?? poll?.title ?? '');
  const [description, setDescription] = useState(german?.description ?? poll?.description ?? '');
  const [type, setType] = useState<PollType>(poll?.type ?? 'single');
  const [options, setOptions] = useState<string[]>(german?.options ?? (poll ? poll.options.map((o) => o.title) : ['', '']));
  const [trFields, setTrFields] = useState<FieldTranslations>(() =>
    Object.fromEntries(
      Object.entries(poll?.translations ?? {}).map(([l, tr]) => [l, { title: tr?.title ?? '', description: tr?.description ?? '' }]),
    ),
  );
  const [trOptions, setTrOptions] = useState<OptionTranslations>(() =>
    Object.fromEntries(Object.entries(poll?.translations ?? {}).map(([l, tr]) => [l, tr?.options ?? []])),
  );
  const [anonymous, setAnonymous] = useState(poll?.anonymous ?? false);
  const [results, setResults] = useState<PollResults>(poll?.results ?? 'after_vote');
  const [endsAt, setEndsAt] = useState(toLocalInput(poll?.ends_at ?? null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const locked = !!poll?.locked;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    if (title.trim() === '') return setError(t('Bitte eine Frage eingeben.'));
    if (type !== 'text' && options.filter((o) => o.trim() !== '').length < 2)
      return setError(t('Bitte mindestens zwei Antwortmöglichkeiten eingeben.'));
    if (!poll && !(await confirmAsk(t('Umfrage jetzt veröffentlichen? Alle Mitglieder werden benachrichtigt.')))) return;
    const data: PollInput = { title, description, ends_at: fromLocalInput(endsAt) };
    if (!locked) Object.assign(data, { type, anonymous, results, ...(type !== 'text' ? { options } : {}) });
    const translations = pollTranslationsInput(trFields, trOptions, type !== 'text' ? options.length : 0, poll);
    if (translations) data.translations = translations;
    setBusy(true);
    try {
      const saved = poll ? await api.updatePoll(poll.id, data) : await api.createPoll(data);
      onSaved(saved, !poll);
      if (!poll) {
        setTitle('');
        setDescription('');
        setOptions(['', '']);
        setEndsAt('');
        setTrFields({});
        setTrOptions({});
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card poll-form" onSubmit={submit}>
      <h3>{poll ? t('Umfrage bearbeiten') : t('Neue Umfrage')}</h3>
      <label className="field">
        <span>{t('Frage')}</span>
        <input type="text" value={title} maxLength={TITLE_MAX} onChange={(e) => setTitle(e.target.value)} />
      </label>
      <label className="field">
        <span>{t('Beschreibung (optional)')}</span>
        <textarea rows={2} maxLength={DESCRIPTION_MAX} value={description} onChange={(e) => setDescription(e.target.value)} />
      </label>
      {locked && (
        <p className="muted small">{t('Es gibt schon Antworten: Art, Antwortmöglichkeiten, Anonymität und Sichtbarkeit sind fest.')}</p>
      )}
      <fieldset disabled={locked}>
        <label className="field">
          <span>{t('Art')}</span>
          <select value={type} onChange={(e) => setType(e.target.value as PollType)}>
            {(Object.keys(TYPE_LABELS) as PollType[]).map((k) => (
              <option key={k} value={k}>
                {TYPE_LABELS[k]}
              </option>
            ))}
          </select>
        </label>
        {type !== 'text' && (
          <div className="field poll-options-edit">
            <span>{t('Antwortmöglichkeiten')}</span>
            {options.map((o, i) => (
              <div className="poll-option-edit" key={i}>
                <input
                  type="text"
                  aria-label={t('Möglichkeit {n}', { n: i + 1 })}
                  value={o}
                  maxLength={OPTION_MAX}
                  onChange={(e) => setOptions(options.map((x, j) => (j === i ? e.target.value : x)))}
                />
                {options.length > 2 && (
                  <button
                    type="button"
                    className="btn btn-link"
                    aria-label={t('Möglichkeit {n} entfernen', { n: i + 1 })}
                    onClick={() => {
                      setOptions(options.filter((_, j) => j !== i));
                      setTrOptions(
                        Object.fromEntries(Object.entries(trOptions).map(([l, list]) => [l, (list ?? []).filter((_, j) => j !== i)])),
                      );
                    }}
                  >
                    ×
                  </button>
                )}
              </div>
            ))}
            {options.length < OPTIONS_MAX && (
              <button type="button" className="btn btn-link poll-add-option" onClick={() => setOptions([...options, ''])}>
                + {t('Möglichkeit')}
              </button>
            )}
          </div>
        )}
        <div className="field">
          <span>{t('Antworten')}</span>
          <label className="poll-option">
            <input type="radio" name="poll-anon" checked={!anonymous} onChange={() => setAnonymous(false)} />
            <span>{t('Namentlich')}</span>
          </label>
          <label className="poll-option">
            <input type="radio" name="poll-anon" checked={anonymous} onChange={() => setAnonymous(true)} />
            <span>{t('Anonym (niemand sieht, wer was geantwortet hat)')}</span>
          </label>
        </div>
        <label className="field">
          <span>{t('Wer sieht die Ergebnisse?')}</span>
          <select value={results} onChange={(e) => setResults(e.target.value as PollResults)}>
            {(Object.keys(RESULT_LABELS) as PollResults[]).map((r) => (
              <option key={r} value={r}>
                {RESULT_LABELS[r]}
              </option>
            ))}
          </select>
        </label>
      </fieldset>
      <FieldTranslationsEditor
        fields={TRANSLATED}
        german={{ title, description }}
        value={trFields}
        onChange={setTrFields}
        hint={(language) =>
          t(
            'Wer die App auf {language} nutzt, sieht Frage, Beschreibung und Antwortmöglichkeiten in dieser Fassung. Was leer bleibt, erscheint auf Deutsch.',
            {
              language,
            },
          )
        }
        extra={(lang) =>
          type !== 'text' && (
            <div className="field poll-options-edit">
              <span>{t('Antwortmöglichkeiten auf {language}', { language: LANGUAGE_NAMES[lang] })}</span>
              {options.map((o, i) => (
                <input
                  key={i}
                  type="text"
                  aria-label={t('Möglichkeit {n} auf {language}', { n: i + 1, language: LANGUAGE_NAMES[lang] })}
                  value={trOptions[lang]?.[i] ?? ''}
                  placeholder={o}
                  maxLength={OPTION_MAX}
                  onChange={(e) => {
                    const list = Array.from({ length: options.length }, (_, j) => trOptions[lang]?.[j] ?? '');
                    list[i] = e.target.value;
                    setTrOptions({ ...trOptions, [lang]: list });
                  }}
                />
              ))}
            </div>
          )
        }
      />
      <label className="field">
        <span>{t('Ende (optional)')}</span>
        <input type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} />
      </label>
      <p className="muted small">
        {poll
          ? t('Ändern verschickt keine neue Benachrichtigung.')
          : t(
              'Alle Mitglieder bekommen eine Benachrichtigung; 24 Stunden vor dem Ende werden alle erinnert, die noch nicht geantwortet haben. Anonymität und Sichtbarkeit lassen sich nach der ersten Antwort nicht mehr ändern.',
            )}
      </p>
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? t('Speichert …') : poll ? t('Speichern') : t('Veröffentlichen')}
        </button>
        {onCancel && (
          <button className="btn btn-secondary" type="button" disabled={busy} onClick={onCancel}>
            {t('Abbrechen')}
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
          onToast(t('Umfrage geändert'));
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
        {poll.anonymous ? t('Anonym') : t('Namentlich')} · {t('Ergebnisse: {hint}', { hint: RESULT_LABELS[poll.results] })}
      </p>
      <PollResultsView poll={poll} />
      {error && (
        <p className="form-error small" role="alert">
          {error}
        </p>
      )}
      <div className="icon-row poll-manage-actions">
        {poll.status === 'running' && (
          <>
            <IconButton icon="edit" label={t('Bearbeiten')} disabled={busy} onClick={() => setEditing(true)} />
            <button
              className="btn btn-secondary poll-close"
              disabled={busy}
              onClick={async () =>
                (await confirmAsk(t('Umfrage jetzt beenden? Danach kann niemand mehr antworten.'))) &&
                run(() => api.closePoll(poll.id), t('Umfrage beendet'))
              }
            >
              {t('Beenden')}
            </button>
          </>
        )}
        <IconButton
          icon="delete"
          label={t('Löschen')}
          variant="danger"
          disabled={busy}
          onClick={async () =>
            (await confirmAsk(t('Umfrage mit allen Antworten löschen?'))) && run(() => api.deletePoll(poll.id), t('Umfrage gelöscht'))
          }
        />
      </div>
    </article>
  );
}

/** #/umfragen/verwalten (manage_polls): anlegen, ändern, beenden, löschen; alle Umfragen mit Ergebnissen. */
function PollAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload } = useAsync(sources.polls.load, sources.polls.deps, sources.polls.key);
  return (
    <div className="poll-admin">
      <PollForm
        onSaved={() => {
          onToast(t('Umfrage veröffentlicht'));
          reload();
          void refreshBoard();
        }}
      />
      {!data && loading ? (
        <Loading shape="text" />
      ) : !data ? (
        <ErrorBox message={error ?? t('Fehler')} onRetry={reload} />
      ) : data.length === 0 ? (
        <Empty>{t('Noch keine Umfragen.')}</Empty>
      ) : (
        <>
          <h2 className="board-page-title">{t('Alle {section}', { section: sec.polls })}</h2>
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
