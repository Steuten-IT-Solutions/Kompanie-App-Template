import { useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { api, can, type PassiveBirthday, type PassiveInput, type PassiveList, type PassiveMember, type PassivePayment } from '../api';
import { addressInput, EMPTY_ADDRESS, formatAddress, mapLinkTarget, mapPlatform, mapUrl, type Address } from '../address';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { formatBirthday } from '../birthdays';
import { formatDate, formatEuro, formatWeekdayDateYear, parseEuroToCents, parseLocalDate, shortMonth, todayYmd } from '../format';
import { AddressFields, EmailField, Empty, ErrorBox, Loading, PhoneField, useAsync } from '../ui';
import { IconButton } from '../icons';
import { confirmAsk } from '../ask';
import { parseAges } from '../passiveAges';
import { navigate, redirect as redirectTo } from '../router';
import { joinedBy, joinYear } from '../passiveDue';
import { neighbors, useSwipeDrag } from '../swipe';

const NAME_MAX = 100;
/** Höchstlängen von Eintritt und Bemerkung (wie auf dem Server) */
const JOINED_MAX = 40;
const NOTE_MAX = 500;
/** Höchster Betrag einer Zahlung (wie auf dem Server) */
const MAX_CENTS = 100000;

/** Höchstlänge der Bezeichnung einer Zahlungsart (wie auf dem Server) */
const METHOD_MAX = 40;
const METHODS_MAX = 20;

/** Betrag in Cent als Text fürs Eingabefeld, z. B. 2400 → „24,00“ */
function centsText(cents: number): string {
  return (cents / 100).toFixed(2).replace('.', ',');
}

/** Betrag aus dem Feld prüfen: Cent oder Fehlermeldung */
function parseAmount(text: string): number | string {
  const cents = parseEuroToCents(text);
  if (cents === null || cents <= 0) return 'Bitte einen gültigen Betrag angeben, z. B. 24,00.';
  if (cents > MAX_CENTS) return 'Der Betrag darf höchstens 1.000 € sein.';
  return cents;
}

/** Zahlung eines Jahres oder undefined */
function paymentOf(p: PassiveMember, year: number): PassivePayment | undefined {
  return p.payments?.find((x) => x.year === year);
}

/** Stand des Beitrags eines Jahres: bezahlt, offen, noch nicht fällig (künftiges Jahr) oder noch nicht dabei (vor dem Eintritt). */
type FeeState = 'paid' | 'open' | 'later' | 'not-joined';

function feeState(p: PassiveMember, year: number, current: number): FeeState {
  if (paymentOf(p, year)) return 'paid';
  if (!joinedBy(p.joined, year)) return 'not-joined';
  return year > current ? 'later' : 'open';
}

/** „24,00 €, bezahlt am 03.02.2026 (Bar)“, „offen“, „noch nicht fällig“ bzw. „noch nicht dabei (Eintritt 2027)“ */
function FeeText({ p, year, current }: { p: PassiveMember; year: number; current: number }) {
  const payment = paymentOf(p, year);
  const state = feeState(p, year, current);
  if (payment)
    return (
      <span>
        <span className="pos">{formatEuro(payment.amount_cents)}</span>, bezahlt am {formatDate(payment.paid_on)}
        {payment.method ? ` (${payment.method})` : ''}
      </span>
    );
  if (state === 'not-joined') return <span className="muted">noch nicht dabei (Eintritt {joinYear(p.joined)})</span>;
  if (state === 'later') return <span className="muted">noch nicht fällig</span>;
  return <span className="neg">offen</span>;
}

/** Zahlung eintragen oder ändern: Betrag (Vorschlag: Jahresbeitrag) und Datum (Vorschlag: heute). */
function PaymentForm({
  year,
  payment,
  feeCents,
  methods,
  busy,
  onSave,
  onDelete,
  onCancel,
}: {
  year: number;
  payment: PassivePayment | undefined;
  feeCents: number;
  methods: string[];
  busy: boolean;
  onSave: (amountCents: number, paidOn: string, method: string) => void;
  onDelete: () => void;
  onCancel: () => void;
}) {
  const [amount, setAmount] = useState(payment ? centsText(payment.amount_cents) : feeCents > 0 ? centsText(feeCents) : '');
  const [date, setDate] = useState(payment?.paid_on ?? todayYmd());
  const [method, setMethod] = useState(payment?.method ?? (methods.length === 1 ? methods[0] : ''));
  // Eine frühere Zahlungsart, die nicht mehr zur Auswahl steht, bleibt beim Ändern wählbar
  const options = payment?.method && !methods.includes(payment.method) ? [...methods, payment.method] : methods;
  const [error, setError] = useState<string | null>(null);
  function submit(e: FormEvent) {
    e.preventDefault();
    const cents = parseAmount(amount);
    if (typeof cents === 'string') return setError(cents);
    if (method === '') return setError('Bitte die Zahlungsart wählen.');
    setError(null);
    onSave(cents, date, method);
  }
  return (
    <form className="form passive-payment-form" onSubmit={submit}>
      <h4>Beitrag {year}</h4>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="field-row">
        <label className="field">
          <span>Betrag in €</span>
          <input
            type="text"
            inputMode="decimal"
            placeholder="24,00"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            required
            autoFocus
          />
        </label>
        <label className="field">
          <span>Bezahlt am</span>
          <input type="date" min="1900-01-01" max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} required />
        </label>
      </div>
      <label className="field">
        <span>Zahlungsart</span>
        <select value={method} onChange={(e) => setMethod(e.target.value)} required>
          <option value="">bitte wählen</option>
          {options.map((m) => (
            <option key={m} value={m}>
              {m}
            </option>
          ))}
        </select>
      </label>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel} disabled={busy}>
          Abbrechen
        </button>
        {payment && (
          <button className="btn btn-danger" type="button" onClick={onDelete} disabled={busy}>
            Zahlung löschen
          </button>
        )}
      </div>
    </form>
  );
}

/** Einstellungen der Beiträge: Jahresbeitrag als Vorschlag und Zahlungsarten zur Auswahl, anzeigen und ändern. */
function PaymentSettings({
  feeCents,
  methods,
  busy,
  onSave,
}: {
  feeCents: number;
  methods: string[];
  busy: boolean;
  onSave: (data: { fee_cents: number; methods: string[] }) => Promise<boolean>;
}) {
  const [editing, setEditing] = useState(false);
  const [amount, setAmount] = useState('');
  const [names, setNames] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  async function submit(e: FormEvent) {
    e.preventDefault();
    let cents = 0;
    if (amount.trim() !== '') {
      const parsed = parseAmount(amount);
      if (typeof parsed === 'string') return setError(parsed);
      cents = parsed;
    }
    const list = names.map((n) => n.trim()).filter((n) => n !== '');
    if (list.length === 0) return setError('Bitte mindestens eine Zahlungsart angeben.');
    const lower = list.map((n) => n.toLowerCase());
    const twice = list.find((_, i) => lower.indexOf(lower[i]) !== i);
    if (twice) return setError(`Die Zahlungsart „${twice}“ steht doppelt in der Liste.`);
    setError(null);
    if (await onSave({ fee_cents: cents, methods: list })) setEditing(false);
  }
  if (!editing) {
    return (
      <p className="small passive-fee-setting">
        Jahresbeitrag (Vorschlag): {feeCents > 0 ? formatEuro(feeCents) : <span className="muted">keiner</span>}
        <br />
        Zahlungsarten: {methods.join(', ')}{' '}
        <button
          type="button"
          className="link-button"
          onClick={() => {
            setAmount(feeCents > 0 ? centsText(feeCents) : '');
            setNames(methods.length > 0 ? methods : ['']);
            setError(null);
            setEditing(true);
          }}
        >
          Ändern
        </button>
      </p>
    );
  }
  return (
    <form className="form card" onSubmit={submit}>
      <h3>Beiträge einrichten</h3>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <label className="field">
        <span>Jahresbeitrag in € (Vorschlag)</span>
        <input type="text" inputMode="decimal" placeholder="24,00" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
      </label>
      <p className="small muted">Steht beim Eintragen einer Zahlung schon im Feld und lässt sich dort ändern. Leer heißt kein Vorschlag.</p>
      <div className="field poll-options-edit">
        <span>Zahlungsarten</span>
        {names.map((n, i) => (
          <div className="poll-option-edit" key={i}>
            <input
              type="text"
              aria-label={`Zahlungsart ${i + 1}`}
              value={n}
              maxLength={METHOD_MAX}
              onChange={(e) => setNames(names.map((x, j) => (j === i ? e.target.value : x)))}
            />
            {names.length > 1 && (
              <button
                type="button"
                className="btn btn-link"
                aria-label={`Zahlungsart ${i + 1} entfernen`}
                onClick={() => setNames(names.filter((_, j) => j !== i))}
              >
                ×
              </button>
            )}
          </div>
        ))}
        {names.length < METHODS_MAX && (
          <button type="button" className="btn btn-link poll-add-option" onClick={() => setNames([...names, ''])}>
            + Zahlungsart
          </button>
        )}
      </div>
      <p className="small muted">Frühere Zahlungen behalten ihre Zahlungsart, auch wenn du sie hier entfernst.</p>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => setEditing(false)} disabled={busy}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/** Höchste Tage vorher (wie auf dem Server) */
const BIRTHDAY_DAYS_MAX = 30;

/** Einstellung der Erinnerung an Geburtstage: Alter (leer = aus) und Tage vorher, anzeigen und ändern. */
function BirthdaySettings({
  ages,
  days,
  busy,
  onSave,
}: {
  ages: number[];
  days: number;
  busy: boolean;
  onSave: (data: { birthday_ages: number[]; birthday_days: number }) => Promise<boolean>;
}) {
  const [editing, setEditing] = useState(false);
  const [agesText, setAgesText] = useState('');
  const [daysInput, setDaysInput] = useState('');
  const [error, setError] = useState<string | null>(null);
  async function submit(e: FormEvent) {
    e.preventDefault();
    const list = parseAges(agesText);
    if (typeof list === 'string') return setError(list);
    const n = Number(daysInput);
    if (!/^\d+$/.test(daysInput.trim()) || n > BIRTHDAY_DAYS_MAX) return setError(`Bitte 0 bis ${BIRTHDAY_DAYS_MAX} Tage vorher angeben.`);
    setError(null);
    if (await onSave({ birthday_ages: list, birthday_days: n })) setEditing(false);
  }
  if (!editing) {
    return (
      <p className="small passive-birthday-setting">
        Erinnerung an Geburtstage:{' '}
        {ages.length > 0 ? (
          `mit ${ages.join(', ')} Jahren, ${days === 0 ? 'am Tag selbst' : days === 1 ? '1 Tag vorher' : `${days} Tage vorher`}`
        ) : (
          <span className="muted">aus</span>
        )}{' '}
        <button
          type="button"
          className="link-button"
          onClick={() => {
            setAgesText(ages.join(', '));
            setDaysInput(String(days));
            setError(null);
            setEditing(true);
          }}
        >
          {ages.length > 0 ? 'Ändern' : 'Einrichten'}
        </button>
      </p>
    );
  }
  return (
    <form className="form card" onSubmit={submit}>
      <h3>Erinnerung an Geburtstage</h3>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <label className="field">
        <span>Bei diesen Altern erinnern</span>
        <input
          type="text"
          inputMode="numeric"
          placeholder="z. B. 50, 60, 70, 75, 80"
          value={agesText}
          onChange={(e) => setAgesText(e.target.value)}
          autoFocus
        />
      </label>
      <p className="small muted">Mehrere Alter mit Komma trennen. Leer heißt keine Erinnerung.</p>
      <label className="field">
        <span>Tage vorher</span>
        <input type="number" min={0} max={BIRTHDAY_DAYS_MAX} value={daysInput} onChange={(e) => setDaysInput(e.target.value)} required />
      </label>
      <p className="small muted">
        0 heißt am Tag selbst. Erinnert werden alle, die Passive verwalten und persönliche Angaben sehen dürfen, per Benachrichtigung und
        oben in dieser Liste.
      </p>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => setEditing(false)} disabled={busy}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/** Hinweis: anstehende Geburtstage der eingestellten Alter (wie „Anstehende Geburtstage“ bei den Terminen) */
function UpcomingBirthdays({ birthdays }: { birthdays: PassiveBirthday[] }) {
  if (birthdays.length === 0) return null;
  return (
    <section className="card birthdays-upcoming passive-birthdays" aria-label="Anstehende Geburtstage">
      <h2 className="birthdays-title">Anstehende Geburtstage</h2>
      <ul className="card-list birthday-list">
        {birthdays.map((b) => {
          const date = parseLocalDate(b.date);
          return (
            <li key={b.id} className={`card birthday-item ${b.days === 0 ? 'birthday-today' : ''}`}>
              <div className="date-badge" aria-hidden="true">
                <span className="day">{date.getDate()}</span>
                <span className="mon">{shortMonth(date)}</span>
              </div>
              <div className="birthday-main">
                <div className="birthday-name">{b.name}</div>
                <div className="birthday-when">
                  {b.days === 1 ? 'Morgen' : formatWeekdayDateYear(date)} · wird {b.age}
                </div>
              </div>
              {b.days === 0 && <span className="badge birthday-badge">Heute</span>}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

/** Geburtsdatum JJJJ-MM-TT als TT.MM.JJJJ */
function birthText(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  return formatBirthday({ day: d, month: m, year: y });
}

/**
 * Angaben eines Passiven zum Lesen: Adresse (Antippen: Karten-App), Telefon, E-Mail, Geburtsdatum. Nur die Felder, die der
 * Server geschickt hat (persönliche Angaben nur mit view_personal bzw. für den eigenen Betreuer).
 */
function PassiveDetails({ p }: { p: PassiveMember }) {
  const extra = (
    <>
      {p.joined && (
        <>
          <dt>Eintritt</dt>
          <dd>{p.joined}</dd>
        </>
      )}
      {p.note && (
        <>
          <dt>Bemerkung</dt>
          <dd className="passive-note">{p.note}</dd>
        </>
      )}
    </>
  );
  if (p.email === undefined && p.phone === undefined && p.address === undefined && p.birth_date === undefined) {
    return p.joined || p.note ? <dl className="contact-list passive-details">{extra}</dl> : null;
  }
  const lines = formatAddress(p.address ?? null);
  const map = mapUrl(p.address ?? null, mapPlatform());
  const none = <span className="muted">nicht hinterlegt</span>;
  return (
    <dl className="contact-list passive-details">
      <dt>Adresse</dt>
      <dd className="address-lines">
        {map ? (
          <a href={map} {...mapLinkTarget(map)}>
            {lines.join('\n')}
          </a>
        ) : lines.length > 0 ? (
          lines.join('\n')
        ) : (
          none
        )}
      </dd>
      <dt>Telefon</dt>
      <dd>{p.phone ? <a href={`tel:${p.phone.replace(/[^\d+]/g, '')}`}>{p.phone}</a> : none}</dd>
      <dt>E-Mail-Adresse</dt>
      <dd>{p.email ? <a href={`mailto:${p.email}`}>{p.email}</a> : none}</dd>
      <dt>Geburtsdatum</dt>
      <dd>{p.birth_date ? birthText(p.birth_date) : none}</dd>
      {extra}
    </dl>
  );
}

interface Draft {
  first_name: string;
  last_name: string;
  caretaker_id: string;
  address: Address;
  phone: string;
  email: string;
  birth_date: string;
  joined: string;
  note: string;
}

function draftOf(p: PassiveMember | null): Draft {
  return {
    first_name: p?.first_name ?? '',
    last_name: p?.last_name ?? '',
    caretaker_id: p?.caretaker ? String(p.caretaker.id) : '',
    address: p?.address ?? EMPTY_ADDRESS,
    phone: p?.phone ?? '',
    email: p?.email ?? '',
    birth_date: p?.birth_date ?? '',
    joined: p?.joined ?? '',
    note: p?.note ?? '',
  };
}

/** Was an den Server geht: Namen und Betreuer immer, persönliche Angaben nur mit manage_personal und nur, was sich geändert hat. */
function inputOf(d: Draft, before: Draft | null, personal: boolean): PassiveInput {
  const out: PassiveInput = {
    first_name: d.first_name.trim(),
    last_name: d.last_name.trim(),
    caretaker_id: d.caretaker_id === '' ? null : Number(d.caretaker_id),
    joined: d.joined.trim(),
    note: d.note.trim(),
  };
  if (!personal) return out;
  const address = addressInput(d.address);
  const phone = normalizePhone(d.phone);
  const email = normalizeEmail(d.email);
  const birth = d.birth_date === '' ? null : d.birth_date;
  if (!before || JSON.stringify(address) !== JSON.stringify(addressInput(before.address))) out.address = address;
  if (!before || phone !== normalizePhone(before.phone)) out.phone = phone;
  if (!before || email !== normalizeEmail(before.email)) out.email = email;
  if (!before || birth !== (before.birth_date === '' ? null : before.birth_date)) out.birth_date = birth;
  return out;
}

/** Formular zum Anlegen und Bearbeiten. */
function PassiveForm({
  title,
  start,
  list,
  busy,
  onSave,
  onCancel,
}: {
  title: string;
  start: PassiveMember | null;
  list: PassiveList;
  busy: boolean;
  onSave: (input: PassiveInput) => void;
  onCancel: () => void;
}) {
  const [before] = useState(() => (start ? draftOf(start) : null));
  const [d, setD] = useState<Draft>(() => draftOf(start));
  const set = (patch: Partial<Draft>) => setD({ ...d, ...patch });
  // Ein Betreuer, der kein Mitglied mehr ist (z. B. ehemalig), bleibt wählbar, bis jemand anders gewählt wird.
  const options =
    start?.caretaker && !list.caretakers.some((c) => c.id === start.caretaker!.id)
      ? [...list.caretakers, start.caretaker]
      : list.caretakers;
  function submit(e: FormEvent) {
    e.preventDefault();
    onSave(inputOf(d, before, list.manage_personal));
  }
  return (
    <form className="form card" onSubmit={submit}>
      <h3>{title}</h3>
      <label className="field">
        <span>Vorname</span>
        <input
          type="text"
          autoComplete="off"
          maxLength={NAME_MAX}
          value={d.first_name}
          onChange={(e) => set({ first_name: e.target.value })}
          required
          autoFocus
        />
      </label>
      <label className="field">
        <span>Nachname</span>
        <input
          type="text"
          autoComplete="off"
          maxLength={NAME_MAX}
          value={d.last_name}
          onChange={(e) => set({ last_name: e.target.value })}
          required
        />
      </label>
      <label className="field">
        <span>Betreuer (optional)</span>
        <select value={d.caretaker_id} onChange={(e) => set({ caretaker_id: e.target.value })}>
          <option value="">kein Betreuer</option>
          {options.map((c) => (
            <option key={c.id} value={String(c.id)}>
              {c.name}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span>Eintritt (optional)</span>
        <input
          type="text"
          autoComplete="off"
          maxLength={JOINED_MAX}
          placeholder="z. B. 2023 oder vor 2014"
          value={d.joined}
          onChange={(e) => set({ joined: e.target.value })}
        />
      </label>
      <label className="field">
        <span>Bemerkung (optional)</span>
        <textarea maxLength={NOTE_MAX} value={d.note} onChange={(e) => set({ note: e.target.value })} />
      </label>
      {list.manage_personal ? (
        <>
          <AddressFields value={d.address} onChange={(address) => set({ address })} />
          <PhoneField label="Telefon (optional)" placeholder="z. B. 02151 123456" value={d.phone} onChange={(phone) => set({ phone })} />
          <EmailField value={d.email} onChange={(email) => set({ email })} />
          <label className="field">
            <span>Geburtsdatum (optional)</span>
            <input
              type="date"
              min="1900-01-01"
              max={todayYmd()}
              value={d.birth_date}
              onChange={(e) => set({ birth_date: e.target.value })}
            />
          </label>
        </>
      ) : (
        <p className="small muted">Adresse, Telefon, E-Mail und Geburtsdatum trägt ein, wer persönliche Angaben pflegen darf.</p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? 'Speichert …' : 'Speichern'}
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel} disabled={busy}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/** Liste, aus der ein Passiver geöffnet wurde: Verwaltung → Passive oder Meins → Meine Passiven. */
type PassiveBase = 'verwaltung/passive' | 'meins/passive';

/** Reihenfolge der Liste beim Öffnen (gefiltert und sortiert): Auf der Karte blättert man darin weiter. */
let browse: { base: PassiveBase; ids: number[] } | null = null;

/** Suche und Filter der Liste in der Verwaltung, gemerkt bis zum Neuladen (zurück von der Karte steht die Liste wie zuvor). */
let listState: { query: string; year: number | null; show: 'all' | 'open' | 'paid'; filtersOpen: boolean } = {
  query: '',
  year: null,
  show: 'all',
  filtersOpen: false,
};

/** Liste nur mit Namen; Antippen öffnet die Karte mit allen Angaben (wie bei den Mitgliedern). */
function NameList({ base, passives }: { base: PassiveBase; passives: PassiveMember[] }) {
  function open(id: number) {
    browse = { base, ids: passives.map((p) => p.id) };
    navigate(`${base}/${id}`);
  }
  return (
    <ul className="card-list passive-list">
      {passives.map((p) => (
        <li key={p.id} className="card row clickable member-row" onClick={() => open(p.id)}>
          <div className="row-main">
            <div className="row-title">
              {p.last_name}, {p.first_name}
            </div>
          </div>
          <span className="chevron" aria-hidden="true">
            ›
          </span>
        </li>
      ))}
    </ul>
  );
}

/**
 * Beiträge auf der Karte: das laufende Jahr und jedes Jahr mit einer Zahlung, neueste zuerst. Vor dem Eintritt ist der
 * Beitrag nicht offen. Wer Passive verwaltet, trägt Zahlungen ein (auch für ein anderes Jahr) und ändert sie.
 */
function Payments({
  p,
  current,
  editable,
  busy,
  paying,
  onPay,
  form,
}: {
  p: PassiveMember;
  current: number;
  editable: boolean;
  busy: boolean;
  paying: number | null;
  onPay: (year: number) => void;
  form: (year: number) => ReactNode;
}) {
  const [other, setOther] = useState('');
  const paidYears = (p.payments ?? []).map((x) => x.year);
  const shown = [...new Set([current, ...paidYears])].sort((a, b) => b - a);
  const joined = joinYear(p.joined);
  // Zum Eintragen: nächstes Jahr (Vorauszahlung) bis fünf Jahre zurück, ohne die schon gezeigten; Jahre vor dem Eintritt
  // bleiben wählbar (z. B. eine Vorauszahlung), sind aber gekennzeichnet
  const choices = Array.from({ length: 6 }, (_, i) => current + 1 - i).filter((y) => !shown.includes(y));
  return (
    <section className="card passive-payments" aria-label="Beiträge">
      <h3>Beiträge</h3>
      {joined !== null && joined > current && <p className="small muted">Noch nicht dabei: Der Beitrag ist erst ab {joined} fällig.</p>}
      <ul className="passive-payment-years">
        {shown.map((y) =>
          paying === y ? (
            <li key={y}>{form(y)}</li>
          ) : (
            <li key={y} className="passive-payment">
              {y}: <FeeText p={p} year={y} current={current} />{' '}
              {editable && (
                <button type="button" className="link-button" disabled={busy} onClick={() => onPay(y)}>
                  {paymentOf(p, y) ? 'Ändern' : 'Zahlung eintragen'}
                </button>
              )}
            </li>
          ),
        )}
        {paying !== null && !shown.includes(paying) && <li key={paying}>{form(paying)}</li>}
      </ul>
      {editable && paying === null && choices.length > 0 && (
        <div className="field-row passive-payment-other">
          <label className="field">
            <span>Zahlung für ein anderes Jahr</span>
            <select value={other} onChange={(e) => setOther(e.target.value)}>
              <option value="">Jahr wählen</option>
              {choices.map((y) => (
                <option key={y} value={y}>
                  {y}
                  {joined !== null && y < joined ? ' (vor dem Eintritt)' : ''}
                </option>
              ))}
            </select>
          </label>
          <button
            type="button"
            className="btn btn-secondary"
            disabled={busy || other === ''}
            onClick={() => {
              onPay(Number(other));
              setOther('');
            }}
          >
            Eintragen
          </button>
        </div>
      )}
    </section>
  );
}

/** Inhalt der Karte eines Passiven: Name, Betreuer, Beiträge und alle Angaben, die der Server geschickt hat. */
function CardBody({ p, caretaker, payments }: { p: PassiveMember; caretaker: boolean; payments: ReactNode }) {
  return (
    <>
      <h2 className="member-name member-name-centered">{p.name}</h2>
      {caretaker && <p className="muted small member-level">{p.caretaker ? `Betreuer: ${p.caretaker.name}` : 'ohne Betreuer'}</p>}
      {payments}
      <section className="card passive-card-details" aria-label="Angaben">
        <h3>Angaben</h3>
        <PassiveDetails p={p} />
        {!p.joined && !p.note && p.email === undefined && p.address === undefined && <p className="small muted">Keine weiteren Angaben.</p>}
      </section>
    </>
  );
}

/**
 * Karte eines Passiven (wie die Seite eines Mitglieds): oben zurück zur Liste und blättern (auch per Wischen, im Kreis),
 * darunter alle Angaben. In der Verwaltung mit Bearbeiten, Löschen und Zahlungen (manage_passive).
 */
function PassiveCard({
  base,
  id,
  passives,
  current,
  caretaker,
  render,
}: {
  base: PassiveBase;
  id: number;
  passives: PassiveMember[];
  current: number;
  caretaker: boolean;
  render: (p: PassiveMember) => ReactNode;
}) {
  const known = passives.map((p) => p.id);
  const order = browse && browse.base === base && browse.ids.includes(id) ? browse.ids.filter((x) => known.includes(x)) : known;
  const { prev, next, index } = neighbors(order, id, true);
  const show = (other: number | null) => other !== null && redirectTo(`${base}/${other}`);
  const root = useRef<HTMLDivElement>(null);
  const track = useRef<HTMLDivElement>(null);
  const swipe = useSwipeDrag(root, track, {
    canPrev: prev !== null,
    canNext: next !== null,
    onCommit: (step) => show(step === 1 ? next : prev),
  });
  const p = passives.find((x) => x.id === id);
  const back = base === 'meins/passive' ? '‹ Meine Passiven' : '‹ Alle Passiven';
  const nav = (position: number, live: boolean) => (
    <div className="member-nav">
      <button type="button" className="btn btn-link back" onClick={live ? () => navigate(base) : undefined}>
        {back}
      </button>
      {order.length > 1 && (
        <span className="member-step">
          <button
            type="button"
            className="btn btn-link"
            onClick={live ? () => swipe.slide(-1) : undefined}
            aria-label="Vorheriger Passiver"
          >
            ‹
          </button>
          <span className="muted small">
            {position} / {order.length}
          </span>
          <button type="button" className="btn btn-link" onClick={live ? () => swipe.slide(1) : undefined} aria-label="Nächster Passiver">
            ›
          </button>
        </span>
      )}
    </div>
  );
  const peek = (other: number | null, side: 'prev' | 'next') => {
    const o = passives.find((x) => x.id === other);
    if (!o) return null;
    return (
      <div className={`member-peek member-peek-${side}`} aria-hidden="true" ref={(el) => el?.setAttribute('inert', '')}>
        {nav(order.indexOf(o.id) + 1, false)}
        <CardBody
          p={o}
          caretaker={caretaker}
          payments={
            <section className="card passive-payments">
              <h3>Beiträge</h3>
              <p className="passive-payment">
                {current}: <FeeText p={o} year={current} current={current} />
              </p>
            </section>
          }
        />
      </div>
    );
  };
  if (!p)
    return (
      <div>
        {nav(0, true)}
        <Empty>Diesen Passiven gibt es nicht mehr.</Empty>
      </div>
    );
  return (
    <div className="member-detail passive-card" ref={root}>
      <div className={`member-track${swipe.peeking ? ' peeking' : ''}`} ref={track}>
        {swipe.peeking && peek(prev, 'prev')}
        {swipe.peeking && peek(next, 'next')}
        {nav(index + 1, true)}
        {render(p)}
      </div>
    </div>
  );
}

/** Verwaltung → Passive → Karte: alle Angaben, Beiträge eintragen, bearbeiten und löschen (manage_passive). */
export function PassiveDetail({ id, onToast }: { id: number; onToast: (msg: string) => void }) {
  const editable = can('manage_passive');
  const { data, error, loading, reload } = useAsync(() => api.passiveMembers(), [], 'passive-members');
  const [editing, setEditing] = useState(false);
  const [paying, setPaying] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  // Beim Blättern zu einem anderen Passiven offene Formulare schließen
  const [shownId, setShownId] = useState(id);
  if (shownId !== id) {
    setShownId(id);
    setEditing(false);
    setPaying(null);
    setFormError(null);
  }

  async function run(action: () => Promise<unknown>, msg: string, after?: () => void) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      setEditing(false);
      setPaying(null);
      after?.();
      reload();
    } catch (e) {
      setFormError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  const current = data.year ?? Number(todayYmd().slice(0, 4));
  const errorBox = formError && (
    <p className="form-error" role="alert">
      {formError}
    </p>
  );
  return (
    <PassiveCard
      base="verwaltung/passive"
      id={id}
      passives={data.passives}
      current={current}
      caretaker
      render={(p) =>
        editing ? (
          <>
            {errorBox}
            <PassiveForm
              title={p.name}
              start={p}
              list={data}
              busy={busy}
              onSave={(input) => void run(() => api.updatePassive(p.id, input), 'Gespeichert')}
              onCancel={() => {
                setEditing(false);
                setFormError(null);
              }}
            />
          </>
        ) : (
          <>
            {paying === null && errorBox}
            <CardBody
              p={p}
              caretaker
              payments={
                <Payments
                  p={p}
                  current={current}
                  editable={editable}
                  busy={busy}
                  paying={paying}
                  onPay={(y) => {
                    setFormError(null);
                    setPaying(y);
                  }}
                  form={(year) => (
                    <>
                      {errorBox}
                      <PaymentForm
                        year={year}
                        payment={paymentOf(p, year)}
                        feeCents={data.fee_cents ?? 0}
                        methods={data.methods ?? []}
                        busy={busy}
                        onSave={(amount_cents, paid_on, method) => {
                          const payment = paymentOf(p, year);
                          void run(
                            () =>
                              payment
                                ? api.updatePassivePayment(payment.id, { amount_cents, paid_on, method })
                                : api.addPassivePayment(p.id, { year, amount_cents, paid_on, method }),
                            'Zahlung gespeichert',
                          );
                        }}
                        onDelete={async () => {
                          const payment = paymentOf(p, year);
                          if (payment && (await confirmAsk(`Zahlung ${year} von „${p.name}“ löschen?`)))
                            void run(() => api.deletePassivePayment(payment.id), 'Zahlung gelöscht');
                        }}
                        onCancel={() => {
                          setPaying(null);
                          setFormError(null);
                        }}
                      />
                    </>
                  )}
                />
              }
            />
            {editable && (
              <div className="row-actions passive-card-actions">
                <IconButton
                  icon="edit"
                  label="Bearbeiten"
                  disabled={busy}
                  onClick={() => {
                    setFormError(null);
                    setPaying(null);
                    setEditing(true);
                  }}
                />
                <IconButton
                  icon="delete"
                  label="Löschen"
                  variant="danger"
                  disabled={busy}
                  onClick={async () => {
                    if (await confirmAsk(`„${p.name}“ löschen?`))
                      void run(
                        () => api.deletePassive(p.id),
                        'Gelöscht',
                        () => navigate('verwaltung/passive'),
                      );
                  }}
                />
              </div>
            )}
          </>
        )
      }
    />
  );
}

/**
 * Verwaltung → Passive (Bereich passive, view_passive/manage_passive): Liste nur mit Namen, mit Suche und Filter nach dem
 * Beitrag; Antippen öffnet die Karte mit allen Angaben (PassiveDetail). Passive sind keine Konten und haben nie einen
 * Zugang. Adresse, Telefon, E-Mail und Geburtsdatum nur mit view_personal sehen und mit manage_personal ändern (der
 * Server prüft beides). Vor dem Eintrittsjahr ist der Beitrag nicht offen.
 */
export function PassiveAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const editable = can('manage_passive');
  const { data, error, loading, reload } = useAsync(() => api.passiveMembers(), [], 'passive-members');
  const [creating, setCreating] = useState(false);
  const [state, setState] = useState(listState);
  const update = (patch: Partial<typeof listState>) => {
    listState = { ...state, ...patch };
    setState(listState);
  };
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      setCreating(false);
      reload();
      return true;
    } catch (e) {
      setFormError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  const passives = useMemo(() => data?.passives ?? [], [data]);
  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  const q = state.query.trim().toLowerCase();
  const current = data.year ?? Number(todayYmd().slice(0, 4));
  const year = state.year ?? current;
  const feeCents = data.fee_cents ?? 0;
  const methods = data.methods ?? [];
  // Wählbar: nächstes Jahr (Vorauszahlung) bis fünf Jahre zurück, dazu jedes Jahr mit einer Zahlung
  const paidYears = passives.flatMap((p) => (p.payments ?? []).map((x) => x.year));
  const oldest = Math.min(current - 4, ...paidYears);
  const years = Array.from({ length: current + 1 - oldest + 1 }, (_, i) => current + 1 - i);
  const states = passives.map((p) => feeState(p, year, current));
  const paidCount = states.filter((s) => s === 'paid').length;
  const openCount = states.filter((s) => s === 'open').length;
  const notJoined = states.filter((s) => s === 'not-joined').length;
  const list = passives.filter(
    (p) =>
      (q === '' || `${p.first_name} ${p.last_name} ${p.caretaker?.name ?? ''}`.toLowerCase().includes(q)) &&
      (state.show === 'all' || (state.show === 'paid' ? paymentOf(p, year) !== undefined : feeState(p, year, current) === 'open')),
  );
  const filtered = state.show !== 'all' || year !== current;
  const errorBox = formError && (
    <p className="form-error" role="alert">
      {formError}
    </p>
  );

  return (
    <div>
      <p className="small muted member-counts" role="status">
        {passives.length === 1 ? '1 Passiver' : `${passives.length} Passive`}
        {passives.length > 0 &&
          `, Beitrag ${year}: ${paidCount} bezahlt, ${openCount} ${year > current ? 'noch nicht fällig' : 'offen'}` +
            (notJoined > 0 ? `, ${notJoined} noch nicht dabei` : '')}
      </p>
      {!creating && errorBox}
      <UpcomingBirthdays birthdays={data.birthdays ?? []} />
      {editable && (
        <PaymentSettings
          feeCents={feeCents}
          methods={methods}
          busy={busy}
          onSave={(settings) => run(() => api.updatePassiveSettings(settings), 'Gespeichert')}
        />
      )}
      {editable && data.birthday_ages !== undefined && (
        <BirthdaySettings
          ages={data.birthday_ages}
          days={data.birthday_days ?? 7}
          busy={busy}
          onSave={(settings) => run(() => api.updatePassiveSettings(settings), 'Gespeichert')}
        />
      )}
      {!editable ? null : creating ? (
        <>
          {errorBox}
          <PassiveForm
            title="Neuer Passiver"
            start={null}
            list={data}
            busy={busy}
            onSave={(input) => void run(() => api.createPassive(input), 'Passiver angelegt')}
            onCancel={() => {
              setCreating(false);
              setFormError(null);
            }}
          />
        </>
      ) : (
        <div className="button-row add-member">
          <button
            className="btn"
            onClick={() => {
              setFormError(null);
              setCreating(true);
            }}
          >
            + Passiven anlegen
          </button>
          <button type="button" className="btn btn-secondary" onClick={() => navigate('verwaltung/passive/import')}>
            Tabelle importieren
          </button>
        </div>
      )}
      {passives.length > 0 && (
        <>
          <input
            className="search"
            type="search"
            placeholder="Passiven suchen …"
            value={state.query}
            onChange={(e) => update({ query: e.target.value })}
            aria-label="Passiven suchen"
          />
          <button
            type="button"
            className="member-filters-toggle"
            aria-expanded={state.filtersOpen}
            aria-controls="passive-filters"
            onClick={() => update({ filtersOpen: !state.filtersOpen })}
          >
            Filter nach Beitrag
            {filtered && <span className="badge badge-role">aktiv</span>}
            <span className="member-filters-arrow" aria-hidden="true">
              ›
            </span>
          </button>
          <div id="passive-filters" className="member-filters field-row passive-filter" hidden={!state.filtersOpen}>
            <label className="field">
              <span>Beitrag für</span>
              <select value={year} onChange={(e) => update({ year: Number(e.target.value) })}>
                {years.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Zeigen</span>
              <select value={state.show} onChange={(e) => update({ show: e.target.value as 'all' | 'open' | 'paid' })}>
                <option value="all">alle</option>
                <option value="open">nur offen</option>
                <option value="paid">nur bezahlt</option>
              </select>
            </label>
          </div>
        </>
      )}
      {passives.length === 0 ? (
        <Empty>Noch keine Passiven eingetragen.</Empty>
      ) : list.length === 0 ? (
        <Empty>Niemand gefunden.</Empty>
      ) : (
        <NameList base="verwaltung/passive" passives={list} />
      )}
    </div>
  );
}

/**
 * Meins → Meine Passiven: die Passiven, die die Person betreut, als Liste mit Namen; Antippen öffnet die Karte mit allen
 * Angaben und den Beiträgen, nur zum Lesen.
 */
export function MyPassives({ focus }: { focus?: string }) {
  const { data, error, loading, reload } = useAsync(() => api.myPassives(), [], 'my-passives');
  const current = Number(todayYmd().slice(0, 4));
  if (loading && !data) return <Loading shape="list" />;
  if (error || !data) return <ErrorBox message={error ?? 'Deine Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  if (focus && /^\d+$/.test(focus)) {
    return (
      <PassiveCard
        base="meins/passive"
        id={Number(focus)}
        passives={data}
        current={current}
        caretaker={false}
        render={(p) => (
          <CardBody
            p={p}
            caretaker={false}
            payments={
              p.payments && (
                <Payments p={p} current={current} editable={false} busy={false} paying={null} onPay={() => {}} form={() => null} />
              )
            }
          />
        )}
      />
    );
  }
  if (data.length === 0) return <Empty>Du betreust gerade keine Passiven.</Empty>;
  return (
    <div>
      <p className="small muted">
        {data.length === 1 ? 'Du betreust einen Passiven.' : `Du betreust ${data.length} Passive.`} Ändern kann die Angaben, wer die
        Passiven verwaltet.
      </p>
      <NameList base="meins/passive" passives={data} />
    </div>
  );
}
