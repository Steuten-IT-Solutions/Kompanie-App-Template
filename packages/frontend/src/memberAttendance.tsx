// Seite einer Person in der Verwaltung: Übersicht der Anwesenheit (anwesend, entschuldigt, unentschuldigt) für ein
// wählbares Jahr oder gesamt, Vorgabe das laufende Jahr. Antippen öffnet die Einträge des Zeitraums. Es zählen nur
// gespeicherte Einträge; eine Absage, die eine Liste nur vorschlägt, zählt erst nach dem Speichern.

import { useState } from 'react';
import { ATTENDANCE_LABELS, type MemberAttendance as Data } from './api';
import { attendanceYears, inPeriod, periodCounts, type AttendancePeriod } from './attendanceYears';
import { formatDate } from './format';
import { Sheet } from './ui';
import { t, tn } from './i18n';

const CELLS = [
  { status: 'present', label: t('anwesend') },
  { status: 'excused', label: t('entschuldigt') },
  { status: 'absent', label: t('unentschuldigt') },
] as const;

const periodLabel = (p: AttendancePeriod) => (p === 'all' ? t('gesamt') : p);

export function MemberAttendance({ data, open, onOpen, onClose }: { data: Data; open: boolean; onOpen: () => void; onClose: () => void }) {
  const [period, setPeriod] = useState<AttendancePeriod>(data.today.slice(0, 4));
  const years = attendanceYears(data.entries, data.today);
  const counts = periodCounts(data.entries, period, data.today);
  const entries = inPeriod(data.entries, period);
  return (
    <section className="member-attendance">
      <h3 className="section-title">{t('Anwesenheit')}</h3>
      <label className="member-attendance-period">
        <span>{t('Zeitraum')}</span>
        <select value={period} onChange={(e) => setPeriod(e.target.value)}>
          {years.map((y) => (
            <option key={y} value={y}>
              {y}
            </option>
          ))}
          <option value="all">{t('Gesamt')}</option>
        </select>
      </label>
      <button
        type="button"
        className="card member-attendance-tile"
        onClick={onOpen}
        aria-label={t('Anwesenheit {period}: {present} anwesend, {excused} entschuldigt, {absent} unentschuldigt. Einträge ansehen', {
          period: periodLabel(period),
          present: counts.present,
          excused: counts.excused,
          absent: counts.absent,
        })}
      >
        {CELLS.map((c) => (
          <span key={c.status} className="member-attendance-cell">
            <span className={`member-attendance-count badge-att-${c.status}`}>{counts[c.status]}</span>
            <span className="member-attendance-label">{c.label}</span>
          </span>
        ))}
        <span className="chevron" aria-hidden="true">
          ›
        </span>
      </button>
      {counts.upcoming > 0 && (
        <p className="muted small member-attendance-note">
          {tn(
            'Davon {count} Entschuldigung für kommende Termine.',
            'Davon {count} Entschuldigungen für kommende Termine.',
            counts.upcoming,
          )}
        </p>
      )}
      {open && (
        <Sheet title={t('Anwesenheit {period}', { period: periodLabel(period) })} onClose={onClose} routed>
          {entries.length === 0 ? (
            <p className="muted">{t('Im Zeitraum ist für diese Person nichts eingetragen.')}</p>
          ) : (
            <ul className="card-list">
              {entries.map((e) => (
                <li key={e.list_id} className="card row">
                  <div className="row-main">
                    <div className="row-title">{e.title}</div>
                    <div className="row-sub">
                      {formatDate(e.date)}
                      {e.date > data.today && ` · ${t('kommender Termin')}`}
                    </div>
                  </div>
                  <span className={`badge badge-att badge-att-${e.status}`}>
                    {e.status === 'absent' ? t('unentschuldigt') : ATTENDANCE_LABELS[e.status]}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Sheet>
      )}
    </section>
  );
}
