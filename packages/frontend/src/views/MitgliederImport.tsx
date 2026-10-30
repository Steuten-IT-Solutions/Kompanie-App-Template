import { useState } from 'react';
import { api, levelLabel, newAccountRoles, type MemberImportResult, type MemberImportRow } from '../api';
import brand from '../generated/brand';
import { formatAddress } from '../address';
import { formatYearOrDate } from '../format';
import { toCsv } from '../lists';
import { readSheet } from '../sheet';
import { Credentials } from './Konten';
import { formerSummary } from './Ehemalige';

const sec = brand.labels.sections;

/** Vorlage zum Herunterladen: alle Spalten, die der Import kennt, mit Beispielen. */
const TEMPLATE_HEADER = [
  'Vorname',
  'Nachname',
  'Status',
  'Benutzername',
  'E-Mail',
  'Handynummer',
  'Straße',
  'Hausnummer',
  'PLZ',
  'Ort',
  'Land',
  'Geburtstag',
  'Eintritt',
  'Punkte',
  'Dienstgrad',
  'Titel',
  'Ausgeschieden',
  'Grund',
];
const TEMPLATE_ROWS = [
  [
    'Erika',
    'Muster',
    'Mitglied',
    '',
    'erika@example.org',
    '+49 171 1234567',
    'Musterstraße',
    '1',
    '12345',
    'Musterstadt',
    'Deutschland',
    '03.02.1980',
    '1999',
    '',
    '',
    '',
    '',
    '',
  ],
  ['Max', 'Beispiel', 'Mitglied', '', '', '', '', '', '', '', '', '24.12.', '2025', '', '', '', '', ''],
  ['Otto', 'Früher', 'ehemalig', '', '', '', '', '', '', '', '', '', 'Gründer', '', '', '', '2001', 'verstorben'],
];

function downloadTemplate() {
  const blob = new Blob([toCsv(TEMPLATE_HEADER, TEMPLATE_ROWS)], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'mitglieder-vorlage.csv';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/** Was die App aus einer Zeile verstanden hat, kurz. */
function rowSummary(r: MemberImportRow): string {
  if (r.status === 'former') {
    return formerSummary({ reason: r.reason ?? 'left', joined_on: r.joined_on ?? '', founder: !!r.founder, left_on: r.left_on ?? '' });
  }
  const parts: string[] = [];
  if (r.username) parts.push(`Benutzername ${r.username}`);
  if (r.email) parts.push(r.email);
  if (r.phone) parts.push(r.phone);
  if (r.address) parts.push(formatAddress(r.address).join(', '));
  if (r.birthday) {
    const [y, m, d] = r.birthday.length === 5 ? ['', ...r.birthday.split('-')] : r.birthday.split('-');
    parts.push(`Geburtstag ${d}.${m}.${y}`);
  }
  if (r.joined_on) parts.push(`Eintritt ${formatYearOrDate(r.joined_on)}`);
  if (r.points) parts.push(`${r.points} ${sec.points}`);
  if (r.rank) parts.push(r.rank);
  if (r.titles?.length) parts.push(r.titles.join(', '));
  return parts.join(' · ');
}

const count = (n: number, one: string, many: string) => (n === 1 ? `1 ${one}` : `${n} ${many}`);

/**
 * Mitglieder aus einer Tabelle (CSV oder Excel) übernehmen, mit Anwärtern und Ehemaligen: im Einrichtungsassistenten und
 * unter Verwaltung → Mitglied anlegen. Vorschau mit Fehlern je Zeile, dann übernehmen; danach die Zugangsdaten der neuen
 * Mitglieder und Anwärter. Ehemalige bekommen ein Konto ohne Anmeldung (wie beim Import der Ehemaligen).
 */
export function MemberImport({ onDone, doneLabel }: { onDone: () => void; doneLabel?: string }) {
  const [csv, setCsv] = useState('');
  const [fileName, setFileName] = useState('');
  const [result, setResult] = useState<MemberImportResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function send(apply: boolean) {
    setBusy(true);
    setError(null);
    try {
      setResult(await api.importMembers(csv, apply));
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
    try {
      setCsv(await readSheet(file));
      setFileName(file.name);
    } catch (e) {
      setCsv('');
      setFileName('');
      setError((e as Error).message);
    }
  }

  if (result?.applied) {
    // Nach dem Übernehmen zählt new, was wirklich angelegt wurde.
    const former = result.new.former;
    return (
      <div className="member-import">
        <h2 className="member-name no-print">Import übernommen</h2>
        <p role="status" className="no-print">
          {count(result.created.length, 'Konto', 'Konten')} mit Startpasswort angelegt
          {former > 0 && ` · ${count(former, 'Person', 'Personen')} als ehemalig`}
          {result.offices_linked > 0 && ` · ${result.offices_linked} Einträge in den ${sec.offices} verknüpft`}.
        </p>
        {result.error && (
          <p className="form-error no-print" role="alert">
            {result.error}
          </p>
        )}
        {result.created.length > 0 ? (
          <Credentials people={result.created} onDone={onDone} doneLabel={doneLabel} />
        ) : (
          <button className="btn btn-block" onClick={onDone}>
            {doneLabel ?? 'Fertig'}
          </button>
        )}
      </div>
    );
  }

  const errors = result?.rows.filter((r) => r.error).length ?? 0;
  // Rollen, die sich beim Anlegen wählen lassen (z. B. Mitglied, Anwärter)
  const roles = newAccountRoles()
    .map((r) => r.label)
    .join(', ');
  const total = result ? result.new.active + result.new.former : 0;
  return (
    <div className="member-import office-import">
      <p className="muted small">
        Eine Zeile je Person, die erste Zeile enthält die Spaltennamen. Nötig ist nur <strong>Vorname</strong>; außerdem kennt die App
        Nachname, Status ({roles} oder ehemalig; leer heißt {levelLabel('member')}), Benutzername, E-Mail, Handynummer, Adresse (Straße,
        Hausnummer, Adresszusatz, PLZ, Ort, Land; leer heißt Deutschland), Geburtstag, Eintritt, Punkte, Dienstgrad, Titel sowie bei
        Ehemaligen Ausgeschieden und Grund (ausgetreten, verstorben, sonstiges). Daten als TT.MM.JJJJ, den Eintritt auch nur als Jahr. Die
        Reihenfolge der Spalten ist egal, andere Spalten werden übergangen.
      </p>
      <p className="muted small">
        Aktive bekommen ein Startpasswort, das nach dem Übernehmen einmal angezeigt wird. Ehemalige bekommen ein Konto ohne Anmeldung. Wer
        schon ein Konto mit genau diesem Namen hat, wird übersprungen; ein zweiter Import legt also niemanden doppelt an. Bitte die Datei
        danach löschen, wenn du sie nicht mehr brauchst.
      </p>
      <button type="button" className="btn btn-link" onClick={downloadTemplate}>
        Vorlage herunterladen (CSV für Excel)
      </button>
      <label className="field">
        <span>Datei wählen (Excel .xlsx oder CSV)</span>
        <input
          type="file"
          accept=".xlsx,.csv,.txt,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          onChange={(e) => pick(e.target.files?.[0])}
        />
      </label>
      <label className="field">
        <span>{fileName ? `Inhalt von ${fileName}` : 'Oder Tabelle einfügen (z. B. aus Excel kopiert)'}</span>
        <textarea
          value={csv}
          placeholder={'Vorname;Nachname;Status\nErika;Muster;Mitglied\nOtto;Früher;ehemalig'}
          onChange={(e) => {
            setCsv(e.target.value);
            setResult(null);
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
          {busy && !result ? 'Prüft …' : 'Vorschau'}
        </button>
        {result && (
          <button className="btn" disabled={busy || errors > 0 || total === 0} onClick={() => send(true)}>
            {busy ? 'Legt an …' : 'Übernehmen'}
          </button>
        )}
      </div>
      {result && (
        <section>
          <p role="status">
            Neu: {count(result.new.active, 'Konto', 'Konten')} mit Anmeldung · {result.new.former} ehemalig · schon vorhanden:{' '}
            {result.existing}
          </p>
          {result.columns.ignored.length > 0 && (
            <p className="small">Übergangene Spalten: {result.columns.ignored.map((c) => `„${c.column}“ (${c.reason})`).join(', ')}</p>
          )}
          {result.offices_linked > 0 && (
            <p className="small">
              {result.offices_linked} Einträge in den {sec.offices}, die bisher nur den Namen hatten, werden mit dem Konto verknüpft.
            </p>
          )}
          {errors > 0 && (
            <p className="form-error">
              {errors === 1 ? '1 Zeile hat' : `${errors} Zeilen haben`} einen Fehler; bitte erst in der Datei korrigieren.
            </p>
          )}
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.existing ? 'muted' : ''}>
                <strong>Zeile {r.line}</strong> {r.name}
                {r.status && !r.existing && (
                  <>
                    {' '}
                    <span className="badge badge-role">{r.status === 'former' ? 'Ehemalig' : levelLabel(r.level ?? 'member')}</span>
                  </>
                )}
                {!r.existing && !r.error && rowSummary(r) && ` · ${rowSummary(r)}`}
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
