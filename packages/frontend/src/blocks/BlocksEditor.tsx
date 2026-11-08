import { Suspense, useEffect, useState, type ReactNode } from 'react';
import { api, type BlockAudience, type PublicEvent } from '../api';
import { Loading, lazyView } from '../ui';
import {
  BLOCK_KINDS,
  moveBlock,
  newBlock,
  type BlockDraft,
  type BlockKind,
  type ButtonDraft,
  type CountdownDraft,
  type EventsDraft,
} from './blockDrafts';
import Countdown from './Countdown';
import { previewRange } from './countdown';

const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/** Grenzen wie auf dem Server (CountdownBlock::TITLE_MAX, TEXT_MAX, ButtonBlock::LABEL_MAX, EventsBlock::MAX). */
const TITLE_MAX = 100;
const TEXT_MAX = 200;
const LABEL_MAX = 60;
const EVENTS_MAX = 20;

function ButtonFields({ block, onChange }: { block: ButtonDraft; onChange: (b: ButtonDraft) => void }) {
  const set = (patch: Partial<ButtonDraft>) => onChange({ ...block, ...patch });
  return (
    <div className="block-button-fields">
      <label className="field">
        <span>Beschriftung</span>
        <input
          type="text"
          maxLength={LABEL_MAX}
          value={block.label}
          placeholder="z. B. Jetzt anmelden"
          onChange={(e) => set({ label: e.target.value })}
          required
        />
      </label>
      <label className="field">
        <span>Ziel</span>
        <input type="text" inputMode="url" value={block.url} onChange={(e) => set({ url: e.target.value })} required />
        <span className="muted small">
          Eine Adresse mit https://, eine E-Mail-Adresse mit mailto:, eine Telefonnummer mit tel: oder eine Seite der App wie #/termine.
        </span>
      </label>
      <label className="field">
        <span>Aussehen</span>
        <select value={block.style} onChange={(e) => set({ style: e.target.value as ButtonDraft['style'] })}>
          <option value="primary">Hervorgehoben</option>
          <option value="secondary">Zurückhaltend</option>
        </select>
      </label>
    </div>
  );
}

function EventsFields({ block, onChange }: { block: EventsDraft; onChange: (b: EventsDraft) => void }) {
  const set = (patch: Partial<EventsDraft>) => onChange({ ...block, ...patch });
  return (
    <div className="block-events-fields">
      <label className="field">
        <span>Überschrift (optional)</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder="z. B. Unsere nächsten Termine"
          onChange={(e) => set({ title: e.target.value })}
        />
      </label>
      <label className="field">
        <span>Wie viele Termine</span>
        <input
          type="number"
          min={1}
          max={EVENTS_MAX}
          value={block.count}
          onChange={(e) => set({ count: Math.max(1, Math.min(EVENTS_MAX, Number(e.target.value) || 1)) })}
        />
        <span className="muted small">Die App zeigt immer die nächsten Termine ab heute aus den Terminen.</span>
      </label>
    </div>
  );
}

/** Auswahl „+ Block einfügen“ an einer Stelle der Liste. */
function InsertBlock({ onInsert, label = '+ Block einfügen' }: { onInsert: (type: BlockKind) => void; label?: string }) {
  return (
    <div className="block-insert">
      <select
        aria-label={label}
        value=""
        onChange={(e) => {
          if (e.target.value) onInsert(e.target.value as BlockKind);
        }}
      >
        <option value="">{label}</option>
        {BLOCK_KINDS.map((k) => (
          <option key={k.type} value={k.type}>
            {k.label}: {k.hint}
          </option>
        ))}
      </select>
    </div>
  );
}

let events: Promise<PublicEvent[]> | null = null;
/** Termine zur Auswahl, einmal je Sitzung geladen (ohne Recht view_events: keine). */
function upcomingEvents(): Promise<PublicEvent[]> {
  events ??= api.events().catch(() => {
    events = null;
    return [];
  });
  return events;
}

function CountdownFields({
  block,
  onChange,
  idPrefix,
}: {
  block: CountdownDraft;
  onChange: (b: CountdownDraft) => void;
  idPrefix: string;
}) {
  const set = (patch: Partial<CountdownDraft>) => onChange({ ...block, ...patch });
  const [list, setList] = useState<PublicEvent[] | null>(null);
  useEffect(() => {
    if (block.source !== 'event' || list) return;
    let alive = true;
    void upcomingEvents().then((l) => alive && setList(l));
    return () => {
      alive = false;
    };
  }, [block.source, list]);
  const event = block.source === 'event' ? list?.find((e) => e.id === block.event_id) : undefined;
  const range =
    block.source === 'event'
      ? {
          starts_at: event ? new Date(event.start).toISOString() : null,
          ends_at: event && event.end && event.end !== event.start ? new Date(event.end).toISOString() : null,
        }
      : previewRange(block);
  const eventTitle = event?.title ?? block.event_title ?? null;
  return (
    <div className="block-countdown-fields">
      <label className="field">
        <span>Überschrift (optional)</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder="z. B. Bis zum Fest"
          onChange={(e) => set({ title: e.target.value })}
        />
      </label>
      <fieldset className="block-source">
        <legend>Zählt bis</legend>
        <label className="toggle">
          <input type="radio" name={`${idPrefix}-source`} checked={block.source === 'date'} onChange={() => set({ source: 'date' })} />{' '}
          Datum
        </label>
        <label className="toggle">
          <input type="radio" name={`${idPrefix}-source`} checked={block.source === 'event'} onChange={() => set({ source: 'event' })} />{' '}
          Termin
        </label>
      </fieldset>
      {block.source === 'event' ? (
        <label className="field">
          <span>Termin</span>
          {list === null ? (
            <Loading shape="text" />
          ) : (
            <select
              value={block.event_id ?? ''}
              onChange={(e) => set({ event_id: e.target.value ? Number(e.target.value) : undefined })}
              required
            >
              <option value="">Termin wählen</option>
              {block.event_id && !event && <option value={block.event_id}>{block.event_title ?? `Termin ${block.event_id}`}</option>}
              {list.map((ev) => (
                <option key={ev.id} value={ev.id}>
                  {ev.title} ({ev.start_date.split('-').reverse().join('.')})
                </option>
              ))}
            </select>
          )}
          <span className="muted small">Wird der Termin verschoben, zählt der Countdown mit. Beginn und Ende kommen aus dem Termin.</span>
        </label>
      ) : (
        <>
          <div className="field-row">
            <label className="field">
              <span>Datum</span>
              <input type="date" value={block.date ?? ''} onChange={(e) => set({ date: e.target.value })} required />
            </label>
            <label className="field">
              <span>Uhrzeit (optional)</span>
              <input type="time" value={block.time ?? ''} onChange={(e) => set({ time: e.target.value })} />
            </label>
          </div>
          <div className="field-row">
            <label className="field">
              <span>Ende am (optional)</span>
              <input type="date" value={block.end_date ?? ''} onChange={(e) => set({ end_date: e.target.value })} />
            </label>
            <label className="field">
              <span>Ende um (optional)</span>
              <input type="time" value={block.end_time ?? ''} onChange={(e) => set({ end_time: e.target.value })} />
            </label>
          </div>
        </>
      )}
      <label className="field">
        <span>Text, solange es läuft (optional)</span>
        <input
          type="text"
          maxLength={TEXT_MAX}
          value={block.running_text}
          placeholder="z. B. Das Fest läuft!"
          onChange={(e) => set({ running_text: e.target.value })}
        />
      </label>
      <label className="field">
        <span>Danach</span>
        <select value={block.after} onChange={(e) => set({ after: e.target.value as CountdownDraft['after'] })}>
          <option value="text">Text zeigen</option>
          <option value="hide">Block ausblenden</option>
        </select>
      </label>
      {block.after === 'text' && (
        <label className="field">
          <span>Text danach (optional)</span>
          <input
            type="text"
            maxLength={TEXT_MAX}
            value={block.after_text}
            placeholder="z. B. Danke fürs Mitfeiern!"
            onChange={(e) => set({ after_text: e.target.value })}
          />
        </label>
      )}
      <div className="block-preview">
        <span className="muted small">Vorschau</span>
        <Countdown block={{ ...block, event_title: eventTitle, ...range }} />
      </div>
    </div>
  );
}

/**
 * Inhalt aus Blöcken bearbeiten: Blöcke untereinander, je mit „Für wen?“, Verschieben und Entfernen; dazwischen und am
 * Ende „+ Block einfügen“.
 */
export default function BlocksEditor({
  value,
  onChange,
  idPrefix,
  allLabel = 'Für alle, die die Seite sehen',
  renderText,
}: {
  value: BlockDraft[];
  onChange: (blocks: BlockDraft[]) => void;
  idPrefix: string;
  /** Bezeichnung für audience all (z. B. „Für alle, die die Nachricht sehen“) */
  allLabel?: string;
  /** eigener Editor für Textblöcke (Schwarzes Brett: mit „Signatur einfügen“) */
  renderText?: (props: { value: string; onChange: (html: string) => void; id: string }) => ReactNode;
}) {
  const insert = (at: number, type: BlockKind) => onChange([...value.slice(0, at), newBlock(type), ...value.slice(at)]);
  const update = (i: number, b: BlockDraft) => onChange(value.map((x, j) => (j === i ? b : x)));
  return (
    <div className="blocks-editor">
      {value.length === 0 && <p className="muted small">Noch keine Blöcke.</p>}
      {value.map((b, i) => {
        const label = BLOCK_KINDS.find((k) => k.type === b.type)?.label ?? b.type;
        const id = `${idPrefix}-${b.id}`;
        return (
          <div key={b.id} className="block-edit card">
            <div className="block-edit-head">
              <strong>{label}</strong>
              <select
                aria-label={`Zielgruppe des Blocks (${label})`}
                value={b.audience}
                onChange={(e) => update(i, { ...b, audience: e.target.value as BlockAudience })}
              >
                <option value="all">{allLabel}</option>
                <option value="members">Nur Mitglieder</option>
              </select>
              <span className="block-edit-actions">
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  disabled={i === 0}
                  onClick={() => onChange(moveBlock(value, i, -1))}
                  aria-label={`${label} nach oben`}
                >
                  ↑
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  disabled={i === value.length - 1}
                  onClick={() => onChange(moveBlock(value, i, 1))}
                  aria-label={`${label} nach unten`}
                >
                  ↓
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-danger"
                  onClick={() => window.confirm(`Block „${label}“ entfernen?`) && onChange(value.filter((_, j) => j !== i))}
                >
                  Entfernen
                </button>
              </span>
            </div>
            {b.type === 'text' && renderText ? (
              renderText({ value: b.html, onChange: (html) => update(i, { ...b, html }), id })
            ) : b.type === 'text' ? (
              <Suspense fallback={<Loading shape="text" chunk />}>
                <RichTextEditor value={b.html} onChange={(html) => update(i, { ...b, html })} label="Text" id={id} />
              </Suspense>
            ) : b.type === 'button' ? (
              <ButtonFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'events' ? (
              <EventsFields block={b} onChange={(c) => update(i, c)} />
            ) : (
              <CountdownFields block={b} onChange={(c) => update(i, c)} idPrefix={id} />
            )}
            <InsertBlock onInsert={(t) => insert(i + 1, t)} label="+ Block darunter einfügen" />
          </div>
        );
      })}
      <InsertBlock onInsert={(t) => insert(value.length, t)} />
    </div>
  );
}
