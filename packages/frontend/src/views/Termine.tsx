import { useMemo, useState } from 'react';
import { api, can, config, isMember, type CalendarEvent } from '../api';
import { navigate } from '../router';
import { eventEnd, eventStart, formatEventWhen, formatMonth } from '../format';
import { downloadIcs } from '../ics';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

type EventLike = CalendarEvent & { url?: string };

/** Mitglieder sehen den Kalender mit allen Terminen, Gäste die öffentliche Terminliste. */
async function loadEvents(): Promise<EventLike[]> {
  if (isMember() && config?.features.calendar) return api.calendar();
  const list = await api.events();
  return list.map((ev) => ({ ...ev, id: `p${ev.id}` }));
}

export default function Termine() {
  const { data, error, loading, reload } = useAsync(loadEvents, [], 'termine');
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

  const manage =
    can('manage_events') && config?.features.events ? (
      <button className="btn btn-secondary btn-block manage-events" onClick={() => navigate('termine/verwalten')}>
        Öffentliche Termine verwalten
      </button>
    ) : null;

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (groups.length === 0)
    return (
      <>
        {manage}
        <Empty>Keine anstehenden Termine.</Empty>
      </>
    );

  return (
    <div className="events">
      {manage}
      {groups.map((evs) => (
        <section key={evs[0].id} className="month">
          <h2 className="month-title">{formatMonth(eventStart(evs[0]))}</h2>
          <ul className="card-list">
            {evs.map((ev) => {
              const s = eventStart(ev);
              const isOpen = open === ev.id;
              return (
                <li key={ev.id} className={`card event ${isOpen ? 'open' : ''}`}>
                  <button className="event-head" onClick={() => setOpen(isOpen ? null : ev.id)} aria-expanded={isOpen}>
                    <div className="date-badge" aria-hidden="true">
                      <span className="day">{s.getDate()}</span>
                      <span className="mon">{s.toLocaleDateString('de-DE', { month: 'short' }).replace('.', '')}</span>
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
                        {ev.location && (
                          <a
                            className="btn btn-secondary"
                            href={`https://maps.google.com/?q=${encodeURIComponent(ev.location)}`}
                            target="_blank"
                            rel="noreferrer"
                          >
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
    </div>
  );
}
