import { useMemo, useState, type FormEvent } from 'react';
import { api, levelLabel, type FormerImportResult, type FormerInfo, type FormerInput, type FormerReason } from '../api';
import brand from '../generated/brand';
import { formatDate, formatEuro } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { MemberAvatar } from '../memberPhoto';
import { DEFAULT_MEMBER_SORT, listName, loadMemberOrder, sortMembers, type MemberOrder } from '../memberSort';

const sec = brand.labels.sections;

/** Auswahl beim Eintragen */
export const REASON_OPTIONS: Record<FormerReason, string> = { left: 'Ausgetreten', deceased: 'Verstorben', other: 'Sonstiges' };

/** In Sätzen: „ausgetreten 2015“, „verstorben 14.03.2024“ */
const REASON_TEXT: Record<FormerReason, string> = { left: 'ausgetreten', deceased: 'verstorben', other: 'ausgeschieden' };

/** Datum vom Server (JJJJ-MM-TT oder nur JJJJ) zum Anzeigen. */
export const formerDate = (value: string): string => (value.length === 4 ? value : formatDate(value));

/** Datum vom Server zum Bearbeiten: TT.MM.JJJJ oder das Jahr. */
const editDate = (value: string): string => (value.length === 10 ? value.split('-').reverse().join('.') : value);

/** Eine Zeile wie „Gründungsmitglied · verstorben 14.03.2024“ bzw. „Eintritt 1990 · ausgetreten 31.12.2015“. */
export function formerSummary(f: Pick<FormerInfo, 'reason' | 'joined_on' | 'founder' | 'left_on'>): string {
  const parts: string[] = [];
  if (f.founder) parts.push('Gründungsmitglied');
  if (f.joined_on) parts.push(`Eintritt ${formerDate(f.joined_on)}`);
  parts.push(REASON_TEXT[f.reason] + (f.left_on ? ` ${formerDate(f.left_on)}` : ''));
  return parts.join(' · ');
}

/**
 * Verwaltung → Ehemalige (create_users): ausgetretene, verstorbene und sonst ausgeschiedene Personen mit ihren Daten.
 * Antippen öffnet die Seite der Person wie in der Mitgliederliste.
 */
export function FormerList() {
  const { data, error, loading, reload } = useAsync(() => api.formerMembers(), [], 'former-members');
  const [query, setQuery] = useState('');
  const [reason, setReason] = useState<FormerReason | ''>('');
  // Wie die Mitgliederliste nach Vor- oder Nachname; nach Punkten gibt es hier nicht.
  const stored = loadMemberOrder();
  const order: MemberOrder = stored.sort === 'points' ? { sort: DEFAULT_MEMBER_SORT, dir: 'asc' } : stored;
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

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const count = (r: FormerReason) => (data ?? []).filter((f) => f.reason === r).length;

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/mitglieder')}>
        ‹ Alle Mitglieder
      </button>
      <h2 className="member-name">Ehemalige</h2>
      <p className="member-counts small muted" role="status">
        {(Object.keys(REASON_TEXT) as FormerReason[])
          .filter((r) => count(r) > 0)
          .map((r) => `${count(r)} ${REASON_TEXT[r]}`)
          .join(' · ') || 'Noch niemand eingetragen.'}
      </p>
      <button className="btn btn-block btn-secondary" onClick={() => navigate('verwaltung/ehemalige/import')}>
        Tabelle importieren (z. B. Chronik)
      </button>
      <input
        className="search"
        type="search"
        placeholder="Suchen …"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Ehemalige suchen"
      />
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
      <ul className="card-list">
        {list.map((f) => (
          <li key={f.id} className="card row clickable member-row" onClick={() => navigate(`verwaltung/mitglied/${f.id}`)}>
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
    </div>
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

/**
 * Im Mitglied unter „Konto und Rolle“ (create_users): als ehemalig eintragen bzw. die Angaben ändern und wieder
 * aufnehmen. Offene Beträge werden vor dem Eintragen genannt, damit sie nicht in Vergessenheit geraten.
 */
export function FormerPanel({
  id,
  name,
  former,
  openCents,
  onChanged,
  onToast,
}: {
  id: number;
  name: string;
  former: FormerInfo | null;
  /** offene Beträge; null, wenn man sie nicht sieht */
  openCents: number | null;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [form, setForm] = useState<FormerInput | null>(null);
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
        await api.markFormer(id, data);
        onToast(`${name} ist jetzt als ehemalig eingetragen`);
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
    if (!former || !window.confirm(`${name} wieder aufnehmen (als ${levelLabel(former.previous_level)})?`)) return;
    setBusy(true);
    try {
      await api.reactivate(id);
      onToast(`${name} ist wieder aufgenommen. Für die Anmeldung bitte ein neues Startpasswort erzeugen.`);
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
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <div className="button-row">
            <button className="btn" type="submit" disabled={busy}>
              {former ? 'Speichern' : 'Als ehemalig eintragen'}
            </button>
            <button className="btn btn-secondary" type="button" onClick={() => setForm(null)}>
              Abbrechen
            </button>
          </div>
        </form>
      ) : former ? (
        <>
          <p className="small">{formerSummary(former)}</p>
          <div className="button-row">
            <button
              className="btn btn-secondary"
              disabled={busy}
              onClick={() =>
                setForm({
                  reason: former.reason,
                  joined_on: editDate(former.joined_on),
                  founder: former.founder,
                  left_on: editDate(former.left_on),
                })
              }
            >
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
          <button
            className="btn btn-secondary"
            disabled={busy}
            onClick={() => setForm({ reason: 'left', joined_on: '', founder: false, left_on: '' })}
          >
            Als ehemalig eintragen …
          </button>
        </>
      )}
    </section>
  );
}

const IMPORT_EXAMPLE =
  'vorname;nachname;eintritt;ausgeschieden;grund\nErika;Muster;Gründer;31.12.1990;ausgetreten\nMax;Beispiel;1975;2003;verstorben';

/** Datei lesen: UTF-8, sonst Windows-1252 (so speichert Excel unter Windows „CSV (Trennzeichen-getrennt)“). */
async function readText(file: File): Promise<string> {
  const bytes = await file.arrayBuffer();
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return new TextDecoder('windows-1252').decode(bytes);
  }
}

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
        onToast(`${res.new === 1 ? '1 Person' : `${res.new} Personen`} als ehemalig angelegt`);
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
      <button className="btn btn-link back" onClick={() => navigate('verwaltung/ehemalige')}>
        ‹ Ehemalige
      </button>
      <h2 className="member-name">Ehemalige importieren</h2>
      <p className="muted small">
        Eine Zeile je Person, Spalten getrennt durch Semikolon (oder Tabulator, z. B. aus einer Tabellenkalkulation kopiert):{' '}
        <code>vorname;nachname;eintritt;ausgeschieden;grund</code>. Daten als TT.MM.JJJJ oder nur das Jahr; Eintritt „Gründer“ für
        Gründungsmitglieder. Grund: ausgetreten (auch leer), verstorben oder sonstiges. Bei Verstorbenen ist „ausgeschieden“ der Todestag.
      </p>
      <p className="muted small">
        Für jede Person entsteht ein Konto, das gleich als ehemalig eingetragen ist (ohne Anmeldung). Wer schon ein Konto mit genau diesem
        Namen hat, wird übersprungen; ein zweiter Import legt also nichts doppelt an.
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
        <span>Oder Datei wählen (CSV)</span>
        <input
          type="file"
          accept=".csv,.txt,text/csv,text/plain"
          onChange={async (e) => {
            const file = e.target.files?.[0];
            if (file) {
              setCsv(await readText(file));
              setResult(null);
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
