import { useState } from 'react';
import { api, type PassiveImportField, type PassiveImportResult, type PassiveImportRow } from '../api';
import { formatAddress } from '../address';
import { formatDate, formatEuro, todayYmd } from '../format';
import { navigate } from '../router';
import { readSheet } from '../sheet';
import { FileInput } from '../ui';

/** Felder, denen man Spalten zuordnen kann, in dieser Reihenfolge; personal = nur mit manage_personal */
const FIELDS: { key: PassiveImportField; label: string; personal?: boolean }[] = [
  { key: 'first_name', label: 'Vorname' },
  { key: 'last_name', label: 'Nachname' },
  { key: 'street', label: 'Straße (auch mit Hausnummer)', personal: true },
  { key: 'house_number', label: 'Hausnummer', personal: true },
  { key: 'extra', label: 'Adresszusatz', personal: true },
  { key: 'postal_code', label: 'PLZ', personal: true },
  { key: 'city', label: 'Ort', personal: true },
  { key: 'country', label: 'Land', personal: true },
  { key: 'phone', label: 'Telefon', personal: true },
  { key: 'email', label: 'E-Mail-Adresse', personal: true },
  { key: 'birth_date', label: 'Geburtsdatum', personal: true },
  { key: 'joined', label: 'Eintritt' },
  { key: 'note', label: 'Bemerkung' },
  { key: 'caretaker', label: 'Betreuer' },
  { key: 'amount', label: 'Betrag' },
  { key: 'method', label: 'Zahlungsart' },
  { key: 'paid_on', label: 'Bezahlt am' },
];

type Columns = Partial<Record<PassiveImportField, number | null>>;

const count = (n: number, one: string, many: string) => (n === 1 ? `1 ${one}` : `${n} ${many}`);

/** Was die App aus einer Zeile verstanden hat, kurz. */
function rowSummary(r: PassiveImportRow): string {
  const parts: string[] = [];
  if (r.address) parts.push(formatAddress(r.address).join(', '));
  if (r.phone) parts.push(r.phone);
  if (r.email) parts.push(r.email);
  if (r.birth_date) parts.push(`geboren ${formatDate(r.birth_date)}`);
  if (r.joined) parts.push(`Eintritt ${r.joined}`);
  if (r.caretaker) parts.push(`Betreuer: ${r.caretaker.name}`);
  if (r.payment)
    parts.push(`Beitrag ${r.payment.year}: ${formatEuro(r.payment.amount_cents)} (${r.payment.method}, ${formatDate(r.payment.paid_on)})`);
  if (r.note) parts.push(`Bemerkung: ${r.note}`);
  return parts.join(' · ');
}

/**
 * Verwaltung → Passive → Tabelle importieren (manage_passive): eine bisher geführte Liste (Excel oder CSV) übernehmen.
 * Die Kopfzeile darf unter einem Titel stehen; die Spalten erkennt der Server an ihren Namen, hier lässt sich die
 * Zuordnung ändern. Vorschau mit Fehlern und Hinweisen je Zeile, dann übernehmen (auf Wunsch ohne die Zeilen mit Fehler,
 * z. B. Summenzeilen). Wer schon als Passiver eingetragen ist, wird übersprungen.
 */
export function PassiveImport({ onToast }: { onToast: (msg: string) => void }) {
  const current = Number(todayYmd().slice(0, 4));
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [columns, setColumns] = useState<Columns | null>(null);
  const [year, setYear] = useState(String(current));
  const [paidOn, setPaidOn] = useState(todayYmd());
  const [skipErrors, setSkipErrors] = useState(false);
  const [result, setResult] = useState<PassiveImportResult | null>(null);
  const [stale, setStale] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(apply: boolean, cols: Columns | null = columns) {
    setBusy(true);
    setError(null);
    try {
      const res = await api.importPassives({
        csv,
        apply,
        columns: cols,
        year: Number(year) || current,
        paid_on: paidOn,
        skip_errors: apply && skipErrors,
      });
      if (apply) {
        onToast(
          `${count(res.new, 'Passiver', 'Passive')} angelegt` +
            (res.payments > 0 ? `, ${count(res.payments, 'Zahlung', 'Zahlungen')}` : ''),
        );
        navigate('verwaltung/passive');
        return;
      }
      setResult(res);
      setStale(false);
      // Ab der ersten Vorschau gilt die Zuordnung, die der Server erkannt hat; Änderungen gehen von dort aus.
      const next: Columns = {};
      for (const f of FIELDS) next[f.key] = res.columns[f.key] ?? null;
      setColumns(next);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function pick(file: File | undefined) {
    if (!file) return;
    setError(null);
    setResult(null);
    setColumns(null);
    try {
      setCsv(await readSheet(file));
      setFileName(file.name);
    } catch (e) {
      setCsv('');
      setFileName('');
      setError((e as Error).message);
    }
  }

  const changed = () => setStale(true);
  const fields = FIELDS.filter((f) => !f.personal || result?.personal);
  const warnings = result?.rows.filter((r) => r.warnings?.length).length ?? 0;
  const canApply = !!result && !stale && !busy && result.new > 0 && (result.errors === 0 || skipErrors);

  return (
    <div className="office-import passive-import stack">
      <button type="button" className="btn btn-link back" onClick={() => navigate('verwaltung/passive')}>
        ‹ Passive
      </button>
      <h2 className="member-name">Passive importieren</h2>
      <p className="small muted">
        Eine Liste aus Excel (.xlsx) oder als CSV. Über der Kopfzeile darf z. B. ein Titel stehen. Wer schon als Passiver eingetragen ist
        (gleicher Vor- und Nachname), wird übersprungen.
      </p>
      <label className="field">
        <span>Datei wählen (Excel .xlsx oder CSV)</span>
        <FileInput
          accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e) => pick(e.target.files?.[0])}
        />
      </label>
      <label className="field">
        <span>{fileName ? `Inhalt von ${fileName}` : 'Oder Tabelle einfügen (z. B. aus Excel kopiert)'}</span>
        <textarea
          value={csv}
          placeholder={'Vorname;Nachname;Betreuer;Bar\nErika;Muster;A. Beispiel;25'}
          onChange={(e) => {
            setCsv(e.target.value);
            setResult(null);
            setColumns(null);
          }}
        />
      </label>
      <div className="field-row">
        <label className="field">
          <span>Beitrag für das Jahr</span>
          <input
            type="number"
            min={1900}
            max={current + 1}
            value={year}
            onChange={(e) => {
              setYear(e.target.value);
              changed();
            }}
          />
        </label>
        <label className="field">
          <span>Datum für Zahlungen ohne Datum</span>
          <input
            type="date"
            min="1900-01-01"
            max={todayYmd()}
            value={paidOn}
            onChange={(e) => {
              setPaidOn(e.target.value);
              changed();
            }}
          />
        </label>
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      {result && columns && (
        <fieldset className="fieldset passive-import-columns">
          <legend>Spalten zuordnen</legend>
          <p className="small muted">Kopfzeile ist Zeile {result.header_line}. Was nicht passt, hier ändern; danach „Vorschau“.</p>
          {fields.map((f) => (
            <label key={f.key} className="field">
              <span>{f.label}</span>
              <select
                value={columns[f.key] ?? ''}
                onChange={(e) => {
                  setColumns({ ...columns, [f.key]: e.target.value === '' ? null : Number(e.target.value) });
                  changed();
                }}
              >
                <option value="">keine Spalte</option>
                {result.headers.map((h, i) => (
                  <option key={i} value={i}>
                    {h.trim() !== '' ? h : `Spalte ${i + 1} (ohne Namen)`}
                  </option>
                ))}
              </select>
            </label>
          ))}
          {result.method_columns.length > 0 ? (
            <p className="small">
              Beträge aus den Spalten der Zahlungsarten:{' '}
              {result.method_columns.map((m) => `„${result.headers[m.column]}“ (${m.method})`).join(', ')}.
            </p>
          ) : (
            columns.amount == null && (
              <p className="small muted">
                Steht der Beitrag in Spalten je Zahlungsart (z. B. „Bar“), erkennt die App sie, wenn sie so heißen wie die eingerichteten
                Zahlungsarten ({result.methods.join(', ')}).
              </p>
            )
          )}
        </fieldset>
      )}
      <div className="button-row">
        <button className="btn btn-secondary" disabled={busy || csv.trim() === ''} onClick={() => send(false)}>
          {busy ? 'Prüft …' : 'Vorschau'}
        </button>
        {result && (
          <button className="btn" disabled={!canApply} onClick={() => send(true)}>
            Übernehmen
          </button>
        )}
      </div>
      {result && (
        <section>
          {stale && <p className="small muted">Geändert: Bitte die Vorschau aktualisieren.</p>}
          <p role="status">
            Neu: {count(result.new, 'Passiver', 'Passive')} · davon mit Beitrag {result.year}: {result.payments} · schon vorhanden:{' '}
            {result.existing}
          </p>
          {result.ignored.length > 0 && (
            <p className="small">Übergangene Spalten: {result.ignored.map((c) => `„${c.column}“ (${c.reason})`).join(', ')}</p>
          )}
          {warnings > 0 && (
            <p className="small">{count(warnings, 'Zeile hat', 'Zeilen haben')} einen Hinweis (wird trotzdem übernommen).</p>
          )}
          {result.errors > 0 && (
            <>
              <p className="form-error">
                {result.errors === 1 ? '1 Zeile hat' : `${result.errors} Zeilen haben`} einen Fehler. In der Datei korrigieren oder diese
                Zeilen überspringen (z. B. Summen unter der Liste).
              </p>
              <label className="toggle">
                <input type="checkbox" checked={skipErrors} onChange={(e) => setSkipErrors(e.target.checked)} />
                <span>Zeilen mit Fehler überspringen</span>
              </label>
            </>
          )}
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.existing || r.error ? 'muted' : ''}>
                <strong>Zeile {r.line}</strong> {r.name}
                {r.existing && ' · schon eingetragen, wird übersprungen'}
                {!r.existing && !r.error && rowSummary(r) && ` · ${rowSummary(r)}`}
                {r.warnings?.map((w) => (
                  <p key={w} className="small passive-import-warning">
                    {w}
                  </p>
                ))}
                {r.error && <p className="form-error">{r.error}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
