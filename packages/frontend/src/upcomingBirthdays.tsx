// Geburtstage (Issue #1), was die App schon beim Start braucht: wer sie sieht und die Karte „Anstehende Geburtstage“
// bei den Terminen. Alles Übrige (ganze Liste, eigener Geburtstag, Pflege) steht in birthdays.tsx und wird erst bei
// Bedarf geladen.

import { api, can, config, type BirthdayEntry } from './api';
import brand from './generated/brand';
import { parseLocalDate } from './format';
import { navigate } from './router';
import { ErrorBox, useAsync } from './ui';

const sec = brand.labels.sections;

/** Wer Geburtstage sieht und seinen eintragen kann: Bereich an, Mitglieder (wie BirthdayService::isMember im Kern). */
export function birthdaysVisible(): boolean {
  return !!config?.features.birthdays && can('member');
}

const weekdayDate = new Intl.DateTimeFormat('de-DE', { weekday: 'short', day: 'numeric', month: 'short' });

function whenText(b: BirthdayEntry): string {
  if (b.days_until === 1) return 'Morgen';
  return weekdayDate.format(parseLocalDate(b.date));
}

export function BirthdayRow({ b }: { b: BirthdayEntry }) {
  const date = parseLocalDate(b.date);
  return (
    <li className={`card birthday-item ${b.today ? 'birthday-today' : ''}`}>
      <div className="date-badge" aria-hidden="true">
        <span className="day">{date.getDate()}</span>
        <span className="mon">{date.toLocaleDateString('de-DE', { month: 'short' }).replace('.', '')}</span>
      </div>
      <div className="birthday-main">
        <div className="birthday-name">{b.name}</div>
        <div className="birthday-when">
          {whenText(b)}
          {b.age !== null && ` · wird ${b.age}`}
        </div>
      </div>
      {b.today && <span className="badge birthday-badge">Heute</span>}
    </li>
  );
}

/**
 * Karte „Anstehende Geburtstage“ oben bei den Termine: heute und die nächsten 7 Tage, heute hervorgehoben. Ohne
 * anstehende erscheint sie nicht (außer mit `always`, wenn es sonst nichts zu sehen gibt).
 */
export function UpcomingBirthdays({ always = false }: { always?: boolean }) {
  return <UpcomingBirthdaysCard state={useUpcomingBirthdays(true)} always={always} />;
}

/** Lädt die anstehenden Geburtstage (nur mit `on`), z. B. gleichzeitig mit den Terminen. */
export function useUpcomingBirthdays(on: boolean) {
  return useAsync(() => (on ? api.birthdays(7) : Promise.resolve(null)), [], 'geburtstage-anstehend');
}

/** Die Karte zu schon geladenen Daten (useUpcomingBirthdays). */
export function UpcomingBirthdaysCard({
  state: { data, error },
  always = false,
}: {
  state: { data: BirthdayEntry[] | null; error: string | null };
  always?: boolean;
}) {
  if (!data) return error && always ? <ErrorBox message={error} /> : null;
  if (data.length === 0 && !always) return null;
  return (
    <section className="card birthdays-upcoming" aria-label={`Anstehende ${sec.birthdays}`}>
      <h2 className="birthdays-title">Anstehende {sec.birthdays}</h2>
      {data.length === 0 ? (
        <p className="muted small">In den nächsten 7 Tagen hat niemand Geburtstag.</p>
      ) : (
        <ul className="card-list birthday-list">
          {data.map((b) => (
            <BirthdayRow key={b.user_id} b={b} />
          ))}
        </ul>
      )}
      <button className="btn btn-link birthdays-all" onClick={() => navigate('termine/geburtstage')}>
        Alle {sec.birthdays}
      </button>
    </section>
  );
}
