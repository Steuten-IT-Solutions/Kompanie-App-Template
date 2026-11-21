import { useEffect, useState, type FormEvent } from 'react';
import {
  api,
  config,
  type BoardAudience,
  type BoardAudiences,
  type BoardEnded,
  type BoardHistory,
  type BoardMailInfo,
  type BoardMailSummary,
  type BoardMessage,
  type BoardPoll,
  type BoardRevision,
  type Attachment,
} from '../api';
import { AttachmentList, AttachmentsField } from '../attachments';
import { blocksInput, draftsFrom, hasContent, newBlock, type BlockDraft } from '../blocks/blockDrafts';
import BlocksEditor from '../blocks/BlocksEditor';
import BlocksView from '../blocks/BlocksView';
import { boardLoaded, boardSeesAll, boardVisible, canManagePolls, canPostBoard, pollsVisible, refreshBoard, useBoard } from '../board';
import { ComposeEditor } from '../composeEditor';
import { BoardMeta, pollEndText } from '../BoardBanner';
import brand from '../generated/brand';
import { navigate } from '../router';
import { useFocusEntry } from '../focus';
import { formatShortTime, formatUntil, fromLocalInput, toLocalInput } from '../format';
import RichTextView from '../richtext/RichTextView';
import { Caps, Empty, ErrorBox, Loading } from '../ui';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { t } from '../i18n';

/** Entwurf der neuen Nachricht (auf diesem Gerät): geht beim Sprung zur Signatur-Einrichtung nicht verloren. */
const DRAFT_KEY = 'kompanie-board-draft';
const DAY = 24 * 60 * 60 * 1000;
const AUDIENCES: BoardAudience[] = ['members', 'public', 'company', 'person'];
const targeted = (audience?: BoardAudience): boolean => audience === 'company' || audience === 'person';
const sameIds = (a: number[], b: number[]): boolean => [...a].sort((x, y) => x - y).join() === [...b].sort((x, y) => x - y).join();

interface Draft {
  title: string;
  /** Inhalt aus Blöcken (ältere Entwürfe: text, beim Lesen ein Textblock) */
  blocks: BlockDraft[];
  /** datetime-local (Ortszeit); leer = jetzt + 24 Stunden beim Öffnen */
  expires: string;
  /** intern (members, Standard), öffentlich (public), Kompanie (company) oder einzeln (person) */
  audience: BoardAudience;
  /** bei Kompanie und einzeln: ausgewählte Kompanien bzw. Personen */
  audience_ids: number[];
  push: boolean;
  mail: boolean;
  /** schon hochgeladene Anhänge (Entwürfe auf dem Server, 7 Tage gültig) */
  attachments: Attachment[];
}

function readDraft(): Draft | null {
  try {
    const raw = JSON.parse(localStorage.getItem(DRAFT_KEY) ?? 'null');
    return raw && typeof raw === 'object' && typeof raw.title === 'string' && (Array.isArray(raw.blocks) || typeof raw.text === 'string')
      ? ({
          ...raw,
          blocks: Array.isArray(raw.blocks) ? raw.blocks : draftsFrom(undefined, raw.text),
          audience: AUDIENCES.includes(raw.audience) ? raw.audience : 'members',
          audience_ids: Array.isArray(raw.audience_ids) ? raw.audience_ids.filter((id: unknown) => typeof id === 'number') : [],
          attachments: Array.isArray(raw.attachments) ? raw.attachments : [],
        } as Draft)
      : null;
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

const defaultExpires = () => toLocalInput(new Date(Date.now() + DAY).toISOString());

/**
 * Inhalt aus Blöcken; Textblöcke mit „Signatur einfügen“ (wie bei E-Mails aus der Verwaltung), der Entwurf bleibt beim
 * Sprung zur Signatur erhalten.
 */
function ContentEditor(props: { value: BlockDraft[]; onChange: (blocks: BlockDraft[]) => void; id: string; draft?: Draft }) {
  const { draft } = props;
  return (
    <BlocksEditor
      value={props.value}
      onChange={props.onChange}
      idPrefix={props.id}
      allLabel={t('Für alle, die die Nachricht sehen')}
      renderText={(p) => (
        <ComposeEditor {...p} label={t('Text der Nachricht')} returnTo="brett" saveDraft={draft ? () => saveDraft(draft) : undefined} />
      )}
    />
  );
}

const emptyDraft = (): Draft => ({
  title: '',
  blocks: [newBlock('text')],
  expires: defaultExpires(),
  audience: 'members',
  audience_ids: [],
  push: true,
  mail: true,
  attachments: [],
});

/** Inhalt einer Nachricht: Blöcke (ältere Server: text als ein Textblock). */
function MessageContent({ item, manager }: { item: { text: string; blocks?: BoardMessage['blocks'] }; manager?: boolean }) {
  return item.blocks ? (
    <BlocksView blocks={item.blocks} manager={manager} textClassName="board-text" />
  ) : (
    <RichTextView html={item.text} className="board-text" />
  );
}

/** „Sichtbar bis“: Ortszeit des Geräts, nicht in der Vergangenheit, ohne Obergrenze. */
function ExpiresField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <label className="field">
      <span>{t('Sichtbar bis')}</span>
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

/** Kompanien und Personen zur Auswahl (einmal je Seitenaufruf geladen; nur mit post_board). */
let audiencesRequest: Promise<BoardAudiences> | null = null;
function useAudiences(): BoardAudiences | null {
  const [data, setData] = useState<BoardAudiences | null>(null);
  useEffect(() => {
    audiencesRequest ??= api.boardAudiences().catch((err: unknown) => {
      audiencesRequest = null;
      throw err;
    });
    audiencesRequest.then(setData, () => setData({ companies: [], people: [] }));
  }, []);
  return data;
}

/** Ab so vielen Einträgen gibt es ein Suchfeld über der Auswahl. */
const SEARCH_FROM = 8;

/** Häkchen für Kompanien bzw. Personen (mindestens eine). */
function TargetPicker(props: {
  kind: 'company' | 'person';
  items: { id: number; name: string }[];
  ids: number[];
  onChange: (ids: number[]) => void;
}) {
  const { kind, items, ids, onChange } = props;
  const [search, setSearch] = useState('');
  const term = search.trim().toLocaleLowerCase('de');
  const shown = term === '' ? items : items.filter((i) => ids.includes(i.id) || i.name.toLocaleLowerCase('de').includes(term));
  const label = kind === 'company' ? brand.labels.sections.companies : t('Personen');
  return (
    <fieldset className={`field board-targets board-targets-${kind}`}>
      <legend>
        {t('{label} auswählen', { label })}
        {ids.length > 0 ? ` ${t('({count} ausgewählt)', { count: ids.length })}` : ''}
      </legend>
      {items.length >= SEARCH_FROM && (
        <input
          type="search"
          className="board-targets-search"
          placeholder={t('Suchen …')}
          aria-label={t('{label} suchen', { label })}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      )}
      <div className="board-targets-list">
        {shown.map((i) => (
          <label key={i.id} className="toggle">
            <input
              type="checkbox"
              checked={ids.includes(i.id)}
              onChange={(e) => onChange(e.target.checked ? [...ids, i.id] : ids.filter((id) => id !== i.id))}
            />
            <span>{i.name}</span>
          </label>
        ))}
        {shown.length === 0 && <p className="muted small">{t('Keine Treffer.')}</p>}
      </div>
    </fieldset>
  );
}

/**
 * Wer die Nachricht sieht: intern (wer das Brett liest), öffentlich (alle, auch ohne Anmeldung), Kompanie (wer das Brett
 * liest und einer der ausgewählten Kompanien angehört) oder einzeln (nur die ausgewählten Personen). „Kompanie“ gibt es
 * nur, wenn der Bereich Kompanien an ist und Kompanien angelegt sind.
 */
function AudienceField(props: { value: BoardAudience; ids: number[]; onChange: (audience: BoardAudience, ids: number[]) => void }) {
  const { value, ids, onChange } = props;
  const options = useAudiences();
  const companies = options?.companies ?? [];
  const people = options?.people ?? [];
  return (
    <>
      <label className="field">
        <span>{t('Sichtbar für')}</span>
        <select
          className="board-audience"
          value={value}
          onChange={(e) => {
            const next = AUDIENCES.find((a) => a === e.target.value) ?? 'members';
            onChange(next, next === value ? ids : []);
          }}
        >
          <option value="members">{t('Intern: Mitglieder und alle mit dem internen Kalender')}</option>
          <option value="public">{t('Öffentlich: alle, auch ohne Anmeldung')}</option>
          {(companies.length > 0 || value === 'company') && (
            <option value="company">
              {t('Kompanie: nur Mitglieder ausgewählter {companies}', { companies: brand.labels.sections.companies })}
            </option>
          )}
          {(people.length > 0 || value === 'person') && <option value="person">{t('Einzeln: nur ausgewählte Personen')}</option>}
        </select>
      </label>
      {value === 'company' && <TargetPicker kind="company" items={companies} ids={ids} onChange={(next) => onChange(value, next)} />}
      {value === 'person' && <TargetPicker kind="person" items={people} ids={ids} onChange={(next) => onChange(value, next)} />}
    </>
  );
}

/** Kennzeichen an einer Nachricht: öffentlich, für welche Kompanien, einzeln (interne tragen keins). */
export function AudienceBadge({ item }: { item: { audience?: BoardAudience; audience_names?: string[] } }) {
  const names = item.audience_names ?? [];
  if (item.audience === 'public') return <span className="badge badge-open board-public">{t('öffentlich')}</span>;
  if (item.audience === 'company') {
    return (
      <span className="badge badge-open board-audience-company">
        {t('nur {names}', { names: names.length > 0 ? names.join(', ') : brand.labels.sections.companies })}
      </span>
    );
  }
  if (item.audience === 'person') {
    return (
      <span className="badge badge-open board-audience-person">
        {names.length > 0 ? t('nur für {names}', { names: names.join(', ') }) : t('nur für dich')}
      </span>
    );
  }
  return null;
}

/** „Sichtbar für“ in der Bestätigung: mit den Namen der Auswahl. */
function audienceText(audience: BoardAudience, ids: number[], options: BoardAudiences | null): string {
  if (audience === 'public') return t('alle, auch ohne Anmeldung (öffentlich)');
  if (audience === 'members') return t('intern');
  const items: { id: number; name: string }[] = (audience === 'company' ? options?.companies : options?.people) ?? [];
  const names = ids.map((id) => items.find((i) => i.id === id)?.name ?? `#${id}`).join(', ');
  return audience === 'company'
    ? t('nur {companies} {names}', { companies: brand.labels.sections.companies, names })
    : t('nur {names}', { names });
}

/** Wer beim Veröffentlichen eine Benachrichtigung bekommt. */
function pushAudienceText(audience: BoardAudience): string {
  if (audience === 'company') return t('die Mitglieder der ausgewählten Kompanien, die das Brett lesen');
  if (audience === 'person') return t('die ausgewählten Personen');
  return audience === 'public'
    ? t(
        'alle Mitglieder, alle mit dem internen Kalender (z. B. Anwärter) und alle anderen Geräte, die öffentliche Nachrichten vom Brett eingeschaltet haben (auch ohne Anmeldung)',
      )
    : t('alle Mitglieder und alle mit dem internen Kalender (z. B. Anwärter)');
}

/** Verlauf: members, public oder „company: Namen“ bzw. „person: Namen“ (BoardService::audienceText). */
function audienceRevisionText(value: string): string {
  if (value === 'public') return t('öffentlich');
  if (value === 'members') return t('intern');
  const [kind, ...rest] = value.split(':');
  const names = rest.join(':').trim();
  if (kind === 'company') return t('Kompanie: {names}', { names: names || '–' });
  if (kind === 'person') return t('einzeln: {names}', { names: names || '–' });
  return value;
}

const capitalize = (text: string): string => text.charAt(0).toUpperCase() + text.slice(1);

function mailText(mail: BoardMailSummary): string {
  const parts = [t('{sent} von {count} gesendet', { sent: mail.sent, count: mail.recipients })];
  if (mail.pending > 0) parts.push(t('{count} ausstehend', { count: mail.pending }));
  if (mail.failed > 0) parts.push(t('{count} fehlgeschlagen', { count: mail.failed }));
  if (mail.cancelled > 0) parts.push(t('{count} nicht mehr gesendet', { count: mail.cancelled }));
  return t('E-Mail: {status}', { status: parts.join(', ') });
}

/** Neue Nachricht: Titel, formatierter Text, Ende, Benachrichtigung und Mail; Bestätigung vor dem Senden. */
function NewMessage({ onToast }: { onToast: (msg: string) => void }) {
  const [draft, setDraft] = useState<Draft>(() => {
    const saved = readDraft();
    return saved ? { ...saved, expires: saved.expires || defaultExpires() } : emptyDraft();
  });
  const options = useAudiences();
  const [editorKey, setEditorKey] = useState(0);
  const [mailInfo, setMailInfo] = useState<BoardMailInfo | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Zahl der Empfänger der Mail passt zur Auswahl „Sichtbar für“.
  const idsKey = draft.audience_ids.join();
  useEffect(() => {
    let current = true;
    api.boardMailInfo(draft.audience, draft.audience_ids).then(
      (info) => current && setMailInfo(info),
      () => current && setMailInfo(null),
    );
    return () => {
      current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft.audience, idsKey]);

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
    if (draft.title.trim() === '') return setError(t('Bitte einen Titel eingeben.'));
    if (!hasContent(draft.blocks)) return setError(t('Bitte einen Text eingeben oder einen Block einfügen.'));
    const expires = fromLocalInput(draft.expires);
    if (!expires || Date.parse(expires) <= Date.now()) return setError(t('„Sichtbar bis“ muss in der Zukunft liegen.'));
    if (draft.audience === 'company' && draft.audience_ids.length === 0) return setError(t('Bitte mindestens eine Kompanie auswählen.'));
    if (draft.audience === 'person' && draft.audience_ids.length === 0) return setError(t('Bitte mindestens eine Person auswählen.'));
    const lines = [
      t('Nachricht „{title}“ jetzt veröffentlichen?', { title: draft.title.trim() }),
      '',
      t('Sichtbar für: {audience}', { audience: audienceText(draft.audience, draft.audience_ids, options) }),
      t('Sichtbar bis: {time}', { time: formatUntil(expires) }),
      draft.push ? t('Benachrichtigung: an {audience}', { audience: pushAudienceText(draft.audience) }) : t('Benachrichtigung: keine'),
      mailOn ? t('E-Mail: an {count} Empfänger', { count: mailInfo?.recipients ?? 0 }) : t('E-Mail: keine'),
    ];
    if (draft.attachments.length > 0) lines.push(t('Anhänge: {names}', { names: draft.attachments.map((a) => a.name).join(', ') }));
    if (!(await confirmAsk(lines.join('\n')))) return;
    setBusy(true);
    try {
      await api.postBoard({
        title: draft.title,
        blocks: blocksInput(draft.blocks),
        expires_at: expires,
        audience: draft.audience,
        ...(targeted(draft.audience) ? { audience_ids: draft.audience_ids } : {}),
        push: draft.push,
        mail: mailOn,
        attachments: draft.attachments.map((a) => a.uid),
      });
      saveDraft(null);
      setDraft(emptyDraft());
      setEditorKey((k) => k + 1);
      onToast(t('Nachricht veröffentlicht'));
      await refreshBoard();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card board-form" onSubmit={submit}>
      <h3>{t('Neue Nachricht')}</h3>
      <label className="field">
        <span>{t('Titel')}</span>
        <input type="text" className="board-title-input" value={draft.title} onChange={(e) => update({ title: e.target.value })} required />
      </label>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <ContentEditor key={editorKey} value={draft.blocks} onChange={(blocks) => update({ blocks })} id="board-new-text" draft={draft} />
      </div>
      <AttachmentsField
        id="board-new-attachments"
        items={draft.attachments}
        onAdd={(a) => setDraft((d) => withSaved({ ...d, attachments: [...d.attachments, a] }))}
        onRemove={(uid) => setDraft((d) => withSaved({ ...d, attachments: d.attachments.filter((a) => a.uid !== uid) }))}
      />
      {/* Wer, wie lange, auf welchem Weg (Issue #347): eigener Abschnitt unter Text und Anhängen */}
      <section className="form-section board-options" aria-labelledby="board-new-options">
        <h4 id="board-new-options">{t('Sichtbarkeit und Versand')}</h4>
        <AudienceField
          value={draft.audience}
          ids={draft.audience_ids}
          onChange={(audience, audience_ids) => update({ audience, audience_ids })}
        />
        <ExpiresField value={draft.expires} onChange={(expires) => update({ expires })} />
        <label className="toggle">
          <input type="checkbox" className="board-push" checked={draft.push} onChange={(e) => update({ push: e.target.checked })} />
          <span>{t('Benachrichtigung senden')}</span>
        </label>
        <label className="toggle">
          <input
            type="checkbox"
            className="board-mail"
            checked={mailOn}
            disabled={!mailInfo?.available}
            onChange={(e) => update({ mail: e.target.checked })}
          />
          <span>
            {mailInfo?.available ? t('E-Mail senden (an {count} Empfänger)', { count: mailInfo.recipients }) : t('E-Mail senden')}
          </span>
        </label>
        {mailInfo && !mailInfo.available && <p className="muted small">{mailInfo.reason}</p>}
        <p className="muted small">
          {draft.audience === 'public' &&
            `${t('Öffentlich: Alle sehen die Nachricht samt Anhängen und deinem Namen, auch ohne Anmeldung. Die E-Mail geht trotzdem nur an Mitglieder und alle mit dem internen Kalender.')} `}
          {targeted(draft.audience) &&
            `${t('Nur die Auswahl sieht die Nachricht (und alle, die ans Brett schreiben dürfen); Benachrichtigung und E-Mail gehen nur an sie.')} `}
          {draft.push
            ? t('{who} bekommen sofort eine Benachrichtigung mit dem Titel.', { who: capitalize(pushAudienceText(draft.audience)) })
            : t('Ohne Benachrichtigung: Die Nachricht erscheint nur in der App.')}{' '}
          {t(
            'Der Titel steht oben auf jeder Seite der App, bis zum gewählten Ende (standardmäßig 24 Stunden); danach verschwindet die Nachricht von selbst.',
          )}
        </p>
      </section>
      {error && <p className="form-error small">{error}</p>}
      <button className="btn btn-block" type="submit" disabled={busy || draft.title.trim() === '' || !hasContent(draft.blocks)}>
        {busy ? t('Sendet …') : t('Veröffentlichen')}
      </button>
    </form>
  );
}

const ACTIONS: Record<BoardRevision['action'], string> = {
  created: t('erstellt'),
  title: t('Titel geändert'),
  content: t('Inhalt geändert'),
  expires: t('Enddatum geändert'),
  audience: t('Sichtbarkeit geändert'),
  attachments: t('Anhänge geändert'),
  deleted: t('gelöscht'),
  mailed: t('per E-Mail versendet'),
};

/** Verlauf einer Nachricht (nur view_board_history): wer wann was geändert hat, bei Inhalten vorher/nachher. */
function History({ id }: { id: number }) {
  const [data, setData] = useState<BoardHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.boardHistory(id).then(setData, (err: Error) => setError(err.message));
  }, [id]);
  if (error) return <p className="form-error small">{error}</p>;
  if (!data) return <Loading shape="detail" />;
  const value = (r: BoardRevision, v: unknown) => {
    if (v === null || v === undefined) return null;
    if (r.action === 'expires') return <span>{formatUntil(String(v))}</span>;
    if (r.action === 'title') return <span>{String(v)}</span>;
    if (r.action === 'audience') return <span>{audienceRevisionText(String(v))}</span>;
    if (r.action === 'content') return <RichTextView html={String(v)} className="board-rev-content" />;
    if (r.action === 'attachments') return <span>{Array.isArray(v) && v.length > 0 ? v.join(', ') : t('keine')}</span>;
    return null;
  };
  return (
    <div className="board-history">
      <ol className="board-revisions">
        {data.revisions.map((r) => (
          <li key={r.id} className="board-revision">
            <span className="board-meta">
              {formatShortTime(r.at)} · {r.user_name ?? t('Unbekannt')}: {ACTIONS[r.action]}
              {r.action === 'mailed' &&
                typeof r.after === 'object' &&
                r.after !== null &&
                ` ${t('(an {count} Empfänger)', { count: (r.after as { recipients?: number }).recipients ?? 0 })}`}
            </span>
            {(r.action === 'title' ||
              r.action === 'content' ||
              r.action === 'expires' ||
              r.action === 'audience' ||
              r.action === 'attachments') && (
              <div className="board-diff">
                <div className="board-before">
                  <span className="muted small">{t('vorher')}</span>
                  {value(r, r.before)}
                </div>
                <div className="board-after">
                  <span className="muted small">{t('nachher')}</span>
                  {value(r, r.after)}
                </div>
              </div>
            )}
          </li>
        ))}
      </ol>
      {data.mail_log.length > 0 && (
        <details className="board-mail-log">
          <summary>{t('Mailversand je Empfänger')}</summary>
          <ul>
            {data.mail_log.map((l, i) => (
              <li key={i} className={`board-mail-${l.status}`}>
                {l.name ?? l.email}:{' '}
                {
                  { pending: t('ausstehend'), sent: t('gesendet'), failed: t('fehlgeschlagen'), cancelled: t('nicht mehr gesendet') }[
                    l.status
                  ]
                }
                {l.error && <span className="muted small"> – {l.error}</span>}
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  );
}

function Entry({ item, focused, onToast }: { item: BoardMessage; focused: boolean; onToast: (msg: string) => void }) {
  const [edit, setEdit] = useState<{
    title: string;
    blocks: BlockDraft[];
    expires: string;
    audience: BoardAudience;
    audience_ids: number[];
    attachments: Attachment[];
  } | null>(null);
  const [history, setHistory] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const canPost = canPostBoard();
  const seeAll = boardSeesAll();

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
    const changes: {
      title?: string;
      blocks?: ReturnType<typeof blocksInput>;
      expires_at?: string | null;
      audience?: BoardAudience;
      audience_ids?: number[];
      attachments?: string[];
    } = {};
    if (edit.title !== item.title) changes.title = edit.title;
    if (targeted(edit.audience) && edit.audience_ids.length === 0) {
      return setError(
        edit.audience === 'company' ? t('Bitte mindestens eine Kompanie auswählen.') : t('Bitte mindestens eine Person auswählen.'),
      );
    }
    if (
      edit.audience !== (item.audience ?? 'members') ||
      (targeted(edit.audience) && !sameIds(edit.audience_ids, item.audience_ids ?? []))
    ) {
      changes.audience = edit.audience;
      if (targeted(edit.audience)) changes.audience_ids = edit.audience_ids;
    }
    const blocks = blocksInput(edit.blocks);
    if (JSON.stringify(blocks) !== JSON.stringify(blocksInput(draftsFrom(item.blocks, item.text)))) changes.blocks = blocks;
    const uids = edit.attachments.map((a) => a.uid);
    if (uids.join() !== (item.attachments ?? []).map((a) => a.uid).join()) changes.attachments = uids;
    const expires = fromLocalInput(edit.expires);
    if (expires && toLocalInput(expires) !== toLocalInput(item.expires_at)) changes.expires_at = expires;
    if (Object.keys(changes).length === 0) return setEdit(null);
    void run(() => api.updateBoard(item.id, changes), t('Nachricht geändert'));
  }

  return (
    <article className={`card board-card ${focused ? 'focused' : ''}`} data-focus-id={item.id}>
      <BoardMeta item={item} /> {(seeAll || boardVisible()) && <AudienceBadge item={item} />}
      {edit === null ? (
        <>
          <h3 className="board-title">{item.title}</h3>
          <MessageContent item={item} manager={seeAll} />
          <AttachmentList items={item.attachments} />
        </>
      ) : (
        <div className="form board-edit">
          <label className="field">
            <span>{t('Titel')}</span>
            <input
              type="text"
              className="board-title-input"
              value={edit.title}
              onChange={(e) => setEdit({ ...edit, title: e.target.value })}
              required
            />
          </label>
          <div className="field">
            <span>{t('Inhalt')}</span>
            <ContentEditor
              value={edit.blocks}
              onChange={(blocks) => setEdit((d) => (d ? { ...d, blocks } : d))}
              id={`board-edit-${item.id}`}
            />
          </div>
          <AttachmentsField
            id={`board-edit-attachments-${item.id}`}
            items={edit.attachments}
            onAdd={(a) => setEdit((d) => (d ? { ...d, attachments: [...d.attachments, a] } : d))}
            onRemove={(uid) => setEdit((d) => (d ? { ...d, attachments: d.attachments.filter((a) => a.uid !== uid) } : d))}
          />
          <AudienceField
            value={edit.audience}
            ids={edit.audience_ids}
            onChange={(audience, audience_ids) => setEdit({ ...edit, audience, audience_ids })}
          />
          <ExpiresField value={edit.expires} onChange={(expires) => setEdit({ ...edit, expires })} />
        </div>
      )}
      <p className="muted small board-until">{t('Sichtbar bis {time}', { time: formatUntil(item.expires_at) })}</p>
      {seeAll && item.last_edit && (
        <p className="muted small board-last-edit">
          {t('Zuletzt geändert von {name}', { name: item.last_edit.name ?? t('Unbekannt') })} · {formatShortTime(item.last_edit.at)}
        </p>
      )}
      {seeAll && item.mail && <p className="muted small board-mail-status">{mailText(item.mail)}</p>}
      {error && <p className="form-error small">{error}</p>}
      {seeAll &&
        (edit === null ? (
          <div className="icon-row board-item-actions">
            {canPost && (
              <>
                <IconButton
                  icon="edit"
                  label={t('Bearbeiten')}
                  disabled={busy}
                  onClick={() =>
                    setEdit({
                      title: item.title,
                      blocks: draftsFrom(item.blocks, item.text),
                      expires: toLocalInput(item.expires_at),
                      audience: item.audience ?? 'members',
                      audience_ids: item.audience_ids ?? [],
                      attachments: item.attachments ?? [],
                    })
                  }
                />
                <IconButton
                  icon="delete"
                  label={t('Löschen')}
                  variant="danger"
                  disabled={busy}
                  onClick={async () =>
                    (await confirmAsk(t('Nachricht „{title}“ jetzt löschen?', { title: item.title }))) &&
                    run(() => api.deleteBoard(item.id), t('Nachricht gelöscht'))
                  }
                />
              </>
            )}
            <IconButton
              icon="history"
              label={history ? t('Verlauf ausblenden') : t('Verlauf')}
              className="board-history-toggle"
              expanded={history}
              onClick={() => setHistory(!history)}
            />
          </div>
        ) : (
          <>
            <div className="button-row">
              <button className="btn" disabled={busy || edit.title.trim() === '' || !hasContent(edit.blocks)} onClick={save}>
                {t('Speichern')}
              </button>
              <button className="btn btn-secondary" disabled={busy} onClick={() => setEdit(null)}>
                {t('Abbrechen')}
              </button>
            </div>
          </>
        ))}
      {seeAll && history && edit === null && <History id={item.id} />}
    </article>
  );
}

/** Bereich „Verlauf“ (nur view_board_history): abgelaufene und gelöschte Nachrichten der letzten 30 Tage samt Verlauf. */
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
        {open ? t('Verlauf ausblenden') : t('Verlauf: abgelaufene und gelöschte Nachrichten')}
      </button>
      {open &&
        (error ? (
          <p className="form-error small">{error}</p>
        ) : data === null ? (
          <Loading shape="text" />
        ) : (
          <>
            <p className="muted small">
              {t('Abgelaufene und gelöschte Nachrichten der letzten {count} Tage; danach werden sie endgültig gelöscht.', {
                count: data.keep_days,
              })}
            </p>
            {data.items.length === 0 ? (
              <p className="muted small">{t('Keine.')}</p>
            ) : (
              data.items.map((m) => (
                <article key={m.id} className="card board-card board-ended-card">
                  <AudienceBadge item={m} />
                  <span className="board-meta">
                    {m.author_name ?? t('Unbekannt')} · {formatShortTime(m.created_at)} ·{' '}
                    {m.deleted_at
                      ? `${t('gelöscht von {name}', { name: m.deleted_by_name ?? t('Unbekannt') })} · ${formatShortTime(m.deleted_at)}`
                      : `${t('abgelaufen')} · ${formatShortTime(m.expires_at)}`}
                  </span>
                  <h3 className="board-title">{m.title}</h3>
                  <MessageContent item={m} manager />
                  <AttachmentList items={m.attachments} />
                  <button className="btn btn-link" aria-expanded={shown === m.id} onClick={() => setShown(shown === m.id ? null : m.id)}>
                    {shown === m.id ? t('Verlauf ausblenden') : t('Verlauf')}
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
        <span className={`badge ${poll.has_voted ? 'badge-paid' : 'badge-open'}`}>{poll.has_voted ? t('beantwortet') : t('offen')}</span>
        <button className="btn btn-secondary" onClick={() => navigate(`umfragen/${poll.id}`)}>
          {poll.has_voted ? t('Antwort ansehen') : t('Teilnehmen')}
        </button>
      </div>
    </article>
  );
}

/**
 * Seite #/brett: alle gültigen Nachrichten (auch ausgeblendete) mit Titel und formatiertem Inhalt; mit view_board_history
 * auch die für andere und den Verlauf, mit post_board schreiben, ändern, löschen. Darunter für Mitglieder alle laufenden Umfragen mit Status und der Weg zu den
 * beendeten (Issue #4).
 */
/** `focus`: Kennung einer Nachricht aus der Adresse brett/<id> (Ziel einer Benachrichtigung, Issue #190). */
export default function Brett({ focus, onToast }: { focus?: string; onToast: (msg: string) => void }) {
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
  // Frisch geladen, damit eine gerade gemeldete Nachricht schon dabei ist.
  const flash = useFocusEntry(focus, !loading && messages.some((m) => String(m.id) === focus));

  return (
    <div className="board-page">
      {canPostBoard() && <NewMessage onToast={onToast} />}
      {!boardLoaded() && loading ? (
        <Loading />
      ) : !boardLoaded() && error ? (
        <ErrorBox message={error} onRetry={load} />
      ) : messages.length === 0 ? (
        <Empty>
          {boardVisible() || boardSeesAll()
            ? t('Zurzeit gibt es keine Nachrichten.')
            : t('Zurzeit gibt es keine öffentlichen Nachrichten.')}
        </Empty>
      ) : (
        <>
          <h2 className="board-page-title">{t('Aktuelle Nachrichten')}</h2>
          {messages.map((item) => (
            <Entry key={`${item.id}-${item.updated_at}`} item={item} focused={flash === String(item.id)} onToast={onToast} />
          ))}
        </>
      )}
      {!config?.user && (config?.auth.mode === 'form' || !!config?.auth.loginUrl) && (
        <p className="muted small board-login-hint">{t('Interne Nachrichten siehst du nach der Anmeldung.')}</p>
      )}
      {boardSeesAll() && <Ended />}
      {(pollsVisible() || canManagePolls()) && boardLoaded() && (
        <section className="board-polls stack">
          <h2 className="board-page-title">{t('Laufende {polls}', { polls: brand.labels.sections.polls })}</h2>
          {polls.length === 0 ? (
            <p className="muted small">{t('Zurzeit läuft keine Umfrage.')}</p>
          ) : (
            polls.map((p) => <PollEntry key={p.id} poll={p} />)
          )}
          <div className="button-row">
            <button className="btn btn-link board-polls-ended" onClick={() => navigate('umfragen')}>
              {t('Beendete {polls}', { polls: brand.labels.sections.polls })}
            </button>
            {canManagePolls() && (
              <button className="btn btn-secondary board-polls-manage" onClick={() => navigate('umfragen/verwalten')}>
                <Caps text={t('{polls} verwalten', { polls: brand.labels.sections.polls })} />
              </button>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
