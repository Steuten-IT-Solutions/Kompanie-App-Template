import { useState, type ReactElement } from 'react';
import { api, type Office, type OfficeHolder, type OfficesOverview, type OfficeTerm } from '../api';
import brand from '../generated/brand';
import { MemberAvatar } from '../memberPhoto';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const sec = brand.labels.sections;

/** „2007–2020“, „seit 2020“ (läuft), „2025“ (einzelnes Jahr oder Anfang und Ende im selben Jahr). */
export function termYears(t: Pick<OfficeTerm, 'kind' | 'start_year' | 'end_year'>): string {
  if (t.kind === 'point' || t.end_year === t.start_year) return String(t.start_year);
  return t.end_year === null ? `seit ${t.start_year}` : `${t.start_year}–${t.end_year}`;
}

/**
 * Ämter laden; derselbe Speicher für Zeitstrahl, Mitgliederliste, „Meins“ und Mitgliedsdetails. Ohne den Bereich
 * (enabled = false) keine Anfrage: Sie endete mit 403 und stünde dann unter „Fehler melden“.
 */
export function useOffices(enabled = true) {
  return useAsync<OfficesOverview | null>(() => (enabled ? api.offices() : Promise.resolve(null)), [], enabled ? 'offices' : undefined);
}

/** Personen, die mindestens eine Amtszeit mit ihrem Konto haben (für den Knopf „Ämter“ in der Mitgliederliste). */
export function holderIds(data: OfficesOverview | null | undefined): Set<number> {
  const ids = new Set<number>();
  for (const o of data?.offices ?? []) for (const t of o.terms) for (const h of t.holders) if (h.user_id) ids.add(h.user_id);
  return ids;
}

/** Dauer in Jahren: „18 Jahre“, laufende bis heute; leer bei einzelnen Jahren. */
export function termDuration(t: Pick<OfficeTerm, 'kind' | 'start_year' | 'end_year'>, thisYear = new Date().getFullYear()): string {
  if (t.kind === 'point') return '';
  const years = (t.end_year ?? thisYear) - t.start_year;
  if (years <= 0) return t.end_year === null ? 'seit diesem Jahr' : '';
  return years === 1 ? '1 Jahr' : `${years} Jahre`;
}

/** Personen als Chips mit Foto bzw. Anfangsbuchstaben; Personen der Mitgliederliste führen zu ihren Ämtern. */
function Holders({ holders, link }: { holders: OfficeHolder[]; link: boolean }) {
  return (
    <ul className="timeline-holders">
      {holders.map((h, i) => (
        <li key={i}>
          {link && h.user_id ? (
            <button type="button" className="holder-chip holder-chip-link" onClick={() => navigate(`mitglieder/aemter/${h.user_id}`)}>
              <MemberAvatar url={h.photo_url} name={h.name} />
              <span>{h.name}</span>
            </button>
          ) : (
            <span className="holder-chip">
              <MemberAvatar url={h.photo_url} name={h.name} />
              <span>{h.name}</span>
            </span>
          )}
        </li>
      ))}
    </ul>
  );
}

export type TimelineEntry = { key: string | number; term: OfficeTerm; office?: string };

/** Jahreszahl groß, Zusatz klein: „seit / 2020“, „2007 / bis 2020“, „2025“. */
function When({ term }: { term: OfficeTerm }) {
  const single = term.kind === 'point' || term.end_year === term.start_year;
  return (
    <div className="tl-when" aria-hidden="true">
      {term.current && <span className="tl-when-small">seit</span>}
      <span className="tl-when-year">{term.start_year}</span>
      {!single && !term.current && <span className="tl-when-small">bis {term.end_year}</span>}
    </div>
  );
}

/**
 * Chronik als Zeitleiste: links (auf breiten Bildschirmen abwechselnd gegenüber) die Jahre, in der Mitte die Linie, daneben
 * je Amtszeit eine Karte. Jahrzehnt-Marken gliedern lange Listen; laufende Amtszeiten leuchten, Ehrungen tragen einen Stern.
 */
export function Timeline({ entries, link = true, label }: { entries: TimelineEntry[]; link?: boolean; label: string }) {
  let decade: number | null = null;
  const rows: ReactElement[] = [];
  entries.forEach(({ key, term, office }, i) => {
    const d = Math.floor(term.start_year / 10) * 10;
    if (entries.length > 4 && d !== decade && !term.current) {
      rows.push(
        <li key={`d${d}-${i}`} className="tl-decade" aria-hidden="true">
          <span className="tl-decade-label">{d}er</span>
        </li>,
      );
    }
    if (!term.current) decade = d;
    const duration = termDuration(term);
    rows.push(
      <li
        key={key}
        className={`tl-item ${i % 2 === 0 ? 'tl-left' : 'tl-right'}${term.current ? ' current' : ''}${term.kind === 'point' ? ' point' : ''}`}
      >
        <When term={term} />
        <span className="tl-dot" aria-hidden="true">
          {term.kind === 'point' ? '★' : ''}
        </span>
        <div className="tl-card">
          <span className="visually-hidden">{termYears(term)}: </span>
          {(office || term.current || term.kind === 'point' || duration) && (
            <div className="tl-meta">
              {office && <span className="tl-office">{office}</span>}
              {term.current && <span className="badge timeline-now">im Amt</span>}
              {term.kind === 'point' && <span className="badge timeline-honor">Ehrung</span>}
              {duration && <span className="tl-duration">{duration}</span>}
            </div>
          )}
          <Holders holders={term.holders} link={link} />
          {term.note && <div className="timeline-note">{term.note}</div>}
        </div>
      </li>,
    );
  });
  return (
    <ol className="tl" aria-label={label}>
      {rows}
    </ol>
  );
}

/** Siegel aus der Bezeichnung: „1. Vorsitzende“ → „1V“, „Beauftragte für Öffentlichkeitsarbeit“ → „BÖ“. */
export function monogram(title: string): string {
  const words = title.split(/\s+/).filter((w) => w !== '' && !/^[a-zäöüß]/.test(w));
  const out = words.map((w) => (/^\d/.test(w) ? w.replace(/\D/g, '') : w[0])).join('');
  return (out || title[0] || '?').slice(0, 2).toUpperCase();
}

/** „Im Amt“: die aktuellen Inhaber groß, mit Foto und wie lange schon. */
function Spotlight({ terms, link }: { terms: OfficeTerm[]; link: boolean }) {
  const people = terms.flatMap((t) => t.holders.map((h) => ({ h, t })));
  return (
    <section className="spotlight" aria-label="Im Amt">
      <div className="spotlight-label">Im Amt</div>
      <ul className="spotlight-list">
        {people.map(({ h, t }, i) => {
          const body = (
            <>
              <span className="spotlight-avatar" aria-hidden="true">
                <MemberAvatar url={h.photo_url} name={h.name} size="medium" />
              </span>
              <span className="spotlight-text">
                <span className="spotlight-name">{h.name}</span>
                <span className="spotlight-since">
                  seit {t.start_year}
                  {termDuration(t) ? ` · ${termDuration(t)}` : ''}
                </span>
                {t.note && <span className="spotlight-note">{t.note}</span>}
              </span>
            </>
          );
          return (
            <li key={i}>
              {link && h.user_id ? (
                <button type="button" className="spotlight-person" onClick={() => navigate(`mitglieder/aemter/${h.user_id}`)}>
                  {body}
                </button>
              ) : (
                <span className="spotlight-person">{body}</span>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

/** Zugeklappt: wer das Amt gerade hat (mit Fotos), sonst die letzte Amtszeit. */
function Summary({ office }: { office: Office }) {
  const current = office.terms.filter((t) => t.current);
  const shown = current.length > 0 ? current : office.terms.slice(0, 1);
  if (shown.length === 0) return <span className="office-summary muted small">Noch keine Einträge</span>;
  const people = shown.flatMap((t) => t.holders);
  // Jahre nur, wenn sie für alle gezeigten Personen gelten
  const years = new Set(shown.map((t) => termYears(t)));
  const extra = people.length - 3;
  return (
    <span className="office-summary">
      <span className="office-faces" aria-hidden="true">
        {people.slice(0, 3).map((h, i) => (
          <MemberAvatar key={i} url={h.photo_url} name={h.name} />
        ))}
        {extra > 0 && <span className="office-faces-more">+{extra}</span>}
      </span>
      <span className="office-summary-text small">
        {current.length === 0 && <span className="muted">zuletzt </span>}
        {people.map((h) => h.name).join(', ')}
        {years.size === 1 && <span className="muted"> · {termYears(shown[0])}</span>}
      </span>
    </span>
  );
}

function OfficeCard({ office, open, onToggle }: { office: Office; open: boolean; onToggle: () => void }) {
  const id = `office-${office.id}`;
  const n = office.terms.length;
  const since = n > 0 ? Math.min(...office.terms.map((t) => t.start_year)) : null;
  const current = office.terms.filter((t) => t.current);
  const history = office.terms.filter((t) => !t.current);
  return (
    <section className={`card office-card${open ? ' open' : ''}`}>
      <button type="button" className="office-head" aria-expanded={open} aria-controls={id} onClick={onToggle}>
        <span className="office-mono" aria-hidden="true">
          {monogram(office.title)}
        </span>
        <span className="office-head-main">
          <span className="office-title">{office.title}</span>
          <Summary office={office} />
          {since !== null && (
            <span className="office-meta">
              Chronik seit {since} · {n === 1 ? '1 Eintrag' : `${n} Einträge`}
            </span>
          )}
        </span>
        <span className="office-arrow" aria-hidden="true">
          ›
        </span>
      </button>
      {open && (
        <div id={id} className="office-body">
          {n === 0 && <p className="muted small">Noch keine Einträge.</p>}
          {current.length > 0 && <Spotlight terms={current} link />}
          {history.length > 0 && (
            <>
              {current.length > 0 && <h3 className="tl-heading">Frühere</h3>}
              <Timeline label={office.title} entries={history.map((t) => ({ key: t.id, term: t }))} />
            </>
          )}
        </div>
      )}
    </section>
  );
}

/** Alle Ämter nach Gruppen; je Amt eine aufklappbare Karte mit dem Zeitstrahl. */
export function OfficesView() {
  const { data, error, loading, reload } = useOffices();
  const [open, setOpen] = useState<Set<number>>(new Set());

  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const offices = data?.offices ?? [];
  if (offices.length === 0) return <Empty>Noch keine {sec.offices} eingetragen.</Empty>;

  const toggle = (id: number) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  const sections = [
    ...(data?.groups ?? []).map((g) => ({ key: `g${g.id}`, title: g.title, offices: offices.filter((o) => o.group_id === g.id) })),
    { key: 'none', title: '', offices: offices.filter((o) => o.group_id === null) },
  ].filter((s) => s.offices.length > 0);
  const all = offices.every((o) => open.has(o.id));

  return (
    <div className="offices">
      <div className="button-row offices-tools">
        <button
          type="button"
          className="btn btn-small btn-secondary"
          onClick={() => setOpen(all ? new Set() : new Set(offices.map((o) => o.id)))}
        >
          {all ? 'Alle zuklappen' : 'Alle aufklappen'}
        </button>
      </div>
      {sections.map((s) => (
        <section key={s.key} className="office-group">
          {s.title && <h2 className="section-title">{s.title}</h2>}
          {s.offices.map((o) => (
            <OfficeCard key={o.id} office={o} open={open.has(o.id)} onToggle={() => toggle(o.id)} />
          ))}
        </section>
      ))}
    </div>
  );
}

/** Alle Amtszeiten einer Person über alle Ämter, als ein Zeitstrahl (laufende zuerst, dann nach Beginn). */
export function personEntries(data: OfficesOverview | null | undefined, userId: number): TimelineEntry[] {
  const groups = new Map((data?.groups ?? []).map((g) => [g.id, g.title]));
  const out: TimelineEntry[] = [];
  for (const o of data?.offices ?? []) {
    for (const t of o.terms) {
      if (t.holders.some((h) => h.user_id === userId)) {
        const group = o.group_id !== null ? groups.get(o.group_id) : undefined;
        out.push({ key: t.id, term: t, office: group ? `${o.title} · ${group}` : o.title });
      }
    }
  }
  return out.sort(
    (a, b) =>
      Number(b.term.current) - Number(a.term.current) ||
      b.term.start_year - a.term.start_year ||
      (b.term.end_year ?? Infinity) - (a.term.end_year ?? Infinity),
  );
}

/** Kleiner Zeitstrahl „Ämter von …“ (Mitgliederliste, Meins, Mitgliedsdetails). */
export function PersonOffices({ userId, empty }: { userId: number; empty: string }) {
  const { data, error, loading, reload } = useOffices();
  if (loading && !data) return <Loading />;
  if (error && !data) return <ErrorBox message={error} onRetry={reload} />;
  const entries = personEntries(data, userId);
  if (entries.length === 0) return <Empty>{empty}</Empty>;
  return <Timeline label={sec.offices} entries={entries} link={false} />;
}

/** Seite „Ämter von …“ im Tab Mitglieder. */
export function PersonOfficesPage({ userId }: { userId: number }) {
  const { data } = useOffices();
  const name = data?.offices.flatMap((o) => o.terms.flatMap((t) => t.holders)).find((h) => h.user_id === userId)?.name;
  return (
    <div>
      <button type="button" className="btn btn-link back" onClick={() => navigate('mitglieder/aemter')}>
        ← Alle {sec.offices}
      </button>
      <h2 className="section-title">
        {sec.offices}
        {name ? ` von ${name}` : ''}
      </h2>
      <PersonOffices userId={userId} empty={`Keine ${sec.offices} eingetragen.`} />
    </div>
  );
}
