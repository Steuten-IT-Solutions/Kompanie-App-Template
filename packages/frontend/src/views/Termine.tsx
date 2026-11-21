import { useEffect, useMemo, useState } from 'react';
import { api, can, config, eventKindTag, eventKinds } from '../api';
import { CollapsibleKindChecks, kindsOf } from '../eventKindChecks';
import { kindName } from '../names';
import GuestPushButton from '../GuestPushButton';
import { birthdaysVisible, UpcomingBirthdays, UpcomingBirthdaysCard, useUpcomingBirthdays } from '../upcomingBirthdays';
import { navigate } from '../router';
import { eventEnd, eventStart, formatEventWhen, formatMonth, shortMonth } from '../format';
import { downloadIcs } from '../ics';
import { t } from '../i18n';
import { UntranslatedNote } from '../language';
import { mapLinkTarget, mapPlatform, mapSearchUrl } from '../address';
import { useFocusEntry } from '../focus';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { hasEvents, prefetcher, prefetchOnTap, sources, type EventLike } from '../viewData';
import { ResponseBar, ResponseDetails } from '../eventResponses';
import type { CalendarEvent } from '../api';

/** Interne Termine in der App pflegen: Quelle „In der App“ und das Recht dazu. */
export const canManageCalendar = (): boolean => !!config?.calendarInApp && !!config.features.calendar && can('manage_calendar');

/** Terminart am Termin wählen: wer Termine pflegt (manage_calendar), bei jeder Quelle des Kalenders. */
const canSetKind = (): boolean => !!config?.features.calendar && can('manage_calendar') && eventKinds().length > 0;

/** `focus`: Kennung eines Termins aus der Adresse termine/termin/<id> (Ziel einer Benachrichtigung, Issue #190). */
export default function Termine({ focus }: { focus?: string }) {
  // Geburtstage (Issue #1): „Anstehende Geburtstage“ oben; ohne Termine die einzige Karte.
  if (!hasEvents()) return <UpcomingBirthdays always />;
  return <EventList focus={focus} />;
}

function EventList({ focus }: { focus?: string }) {
  const { data, error, loading, reload, setData } = useAsync(sources.termine.load, sources.termine.deps, sources.termine.key);
  // Nach einer Zu- oder Absage: den Termin in der Liste (und im Zwischenspeicher) ersetzen.
  const replace = (ev: CalendarEvent) => setData((data ?? []).map((e) => (e.id === ev.id ? { ...e, responses: ev.responses } : e)));
  // Nach dem Setzen der Terminart: nur die Art übernehmen (der Vorschlag entfällt mit einer gesetzten Art).
  const replaceKinds = (id: string, kinds: string[]) =>
    setData(
      (data ?? []).map((e) =>
        e.id === id
          ? { ...e, kind: kinds[0] ?? null, kinds, ...(kinds.length > 0 ? { kind_suggestion: null, kind_suggestions: [] } : {}) }
          : e,
      ),
    );
  // Geburtstage gleichzeitig laden und erst mit den Terminen zeigen: Sonst taucht die Karte oben nachträglich auf und
  // schiebt die Liste nach unten.
  const showBirthdays = birthdaysVisible();
  const upcoming = useUpcomingBirthdays(showBirthdays);
  const birthdays = showBirthdays ? <UpcomingBirthdaysCard state={upcoming} /> : null;
  const [open, setOpen] = useState<string | null>(null);

  const groups = useMemo(() => {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const upcoming = (data ?? []).filter((ev) => eventEnd(ev) >= now);
    const map = new Map<string, EventLike[]>();
    for (const ev of upcoming) {
      const s = eventStart(ev);
      const key = `${s.getFullYear()}-${String(s.getMonth()).padStart(2, '0')}`;
      if (!map.has(key)) map.set(key, []);
      map.get(key)!.push(ev);
    }
    return Array.from(map.values());
  }, [data]);

  // Der Termin aus der Benachrichtigung: hinscrollen, aufklappen und kurz hervorheben, sobald die Liste steht.
  const ready = !!data && !error && !(showBirthdays && upcoming.loading && !upcoming.data);
  const flash = useFocusEntry(focus, ready && groups.some((evs) => evs.some((ev) => ev.id === focus)));
  useEffect(() => {
    if (flash) setOpen(flash);
  }, [flash]);

  const canManage = can('manage_events') && !!config?.features.events;
  const canCalendar = canManageCalendar();
  // Wer Termine pflegt, öffnet die Seite dahinter oft: im Leerlauf vorab laden, beim Antippen sofort.
  useEffect(() => {
    if (canManage) void prefetcher.idle([sources.eventsSource]);
    if (canCalendar) void prefetcher.idle([sources.calendarEntries]);
  }, [canManage, canCalendar]);
  const manage =
    canManage || canCalendar ? (
      <>
        {canCalendar && (
          <button
            className="btn btn-secondary btn-block manage-events"
            onClick={() => navigate('termine/kalender')}
            {...prefetchOnTap(sources.calendarEntries)}
          >
            {t('Internen Kalender verwalten')}
          </button>
        )}
        {canManage && (
          <button
            className="btn btn-secondary btn-block manage-events"
            onClick={() => navigate('termine/verwalten')}
            {...prefetchOnTap(sources.eventsSource)}
          >
            {t('Öffentliche Termine verwalten')}
          </button>
        )}
      </>
    ) : null;

  const notify = <GuestPushButton label={t('Bei neuen Terminen benachrichtigen')} />;

  if ((loading && !data) || (showBirthdays && upcoming.loading && !upcoming.data)) return <Loading shape="events" />;
  if (error)
    return (
      <>
        {birthdays}
        <ErrorBox message={error} onRetry={reload} />
      </>
    );
  if (groups.length === 0)
    return (
      <>
        {birthdays}
        {manage}
        <Empty>{t('Zurzeit steht nichts an.')}</Empty>
        {notify}
      </>
    );

  const platform = mapPlatform();
  return (
    <div className="events">
      {birthdays}
      {manage}
      {groups.map((evs) => (
        <section key={evs[0].id} className="month">
          <h2 className="month-title">{formatMonth(eventStart(evs[0]))}</h2>
          <ul className="card-list">
            {evs.map((ev) => {
              const s = eventStart(ev);
              const isOpen = open === ev.id;
              const map = isOpen ? mapSearchUrl(ev.location, platform) : null;
              return (
                <li key={ev.id} className={`card event ${isOpen ? 'open' : ''} ${flash === ev.id ? 'focused' : ''}`} data-focus-id={ev.id}>
                  <button className="event-head" onClick={() => setOpen(isOpen ? null : ev.id)} aria-expanded={isOpen}>
                    <div className="date-badge" aria-hidden="true">
                      <span className="day">{s.getDate()}</span>
                      <span className="mon">{shortMonth(s)}</span>
                    </div>
                    <div className="event-main">
                      <div className="event-title">
                        {ev.title}{' '}
                        {kindsOf(ev)
                          .filter(eventKindTag)
                          .map((k) => (
                            <span key={k} className="badge event-kind">
                              {kindName(k)}
                            </span>
                          ))}
                      </div>
                      <div className="event-when">{formatEventWhen(ev)}</div>
                      {ev.location && <div className="event-where">{ev.location}</div>}
                    </div>
                  </button>
                  <ResponseBar ev={ev} onChange={replace} />
                  {isOpen && (
                    <div className="event-body">
                      <UntranslatedNote untranslated={ev.untranslated} />
                      {ev.description && <p className="event-desc">{ev.description}</p>}
                      {canSetKind() && <KindPicker ev={ev} onChange={replaceKinds} />}
                      <ResponseDetails ev={ev} onChange={replace} />
                      <div className="event-actions">
                        <button className="btn btn-secondary" onClick={() => downloadIcs(ev)}>
                          {t('In meinen Kalender')}
                        </button>
                        {ev.url && (
                          <a className="btn btn-secondary" href={ev.url} target="_blank" rel="noreferrer">
                            {t('Mehr Infos')}
                          </a>
                        )}
                        {map && (
                          <a className="btn btn-secondary" href={map} {...mapLinkTarget(map)}>
                            {t('Karte')}
                          </a>
                        )}
                      </div>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </section>
      ))}
      {notify}
    </div>
  );
}

/**
 * Terminarten eines Termins ankreuzen (mehrere möglich); speichert sofort. Eingeklappt, bis man auf „Terminarten“ tippt;
 * dann stehen nur die gewählten Arten da. Ohne Art mit Vorschlag aus dem Titel (zählt erst übernommen).
 */
function KindPicker({ ev, onChange }: { ev: CalendarEvent; onChange: (id: string, kinds: string[]) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Während des Speicherns schon die neue Auswahl zeigen
  const [draft, setDraft] = useState<string[] | null>(null);
  const current = kindsOf(ev);
  const shown = draft ?? current;
  const listId = `event-kinds-${ev.id}`;
  const suggestions = ev.kind_suggestions ?? (ev.kind_suggestion ? [ev.kind_suggestion] : []);

  async function choose(kinds: string[]) {
    setBusy(true);
    setDraft(kinds);
    setError(null);
    try {
      const r = await api.setKindsOfEvent(ev.id, kinds);
      onChange(ev.id, kindsOf(r));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setDraft(null);
      setBusy(false);
    }
  }

  return (
    <div className="event-kind-picker">
      <CollapsibleKindChecks id={listId} value={shown} disabled={busy} onChange={(kinds) => void choose(kinds)} />
      {current.length === 0 && suggestions.length > 0 && (
        <p className="small muted">
          {t('Vorschlag aus dem Titel: {kinds}.', { kinds: suggestions.map((k) => kindName(k)).join(', ') })}{' '}
          <button type="button" className="btn btn-link inline-link" disabled={busy} onClick={() => void choose(suggestions)}>
            {t('Übernehmen')}
          </button>
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
