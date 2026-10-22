// Seite einer Person in der Mitgliederliste: wem Geburtstag und Eintrittsjahr dort erscheinen (wählt nur die Person
// selbst), dazu das Eintrittsjahr unter „Mein Konto“ und in den Mitgliedsdetails (wer Mitglieder anlegen darf).

import { useState, type FormEvent } from 'react';
import { api, can, config, type JoinYear, type ProfileVisibility } from './api';
import brand from './generated/brand';
import { ErrorBox, useAsync } from './ui';

const sec = brand.labels.sections;

export const PROFILE_OPTIONS: Record<ProfileVisibility, string> = {
  none: 'Nicht anzeigen',
  members: 'Mitgliedern anzeigen',
  public: 'Öffentlich anzeigen',
};

/** Die Seite in der Mitgliederliste gibt es nur mit dem Bereich; wählen kann nur, wer Mitglied ist. */
export function profileChoice(): boolean {
  return !!config?.features.directory && can('member');
}

/** Auswahl, wem eine Angabe auf der eigenen Seite in der Mitgliederliste erscheint. */
export function ProfileSelect({
  value,
  onChange,
  disabled = false,
}: {
  value: ProfileVisibility;
  onChange: (v: ProfileVisibility) => void;
  disabled?: boolean;
}) {
  return (
    <label className="field profile-select">
      <span>Auf meiner Seite unter „{sec.directory}“</span>
      <select value={value} disabled={disabled} onChange={(e) => onChange(e.target.value as ProfileVisibility)}>
        {(Object.keys(PROFILE_OPTIONS) as ProfileVisibility[]).map((k) => (
          <option key={k} value={k}>
            {PROFILE_OPTIONS[k]}
          </option>
        ))}
      </select>
    </label>
  );
}

/** Hinweis zur gewählten Anzeige; „öffentlich“ heißt: für jeden mit der Adresse der App. */
export function profileHint(v: ProfileVisibility): string {
  return v === 'public'
    ? 'Öffentlich: Jeder, der die Adresse der App kennt, sieht es, auch ohne Anmeldung.'
    : v === 'members'
      ? 'Nur angemeldete Mitglieder sehen es.'
      : 'Niemand sieht es auf deiner Seite.';
}

const thisYear = () => new Date().getFullYear();

/** Grobe Prüfung (der Server prüft genau): Text = Fehlermeldung, sonst das Jahr. */
function readYear(v: string): number | string {
  const year = v.trim();
  if (!/^\d{4}$/.test(year)) return 'Bitte das Eintrittsjahr vierstellig angeben.';
  if (Number(year) > thisYear()) return 'Das Eintrittsjahr darf nicht in der Zukunft liegen.';
  return Number(year);
}

function YearField({ value, onChange, disabled }: { value: string; onChange: (v: string) => void; disabled: boolean }) {
  return (
    <label className="field join-year-field">
      <span>Jahr</span>
      <input
        type="text"
        inputMode="numeric"
        autoComplete="off"
        maxLength={4}
        placeholder="JJJJ"
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value.replace(/\D/g, ''))}
      />
    </label>
  );
}

/** „Mein Konto“: eigenes Eintrittsjahr und wem es auf der eigenen Seite erscheint. */
export function OwnJoinYearCard({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.myJoinYear(), [], 'me/join-year');
  const [value, setValue] = useState<string | null>(null);
  const [profile, setProfile] = useState<ProfileVisibility | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const pending = loading && !data;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const current = data?.join_year ?? null;
  const v = value ?? (current ? String(current.year) : '');
  const p = profile ?? current?.profile ?? 'none';

  const done = (joinYear: JoinYear | null, msg: string) => {
    setData({ join_year: joinYear });
    setValue(null);
    setProfile(null);
    onToast(msg);
  };

  async function save(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    const year = readYear(v);
    if (typeof year === 'string') return setFormError(year);
    setBusy(true);
    try {
      const res = await api.saveMyJoinYear({ year, profile: p });
      done(res.join_year, 'Eintrittsjahr gespeichert');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!window.confirm('Dein Eintrittsjahr entfernen?')) return;
    setBusy(true);
    try {
      await api.deleteMyJoinYear();
      done(null, 'Eintrittsjahr entfernt');
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card account-card join-year-card" onSubmit={save}>
      <h3>Eintritt</h3>
      <p className="muted small">Das Jahr, in dem du eingetreten bist.</p>
      <YearField value={v} onChange={setValue} disabled={busy || pending} />
      <ProfileSelect value={p} onChange={setProfile} disabled={busy || pending} />
      <p className="muted small">{profileHint(p)}</p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy || pending}>
        {busy ? 'Speichert …' : 'Eintrittsjahr speichern'}
      </button>
      {current && (
        <button type="button" className="btn btn-secondary btn-block join-year-remove" disabled={busy} onClick={remove}>
          Entfernen
        </button>
      )}
    </form>
  );
}

/**
 * Mitgliedsdetails (create_users): Eintrittsjahr setzen oder entfernen. Wem es auf der Seite der Person erscheint,
 * wählt nur die Person selbst; hier steht es nur zur Info.
 */
export function MemberJoinYear({
  id,
  joinYear,
  onChanged,
  onToast,
}: {
  id: number;
  joinYear: JoinYear | null;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [value, setValue] = useState(joinYear ? String(joinYear.year) : '');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

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
    const year = readYear(value);
    if (typeof year === 'string') return setFormError(year);
    void run(() => api.setMemberJoinYear(id, year), 'Eintrittsjahr gespeichert');
  }

  return (
    <form className="member-join-year" onSubmit={save}>
      <h3 className="section-title">Eintritt</h3>
      <div className="card member-join-year-card">
        <p className="member-join-year-current">{joinYear ? `Eingetreten ${joinYear.year}` : 'Nicht eingetragen.'}</p>
        {joinYear && config?.features.directory && (
          <p className="muted small">
            Auf der Seite unter „{sec.directory}“: {PROFILE_OPTIONS[joinYear.profile]} (wählt nur die Person selbst)
          </p>
        )}
        <YearField value={value} onChange={setValue} disabled={busy} />
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <div className="button-row">
          <button className="btn" type="submit" disabled={busy}>
            Eintrittsjahr speichern
          </button>
          {joinYear && (
            <button
              type="button"
              className="btn btn-secondary"
              disabled={busy}
              onClick={() =>
                window.confirm('Eintrittsjahr entfernen?') &&
                run(async () => {
                  await api.deleteMemberJoinYear(id);
                  setValue('');
                }, 'Eintrittsjahr entfernt')
              }
            >
              Entfernen
            </button>
          )}
        </div>
      </div>
    </form>
  );
}
