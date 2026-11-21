import { Suspense, useEffect, useState, type ReactNode } from 'react';
import { api, type BlockAudience, type DocumentInfo, type GalleryAlbumSummary, type Office, type Poll, type PublicEvent } from '../api';
import { Loading, Sheet, lazyView } from '../ui';
import {
  BLOCK_KINDS,
  moveBlock,
  newBlock,
  type BlockDraft,
  type BlockKind,
  type ButtonDraft,
  type ContactsDraft,
  type CountdownDraft,
  type DividerDraft,
  type EventsDraft,
  type FactsDraft,
  type NoticeDraft,
  type ScheduleDraft,
  type PollDraft,
  type AlbumDraft,
  type DocumentDraft,
  type SponsorsDraft,
  type MapDraft,
} from './blockDrafts';
import { BlockIcon } from './blockIcons';
import Countdown from './Countdown';
import { previewRange } from './countdown';
import { confirmAsk } from '../ask';
import brand from '../generated/brand';
import { t } from '../i18n';

/** Name einer Blockart; „Sponsoren“ heißt wie der Bereich (eigener Name aus Einstellungen → Navigation, Issue #362). */
const kindLabel = (k: { type: string; label: string }): string => (k.type === 'sponsors' ? brand.labels.sections.sponsors : k.label);

const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/** Grenzen wie auf dem Server (CountdownBlock::TITLE_MAX, TEXT_MAX, ButtonBlock::LABEL_MAX, EventsBlock::MAX). */
const TITLE_MAX = 100;
const TEXT_MAX = 200;
const LABEL_MAX = 60;
const EVENTS_MAX = 20;
const NOTICE_MAX = 1000;
const OFFICES_MAX = 20;

let offices: Promise<Office[]> | null = null;
/** Ämter zur Auswahl, einmal je Sitzung geladen (ohne Bereich Ämter: keine). */
function allOffices(): Promise<Office[]> {
  offices ??= api.offices().then(
    (o) => o.offices,
    () => {
      offices = null;
      return [];
    },
  );
  return offices;
}

function ContactsFields({ block, onChange }: { block: ContactsDraft; onChange: (b: ContactsDraft) => void }) {
  const set = (patch: Partial<ContactsDraft>) => onChange({ ...block, ...patch });
  const [list, setList] = useState<Office[] | null>(null);
  useEffect(() => {
    let alive = true;
    void allOffices().then((l) => alive && setList(l));
    return () => {
      alive = false;
    };
  }, []);
  const toggle = (id: number, on: boolean) =>
    set({ office_ids: on ? [...block.office_ids, id].slice(0, OFFICES_MAX) : block.office_ids.filter((x) => x !== id) });
  return (
    <div className="block-contacts-fields">
      <label className="field">
        <span>{t('Überschrift (optional)')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder={t('z. B. Sprich uns an')}
          onChange={(e) => set({ title: e.target.value })}
        />
      </label>
      <fieldset className="block-offices">
        <legend>{t('Ämter')}</legend>
        {list === null ? (
          <Loading shape="text" />
        ) : list.length === 0 ? (
          <p className="muted small">{t('Noch keine Ämter angelegt.')}</p>
        ) : (
          list.map((o) => (
            <label key={o.id} className="toggle">
              <input type="checkbox" checked={block.office_ids.includes(o.id)} onChange={(e) => toggle(o.id, e.target.checked)} /> {o.title}
            </label>
          ))
        )}
        <span className="muted small">
          {t(
            'Die App zeigt, wer das Amt gerade innehat, in der Reihenfolge deiner Auswahl. E-Mail-Adresse und Handynummer sehen nur Personen, die persönliche Angaben sehen dürfen.',
          )}
        </span>
      </fieldset>
    </div>
  );
}

function NoticeFields({ block, onChange }: { block: NoticeDraft; onChange: (b: NoticeDraft) => void }) {
  const set = (patch: Partial<NoticeDraft>) => onChange({ ...block, ...patch });
  return (
    <div className="block-notice-fields">
      <label className="field">
        <span>{t('Überschrift (optional)')}</span>
        <input type="text" maxLength={TITLE_MAX} value={block.title} onChange={(e) => set({ title: e.target.value })} />
      </label>
      <label className="field">
        <span>{t('Hinweis')}</span>
        <textarea maxLength={NOTICE_MAX} rows={3} value={block.text} onChange={(e) => set({ text: e.target.value })} required />
      </label>
      <label className="field">
        <span>{t('Art')}</span>
        <select value={block.tone} onChange={(e) => set({ tone: e.target.value as NoticeDraft['tone'] })}>
          <option value="info">{t('Info')}</option>
          <option value="warning">{t('Warnung')}</option>
          <option value="success">{t('Erfolg')}</option>
        </select>
      </label>
    </div>
  );
}

const SCHEDULE_MAX = 50;
const FACTS_MAX = 12;

/** Zeilen einer Liste (Ablauf, Zahlen) bearbeiten: hinzufügen, entfernen. */
function RowsEditor<T>({
  rows,
  onChange,
  empty,
  max,
  addLabel,
  render,
}: {
  rows: T[];
  onChange: (rows: T[]) => void;
  empty: T;
  max: number;
  addLabel: string;
  render: (row: T, set: (r: T) => void, n: number) => ReactNode;
}) {
  return (
    <div className="block-rows">
      {rows.map((r, n) => (
        <div key={n} className="block-row field-row">
          {render(r, (next) => onChange(rows.map((x, j) => (j === n ? next : x))), n)}
          <button
            type="button"
            className="btn btn-small btn-secondary"
            aria-label={t('Zeile {n} entfernen', { n: n + 1 })}
            disabled={rows.length === 1}
            onClick={() => onChange(rows.filter((_, j) => j !== n))}
          >
            ×
          </button>
        </div>
      ))}
      <button
        type="button"
        className="btn btn-small btn-secondary"
        disabled={rows.length >= max}
        onClick={() => onChange([...rows, { ...empty }])}
      >
        {addLabel}
      </button>
    </div>
  );
}

function ScheduleFields({ block, onChange }: { block: ScheduleDraft; onChange: (b: ScheduleDraft) => void }) {
  return (
    <div className="block-schedule-fields">
      <label className="field">
        <span>{t('Überschrift (optional)')}</span>
        <input type="text" maxLength={TITLE_MAX} value={block.title} onChange={(e) => onChange({ ...block, title: e.target.value })} />
      </label>
      <RowsEditor
        rows={block.items}
        onChange={(items) => onChange({ ...block, items })}
        empty={{ time: '', text: '' }}
        max={SCHEDULE_MAX}
        addLabel={t('+ Programmpunkt')}
        render={(r, set, n) => (
          <>
            <label className="field block-row-short">
              <span>{t('Zeit')}</span>
              <input
                type="text"
                maxLength={20}
                value={r.time}
                placeholder="14:00"
                aria-label={t('Zeit {n}', { n: n + 1 })}
                onChange={(e) => set({ ...r, time: e.target.value })}
              />
            </label>
            <label className="field">
              <span>{t('Programmpunkt')}</span>
              <input
                type="text"
                maxLength={200}
                value={r.text}
                aria-label={t('Programmpunkt {n}', { n: n + 1 })}
                onChange={(e) => set({ ...r, text: e.target.value })}
                required
              />
            </label>
          </>
        )}
      />
    </div>
  );
}

function FactsFields({ block, onChange }: { block: FactsDraft; onChange: (b: FactsDraft) => void }) {
  return (
    <div className="block-facts-fields">
      <label className="field">
        <span>{t('Überschrift (optional)')}</span>
        <input type="text" maxLength={TITLE_MAX} value={block.title} onChange={(e) => onChange({ ...block, title: e.target.value })} />
      </label>
      <RowsEditor
        rows={block.items}
        onChange={(items) => onChange({ ...block, items })}
        empty={{ value: '', label: '' }}
        max={FACTS_MAX}
        addLabel={t('+ Zahl')}
        render={(r, set, n) => (
          <>
            <label className="field block-row-short">
              <span>{t('Wert')}</span>
              <input
                type="text"
                maxLength={20}
                value={r.value}
                placeholder="1848"
                aria-label={t('Wert {n}', { n: n + 1 })}
                onChange={(e) => set({ ...r, value: e.target.value })}
                required
              />
            </label>
            <label className="field">
              <span>{t('Bezeichnung')}</span>
              <input
                type="text"
                maxLength={60}
                value={r.label}
                placeholder={t('gegründet')}
                aria-label={t('Bezeichnung {n}', { n: n + 1 })}
                onChange={(e) => set({ ...r, label: e.target.value })}
                required
              />
            </label>
          </>
        )}
      />
    </div>
  );
}

/** Liste einmal je Sitzung laden (ohne Recht oder Bereich: leer). */
function cached<T>(load: () => Promise<T[]>): () => Promise<T[]> {
  let p: Promise<T[]> | null = null;
  return () =>
    (p ??= load().catch(() => {
      p = null;
      return [];
    }));
}
const allPolls = cached<Poll>(() => api.polls());
const allAlbums = cached<GalleryAlbumSummary & { year: string }>(() =>
  api.gallery().then((g) => g.years.flatMap((y) => y.albums.map((a) => ({ ...a, year: y.year })))),
);

function useList<T>(load: () => Promise<T[]>): T[] | null {
  const [list, setList] = useState<T[] | null>(null);
  useEffect(() => {
    let alive = true;
    void load().then((l) => alive && setList(l));
    return () => {
      alive = false;
    };
  }, [load]);
  return list;
}

const allDocuments = cached<DocumentInfo>(() => api.documents().then((r) => r.documents));

function DocumentFields({ block, onChange }: { block: DocumentDraft; onChange: (b: DocumentDraft) => void }) {
  const list = useList(allDocuments);
  const picked = list?.find((d) => d.id === block.document_id);
  return (
    <label className="field">
      <span>{t('Dokument')}</span>
      {list === null ? (
        <Loading shape="text" />
      ) : (
        <select value={block.document_id || ''} onChange={(e) => onChange({ ...block, document_id: Number(e.target.value) || 0 })} required>
          <option value="">{t('Dokument wählen')}</option>
          {block.document_id > 0 && !picked && (
            <option value={block.document_id}>{block.document_title ?? t('Dokument {id}', { id: block.document_id })}</option>
          )}
          {list.map((d) => (
            <option key={d.id} value={d.id}>
              {d.title} ({d.ext.toUpperCase()}){d.visibility === 'managers' ? ' – ' + t('nur Pflege') : ''}
            </option>
          ))}
        </select>
      )}
      <span className="muted small">
        {t('Sichtbar nur für alle, die das Dokument in den Dokumenten sehen dürfen; für andere und ohne Anmeldung fehlt der Block.')}
      </span>
    </label>
  );
}

function SponsorsFields({ block, onChange }: { block: SponsorsDraft; onChange: (b: SponsorsDraft) => void }) {
  return (
    <label className="field">
      <span>{t('Überschrift (optional)')}</span>
      <input
        type="text"
        maxLength={TITLE_MAX}
        value={block.title}
        placeholder={t('z. B. Mit Dank an unsere Sponsoren')}
        onChange={(e) => onChange({ ...block, title: e.target.value })}
      />
      <span className="muted small">{t('Zeigt alle Einträge in ihrer Reihenfolge, mit Logo und Link.')}</span>
    </label>
  );
}

function MapFields({ block, onChange }: { block: MapDraft; onChange: (b: MapDraft) => void }) {
  return (
    <div className="block-map-fields">
      <label className="field">
        <span>{t('Überschrift (optional)')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder={t('z. B. So findet ihr uns')}
          onChange={(e) => onChange({ ...block, title: e.target.value })}
        />
      </label>
      <label className="field">
        <span>{t('Ort oder Adresse')}</span>
        <input
          type="text"
          maxLength={200}
          value={block.place}
          placeholder={t('z. B. Am Markt 1, 12345 Musterstadt')}
          onChange={(e) => onChange({ ...block, place: e.target.value })}
          required
        />
        <span className="muted small">
          {t(
            'Der Knopf „Karte“ öffnet den Ort in der Karten-App des Geräts, wie beim Ort eines Termins. Es wird keine Karte eines fremden Anbieters eingebettet.',
          )}
        </span>
      </label>
    </div>
  );
}

function PollFields({ block, onChange }: { block: PollDraft; onChange: (b: PollDraft) => void }) {
  const list = useList(allPolls);
  return (
    <label className="field">
      <span>{t('Umfrage')}</span>
      {list === null ? (
        <Loading shape="text" />
      ) : (
        <select value={block.poll_id || ''} onChange={(e) => onChange({ ...block, poll_id: Number(e.target.value) || 0 })} required>
          <option value="">{t('Umfrage wählen')}</option>
          {block.poll_id > 0 && !list.some((p) => p.id === block.poll_id) && (
            <option value={block.poll_id}>{block.poll_title ?? t('Umfrage {id}', { id: block.poll_id })}</option>
          )}
          {list.map((p) => (
            <option key={p.id} value={p.id}>
              {p.title}
              {p.status === 'ended' ? ' ' + t('(beendet)') : ''}
            </option>
          ))}
        </select>
      )}
      <span className="muted small">{t('Sichtbar nur für alle, die an Umfragen teilnehmen dürfen; für andere fehlt der Block.')}</span>
    </label>
  );
}

function AlbumFields({ block, onChange }: { block: AlbumDraft; onChange: (b: AlbumDraft) => void }) {
  const list = useList(allAlbums);
  return (
    <div className="block-album-fields">
      <label className="field">
        <span>{t('Album')}</span>
        {list === null ? (
          <Loading shape="text" />
        ) : (
          <select value={block.album_id || ''} onChange={(e) => onChange({ ...block, album_id: Number(e.target.value) || 0 })} required>
            <option value="">{t('Album wählen')}</option>
            {block.album_id > 0 && !list.some((a) => a.id === block.album_id) && (
              <option value={block.album_id}>{block.album_title ?? t('Album {id}', { id: block.album_id })}</option>
            )}
            {list.map((a) => (
              <option key={a.id} value={a.id}>
                {a.title} ({a.year})
              </option>
            ))}
          </select>
        )}
      </label>
      <label className="field">
        <span>{t('Wie viele Bilder')}</span>
        <input
          type="number"
          min={1}
          max={12}
          value={block.count}
          onChange={(e) => onChange({ ...block, count: Math.max(1, Math.min(12, Number(e.target.value) || 1)) })}
        />
      </label>
    </div>
  );
}

function DividerFields({ block, onChange }: { block: DividerDraft; onChange: (b: DividerDraft) => void }) {
  return (
    <label className="field">
      <span>{t('Art der Trennung')}</span>
      <select value={block.style} onChange={(e) => onChange({ ...block, style: e.target.value as DividerDraft['style'] })}>
        <option value="line">{t('Linie')}</option>
        <option value="space">{t('Nur Abstand')}</option>
      </select>
    </label>
  );
}

function ButtonFields({ block, onChange }: { block: ButtonDraft; onChange: (b: ButtonDraft) => void }) {
  const set = (patch: Partial<ButtonDraft>) => onChange({ ...block, ...patch });
  return (
    <div className="block-button-fields">
      <label className="field">
        <span>{t('Beschriftung')}</span>
        <input
          type="text"
          maxLength={LABEL_MAX}
          value={block.label}
          placeholder={t('z. B. Jetzt anmelden')}
          onChange={(e) => set({ label: e.target.value })}
          required
        />
      </label>
      <label className="field">
        <span>{t('Ziel')}</span>
        <input type="text" inputMode="url" value={block.url} onChange={(e) => set({ url: e.target.value })} required />
        <span className="muted small">
          {t(
            'Eine Adresse mit https://, eine E-Mail-Adresse mit mailto:, eine Telefonnummer mit tel: oder eine Seite der App wie #/termine.',
          )}
        </span>
      </label>
      <label className="field">
        <span>{t('Aussehen')}</span>
        <select value={block.style} onChange={(e) => set({ style: e.target.value as ButtonDraft['style'] })}>
          <option value="primary">{t('Hervorgehoben')}</option>
          <option value="secondary">{t('Zurückhaltend')}</option>
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
        <span>{t('Überschrift (optional)')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder={t('z. B. Unsere nächsten Termine')}
          onChange={(e) => set({ title: e.target.value })}
        />
      </label>
      <label className="field">
        <span>{t('Wie viele Termine')}</span>
        <input
          type="number"
          min={1}
          max={EVENTS_MAX}
          value={block.count}
          onChange={(e) => set({ count: Math.max(1, Math.min(EVENTS_MAX, Number(e.target.value) || 1)) })}
        />
        <span className="muted small">{t('Die App zeigt immer die nächsten Termine ab heute.')}</span>
      </label>
    </div>
  );
}

/**
 * Knopf „+ Block einfügen“ an einer Stelle der Liste. Er öffnet ein Fenster mit allen Blockarten, je mit Symbol, Name
 * und kurzer Erklärung; Tippen fügt den Block ein und schließt das Fenster („Zurück“ schließt es ebenso, Sheet).
 */
function InsertBlock({ onInsert, label = t('+ Block einfügen') }: { onInsert: (type: BlockKind) => void; label?: string }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="block-insert">
      <button type="button" className="btn btn-secondary block-insert-btn" aria-haspopup="dialog" onClick={() => setOpen(true)}>
        {label}
      </button>
      {open && (
        <Sheet title={t('Block einfügen')} onClose={() => setOpen(false)}>
          <ul className="block-kinds" aria-label={t('Blockarten')}>
            {BLOCK_KINDS.map((k) => (
              <li key={k.type}>
                <button
                  type="button"
                  className="block-kind"
                  data-kind={k.type}
                  onClick={() => {
                    setOpen(false);
                    onInsert(k.type);
                  }}
                >
                  <span className="block-kind-icon">
                    <BlockIcon type={k.type} />
                  </span>
                  <span className="block-kind-text">
                    <strong>{kindLabel(k)}</strong>
                    <span className="muted small">{k.hint}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Sheet>
      )}
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
        <span>{t('Überschrift (optional)')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={block.title}
          placeholder={t('z. B. Bis zum Fest')}
          onChange={(e) => set({ title: e.target.value })}
        />
      </label>
      <fieldset className="block-source">
        <legend>{t('Zählt bis')}</legend>
        <label className="toggle">
          <input type="radio" name={`${idPrefix}-source`} checked={block.source === 'date'} onChange={() => set({ source: 'date' })} />{' '}
          {t('Datum')}
        </label>
        <label className="toggle">
          <input type="radio" name={`${idPrefix}-source`} checked={block.source === 'event'} onChange={() => set({ source: 'event' })} />{' '}
          {t('Termin')}
        </label>
      </fieldset>
      {block.source === 'event' ? (
        <label className="field">
          <span>{t('Termin')}</span>
          {list === null ? (
            <Loading shape="text" />
          ) : (
            <select
              value={block.event_id ?? ''}
              onChange={(e) => set({ event_id: e.target.value ? Number(e.target.value) : undefined })}
              required
            >
              <option value="">{t('Termin wählen')}</option>
              {block.event_id && !event && (
                <option value={block.event_id}>{block.event_title ?? t('Termin {id}', { id: block.event_id })}</option>
              )}
              {list.map((ev) => (
                <option key={ev.id} value={ev.id}>
                  {ev.title} ({ev.start_date.split('-').reverse().join('.')})
                </option>
              ))}
            </select>
          )}
          <span className="muted small">
            {t('Wird der Termin verschoben, zählt der Countdown mit. Beginn und Ende kommen aus dem Termin.')}
          </span>
        </label>
      ) : (
        <>
          <div className="field-row">
            <label className="field">
              <span>{t('Datum')}</span>
              <input type="date" value={block.date ?? ''} onChange={(e) => set({ date: e.target.value })} required />
            </label>
            <label className="field">
              <span>{t('Uhrzeit (optional)')}</span>
              <input type="time" value={block.time ?? ''} onChange={(e) => set({ time: e.target.value })} />
            </label>
          </div>
          <div className="field-row">
            <label className="field">
              <span>{t('Ende am (optional)')}</span>
              <input type="date" value={block.end_date ?? ''} onChange={(e) => set({ end_date: e.target.value })} />
            </label>
            <label className="field">
              <span>{t('Ende um (optional)')}</span>
              <input type="time" value={block.end_time ?? ''} onChange={(e) => set({ end_time: e.target.value })} />
            </label>
          </div>
        </>
      )}
      <label className="field">
        <span>{t('Text, solange es läuft (optional)')}</span>
        <input
          type="text"
          maxLength={TEXT_MAX}
          value={block.running_text}
          placeholder={t('z. B. Das Fest läuft!')}
          onChange={(e) => set({ running_text: e.target.value })}
        />
      </label>
      <label className="field">
        <span>{t('Danach')}</span>
        <select value={block.after} onChange={(e) => set({ after: e.target.value as CountdownDraft['after'] })}>
          <option value="text">{t('Text zeigen')}</option>
          <option value="hide">{t('Block ausblenden')}</option>
        </select>
      </label>
      {block.after === 'text' && (
        <label className="field">
          <span>{t('Text danach (optional)')}</span>
          <input
            type="text"
            maxLength={TEXT_MAX}
            value={block.after_text}
            placeholder={t('z. B. Danke fürs Mitfeiern!')}
            onChange={(e) => set({ after_text: e.target.value })}
          />
        </label>
      )}
      <div className="block-preview">
        <span className="muted small">{t('Vorschau')}</span>
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
  allLabel = t('Für alle, die die Seite sehen'),
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
      {value.length === 0 && <p className="muted small">{t('Noch keine Blöcke.')}</p>}
      {value.map((b, i) => {
        const kind = BLOCK_KINDS.find((k) => k.type === b.type);
        const label = kind ? kindLabel(kind) : b.type;
        const id = `${idPrefix}-${b.id}`;
        return (
          <div key={b.id} className="block-edit card">
            <div className="block-edit-head">
              <strong>{label}</strong>
              <select
                aria-label={t('Zielgruppe des Blocks ({name})', { name: label })}
                value={b.audience}
                onChange={(e) => update(i, { ...b, audience: e.target.value as BlockAudience })}
              >
                <option value="all">{allLabel}</option>
                <option value="members">{t('Nur Mitglieder')}</option>
              </select>
              <span className="block-edit-actions">
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  disabled={i === 0}
                  onClick={() => onChange(moveBlock(value, i, -1))}
                  aria-label={t('{name} nach oben', { name: label })}
                >
                  ↑
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-secondary"
                  disabled={i === value.length - 1}
                  onClick={() => onChange(moveBlock(value, i, 1))}
                  aria-label={t('{name} nach unten', { name: label })}
                >
                  ↓
                </button>
                <button
                  type="button"
                  className="btn btn-small btn-danger"
                  onClick={async () =>
                    (await confirmAsk(t('Block „{name}“ entfernen?', { name: label }))) && onChange(value.filter((_, j) => j !== i))
                  }
                >
                  {t('Entfernen')}
                </button>
              </span>
            </div>
            {b.type === 'text' && renderText ? (
              renderText({ value: b.html, onChange: (html) => update(i, { ...b, html }), id })
            ) : b.type === 'text' ? (
              <Suspense fallback={<Loading shape="text" chunk />}>
                <RichTextEditor value={b.html} onChange={(html) => update(i, { ...b, html })} label={t('Text')} id={id} />
              </Suspense>
            ) : b.type === 'button' ? (
              <ButtonFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'events' ? (
              <EventsFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'contacts' ? (
              <ContactsFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'notice' ? (
              <NoticeFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'divider' ? (
              <DividerFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'schedule' ? (
              <ScheduleFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'facts' ? (
              <FactsFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'poll' ? (
              <PollFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'album' ? (
              <AlbumFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'document' ? (
              <DocumentFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'sponsors' ? (
              <SponsorsFields block={b} onChange={(c) => update(i, c)} />
            ) : b.type === 'map' ? (
              <MapFields block={b} onChange={(c) => update(i, c)} />
            ) : (
              <CountdownFields block={b} onChange={(c) => update(i, c)} idPrefix={id} />
            )}
            <InsertBlock onInsert={(kind) => insert(i + 1, kind)} label={t('+ Block darunter einfügen')} />
          </div>
        );
      })}
      <InsertBlock onInsert={(kind) => insert(value.length, kind)} />
    </div>
  );
}
