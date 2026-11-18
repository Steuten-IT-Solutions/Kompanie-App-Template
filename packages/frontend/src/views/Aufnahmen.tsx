import { Fragment, useEffect, useState, type FormEvent } from 'react';
import { api, applyAdmissionsDue, can, config, levelLabel, type Admission } from '../api';
import { confirmAsk } from '../ask';
import { formatDate } from '../format';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';
import { describeRule, missingText } from '../admissionRule';
import { RuleResult } from '../admissionRuleView';
import { sources } from '../viewData';

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** Was die Voraussetzung verlangt, z. B. „6 Teilnahmen an „Versammlung“ und 12 Monate als Anwärter“; null ohne. */
export function admissionRule(a: Pick<Admission, 'kind' | 'count' | 'months' | 'result'>): string | null {
  if (a.result) return describeRule(a.result) || null;
  const parts: string[] = [];
  if (a.kind && a.count > 0) parts.push(`${plural(a.count, 'Teilnahme', 'Teilnahmen')} an „${a.kind}“`);
  if (a.months > 0) parts.push(`${plural(a.months, 'Monat', 'Monate')} als Anwärter`);
  return parts.length > 0 ? parts.join(' und ') : null;
}

/** Stand in einer Zeile: erfüllt seit …, sonst was noch fehlt. */
export function admissionState(a: Admission): string {
  if (a.status === 'none') return 'Ohne Voraussetzung';
  if (a.status === 'fulfilled') return `Voraussetzung erfüllt${a.fulfilled_on ? ` seit ${formatDate(a.fulfilled_on)}` : ''}`;
  if (a.result) return missingText(a.result) || 'Noch nicht erfüllt';
  const open: string[] = [];
  if (a.kind && a.count > 0 && a.missing > 0) open.push(`noch ${plural(a.missing, 'Teilnahme', 'Teilnahmen')} an „${a.kind}“`);
  if (a.months > 0) {
    if (!a.since) open.push('„Anwärter seit“ fehlt');
    else if (a.months_on) open.push(`Mindestdauer ab ${formatDate(a.months_on)}`);
  }
  return open.length > 0 ? open.join(' · ') : 'Noch nicht erfüllt';
}

const SECTION: Record<Admission['status'], number> = { fulfilled: 0, open: 1, none: 2 };
const HEADINGS = ['Voraussetzung erfüllt', 'Noch nicht erfüllt', 'Ohne Voraussetzung'];

/**
 * Verwaltung → Aufnahmen (view_accounts; aufnehmen mit create_users): alle Anwärter, zuerst wer die Voraussetzung erfüllt (ältestes Datum zuerst),
 * dann wer noch nicht, dann Rollen ohne Voraussetzung. Tippen öffnet die Seite der Person mit „Als Mitglied aufnehmen“.
 */
export function Admissions() {
  const { data, error, loading, reload } = useAsync(sources.admissions.load, sources.admissions.deps, sources.admissions.key);
  // Zahl an der Kachel gleich mit aktualisieren.
  useEffect(() => {
    if (data) applyAdmissionsDue(data.filter((a) => a.status === 'fulfilled').length);
  }, [data]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data ?? [];
  if (list.length === 0) return <NoCandidates />;
  return (
    <ul className="card-list admissions">
      {list.map((a, i) => {
        const heading = i === 0 || SECTION[list[i - 1].status] !== SECTION[a.status] ? HEADINGS[SECTION[a.status]] : null;
        const rule = admissionRule(a);
        return (
          <Fragment key={a.id}>
            {heading && <li className="section-title list-divider">{heading}</li>}
            <li
              className={`card row clickable ${a.status === 'fulfilled' ? 'due' : ''}`}
              onClick={() => navigate(`verwaltung/mitglied/${a.id}/konto`)}
            >
              <div className="row-main">
                <div className="row-title">{a.name}</div>
                <div className="row-sub">
                  {a.role}
                  {a.since ? ` seit ${formatDate(a.since)}` : ''}
                  {!a.result && rule && a.kind && a.count > 0 ? ` · ${a.attended} von ${a.count} ${a.kind}` : ''}
                </div>
                <div className="row-sub">{admissionState(a)}</div>
              </div>
              <span className="chevron" aria-hidden="true">
                ›
              </span>
            </li>
          </Fragment>
        );
      })}
    </ul>
  );
}

/**
 * Noch niemand: Gibt es keine Anwärter-Rolle, erscheint hier auch niemand. Darum steht hier, wie man sie einrichtet
 * (Einstellungen → Rollen, nur Admins).
 */
function NoCandidates() {
  const admin = can('manage_settings');
  return (
    <div className="card stack">
      <p>Keine Anwärter.</p>
      <p className="muted">
        Hier erscheint, wer eine Anwärter-Rolle hat, als Rolle oder als Zusatzrolle. Dafür setzt ein Admin unter Einstellungen → Rollen an
        der Rolle den Haken „Anwärter-Rolle“ und legt dort auch die Voraussetzung fest, z. B. 6 Teilnahmen an Terminen einer Terminart.
      </p>
      {admin && (
        <div className="button-row">
          <button className="btn" type="button" onClick={() => navigate('einstellungen/rollen')}>
            Zu den Rollen
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Auf der Seite eines Anwärters (unter „Konto und Rolle“, view_accounts; ändern und aufnehmen mit create_users): Anwärter-Rolle, „Anwärter seit“ (ändern), Stand
 * der Voraussetzung und „Als Mitglied aufnehmen“. Die Voraussetzung sperrt nicht; die Rückfrage weist darauf hin.
 */
export function MemberAdmission({
  admission: a,
  onChanged,
  onToast,
}: {
  admission: Admission;
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const [since, setSince] = useState(a.since ?? '');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const rule = admissionRule(a);
  const member = levelLabel('member');
  // Nur sehen (Konten sehen ohne Mitglieder anlegen): Stand ohne Datum ändern und Aufnehmen.
  const editable = can('create_users');

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

  function saveSince(e: FormEvent) {
    e.preventDefault();
    void run(() => api.setCandidateSince(a.id, since), since ? 'Anwärter seit gespeichert' : '„Anwärter seit“ entfernt');
  }

  async function admit() {
    const what = a.way === 'extra' ? `Die Rolle „${a.role}“ wird entfernt.` : `Die Rolle wird ${member}.`;
    const note = a.status === 'open' ? ` Die Voraussetzung ist noch nicht erfüllt: ${admissionState(a)}.` : '';
    if (!(await confirmAsk(`${a.name} als ${member} aufnehmen? ${what}${note}`))) return;
    void run(async () => {
      const res = await api.admit(a.id);
      if (res.join_date) onToast(`Eintritt auf ${formatDate(res.join_date)} gesetzt`);
    }, `${a.name} ist jetzt ${member}`);
  }

  return (
    <section className="member-admission">
      <h3 className="section-title">Aufnahme</h3>
      <div className="card stack">
        <p>
          {a.role}
          {rule ? ` · Voraussetzung: ${rule}` : ''}
        </p>
        <p className={a.status === 'fulfilled' ? 'admission-fulfilled' : 'muted'}>
          {admissionState(a)}
          {!a.result && a.kind && a.count > 0 ? ` (${a.attended} von ${a.count})` : ''}
        </p>
        {a.result && <RuleResult result={a.result} />}
        {!editable && a.since && <p className="muted">Anwärter seit {formatDate(a.since)}</p>}
        {editable && (
          <form className="stack" onSubmit={saveSince}>
            <label className="field">
              <span>Anwärter seit</span>
              <input type="date" value={since} onChange={(e) => setSince(e.target.value)} disabled={busy} />
            </label>
            <div className="button-row">
              <button className="btn btn-secondary" type="submit" disabled={busy || since === (a.since ?? '')}>
                Datum speichern
              </button>
            </div>
          </form>
        )}
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        {editable && (
          <button className="btn btn-block promote-candidate" type="button" disabled={busy} onClick={() => void admit()}>
            Als {member} aufnehmen
          </button>
        )}
      </div>
    </section>
  );
}

/* Wer nach dem Speichern einer Anwesenheitsliste neu die Voraussetzung erfüllt: Hinweis oben in der Verwaltung, bis er
 * geschlossen wird (wie nach dem Vergeben von Punkten bei den Beförderungen). */
type NewAdmission = { user_id: number; name: string };
let admitted: NewAdmission[] = [];
const admittedListeners = new Set<(a: NewAdmission[]) => void>();

function setAdmitted(next: NewAdmission[]) {
  admitted = next;
  admittedListeners.forEach((l) => l(admitted));
}

/** Nach dem Speichern einer Anwesenheitsliste: Hinweis zeigen und die Zahl an der Kachel erhöhen. */
export function noteAdmissions(list: NewAdmission[] | undefined) {
  if (!list || list.length === 0) return;
  const known = new Set(admitted.map((a) => a.user_id));
  setAdmitted([...admitted, ...list.filter((a) => !known.has(a.user_id))]);
  if (config?.admissionsDue != null) applyAdmissionsDue(config.admissionsDue + list.length);
}

export function AdmissionNotice() {
  const [list, setList] = useState(admitted);
  useEffect(() => {
    admittedListeners.add(setList);
    setList(admitted);
    return () => {
      admittedListeners.delete(setList);
    };
  }, []);
  if (list.length === 0) return null;
  return (
    <div className="card notice-card" role="status">
      <h3 className="section-title">Voraussetzung für die Aufnahme erfüllt</h3>
      <ul className="notice-list">
        {list.map((a) => (
          <li key={a.user_id}>
            <strong>{a.name}</strong> erfüllt jetzt die Voraussetzung für die Aufnahme als {levelLabel('member')}
          </li>
        ))}
      </ul>
      <div className="button-row">
        <button
          className="btn"
          type="button"
          onClick={() => {
            setAdmitted([]);
            navigate('verwaltung/aufnahmen');
          }}
        >
          Zu den Aufnahmen
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => setAdmitted([])}>
          Schließen
        </button>
      </div>
    </div>
  );
}

/**
 * Eigener Stand als Anwärter (Meins → Übersicht und Konto): Rolle, „Anwärter seit“ und was noch fehlt, ohne Knöpfe.
 * Erscheint nur bei Anwärtern (GET /me/admission liefert sonst null) und nie bei älteren Servern ohne den Weg.
 */
export function MyAdmission() {
  const [a, setA] = useState<Admission | null>(null);
  useEffect(() => {
    let live = true;
    api
      .myAdmission()
      .then((res) => {
        if (live) setA(res.admission);
      })
      .catch(() => {
        // Ältere Server oder offline: nichts zeigen.
      });
    return () => {
      live = false;
    };
  }, []);
  if (!a) return null;
  const rule = admissionRule(a);
  return (
    <section className="member-admission my-admission">
      <h3 className="section-title">Aufnahme als {levelLabel('member')}</h3>
      <div className="card stack">
        <p>
          {a.role}
          {a.since ? ` seit ${formatDate(a.since)}` : ''}
        </p>
        <p className={a.status === 'fulfilled' ? 'admission-fulfilled' : 'muted'}>
          {a.status === 'none' ? 'Für die Aufnahme ist keine Voraussetzung festgelegt.' : rule ? `Voraussetzung: ${rule}` : ''}
        </p>
        {a.result && <RuleResult result={a.result} />}
        {a.status === 'fulfilled' && (
          <p className="admission-fulfilled">Du erfüllst die Voraussetzung. Aufgenommen wirst du von Hand, nicht automatisch.</p>
        )}
      </div>
    </section>
  );
}
