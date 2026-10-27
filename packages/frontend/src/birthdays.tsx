// Geburtstage (Issue #1): die ganze Liste unter #/termine/geburtstage, der eigene unter „Mein Konto“, die Pflege in
// den Mitgliedsdetails (wer Mitglieder anlegen darf). Erst bei Bedarf geladen; die anstehenden bei den Terminen stehen
// in upcomingBirthdays.tsx.

import { useState, type FormEvent } from 'react';
import { api, can, config, type Birthday, type BirthdayEntry, type ProfileVisibility } from './api';
import { PROFILE_OPTIONS, profileChoice, profileHint, ProfileSelect } from './joinYear';
import { BirthdayRow } from './upcomingBirthdays';
import brand from './generated/brand';
import { formatMonth, parseLocalDate } from './format';
import { navigate } from './router';
import { Empty, ErrorBox, Loading, useAsync } from './ui';

export { birthdaysVisible } from './upcomingBirthdays';
export { ownBirthday } from './viewData';

const sec = brand.labels.sections;

/** Das Datum anderer pflegen: wer Mitglieder anlegen darf. */
export function canSetBirthdays(): boolean {
  return !!config?.features.birthdays && can('create_users');
}

const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

const pad2 = (n: number) => String(n).padStart(2, '0');

/** „14.03.1990“ bzw. ohne Jahr „14.03.“ – wie Eintritt und Austritt (TT.MM.JJJJ) */
export function formatBirthday(b: { day: number; month: number; year?: number | null }): string {
  return `${pad2(b.day)}.${pad2(b.month)}.${b.year ?? ''}`;
}

/** Eingabe als Text, wie sie in den Feldern steht. */
export interface BirthdayValue {
  day: string;
  month: string;
  year: string;
}

export const emptyBirthday = (): BirthdayValue => ({ day: '', month: '', year: '' });

export const toBirthdayValue = (b: Birthday | null | undefined): BirthdayValue =>
  b ? { day: String(b.day), month: String(b.month), year: b.year ? String(b.year) : '' } : emptyBirthday();

/**
 * Prüft die Eingabe grob (der Server prüft genau): null = nichts eingetragen, Text = Fehlermeldung, sonst das Datum.
 */
export function readBirthday(v: BirthdayValue): { month: number; day: number; year: number | null } | null | string {
  if (!v.day && !v.month && !v.year.trim()) return null;
  if (!v.day || !v.month) return 'Bitte Tag und Monat des Geburtstags wählen.';
  const year = v.year.trim();
  if (year && !/^\d{4}$/.test(year)) return 'Das Geburtsjahr bitte vierstellig angeben (oder leer lassen).';
  const day = Number(v.day);
  const month = Number(v.month);
  // Ohne Jahr gilt der 29.02. (Maßstab: ein Schaltjahr).
  const check = new Date(year ? Number(year) : 2000, month - 1, day);
  if (check.getMonth() !== month - 1) return 'Dieses Datum gibt es nicht.';
  return { month, day, year: year ? Number(year) : null };
}

/** Für POST /users: „JJJJ-MM-TT“ oder ohne Jahr „MM-TT“; '' ohne Eintrag. */
export function birthdayString(b: { month: number; day: number; year: number | null }): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${b.year ? `${b.year}-` : ''}${pad(b.month)}-${pad(b.day)}`;
}

/** Tag, Monat und (freiwillig) Jahr: Auswahllisten statt Datumsfeld, damit das Jahr weggelassen werden kann. */
export function BirthdayFields({
  value,
  onChange,
  disabled = false,
}: {
  value: BirthdayValue;
  onChange: (v: BirthdayValue) => void;
  disabled?: boolean;
}) {
  return (
    <div className="field-row birthday-fields">
      <label className="field birthday-day">
        <span>Tag</span>
        <select value={value.day} disabled={disabled} onChange={(e) => onChange({ ...value, day: e.target.value })}>
          <option value="">–</option>
          {Array.from({ length: 31 }, (_, i) => (
            <option key={i + 1} value={i + 1}>
              {i + 1}.
            </option>
          ))}
        </select>
      </label>
      <label className="field birthday-month">
        <span>Monat</span>
        <select value={value.month} disabled={disabled} onChange={(e) => onChange({ ...value, month: e.target.value })}>
          <option value="">–</option>
          {MONTHS.map((m, i) => (
            <option key={m} value={i + 1}>
              {m}
            </option>
          ))}
        </select>
      </label>
      <label className="field birthday-year">
        <span>Jahr</span>
        <input
          type="text"
          inputMode="numeric"
          autoComplete="off"
          maxLength={4}
          placeholder="freiwillig"
          value={value.year}
          disabled={disabled}
          onChange={(e) => onChange({ ...value, year: e.target.value.replace(/\D/g, '') })}
        />
      </label>
    </div>
  );
}

/** Alle sichtbaren Geburtstage, ab heute nach Monaten gruppiert (#/termine/geburtstage). */
export default function BirthdayPage() {
  const { data, error, loading, reload } = useAsync(() => api.birthdays(), [], 'geburtstage');
  const back = (
    <button className="btn btn-link back" onClick={() => navigate('termine')}>
      ‹ {sec.events}
    </button>
  );
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const groups: BirthdayEntry[][] = [];
  for (const b of data ?? []) {
    const last = groups[groups.length - 1];
    if (last && last[0].date.slice(0, 7) === b.date.slice(0, 7)) last.push(b);
    else groups.push([b]);
  }
  return (
    <div className="birthdays-page">
      {back}
      <h2 className="birthdays-page-title">Alle {sec.birthdays}</h2>
      {groups.length === 0 && <Empty>Noch hat niemand seinen Geburtstag eingetragen (oder zeigt ihn anderen).</Empty>}
      {groups.map((list) => (
        <section key={list[0].date.slice(0, 7)} className="month">
          <h3 className="month-title">{formatMonth(parseLocalDate(list[0].date))}</h3>
          <ul className="card-list birthday-list">
            {list.map((b) => (
              <BirthdayRow key={b.user_id} b={b} />
            ))}
          </ul>
        </section>
      ))}
      <p className="muted small">
        Deinen eigenen Geburtstag trägst du unter „Meins → Konto“ ein; dort legst du auch fest, ob andere ihn sehen.
      </p>
    </div>
  );
}

/** „Mein Konto“: eigener Geburtstag mit Jahr und den beiden Schaltern. */
export function OwnBirthdayCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.myBirthday(), [], 'me/birthday');
  const [value, setValue] = useState<BirthdayValue | null>(null);
  const [visible, setVisible] = useState<boolean | null>(null);
  const [showAge, setShowAge] = useState<boolean | null>(null);
  const [profile, setProfile] = useState<ProfileVisibility | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Während des Ladens die Karte schon zeigen (gesperrt), damit sich die Seite danach nicht verschiebt.
  const pending = loading && !data;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const current = data?.birthday ?? null;
  const v = value ?? toBirthdayValue(current);
  const vis = visible ?? current?.visible ?? true;
  const age = showAge ?? current?.show_age ?? false;
  const prof = profile ?? current?.profile ?? 'members';
  const withProfile = profileChoice();

  const done = (birthday: Birthday | null, msg: string) => {
    setData({ birthday });
    setValue(null);
    setVisible(null);
    setShowAge(null);
    setProfile(null);
    onToast(msg);
  };

  async function save(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const parsed = readBirthday(v);
    if (parsed === null) return setFormError('Bitte Tag und Monat des Geburtstags wählen.');
    if (typeof parsed === 'string') return setFormError(parsed);
    setBusy(true);
    try {
      const res = await api.saveMyBirthday({ ...parsed, visible: vis, show_age: age, ...(withProfile ? { profile: prof } : {}) });
      done(res.birthday, 'Geburtstag gespeichert');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!window.confirm('Deinen Geburtstag entfernen?')) return;
    setBusy(true);
    try {
      await api.deleteMyBirthday();
      done(null, 'Geburtstag entfernt');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card account-card birthday-card" onSubmit={save}>
      <h3>Geburtstag</h3>
      <p className="muted small">
        Für die {sec.birthdays} bei den {sec.events} und eine Benachrichtigung der anderen am Tag selbst. Das Jahr ist freiwillig.
      </p>
      <BirthdayFields value={v} onChange={setValue} disabled={busy || pending} />
      <label className="toggle">
        <input type="checkbox" checked={vis} disabled={pending} onChange={(e) => setVisible(e.target.checked)} />
        Anderen Mitgliedern zeigen
      </label>
      <label className="toggle">
        <input type="checkbox" checked={age} disabled={pending} onChange={(e) => setShowAge(e.target.checked)} />
        Alter anzeigen{!v.year.trim() && ' (nur mit Jahr)'}
      </label>
      {withProfile && (
        <>
          <ProfileSelect value={prof} onChange={setProfile} disabled={pending} />
          {prof !== 'none' && (
            <p className="muted small">
              {profileHint(prof)} {age && v.year.trim() ? 'Dort steht das ganze Datum mit deinem Alter.' : 'Dort stehen nur Tag und Monat.'}
            </p>
          )}
        </>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy || pending}>
        {busy ? 'Speichert …' : 'Geburtstag speichern'}
      </button>
      {current && (
        <button type="button" className="btn btn-secondary btn-block birthday-remove" disabled={busy} onClick={remove}>
          Entfernen
        </button>
      )}
      {!vis && current && <p className="muted small">Andere sehen deinen Geburtstag nicht und werden nicht benachrichtigt.</p>}
    </form>
  );
}

/**
 * Mitgliedsdetails: Geburtstag ansehen; wer Mitglieder anlegen darf, setzt oder entfernt das Datum. Die Schalter stellt
 * nur die Person selbst ein, hier stehen sie nur zur Info.
 */
export function MemberBirthday({
  id,
  birthday,
  onChanged,
  onToast,
}: {
  id: number;
  birthday: Birthday | null;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [value, setValue] = useState<BirthdayValue>(() => toBirthdayValue(birthday));
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const editable = canSetBirthdays();

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function save(e: FormEvent) {
    e.preventDefault();
    const parsed = readBirthday(value);
    if (parsed === null) return setFormError('Bitte Tag und Monat des Geburtstags wählen.');
    if (typeof parsed === 'string') return setFormError(parsed);
    void run(() => api.setMemberBirthday(id, parsed), 'Geburtstag gespeichert');
  }

  return (
    <form className="member-birthday" onSubmit={save}>
      <h3 className="section-title">Geburtstag</h3>
      <div className="card member-birthday-card">
        <p className="member-birthday-current">{birthday ? formatBirthday(birthday) : 'Nicht eingetragen.'}</p>
        {birthday && (
          <p className="muted small member-birthday-switches">
            Anderen Mitgliedern zeigen: {birthday.visible ? 'an' : 'aus'} · Alter anzeigen: {birthday.show_age ? 'an' : 'aus'}
            {config?.features.directory &&
              birthday.profile &&
              ` · Auf der Seite unter „${sec.directory}“: ${PROFILE_OPTIONS[birthday.profile]}`}{' '}
            (stellt nur die Person selbst ein)
          </p>
        )}
        {editable && (
          <>
            <BirthdayFields value={value} onChange={setValue} disabled={busy} />
            {formError && (
              <p className="form-error" role="alert">
                {formError}
              </p>
            )}
            <div className="button-row">
              <button className="btn" type="submit" disabled={busy}>
                Geburtstag speichern
              </button>
              {birthday && (
                <button
                  type="button"
                  className="btn btn-secondary"
                  disabled={busy}
                  onClick={() =>
                    window.confirm('Geburtstag entfernen?') &&
                    run(async () => {
                      await api.deleteMemberBirthday(id);
                      setValue(toBirthdayValue(null));
                    }, 'Geburtstag entfernt')
                  }
                >
                  Entfernen
                </button>
              )}
            </div>
          </>
        )}
      </div>
    </form>
  );
}
