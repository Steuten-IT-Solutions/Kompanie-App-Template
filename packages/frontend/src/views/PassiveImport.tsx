import { useState } from 'react';
import { api, type PassiveImportField, type PassiveImportResult, type PassiveImportRow } from '../api';
import { formatAddress } from '../address';
import { formatDate, formatEuro, todayYmd } from '../format';
import { navigate } from '../router';
import { readSheet } from '../sheet';
import { FileInput } from '../ui';
import { t, tc, tn } from '../i18n';

/** Felder, denen man Spalten zuordnen kann, in dieser Reihenfolge; personal = nur mit manage_personal */
const FIELDS: { key: PassiveImportField; label: string; personal?: boolean }[] = [
  { key: 'first_name', label: t('Vorname') },
  { key: 'last_name', label: t('Nachname') },
  { key: 'street', label: t('Straße (auch mit Hausnummer)'), personal: true },
  { key: 'house_number', label: t('Hausnummer'), personal: true },
  { key: 'extra', label: t('Adresszusatz'), personal: true },
  { key: 'postal_code', label: t('PLZ'), personal: true },
  { key: 'city', label: tc('Adresse', 'Ort'), personal: true },
  { key: 'country', label: t('Land'), personal: true },
  { key: 'phone', label: t('Telefon'), personal: true },
  { key: 'email', label: t('E-Mail-Adresse'), personal: true },
  { key: 'birth_date', label: t('Geburtsdatum'), personal: true },
  { key: 'joined', label: t('Eintritt') },
  { key: 'note', label: t('Bemerkung') },
  { key: 'caretaker', label: t('Betreuer') },
  { key: 'amount', label: t('Betrag') },
  { key: 'method', label: t('Zahlungsart') },
  { key: 'paid_on', label: t('Bezahlt am') },
];

type Columns = Partial<Record<PassiveImportField, number | null>>;

/** Was die App aus einer Zeile verstanden hat, kurz. */
function rowSummary(r: PassiveImportRow): string {
  const parts: string[] = [];
  if (r.address) parts.push(formatAddress(r.address).join(', '));
  if (r.phone) parts.push(r.phone);
  if (r.email) parts.push(r.email);
  if (r.birth_date) parts.push(t('geboren {date}', { date: formatDate(r.birth_date) }));
  if (r.joined) parts.push(t('Eintritt {date}', { date: r.joined }));
  if (r.caretaker) parts.push(t('Betreuer: {name}', { name: r.caretaker.name }));
  if (r.payment)
    parts.push(
      t('Beitrag {year}: {amount} ({method}, {date})', {
        year: r.payment.year,
        amount: formatEuro(r.payment.amount_cents),
        method: r.payment.method,
        date: formatDate(r.payment.paid_on),
      }),
    );
  if (r.note) parts.push(t('Bemerkung: {note}', { note: r.note }));
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
          tn('{count} Passiver angelegt', '{count} Passive angelegt', res.new) +
            (res.payments > 0 ? ', ' + tn('{count} Zahlung', '{count} Zahlungen', res.payments) : ''),
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
        ‹ {t('Passive')}
      </button>
      <h2 className="member-name">{t('Passive importieren')}</h2>
      <p className="small muted">
        {t(
          'Eine Liste aus Excel (.xlsx) oder als CSV. Über der Kopfzeile darf z. B. ein Titel stehen. Wer schon als Passiver eingetragen ist (gleicher Vor- und Nachname), wird übersprungen.',
        )}
      </p>
      <label className="field">
        <span>{t('Datei wählen (Excel .xlsx oder CSV)')}</span>
        <FileInput
          accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e) => pick(e.target.files?.[0])}
        />
      </label>
      <label className="field">
        <span>{fileName ? t('Inhalt von {name}', { name: fileName }) : t('Oder Tabelle einfügen (z. B. aus Excel kopiert)')}</span>
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
          <span>{t('Beitrag für das Jahr')}</span>
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
          <span>{t('Datum für Zahlungen ohne Datum')}</span>
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
          <legend>{t('Spalten zuordnen')}</legend>
          <p className="small muted">
            {t('Kopfzeile ist Zeile {line}. Was nicht passt, hier ändern; danach „Vorschau“.', { line: result.header_line })}
          </p>
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
                <option value="">{t('keine Spalte')}</option>
                {result.headers.map((h, i) => (
                  <option key={i} value={i}>
                    {h.trim() !== '' ? h : t('Spalte {number} (ohne Namen)', { number: i + 1 })}
                  </option>
                ))}
              </select>
            </label>
          ))}
          {result.method_columns.length > 0 ? (
            <p className="small">
              {t('Beträge aus den Spalten der Zahlungsarten: {columns}.', {
                columns: result.method_columns
                  .map((m) => t('„{column}“ ({reason})', { column: result.headers[m.column], reason: m.method }))
                  .join(', '),
              })}
            </p>
          ) : (
            columns.amount == null && (
              <p className="small muted">
                {t(
                  'Steht der Beitrag in Spalten je Zahlungsart (z. B. „Bar“), erkennt die App sie, wenn sie so heißen wie die eingerichteten Zahlungsarten ({methods}).',
                  { methods: result.methods.join(', ') },
                )}
              </p>
            )
          )}
        </fieldset>
      )}
      <div className="button-row">
        <button className="btn btn-secondary" disabled={busy || csv.trim() === ''} onClick={() => send(false)}>
          {busy ? t('Prüft …') : t('Vorschau')}
        </button>
        {result && (
          <button className="btn" disabled={!canApply} onClick={() => send(true)}>
            {t('Übernehmen')}
          </button>
        )}
      </div>
      {result && (
        <section>
          {stale && <p className="small muted">{t('Geändert: Bitte die Vorschau aktualisieren.')}</p>}
          <p role="status">
            {t('Neu: {passives} · davon mit Beitrag {year}: {payments}', {
              passives: tn('{count} Passiver', '{count} Passive', result.new),
              year: result.year,
              payments: result.payments,
            })}
            {' · '}
            {t('schon vorhanden: {count}', { count: result.existing })}
          </p>
          {result.ignored.length > 0 && (
            <p className="small">
              {t('Übergangene Spalten: {columns}', {
                columns: result.ignored.map((c) => t('„{column}“ ({reason})', { column: c.column, reason: c.reason })).join(', '),
              })}
            </p>
          )}
          {warnings > 0 && (
            <p className="small">
              {tn(
                '{count} Zeile hat einen Hinweis (wird trotzdem übernommen).',
                '{count} Zeilen haben einen Hinweis (wird trotzdem übernommen).',
                warnings,
              )}
            </p>
          )}
          {result.errors > 0 && (
            <>
              <p className="form-error">
                {tn(
                  '{count} Zeile hat einen Fehler. In der Datei korrigieren oder diese Zeilen überspringen (z. B. Summen unter der Liste).',
                  '{count} Zeilen haben einen Fehler. In der Datei korrigieren oder diese Zeilen überspringen (z. B. Summen unter der Liste).',
                  result.errors,
                )}
              </p>
              <label className="toggle">
                <input type="checkbox" checked={skipErrors} onChange={(e) => setSkipErrors(e.target.checked)} />
                <span>{t('Zeilen mit Fehler überspringen')}</span>
              </label>
            </>
          )}
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.existing || r.error ? 'muted' : ''}>
                <strong>{t('Zeile {line}', { line: r.line })}</strong> {r.name}
                {r.existing && ' · ' + t('schon eingetragen, wird übersprungen')}
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
