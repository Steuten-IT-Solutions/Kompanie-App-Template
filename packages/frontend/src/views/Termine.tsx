import { useEffect, useMemo, useState } from 'react';
import { can, config } from '../api';
import GuestPushButton from '../GuestPushButton';
import { birthdaysVisible, UpcomingBirthdays, UpcomingBirthdaysCard, useUpcomingBirthdays } from '../upcomingBirthdays';
import { navigate } from '../router';
import { eventEnd, eventStart, formatEventWhen, formatMonth, shortMonth } from '../format';
import { downloadIcs } from '../ics';
import { mapLinkTarget, mapPlatform, mapSearchUrl } from '../address';
import { useFocusEntry } from '../focus';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { hasEvents, prefetcher, prefetchOnTap, sources, type EventLike } from '../viewData';

/** `focus`: Kennung eines Termins aus der Adresse termine/termin/<id> (Ziel einer Benachrichtigung, Issue #190). */
export default function Termine({ focus }: { focus?: string }) {
  // Geburtstage (Issue #1): „Anstehende Geburtstage“ oben; ohne Termine die einzige Karte.
  if (!hasEvents()) return <UpcomingBirthdays always />;
  return <EventList focus={focus} />;
}

function EventList({ focus }: { focus?: string }) {
  const { data, error, loading, reload } = useAsync(sources.termine.load, sources.termine.deps, sources.termine.key);
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
  // Wer Termine pflegt, öffnet die Seite dahinter oft: im Leerlauf vorab laden, beim Antippen sofort.
  useEffect(() => {
    if (canManage) void prefetcher.idle([sources.events]);
  }, [canManage]);
  const manage = canManage ? (
    <button
      className="btn btn-secondary btn-block manage-events"
      onClick={() => navigate('termine/verwalten')}
      {...prefetchOnTap(sources.events)}
    >
      Öffentliche Termine verwalten
    </button>
  ) : null;

  const notify = <GuestPushButton label="Bei neuen Terminen benachrichtigen" />;

  if ((loading && !data) || (showBirthdays && upcoming.loading && !upcoming.data)) return <Loading />;
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
        <Empty>Keine anstehenden Termine.</Empty>
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
                      <div className="event-title">{ev.title}</div>
                      <div className="event-when">{formatEventWhen(ev)}</div>
                      {ev.location && <div className="event-where">{ev.location}</div>}
                    </div>
                  </button>
                  {isOpen && (
                    <div className="event-body">
                      {ev.description && <p className="event-desc">{ev.description}</p>}
                      <div className="event-actions">
                        <button className="btn btn-secondary" onClick={() => downloadIcs(ev)}>
                          In meinen Kalender
                        </button>
                        {ev.url && (
                          <a className="btn btn-secondary" href={ev.url} target="_blank" rel="noreferrer">
                            Mehr Infos
                          </a>
                        )}
                        {map && (
                          <a className="btn btn-secondary" href={map} {...mapLinkTarget(map)}>
                            Karte
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
