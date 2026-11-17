import { useState, type FormEvent } from 'react';
import { api, can, type PassiveInput, type PassiveList, type PassiveMember, type PassivePayment } from '../api';
import { addressInput, EMPTY_ADDRESS, formatAddress, mapLinkTarget, mapPlatform, mapUrl, type Address } from '../address';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { formatBirthday } from '../birthdays';
import { formatDate, formatEuro, parseEuroToCents, todayYmd } from '../format';
import { AddressFields, EmailField, Empty, ErrorBox, Loading, PhoneField, useAsync } from '../ui';
import { IconButton } from '../icons';
import { confirmAsk } from '../ask';
import { navigate } from '../router';

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

/** „Beitrag 2026: 24,00 €, bezahlt am 03.02.2026“ bzw. „Beitrag 2026: offen“ */
function PaymentLine({ year, payment }: { year: number; payment: PassivePayment | undefined }) {
  return payment ? (
    <span>
      Beitrag {year}: <span className="pos">{formatEuro(payment.amount_cents)}</span>, bezahlt am {formatDate(payment.paid_on)}
      {payment.method ? ` (${payment.method})` : ''}
    </span>
  ) : (
    <span>
      Beitrag {year}: <span className="neg">offen</span>
    </span>
  );
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

/**
 * Verwaltung → Passive (Bereich passive, manage_passive): Liste der Passiven mit Suche, anlegen, bearbeiten, löschen.
 * Passive sind keine Konten und haben nie einen Zugang. Adresse, Telefon, E-Mail und Geburtsdatum nur mit view_personal
 * sehen und mit manage_personal ändern (der Server prüft beides).
 */
export function PassiveAdmin({ onToast }: { onToast: (msg: string) => void }) {
  // Nur sehen (view_passive): Liste, Beiträge und Angaben; anlegen, ändern, Zahlungen und löschen mit manage_passive
  const editable = can('manage_passive');
  const { data, error, loading, reload } = useAsync(() => api.passiveMembers(), [], 'passive-members');
  const [editing, setEditing] = useState<number | 'new' | null>(null);
  const [paying, setPaying] = useState<number | null>(null);
  const [yearChoice, setYearChoice] = useState<number | null>(null);
  const [show, setShow] = useState<'all' | 'open' | 'paid'>('all');
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      setEditing(null);
      setPaying(null);
      reload();
      return true;
    } catch (e) {
      setFormError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  const q = query.trim().toLowerCase();
  const current = data.year ?? Number(todayYmd().slice(0, 4));
  const year = yearChoice ?? current;
  const feeCents = data.fee_cents ?? 0;
  const methods = data.methods ?? [];
  // Wählbar: nächstes Jahr (Vorauszahlung) bis fünf Jahre zurück, dazu jedes Jahr mit einer Zahlung
  const paidYears = data.passives.flatMap((p) => (p.payments ?? []).map((x) => x.year));
  const oldest = Math.min(current - 4, ...paidYears);
  const years = Array.from({ length: current + 1 - oldest + 1 }, (_, i) => current + 1 - i);
  const paidCount = data.passives.filter((p) => paymentOf(p, year)).length;
  const list = data.passives.filter(
    (p) =>
      (q === '' || `${p.first_name} ${p.last_name} ${p.caretaker?.name ?? ''}`.toLowerCase().includes(q)) &&
      (show === 'all' || (show === 'paid') === (paymentOf(p, year) !== undefined)),
  );
  const errorBox = formError && (
    <p className="form-error" role="alert">
      {formError}
    </p>
  );

  return (
    <div>
      <p className="small muted member-counts" role="status">
        {data.passives.length === 1 ? '1 Passiver' : `${data.passives.length} Passive`}
        {data.passives.length > 0 && `, Beitrag ${year}: ${paidCount} bezahlt, ${data.passives.length - paidCount} offen`}
      </p>
      {editing === null && paying === null && errorBox}
      {editable && (
        <PaymentSettings
          feeCents={feeCents}
          methods={methods}
          busy={busy}
          onSave={(settings) => run(() => api.updatePassiveSettings(settings), 'Gespeichert')}
        />
      )}
      {!editable ? null : editing === 'new' ? (
        <>
          {errorBox}
          <PassiveForm
            title="Neuer Passiver"
            start={null}
            list={data}
            busy={busy}
            onSave={(input) => run(() => api.createPassive(input), 'Passiver angelegt')}
            onCancel={() => {
              setEditing(null);
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
              setEditing('new');
            }}
          >
            + Passiven anlegen
          </button>
          <button type="button" className="btn btn-secondary" onClick={() => navigate('verwaltung/passive/import')}>
            Tabelle importieren
          </button>
        </div>
      )}
      {data.passives.length > 0 && (
        <>
          <div className="field-row passive-filter">
            <label className="field">
              <span>Beitrag für</span>
              <select
                value={year}
                onChange={(e) => {
                  setYearChoice(Number(e.target.value));
                  setPaying(null);
                }}
              >
                {years.map((y) => (
                  <option key={y} value={y}>
                    {y}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              <span>Zeigen</span>
              <select value={show} onChange={(e) => setShow(e.target.value as 'all' | 'open' | 'paid')}>
                <option value="all">alle</option>
                <option value="open">nur offen</option>
                <option value="paid">nur bezahlt</option>
              </select>
            </label>
          </div>
          <input
            className="search"
            type="search"
            placeholder="Passiven suchen …"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            aria-label="Passiven suchen"
          />
        </>
      )}
      {data.passives.length === 0 ? (
        <Empty>Noch keine Passiven eingetragen.</Empty>
      ) : list.length === 0 ? (
        <Empty>Niemand gefunden.</Empty>
      ) : (
        <ul className="card-list passive-list">
          {list.map((p) =>
            editing === p.id ? (
              <li key={p.id}>
                {errorBox}
                <PassiveForm
                  title={p.name}
                  start={p}
                  list={data}
                  busy={busy}
                  onSave={(input) => run(() => api.updatePassive(p.id, input), 'Gespeichert')}
                  onCancel={() => {
                    setEditing(null);
                    setFormError(null);
                  }}
                />
              </li>
            ) : (
              <li key={p.id} className="card">
                <div className="row-main">
                  <div className="row-title">
                    {p.last_name}, {p.first_name}
                  </div>
                  <div className="row-sub">{p.caretaker ? `Betreuer: ${p.caretaker.name}` : 'ohne Betreuer'}</div>
                  {paying === p.id ? (
                    <>
                      {errorBox}
                      <PaymentForm
                        year={year}
                        payment={paymentOf(p, year)}
                        feeCents={feeCents}
                        methods={methods}
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
                  ) : (
                    <div className="row-sub passive-payment">
                      <PaymentLine year={year} payment={paymentOf(p, year)} />{' '}
                      {editable && (
                        <button
                          type="button"
                          className="link-button"
                          disabled={busy}
                          onClick={() => {
                            setFormError(null);
                            setEditing(null);
                            setPaying(p.id);
                          }}
                        >
                          {paymentOf(p, year) ? 'Ändern' : 'Zahlung eintragen'}
                        </button>
                      )}
                    </div>
                  )}
                  <PassiveDetails p={p} />
                  {editable && (
                    <div className="row-actions">
                      <IconButton
                        icon="edit"
                        label="Bearbeiten"
                        disabled={busy}
                        onClick={() => {
                          setFormError(null);
                          setEditing(p.id);
                        }}
                      />
                      <IconButton
                        icon="delete"
                        label="Löschen"
                        variant="danger"
                        disabled={busy}
                        onClick={async () => (await confirmAsk(`„${p.name}“ löschen?`)) && run(() => api.deletePassive(p.id), 'Gelöscht')}
                      />
                    </div>
                  )}
                </div>
              </li>
            ),
          )}
        </ul>
      )}
    </div>
  );
}

/** Meins → Meine Passiven: die Passiven, die die Person betreut, mit allen Angaben, nur zum Lesen. */
export function MyPassives() {
  const { data, error, loading, reload } = useAsync(() => api.myPassives(), [], 'my-passives');
  const year = Number(todayYmd().slice(0, 4));
  if (loading && !data) return <Loading shape="list" />;
  if (error || !data) return <ErrorBox message={error ?? 'Deine Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  if (data.length === 0) return <Empty>Du betreust gerade keine Passiven.</Empty>;
  return (
    <div>
      <p className="small muted">
        {data.length === 1 ? 'Du betreust einen Passiven.' : `Du betreust ${data.length} Passive.`} Ändern kann die Angaben, wer die
        Passiven verwaltet.
      </p>
      <ul className="card-list passive-list">
        {data.map((p) => (
          <li key={p.id} className="card">
            <div className="row-main">
              <div className="row-title">
                {p.last_name}, {p.first_name}
              </div>
              {p.payments && (
                <div className="row-sub">
                  <PaymentLine year={year} payment={paymentOf(p, year)} />
                </div>
              )}
              <PassiveDetails p={p} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
