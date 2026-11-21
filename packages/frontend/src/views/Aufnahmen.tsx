import { Fragment, useEffect, useState, type FormEvent } from 'react';
import { api, applyAdmissionsDue, can, config, levelLabel, type Admission } from '../api';
import { confirmAsk } from '../ask';
import { formatDate } from '../format';
import { t, tn } from '../i18n';
import { navigate } from '../router';
import { ErrorBox, Loading, useAsync } from '../ui';
import { describeRule, missingText } from '../admissionRule';
import { RuleResult } from '../admissionRuleView';
import { sources } from '../viewData';

/** Was die Voraussetzung verlangt, z. B. „6 Teilnahmen an „Versammlung“ und 12 Monate als Anwärter“; null ohne. */
export function admissionRule(a: Pick<Admission, 'kind' | 'count' | 'months' | 'result'>): string | null {
  if (a.result) return describeRule(a.result) || null;
  const parts: string[] = [];
  if (a.kind && a.count > 0) parts.push(tn('{count} Teilnahme an „{kind}“', '{count} Teilnahmen an „{kind}“', a.count, { kind: a.kind }));
  if (a.months > 0) parts.push(tn('{count} Monat als Anwärter', '{count} Monate als Anwärter', a.months));
  if (parts.length === 2) return t('{first} und {second}', { first: parts[0], second: parts[1] });
  return parts[0] ?? null;
}

/** Stand in einer Zeile: erfüllt seit …, sonst was noch fehlt. */
export function admissionState(a: Admission): string {
  if (a.status === 'none') return t('Ohne Voraussetzung');
  if (a.status === 'fulfilled')
    return a.fulfilled_on ? t('Voraussetzung erfüllt seit {date}', { date: formatDate(a.fulfilled_on) }) : t('Voraussetzung erfüllt');
  if (a.result) return missingText(a.result) || t('Noch nicht erfüllt');
  const open: string[] = [];
  if (a.kind && a.count > 0 && a.missing > 0)
    open.push(tn('noch {count} Teilnahme an „{kind}“', 'noch {count} Teilnahmen an „{kind}“', a.missing, { kind: a.kind }));
  if (a.months > 0) {
    if (!a.since) open.push(t('„Anwärter seit“ fehlt'));
    else if (a.months_on) open.push(t('Mindestdauer ab {date}', { date: formatDate(a.months_on) }));
  }
  return open.length > 0 ? open.join(' · ') : t('Noch nicht erfüllt');
}

const SECTION: Record<Admission['status'], number> = { fulfilled: 0, open: 2, none: 3 };
const HEADINGS = [t('Voraussetzung erfüllt'), t('Bald erfüllt'), t('Noch nicht erfüllt'), t('Ohne Voraussetzung')];
/** Abschnitt einer Person: bald erfüllt (noch nicht erfüllt, aber kurz davor) zwischen erfüllt und noch nicht. */
export const admissionSection = (a: Pick<Admission, 'status' | 'soon'>): number =>
  a.status !== 'fulfilled' && a.soon ? 1 : SECTION[a.status];

/**
 * Verwaltung → Aufnahmen (view_accounts; aufnehmen mit create_users): alle Anwärter, zuerst wer die Voraussetzung erfüllt (ältestes Datum zuerst),
 * dann wer noch nicht, dann Rollen ohne Voraussetzung. Tippen öffnet die Seite der Person mit „Als Mitglied aufnehmen“.
 */
export function Admissions() {
  const { data, error, loading, reload } = useAsync(sources.admissions.load, sources.admissions.deps, sources.admissions.key);
  // Zahl an der Kachel gleich mit aktualisieren.
  useEffect(() => {
    if (data)
      applyAdmissionsDue(
        data.filter((a) => a.status === 'fulfilled').length,
        data.filter((a) => a.status !== 'fulfilled' && a.soon).length,
      );
  }, [data]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data ?? [];
  if (list.length === 0) return <NoCandidates />;
  return (
    <ul className="card-list admissions">
      {list.map((a, i) => {
        const section = admissionSection(a);
        const heading = i === 0 || admissionSection(list[i - 1]) !== section ? HEADINGS[section] : null;
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
                  {a.since ? ' ' + t('seit {date}', { date: formatDate(a.since) }) : ''}
                  {!a.result && rule && a.kind && a.count > 0
                    ? ' · ' + t('{attended} von {count} {kind}', { attended: a.attended, count: a.count, kind: a.kind })
                    : ''}
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
      <p>{t('Keine Anwärter.')}</p>
      <p className="muted">
        {t(
          'Hier erscheint, wer eine Anwärter-Rolle hat, als Rolle oder als Zusatzrolle. Dafür setzt ein Admin unter Einstellungen → Rollen an der Rolle den Haken „Anwärter-Rolle“ und legt dort auch die Voraussetzung fest, z. B. 6 Teilnahmen an Terminen einer Terminart.',
        )}
      </p>
      {admin && (
        <div className="button-row">
          <button className="btn" type="button" onClick={() => navigate('einstellungen/rollen')}>
            {t('Zu den Rollen')}
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
    void run(() => api.setCandidateSince(a.id, since), since ? t('Anwärter seit gespeichert') : t('„Anwärter seit“ entfernt'));
  }

  async function admit() {
    const what = a.way === 'extra' ? t('Die Rolle „{role}“ wird entfernt.', { role: a.role }) : t('Die Rolle wird {member}.', { member });
    const note = a.status === 'open' ? ' ' + t('Die Voraussetzung ist noch nicht erfüllt: {state}.', { state: admissionState(a) }) : '';
    if (!(await confirmAsk(t('{name} als {member} aufnehmen? {what}{note}', { name: a.name, member, what, note })))) return;
    void run(
      async () => {
        const res = await api.admit(a.id);
        if (res.join_date) onToast(t('Eintritt auf {date} gesetzt', { date: formatDate(res.join_date) }));
      },
      t('{name} ist jetzt {member}', { name: a.name, member }),
    );
  }

  return (
    <section className="member-admission">
      <h3 className="section-title">{t('Aufnahme')}</h3>
      <div className="card stack">
        <p>
          {a.role}
          {rule ? ' · ' + t('Voraussetzung: {rule}', { rule }) : ''}
        </p>
        <p className={a.status === 'fulfilled' ? 'admission-fulfilled' : 'muted'}>
          {admissionState(a)}
          {!a.result && a.kind && a.count > 0 ? ' ' + t('({attended} von {count})', { attended: a.attended, count: a.count }) : ''}
        </p>
        {a.result && <RuleResult result={a.result} />}
        {!editable && a.since && <p className="muted">{t('Anwärter seit {date}', { date: formatDate(a.since) })}</p>}
        {editable && (
          <form className="stack" onSubmit={saveSince}>
            <label className="field">
              <span>{t('Anwärter seit')}</span>
              <input type="date" value={since} onChange={(e) => setSince(e.target.value)} disabled={busy} />
            </label>
            <div className="button-row">
              <button className="btn btn-secondary" type="submit" disabled={busy || since === (a.since ?? '')}>
                {t('Datum speichern')}
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
            {t('Als {member} aufnehmen', { member })}
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
  // Wer jetzt erfüllt, war vorher bald erfüllt (eine Liste bringt je Terminart höchstens eine Teilnahme).
  if (config?.admissionsDue != null)
    applyAdmissionsDue(
      config.admissionsDue + list.length,
      config.admissionsSoon != null ? Math.max(0, config.admissionsSoon - list.length) : undefined,
    );
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
  // Name fett, der Satz drumherum übersetzt.
  const fulfilled = t('{name} erfüllt jetzt die Voraussetzung für die Aufnahme als {member}', { member: levelLabel('member') })
    .split('{name}')
    .concat('');
  return (
    <div className="card notice-card" role="status">
      <h3 className="section-title">{t('Voraussetzung für die Aufnahme erfüllt')}</h3>
      <ul className="notice-list">
        {list.map((a) => (
          <li key={a.user_id}>
            {fulfilled[0]}
            <strong>{a.name}</strong>
            {fulfilled[1]}
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
          {t('Zu den Aufnahmen')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => setAdmitted([])}>
          {t('Schließen')}
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
      <h3 className="section-title">{t('Aufnahme als {member}', { member: levelLabel('member') })}</h3>
      <div className="card stack">
        <p>
          {a.role}
          {a.since ? ' ' + t('seit {date}', { date: formatDate(a.since) }) : ''}
        </p>
        <p className={a.status === 'fulfilled' ? 'admission-fulfilled' : 'muted'}>
          {a.status === 'none'
            ? t('Für die Aufnahme ist keine Voraussetzung festgelegt.')
            : rule
              ? t('Voraussetzung: {rule}', { rule })
              : ''}
        </p>
        {a.result && <RuleResult result={a.result} />}
        {a.status === 'fulfilled' && (
          <p className="admission-fulfilled">{t('Du erfüllst die Voraussetzung. Aufgenommen wirst du von Hand, nicht automatisch.')}</p>
        )}
      </div>
    </section>
  );
}
