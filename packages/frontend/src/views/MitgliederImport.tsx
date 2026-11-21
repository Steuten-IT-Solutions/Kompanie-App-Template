import { useState } from 'react';
import { api, can, levelLabel, type MemberImportResult, type MemberImportRow } from '../api';
import brand from '../generated/brand';
import { formatAddress } from '../address';
import { formatYearOrDate } from '../format';
import { toCsv } from '../lists';
import { readSheet } from '../sheet';
import { Credentials } from './Konten';
import { formerSummary } from './Ehemalige';
import { FileInput } from '../ui';
import { t, tn } from '../i18n';

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
  'Zugang',
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
    'ja',
  ],
  ['Max', 'Beispiel', 'Mitglied', '', '', '', '', '', '', '', '', '24.12.', '2025', '', '', '', '', '', 'nein'],
  ['Otto', 'Früher', 'ehemalig', '', '', '', '', '', '', '', '', '', 'Gründer', '', '', '', '2001', 'verstorben', ''],
];

function downloadTemplate() {
  // Zusatzrollen nur für alle, die Rollen vergeben dürfen (sonst übergeht der Server die Spalte mit Hinweis).
  const extra = can('promote_users');
  const header = extra ? [...TEMPLATE_HEADER, 'Zusatzrollen'] : TEMPLATE_HEADER;
  const rows = extra ? TEMPLATE_ROWS.map((r) => [...r, '']) : TEMPLATE_ROWS;
  const blob = new Blob([toCsv(header, rows)], { type: 'text/csv;charset=utf-8' });
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
  if (r.username) parts.push(t('Benutzername {username}', { username: r.username }));
  if (r.email) parts.push(r.email);
  if (r.phone) parts.push(r.phone);
  if (r.address) parts.push(formatAddress(r.address).join(', '));
  if (r.birthday) {
    const [y, m, d] = r.birthday.length === 5 ? ['', ...r.birthday.split('-')] : r.birthday.split('-');
    parts.push(t('Geburtstag {date}', { date: `${d}.${m}.${y}` }));
  }
  if (r.joined_on) parts.push(t('Eintritt {date}', { date: formatYearOrDate(r.joined_on) }));
  if (r.points) parts.push(t('{count} {points}', { count: r.points, points: sec.points }));
  if (r.rank) parts.push(r.rank);
  if (r.titles?.length) parts.push(r.titles.join(', '));
  return parts.join(' · ');
}

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
    const noAccess = result.new.no_access ?? 0;
    return (
      <div className="member-import stack">
        <h2 className="member-name no-print">{t('Import übernommen')}</h2>
        <p role="status" className="no-print">
          {tn('{count} Konto mit Startpasswort angelegt', '{count} Konten mit Startpasswort angelegt', result.created.length)}
          {noAccess > 0 && ' · ' + tn('{count} Mitglied ohne Zugang', '{count} Mitglieder ohne Zugang', noAccess)}
          {former > 0 && ' · ' + tn('{count} Person als ehemalig', '{count} Personen als ehemalig', former)}
          {result.offices_linked > 0 &&
            ' · ' +
              tn('{count} Eintrag in den {offices} verknüpft', '{count} Einträge in den {offices} verknüpft', result.offices_linked, {
                offices: sec.offices,
              })}
          .
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
            {doneLabel ?? t('Fertig')}
          </button>
        )}
      </div>
    );
  }

  const errors = result?.rows.filter((r) => r.error).length ?? 0;
  const total = result ? result.new.active + result.new.former : 0;
  return (
    <div className="member-import office-import stack">
      <button type="button" className="btn btn-link" onClick={downloadTemplate}>
        {t('Vorlage herunterladen (CSV für Excel)')}
      </button>
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
          {busy && !result ? t('Prüft …') : t('Vorschau')}
        </button>
        {result && (
          <button className="btn" disabled={busy || errors > 0 || total === 0} onClick={() => send(true)}>
            {busy ? t('Legt an …') : t('Übernehmen')}
          </button>
        )}
      </div>
      {result && (
        <section>
          <p role="status">
            {t('Neu: {accounts} mit Anmeldung', {
              accounts: tn('{count} Konto', '{count} Konten', result.new.active - (result.new.no_access ?? 0)),
            })}
            {(result.new.no_access ?? 0) > 0 && ' · ' + t('{count} ohne Zugang', { count: result.new.no_access ?? 0 })}
            {' · '}
            {t('{count} ehemalig', { count: result.new.former })}
            {' · '}
            {t('schon vorhanden: {count}', { count: result.existing })}
          </p>
          {result.columns.ignored.length > 0 && (
            <p className="small">
              {t('Übergangene Spalten: {columns}', {
                columns: result.columns.ignored.map((c) => t('„{column}“ ({reason})', { column: c.column, reason: c.reason })).join(', '),
              })}
            </p>
          )}
          {result.offices_linked > 0 && (
            <p className="small">
              {tn(
                '{count} Eintrag in den {offices}, der bisher nur den Namen hatte, wird mit dem Konto verknüpft.',
                '{count} Einträge in den {offices}, die bisher nur den Namen hatten, werden mit dem Konto verknüpft.',
                result.offices_linked,
                { offices: sec.offices },
              )}
            </p>
          )}
          {errors > 0 && (
            <p className="form-error">
              {tn(
                '{count} Zeile hat einen Fehler; bitte erst in der Datei korrigieren.',
                '{count} Zeilen haben einen Fehler; bitte erst in der Datei korrigieren.',
                errors,
              )}
            </p>
          )}
          <ul className="office-import-rows">
            {result.rows.map((r) => (
              <li key={r.line} className={r.existing ? 'muted' : ''}>
                <strong>{t('Zeile {line}', { line: r.line })}</strong> {r.name}
                {r.status && !r.existing && (
                  <>
                    {' '}
                    <span className="badge badge-role">{r.status === 'former' ? t('Ehemalig') : levelLabel(r.level ?? 'member')}</span>
                    {r.extra_roles?.map((label) => (
                      <span key={label} className="badge badge-role">
                        {label}
                      </span>
                    ))}
                    {r.no_access && <span className="badge badge-no-access">{t('ohne Zugang')}</span>}
                  </>
                )}
                {!r.existing && !r.error && rowSummary(r) && ` · ${rowSummary(r)}`}
                {r.existing &&
                  ' · ' + (r.existing.former ? t('schon als ehemalig eingetragen') : t('hat schon ein Konto, wird übersprungen'))}
                {r.error && <p className="form-error">{r.error}</p>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}
