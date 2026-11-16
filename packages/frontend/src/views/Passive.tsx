import { useState, type FormEvent } from 'react';
import { api, type PassiveInput, type PassiveList, type PassiveMember } from '../api';
import { addressInput, EMPTY_ADDRESS, formatAddress, mapLinkTarget, mapPlatform, mapUrl, type Address } from '../address';
import { normalizeEmail } from '../email';
import { normalizePhone } from '../phone';
import { formatBirthday } from '../birthdays';
import { todayYmd } from '../format';
import { AddressFields, EmailField, Empty, ErrorBox, Loading, PhoneField, useAsync } from '../ui';
import { IconButton } from '../icons';
import { confirmAsk } from '../ask';

const NAME_MAX = 100;

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
  if (p.email === undefined && p.phone === undefined && p.address === undefined && p.birth_date === undefined) return null;
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
  };
}

/** Was an den Server geht: Namen und Betreuer immer, persönliche Angaben nur mit manage_personal und nur, was sich geändert hat. */
function inputOf(d: Draft, before: Draft | null, personal: boolean): PassiveInput {
  const out: PassiveInput = {
    first_name: d.first_name.trim(),
    last_name: d.last_name.trim(),
    caretaker_id: d.caretaker_id === '' ? null : Number(d.caretaker_id),
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
  const { data, error, loading, reload } = useAsync(() => api.passiveMembers(), [], 'passive-members');
  const [editing, setEditing] = useState<number | 'new' | null>(null);
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
      reload();
    } catch (e) {
      setFormError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading shape="people" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Passiven konnten nicht geladen werden.'} onRetry={reload} />;
  const q = query.trim().toLowerCase();
  const list = data.passives.filter(
    (p) => q === '' || `${p.first_name} ${p.last_name} ${p.caretaker?.name ?? ''}`.toLowerCase().includes(q),
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
      </p>
      {editing === 'new' ? (
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
        </div>
      )}
      {data.passives.length > 0 && (
        <input
          className="search"
          type="search"
          placeholder="Passiven suchen …"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label="Passiven suchen"
        />
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
                  <PassiveDetails p={p} />
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
              <PassiveDetails p={p} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
