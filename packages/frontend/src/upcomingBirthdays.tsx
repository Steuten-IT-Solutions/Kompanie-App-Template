// Geburtstage (Issue #1), was die App schon beim Start braucht: wer sie sieht und die Karte „Anstehende Geburtstage“
// bei den Terminen. Alles Übrige (eigener Geburtstag, Pflege) steht in birthdays.tsx und wird erst bei Bedarf geladen.

import { type BirthdayEntry } from './api';
import brand from './generated/brand';
import { formatWeekdayDateYear, parseLocalDate, shortMonth } from './format';
import { t } from './i18n';
import { ErrorBox, useAsync } from './ui';
import { sources, upcomingBirthdayDays } from './viewData';

const sec = brand.labels.sections;

// Steht bei den Daten der Ansichten (viewData.ts): Das Vorabladen braucht es auch.
export { birthdaysVisible } from './viewData';

function whenText(b: BirthdayEntry): string {
  if (b.days_until === 1) return t('Morgen');
  return formatWeekdayDateYear(parseLocalDate(b.date));
}

function BirthdayRow({ b }: { b: BirthdayEntry }) {
  const date = parseLocalDate(b.date);
  return (
    <li className={`card birthday-item ${b.today ? 'birthday-today' : ''}`}>
      <div className="date-badge" aria-hidden="true">
        <span className="day">{date.getDate()}</span>
        <span className="mon">{shortMonth(date)}</span>
      </div>
      <div className="birthday-main">
        <div className="birthday-name">{b.name}</div>
        <div className="birthday-when">
          {whenText(b)}
          {b.age !== null && ` · ${t('wird {age}', { age: b.age })}`}
        </div>
      </div>
      {b.today && <span className="badge birthday-badge">{t('Heute')}</span>}
    </li>
  );
}

function noneText(days: number): string {
  if (days === 0) return t('Heute hat niemand Geburtstag.');
  if (days === 1) return t('Heute und morgen hat niemand Geburtstag.');
  return t('In den nächsten {days} Tagen hat niemand Geburtstag.', { days });
}

/**
 * Karte „Anstehende Geburtstage“ oben bei den Terminen: heute und die nächsten N Tage (stellt ein Admin ein), heute
 * hervorgehoben. Ohne anstehende erscheint sie nicht (außer mit `always`, wenn es sonst nichts zu sehen gibt).
 */
export function UpcomingBirthdays({ always = false }: { always?: boolean }) {
  return <UpcomingBirthdaysCard state={useUpcomingBirthdays(true)} always={always} />;
}

/** Lädt die anstehenden Geburtstage (nur mit `on`), z. B. gleichzeitig mit den Terminen. */
export function useUpcomingBirthdays(on: boolean) {
  const s = sources.upcomingBirthdays;
  return useAsync(() => (on ? s.load() : Promise.resolve(null)), s.deps, s.key);
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
    <section className="card birthdays-upcoming" aria-label={t('Anstehende {birthdays}', { birthdays: sec.birthdays })}>
      <h2 className="birthdays-title">{t('Anstehende {birthdays}', { birthdays: sec.birthdays })}</h2>
      {data.length === 0 ? (
        <p className="muted small">{noneText(upcomingBirthdayDays())}</p>
      ) : (
        <ul className="card-list birthday-list">
          {data.map((b) => (
            <BirthdayRow key={b.user_id} b={b} />
          ))}
        </ul>
      )}
    </section>
  );
}
