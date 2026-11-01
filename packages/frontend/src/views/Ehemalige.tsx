import { useEffect, useMemo, useState, type FormEvent } from 'react';
import {
  api,
  can,
  config,
  levelLabel,
  type FormerRetention,
  type CreatedFormer,
  type FormerImportResult,
  type FormerInfo,
  type FormerInput,
  type FormerOfficesPreview,
  type FormerReason,
} from '../api';
import { BirthdayFields, birthdayString, canEnterBirthdays, emptyBirthday, readBirthday } from '../birthdays';
import brand from '../generated/brand';
import { editYearOrDate, formatEuro, formatYearOrDate } from '../format';
import { navigate } from '../router';
import { readSheet } from '../sheet';
import { Empty, emptyName, ErrorBox, Loading, NameFields, trimName, useAsync } from '../ui';
import { MemberAvatar, PhotoPicker, type PreparedPhoto } from '../memberPhoto';
import {
  MEMBER_SORT_LABELS,
  defaultDir,
  dirLabel,
  listName,
  loadMemberOrder,
  orderFor,
  saveMemberOrder,
  setBrowseOrder,
  sortMembers,
  type MemberOrder,
  type MemberSort,
} from '../memberSort';
import { sources } from '../viewData';

const sec = brand.labels.sections;

/** Nach Namen, Eintritt oder Austritt; Punkte gibt es in dieser Liste nicht. */
export const FORMER_SORTS: MemberSort[] = ['last-name', 'first-name', 'joined', 'left'];

/** Auswahl beim Eintragen */
export const REASON_OPTIONS: Record<FormerReason, string> = { left: 'Ausgetreten', deceased: 'Verstorben', other: 'Sonstiges' };

/** In Sätzen: „ausgetreten 2015“, „verstorben 14.03.2024“ */
const REASON_TEXT: Record<FormerReason, string> = { left: 'ausgetreten', deceased: 'verstorben', other: 'ausgeschieden' };

/** Eine Zeile wie „Gründungsmitglied · verstorben 14.03.2024“ bzw. „Eintritt 1990 · ausgetreten 31.12.2015“. */
export function formerSummary(f: Pick<FormerInfo, 'reason' | 'joined_on' | 'founder' | 'left_on'>): string {
  const parts: string[] = [];
  if (f.founder) parts.push('Gründungsmitglied');
  if (f.joined_on) parts.push(`Eintritt ${formatYearOrDate(f.joined_on)}`);
  parts.push(REASON_TEXT[f.reason] + (f.left_on ? ` ${formatYearOrDate(f.left_on)}` : ''));
  return parts.join(' · ');
}

/**
 * Verwaltung → Ehemalige (create_users): ausgetretene, verstorbene und sonst ausgeschiedene Personen mit ihren Daten.
 * Antippen öffnet die Seite der Person wie in der Mitgliederliste.
 */
export function FormerList() {
  const { data, error, loading, reload } = useAsync(sources.formerMembers.load, sources.formerMembers.deps, sources.formerMembers.key);
  const [query, setQuery] = useState('');
  const [reason, setReason] = useState<FormerReason | ''>('');
  // Filter und Sortierung zunächst eingeklappt, wie in der Mitgliederliste.
  const [filtersOpen, setFiltersOpen] = useState(false);
  // Wie die Mitgliederliste (gemerkt je Gerät); gemerkt nach Punkten gilt hier der Nachname.
  const [stored, setStored] = useState<MemberOrder>(loadMemberOrder);
  const order = orderFor(stored, FORMER_SORTS);
  const changeOrder = (next: MemberOrder) => {
    setStored(next);
    saveMemberOrder(next);
  };
  const list = useMemo(
    () =>
      sortMembers(
        (data ?? []).filter((f) => f.name.toLowerCase().includes(query.trim().toLowerCase()) && (reason === '' || f.reason === reason)),
        order,
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data, query, reason, order.sort, order.dir],
  );
  const withPhotos = (data ?? []).some((f) => f.photo_url);

  function open(id: number) {
    setBrowseOrder('ehemalige', list);
    navigate(`verwaltung/ehemalige/${id}`);
  }

  if (loading && !data) return <Loading shape="people" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const count = (r: FormerReason) => (data ?? []).filter((f) => f.reason === r).length;

  return (
    <div>
      <p className="member-counts small muted" role="status">
        {(Object.keys(REASON_TEXT) as FormerReason[])
          .filter((r) => count(r) > 0)
          .map((r) => `${count(r)} ${REASON_TEXT[r]}`)
          .join(' · ') || 'Noch niemand eingetragen.'}
      </p>
      <div className="button-row add-member">
        <button className="btn" onClick={() => navigate('verwaltung/ehemalige/neu')}>
          + Ehemalige Person anlegen
        </button>
      </div>
      <input
        className="search"
        type="search"
        placeholder="Suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Ehemalige suchen"
      />
      <button
        type="button"
        className="member-filters-toggle"
        aria-expanded={filtersOpen}
        aria-controls="former-filters"
        onClick={() => setFiltersOpen(!filtersOpen)}
      >
        Filter und Sortierung
        {/* Hinweis am eingeklappten Bereich, damit ein gesetzter Filter nicht übersehen wird (die Sortierung zählt nicht). */}
        {reason !== '' && <span className="badge badge-role">aktiv</span>}
        <span className="member-filters-arrow" aria-hidden="true">
          ›
        </span>
      </button>
      <div id="former-filters" className="member-filters" hidden={!filtersOpen}>
        <select
          className="role-filter"
          value={reason}
          onChange={(e) => setReason(e.target.value as FormerReason | '')}
          aria-label="Nach Grund filtern"
        >
          <option value="">Alle Gründe</option>
          {(Object.keys(REASON_OPTIONS) as FormerReason[]).map((r) => (
            <option key={r} value={r}>
              {REASON_OPTIONS[r]}
            </option>
          ))}
        </select>
        <div className="field-row member-sort">
          {/* Kein umschließendes <label>: dessen Text enthielte sonst alle Optionen. */}
          <div className="field">
            <span id="former-sort-label">Sortierung</span>
            <select
              aria-labelledby="former-sort-label"
              value={order.sort}
              onChange={(e) => {
                const sort = e.target.value as MemberSort;
                changeOrder({ sort, dir: defaultDir(sort) });
              }}
            >
              {FORMER_SORTS.map((s) => (
                <option key={s} value={s}>
                  {MEMBER_SORT_LABELS[s]}
                </option>
              ))}
            </select>
          </div>
          <div className="field member-sort-dir">
            <span id="former-sort-dir-label">Reihenfolge</span>
            <button
              type="button"
              className="btn btn-secondary"
              aria-labelledby="former-sort-dir-label former-sort-dir-value"
              title="Reihenfolge umkehren"
              onClick={() => changeOrder({ ...order, dir: order.dir === 'asc' ? 'desc' : 'asc' })}
            >
              <span id="former-sort-dir-value">{dirLabel(order.sort, order.dir)}</span>
            </button>
          </div>
        </div>
      </div>
      <ul className="card-list">
        {list.map((f) => (
          <li key={f.id} className="card row clickable member-row" onClick={() => open(f.id)}>
            {withPhotos && <MemberAvatar url={f.photo_url} name={f.name} size="medium" />}
            <div className="row-main">
              <div className="row-title">{listName(f, order.sort)}</div>
              <div className="row-sub">
                {formerSummary(f)}
                {(f.open_cents ?? 0) > 0 && (
                  <>
                    {' · '}
                    <span className="neg">{formatEuro(f.open_cents ?? 0)} offen</span>
                  </>
                )}
              </div>
            </div>
            <span className="chevron" aria-hidden="true">
              ›
            </span>
          </li>
        ))}
        {list.length === 0 && (data ?? []).length > 0 && <Empty>Niemand gefunden.</Empty>}
      </ul>
      {can('manage_settings') && <RetentionCard onChanged={reload} />}
    </div>
  );
}

/**
 * Aufbewahrungsfrist (nur Admins, Standard aus): Nach so vielen Jahren seit dem Ausscheiden löscht die App die
 * persönlichen Angaben; die Chronik bleibt. Vorschau, wen es beim nächsten Lauf und in den nächsten 30 Tagen trifft.
 */
function RetentionCard({ onChanged }: { onChanged: () => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.formerRetention(), [], 'admin/former/retention');
  const [form, setForm] = useState<{ years: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  if (loading && !data) return <Loading shape="form" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const r = data!;
  const f = form ?? { years: r.years > 0 ? String(r.years) : '' };
  const names = (list: FormerRetention['due']) =>
    list.map((p) => `${p.name}${p.left_on ? ` (${formatYearOrDate(p.left_on)})` : ''}`).join(', ');

  async function save(e: FormEvent) {
    e.preventDefault();
    const years = f.years.trim() === '' ? 0 : Number(f.years);
    if (!Number.isInteger(years) || years < 0 || years > 50) {
      setSaveError('Bitte die Frist in ganzen Jahren angeben (0 bis 50; leer oder 0 = aus).');
      return;
    }
    const data = { years };
    setBusy(true);
    setSaveError(null);
    try {
      // Erst zeigen, wen es trifft: Beim Einschalten kommt der erste Lauf wenige Minuten nach dem Speichern, sonst gilt
      // der tägliche Lauf.
      if (years > 0) {
        const preview = await api.setFormerRetention({ ...data, preview: true });
        const due = preview.due.length;
        const question =
          due > 0
            ? `Beim nächsten Lauf (spätestens in einem Tag) werden bei ${due} Ehemaligen die persönlichen Angaben endgültig gelöscht: ${names(preview.due)}. Das lässt sich nicht rückgängig machen. Speichern?`
            : `Bei Ehemaligen ${years} Jahre nach dem Ausscheiden die persönlichen Angaben endgültig löschen? Zurzeit ist niemand fällig. Speichern?`;
        if (!window.confirm(question)) return;
      }
      setData(await api.setFormerRetention(data)); // auch in den Zwischenspeicher, neu geladen wird wie sonst
      setForm(null);
      onChanged();
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card settings-section" onSubmit={save} aria-labelledby="former-retention-title">
      <h3 id="former-retention-title">Aufbewahrungsfrist</h3>
      <p className="muted small">
        Personenbezogene Daten sollen nicht länger gespeichert werden als nötig. Ist eine Frist eingestellt, löscht die App bei Ehemaligen
        so viele Jahre nach dem Ausscheiden endgültig die persönlichen Angaben: Adresse, Handynummer, E-Mail-Adresse, Anmeldung, Geburtstag,
        Strafen, Anwesenheiten, Antworten in Umfragen, Benachrichtigungen, Signatur und Einstellungen. Für die Chronik bleiben Name, Eintrag
        als ehemalig, Ämter, Punkte, Dienstgrad, Titel, Foto und Eintrittsjahr. Ohne Datum des Ausscheidens zählt der Tag des Eintragens.
        Leer oder 0: aus.
        {config?.variant === 'wordpress' &&
          ' Der Benutzer der Webseite (Anmeldung, E-Mail-Adresse) bleibt in WordPress; ihn löscht oder ändert ein Administrator der Webseite unter Benutzer.'}
      </p>
      <label className="field">
        <span>Jahre nach dem Ausscheiden</span>
        <input
          type="number"
          inputMode="numeric"
          min={0}
          max={50}
          value={f.years}
          onChange={(e) => setForm({ ...f, years: e.target.value })}
        />
      </label>
      {r.years > 0 && (
        <p className="small">
          {r.due.length > 0 ? <>Beim nächsten Lauf (täglich) fällig: {names(r.due)}.</> : 'Zurzeit ist niemand fällig.'}
          {r.soon.length > 0 && <> In den nächsten 30 Tagen fällig: {names(r.soon)}.</>}
        </p>
      )}
      {saveError && (
        <p className="form-error" role="alert">
          {saveError}
        </p>
      )}
      {form && (
        <div className="button-row">
          <button className="btn" type="submit" disabled={busy}>
            {busy ? 'Speichert …' : 'Speichern'}
          </button>
          <button className="btn btn-secondary" type="button" onClick={() => setForm(null)}>
            Abbrechen
          </button>
        </div>
      )}
    </form>
  );
}

/** Formular: Grund, Eintritt (oder Gründungsmitglied) und Ausscheiden. */
function FormerFields({ value, onChange }: { value: FormerInput; onChange: (v: FormerInput) => void }) {
  return (
    <>
      <label className="field">
        <span>Grund des Ausscheidens</span>
        <select value={value.reason} onChange={(e) => onChange({ ...value, reason: e.target.value as FormerReason })}>
          {(Object.keys(REASON_OPTIONS) as FormerReason[]).map((r) => (
            <option key={r} value={r}>
              {REASON_OPTIONS[r]}
            </option>
          ))}
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>{value.reason === 'deceased' ? 'Verstorben am' : 'Ausgeschieden am'} (optional)</span>
          <input
            type="text"
            inputMode="numeric"
            placeholder="TT.MM.JJJJ oder Jahr"
            value={value.left_on}
            onChange={(e) => onChange({ ...value, left_on: e.target.value })}
          />
        </label>
        <label className="field">
          <span>Eintritt (optional)</span>
          <input
            type="text"
            inputMode="numeric"
            placeholder="TT.MM.JJJJ oder Jahr"
            value={value.joined_on}
            onChange={(e) => onChange({ ...value, joined_on: e.target.value })}
          />
        </label>
      </div>
      <label className="toggle">
        <input type="checkbox" checked={value.founder} onChange={(e) => onChange({ ...value, founder: e.target.checked })} />
        <span>Gründungsmitglied</span>
      </label>
    </>
  );
}

/** Leeres Formular: ausgetreten, ohne Daten */
const emptyFormer = (): FormerInput => ({ reason: 'left', joined_on: '', founder: false, left_on: '' });

/**
 * Verwaltung → Ehemalige → „+ Ehemalige Person anlegen“ (create_users), aufgebaut wie „+ Mitglied anlegen“: Name, Angaben zum
 * Ausscheiden, Geburtstag und Foto. Es entsteht ein Konto ohne Anmeldung, das gleich als ehemalig eingetragen ist. Darüber
 * führt „Tabelle importieren“ zum Import vieler auf einmal.
 */
export function NewFormer() {
  const [name, setName] = useState(emptyName());
  const [former, setFormer] = useState<FormerInput>(emptyFormer);
  const [birthday, setBirthday] = useState(emptyBirthday());
  const [photo, setPhoto] = useState<PreparedPhoto | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [created, setCreated] = useState<CreatedFormer | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!name.first_name.trim()) return setFormError('Bitte einen Vornamen angeben.');
    const bday = canEnterBirthdays() ? readBirthday(birthday) : null;
    if (typeof bday === 'string') return setFormError(bday);
    setBusy(true);
    try {
      setCreated(
        await api.createFormer({
          ...trimName(name),
          ...former,
          joined_on: former.joined_on.trim(),
          left_on: former.left_on.trim(),
          ...(bday ? { birthday: birthdayString(bday) } : {}),
          ...(photo ? { photo: photo.file } : {}),
        }),
      );
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (created) {
    return (
      <div>
        <h2 className="member-name">{created.name} ist als ehemalig angelegt</h2>
        <p className="small">{formerSummary(created)}</p>
        {created.offices_linked > 0 && (
          <p className="small">
            {created.offices_linked === 1 ? '1 Eintrag' : `${created.offices_linked} Einträge`} in den {sec.offices} mit dem Konto
            verknüpft.
          </p>
        )}
        {created.warning && (
          <p className="form-error" role="alert">
            {created.warning}
          </p>
        )}
        <button className="btn btn-block" onClick={() => navigate('verwaltung/ehemalige')}>
          Fertig
        </button>
        <button
          className="btn btn-secondary btn-block"
          onClick={() => {
            setCreated(null);
            setName(emptyName());
            setFormer(emptyFormer());
            setBirthday(emptyBirthday());
            setPhoto(null);
          }}
        >
          Weitere Person
        </button>
      </div>
    );
  }

  return (
    <form className="form" onSubmit={submit}>
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/ehemalige')}>
        ‹ Ehemalige
      </button>
      <button type="button" className="btn btn-secondary btn-block bulk-link" onClick={() => navigate('verwaltung/ehemalige/import')}>
        Tabelle importieren (z. B. Chronik)
      </button>
      <NameFields value={name} onChange={setName} />
      <FormerFields value={former} onChange={setFormer} />
      {canEnterBirthdays() && (
        <fieldset className="fieldset new-member-birthday">
          <legend>Geburtstag (optional)</legend>
          <BirthdayFields value={birthday} onChange={setBirthday} />
        </fieldset>
      )}
      <fieldset className="fieldset new-member-photo">
        <legend>Foto (optional)</legend>
        <PhotoPicker name={`${name.first_name} ${name.last_name}`.trim()} value={photo} onChange={setPhoto} onError={setFormError} />
      </fieldset>
      <p className="muted small">
        Es entsteht ein Konto ohne Anmeldung, das gleich als ehemalig eingetragen ist. Wer schon ein Konto mit genau diesem Namen hat, wird
        nicht noch einmal angelegt. Steht die Person in den {sec.offices} bisher nur mit ihrem Namen, wird der Eintrag mit ihrem Konto
        verknüpft.
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <button className="btn btn-block" type="submit" disabled={busy}>
        {busy ? 'Legt an …' : 'Ehemalige Person anlegen'}
      </button>
    </form>
  );
}

/**
 * Im Mitglied unter „Konto und Rolle“ und hinter „Austritt“ (create_users): als ehemalig eintragen bzw. die Angaben
 * ändern und wieder aufnehmen. Offene Beträge werden vor dem Eintragen genannt, damit sie nicht in Vergessenheit geraten.
 * Mit `editing` steht gleich das Formular da; „Abbrechen“ ruft dann `onCancel` (z. B. schließt das Fenster).
 */
export function FormerPanel({
  id,
  name,
  former,
  joinedOn,
  openCents,
  editing = false,
  onChanged,
  onCancel,
  onToast,
}: {
  id: number;
  name: string;
  former: FormerInfo | null;
  /** Eintritt als aktives Mitglied (JJJJ-MM-TT oder JJJJ): Vorschlag für den Eintritt beim Eintragen als ehemalig */
  joinedOn?: string | null;
  /** offene Beträge; null, wenn man sie nicht sieht */
  openCents: number | null;
  editing?: boolean;
  onChanged: () => void;
  onCancel?: () => void;
  onToast: (msg: string) => void;
}) {
  const startForm = (): FormerInput =>
    former
      ? {
          reason: former.reason,
          joined_on: editYearOrDate(former.joined_on),
          founder: former.founder,
          left_on: editYearOrDate(former.left_on),
        }
      : { reason: 'left', joined_on: joinedOn ? editYearOrDate(joinedOn) : '', founder: false, left_on: '' };
  const [form, setForm] = useState<FormerInput | null>(() => (editing ? startForm() : null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form) return;
    setBusy(true);
    setError(null);
    try {
      const data = { ...form, joined_on: form.joined_on.trim(), left_on: form.left_on.trim() };
      if (former) {
        await api.updateFormer(id, data);
        onToast('Gespeichert');
      } else {
        const res = await api.markFormer(id, data);
        onToast(
          `${name} ist jetzt als ehemalig eingetragen` + (res.offices_ended > 0 ? `. ${sec.offices} beendet: ${res.offices_ended}` : ''),
        );
      }
      setForm(null);
      onChanged();
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function reactivate() {
    // Benutzername und E-Mail-Adresse löscht die Frist nur in der eigenständigen Version (WordPress: Benutzer der Webseite).
    const loginDeleted = former?.personal_deleted && config?.variant !== 'wordpress';
    const deleted = former?.personal_deleted
      ? ` Die persönlichen Angaben wurden nach Ablauf der Aufbewahrungsfrist gelöscht${loginDeleted ? ', auch Benutzername und E-Mail-Adresse' : ''}.`
      : '';
    if (!former || !window.confirm(`${name} wieder aufnehmen (als ${levelLabel(former.previous_level)})?${deleted}`)) return;
    setBusy(true);
    try {
      await api.reactivate(id);
      onToast(
        loginDeleted
          ? `${name} ist wieder aufgenommen. Bitte unter „Angaben ändern“ Benutzername und E-Mail-Adresse neu eintragen, dann ein neues Startpasswort erzeugen.`
          : `${name} ist wieder aufgenommen. Für die Anmeldung bitte ein neues Startpasswort erzeugen.`,
      );
      onChanged();
    } catch (err) {
      onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="former-panel">
      <h3 className="section-title">Ehemalig</h3>
      {form ? (
        <form className="form card" onSubmit={save}>
          {!former && (
            <p className="small">
              Das Konto bleibt mit allen Daten ({sec.points}, {sec.penalties}, {brand.labels.rank}, Titel, {sec.offices}) erhalten. {name}{' '}
              hat danach keine Rolle mehr, steht in keiner Liste, bekommt keine Benachrichtigungen und kann sich nicht mehr in der App
              anmelden.
            </p>
          )}
          {!former && (openCents ?? 0) > 0 && (
            <p className="form-error" role="alert">
              Achtung: Es sind noch {formatEuro(openCents ?? 0)} offen. Sie bleiben offen und stehen auch in der Liste der Ehemaligen.
            </p>
          )}
          <FormerFields value={form} onChange={setForm} />
          {!former && <EndingOffices id={id} leftOn={form.left_on} />}
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              {former ? 'Speichern' : 'Als ehemalig eintragen'}
            </button>
            <button
              className="btn btn-secondary"
              type="button"
              onClick={() => {
                setForm(null);
                onCancel?.();
              }}
            >
              Abbrechen
            </button>
          </div>
        </form>
      ) : former ? (
        <>
          <p className="small">{formerSummary(former)}</p>
          <div className="button-row">
            <button className="btn btn-secondary" disabled={busy} onClick={() => setForm(startForm())}>
              Angaben ändern
            </button>
            <button className="btn" disabled={busy} onClick={reactivate}>
              Wieder aufnehmen
            </button>
          </div>
        </>
      ) : (
        <>
          <p className="muted small">
            Ausgetreten oder verstorben? Dann hier eintragen statt das Konto zu löschen: Die Daten bleiben erhalten.
          </p>
          <button className="btn btn-secondary" disabled={busy} onClick={() => setForm(startForm())}>
            Als ehemalig eintragen …
          </button>
        </>
      )}
    </section>
  );
}

/**
 * Vorschau beim Eintragen als ehemalig (Issue #181): welche laufenden Ämter mit dem Ausscheiden enden. Folgt dem Feld
 * „Ausgeschieden“; ohne laufende Ämter (oder solange das Datum ungültig ist) steht nichts da.
 */
function EndingOffices({ id, leftOn }: { id: number; leftOn: string }) {
  const [preview, setPreview] = useState<FormerOfficesPreview | null>(null);
  useEffect(() => {
    let current = true;
    const timer = window.setTimeout(() => {
      api.formerOfficesPreview(id, leftOn.trim()).then(
        (p) => current && setPreview(p),
        () => current && setPreview(null),
      );
    }, 300);
    return () => {
      current = false;
      window.clearTimeout(timer);
    };
  }, [id, leftOn]);
  if (!preview || preview.terms.length === 0) return null;
  const clamped = preview.terms.filter((t) => t.clamped);
  return (
    <p className="small ending-offices" role="status">
      Mit dem Ausscheiden enden: {preview.terms.map((t) => `${t.office} (seit ${t.start_year})`).join(', ')} – Ende {preview.end_year}.
      {clamped.length > 0 &&
        ` Der Austritt liegt vor dem Beginn von ${clamped.map((t) => t.office).join(', ')}; dort ist das Ende deshalb das Jahr des Beginns.`}
      {preview.terms.some((t) => t.shared) && ' Wer ein Amt gemeinsam innehat, bleibt darin; nur dieser Eintrag endet.'}
    </p>
  );
}

const IMPORT_EXAMPLE =
  'vorname;nachname;eintritt;ausgeschieden;grund\nErika;Muster;Gründer;31.12.1990;ausgetreten\nMax;Beispiel;1975;2003;verstorben';

/**
 * Ehemalige aus einer Tabelle anlegen (Ersterfassung, z. B. aus einer Chronik): einfügen oder Datei wählen, Vorschau mit
 * Fehlern je Zeile, dann übernehmen. Für jede Person entsteht ein Konto, das gleich als ehemalig eingetragen ist; wer
 * schon ein Konto mit genau diesem Namen hat, wird übersprungen.
 */
export function FormerImport({ onToast }: { onToast: (msg: string) => void }) {
  const [csv, setCsv] = useState('');
  const [result, setResult] = useState<FormerImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(apply: boolean) {
    setBusy(true);
    setError(null);
    try {
      const res = await api.importFormer(csv, apply);
      if (apply) {
        onToast(
          `${res.new === 1 ? '1 Person' : `${res.new} Personen`} als ehemalig angelegt` +
            ((res.offices_linked ?? 0) > 0 ? `, ${res.offices_linked} Einträge in den ${sec.offices} verknüpft` : ''),
        );
        navigate('verwaltung/ehemalige');
      } else {
        setResult(res);
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const errors = result?.rows.filter((r) => r.error).length ?? 0;
  return (
    <div className="office-import">
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/ehemalige/neu')}>
        ‹ Einzeln anlegen
      </button>
      <h2 className="member-name">Ehemalige importieren</h2>
      <p className="muted small">
        Eine Zeile je Person, Spalten getrennt durch Semikolon (oder Tabulator, z. B. aus einer Tabellenkalkulation kopiert):{' '}
        <code>vorname;nachname;eintritt;ausgeschieden;grund</code>. Daten als TT.MM.JJJJ oder nur das Jahr; Eintritt „Gründer“ für
        Gründungsmitglieder. Grund: ausgetreten (auch leer), verstorben oder sonstiges. Bei Verstorbenen ist „ausgeschieden“ der Todestag.
      </p>
      <p className="muted small">
        Für jede Person entsteht ein Konto, das gleich als ehemalig eingetragen ist (ohne Anmeldung). Wer schon ein Konto mit genau diesem
        Namen hat, wird übersprungen; ein zweiter Import legt also nichts doppelt an. Steht eine Person in den {sec.offices} bisher nur mit
        ihrem Namen, wird der Eintrag mit ihrem Konto verknüpft.
      </p>
      <label className="field">
        <span>Tabelle</span>
        <textarea
          value={csv}
          placeholder={IMPORT_EXAMPLE}
          onChange={(e) => {
            setCsv(e.target.value);
            setResult(null);
          }}
        />
      </label>
      <label className="field">
        <span>Oder Datei wählen (Excel .xlsx oder CSV)</span>
        <input
          type="file"
          accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={async (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            setResult(null);
            try {
              setCsv(await readSheet(file));
            } catch (err) {
              setError((err as Error).message);
            }
          }}
        />
      </label>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn btn-secondary" disabled={busy || csv.trim() === ''} onClick={() => send(false)}>
          Vorschau
        </button>
        {result && (
          <button className="btn" disabled={busy || errors > 0 || result.new === 0} onClick={() => send(true)}>
            Übernehmen
          </button>
        )}
      </div>
      {result && (
        <section>
          <p role="status">
            Neu: {result.new === 1 ? '1 Person' : `${result.new} Personen`} · schon vorhanden: {result.existing}
          </p>
          {(result.offices_linked ?? 0) > 0 && (
            <p className="small">
              {result.offices_linked} Einträge in den {sec.offices}, die bisher nur den Namen hatten, werden mit dem Konto verknüpft.
            </p>
          )}
          {errors > 0 && (
            <p className="form-error">{errors === 1 ? '1 Zeile hat' : `${errors} Zeilen haben`} einen Fehler; bitte erst korrigieren.</p>
          )}
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.existing ? 'muted' : ''}>
                <strong>Zeile {r.line}</strong> {r.name}
                {r.reason &&
                  ` · ${formerSummary({ reason: r.reason, joined_on: r.joined_on ?? '', founder: !!r.founder, left_on: r.left_on ?? '' })}`}
                {r.existing && (r.existing.former ? ' · schon als ehemalig eingetragen' : ' · hat schon ein Konto, wird übersprungen')}
                {r.error && <p className="form-error">{r.error}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
