import { Fragment, useEffect, useRef, useState, type FormEvent } from 'react';
import { api, config, type Promotion, type Rank, type RankHistoryEntry, type RankStatus } from '../api';
import brand from '../generated/brand';
import { formatDate } from '../format';
import { navigate } from '../router';
import { RankBadge, RankLabel } from '../RankBadge';
import { useDragSort, useSaveQueue } from '../dragSort';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { RankHistory } from './Dienstgrad';
import { isDue } from '../promotionCheck';
import { notePromotions } from '../promotionsDue';
import { sources } from '../viewData';

const rank = brand.labels.rank;
const sec = brand.labels.sections;
/** Dienstgrade mit Punkten: Schwellen, Beförderungsübersicht, „noch N Punkte bis …“ (Issue #9). */
export const ranksUsePoints = (): boolean => !!config?.features.ranks && !!config?.features.points;

const parseThreshold = (v: string): number | null => (v.trim() === '' ? null : Number(v.trim()));

/** Abzeichen (Issue #17): erlaubte Formate und Höchstgröße wie auf dem Server. */
const BADGE_ACCEPT = '.png,.jpg,.jpeg,.webp,.svg';
const BADGE_MAX_BYTES = 1024 * 1024;
/** Größe der PNG-Fassung eines SVG für Benachrichtigungen (längere Kante). */
const BADGE_PUSH_PX = 256;

/** Seitenverhältnis aus der viewBox, falls der Browser für ein SVG ohne width/height keine Größe kennt. */
function svgAspect(text: string): number | null {
  const m = text.match(/viewBox\s*=\s*["']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["']/);
  return m && Number(m[1]) > 0 && Number(m[2]) > 0 ? Number(m[1]) / Number(m[2]) : null;
}

/**
 * Zeichnet ein SVG im Browser als PNG (transparenter Hintergrund, längere Kante 256 Pixel): Der Server kann SVG nicht
 * in Pixel umwandeln, Benachrichtigungen brauchen aber ein Rasterbild. Klappt das nicht, null (dann ohne Bild).
 */
async function svgToPng(file: File): Promise<string | null> {
  let url = '';
  try {
    const text = await file.text();
    url = URL.createObjectURL(new Blob([text], { type: 'image/svg+xml' }));
    const img = new Image();
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error('SVG lässt sich nicht zeichnen'));
      img.src = url;
    });
    const aspect = img.naturalWidth > 0 && img.naturalHeight > 0 ? img.naturalWidth / img.naturalHeight : (svgAspect(text) ?? 1);
    const w = Math.max(1, Math.round(aspect >= 1 ? BADGE_PUSH_PX : BADGE_PUSH_PX * aspect));
    const h = Math.max(1, Math.round(aspect >= 1 ? BADGE_PUSH_PX / aspect : BADGE_PUSH_PX));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.clearRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);
    return canvas.toDataURL('image/png').replace(/^data:[^,]*,/, '');
  } catch {
    return null;
  } finally {
    if (url) URL.revokeObjectURL(url);
  }
}

/** Vorschau eines Abzeichens auf heller und dunkler Fläche: Transparente Abzeichen müssen auf beiden wirken. */
function BadgePreview({ rank: r }: { rank: Rank }) {
  return (
    <div className="badge-preview" aria-label={`Vorschau des Abzeichens von ${r.title}`}>
      <div className="badge-preview-light">
        <RankBadge url={r.badge_url} title={r.title} size="medium" />
        <span>hell</span>
      </div>
      <div className="badge-preview-dark">
        <RankBadge url={r.badge_url} title={r.title} size="medium" />
        <span>dunkel</span>
      </div>
    </div>
  );
}

/**
 * Verwaltung → Dienstgrade: anlegen, umbenennen, Schwelle (nur mit Punkten), Reihenfolge (Ziehen am Griff), deaktivieren,
 * löschen.
 * Die Liste läuft vom niedrigsten zum höchsten Dienstgrad.
 */
export function RankList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.ranksAll.load, sources.ranksAll.deps, sources.ranksAll.key);
  const [title, setTitle] = useState('');
  const [min, setMin] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const badgeInput = useRef<HTMLInputElement>(null);
  // Ref statt State: Der Dateidialog meldet sich erst später, dann zählt der zuletzt gewählte Dienstgrad.
  const badgeFor = useRef<number | null>(null);
  const points = ranksUsePoints();
  // Schwellen immer pflegbar (gespeichert und geprüft auch ohne Punkte); sie wirken nur zusammen mit Punkten.
  const thresholdLabel = points ? `${sec.points}schwelle` : `${sec.points}schwelle (wirkt nur mit ${sec.points})`;

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    setFormError(null);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      setFormError((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  function chooseBadge(id: number) {
    badgeFor.current = id;
    setFormError(null);
    badgeInput.current?.click();
  }

  async function uploadBadge(file: File | null) {
    const id = badgeFor.current;
    if (badgeInput.current) badgeInput.current.value = '';
    if (!file || id === null) return;
    const ext = file.name.toLowerCase().split('.').pop() ?? '';
    if (!['png', 'jpg', 'jpeg', 'webp', 'svg'].includes(ext)) {
      setFormError('Erlaubt sind PNG, JPG, WebP und SVG.');
      return;
    }
    if (file.size > BADGE_MAX_BYTES) {
      setFormError('Das Abzeichen darf höchstens 1 MB groß sein.');
      return;
    }
    await run(async () => api.uploadRankBadge(id, file, ext === 'svg' ? await svgToPng(file) : null), 'Abzeichen gespeichert');
  }

  async function add(e: FormEvent) {
    e.preventDefault();
    if (await run(() => api.createRank({ title: title.trim(), min_points: parseThreshold(min) }), `${rank} angelegt`)) {
      setTitle('');
      setMin('');
    }
  }

  // Reihenfolge per Ziehen am Griff (Issue #176), gesperrt, solange ein Dienstgrad bearbeitet wird. Steigen die Schwellen
  // dann nicht mehr mit der Reihenfolge, lehnt der Server ab: Meldung zeigen und die gespeicherte Reihenfolge laden.
  const saveOrder = useSaveQueue();
  function reorder(next: Rank[]) {
    // Gleich in der neuen Reihenfolge zeigen, damit der Eintrag nach dem Loslassen nicht zurückspringt
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderRanks(next.map((r) => r.id)),
      setData,
      (e) => {
        setFormError(e.message);
        reload();
      },
    );
  }
  const sort = useDragSort(data ?? [], reorder, editing !== null);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;
  const missing = points && list.some((r) => r.active && r.min_points === null);

  return (
    <div>
      <p className="muted small">
        Vom niedrigsten zum höchsten {rank}.{' '}
        {points
          ? `Mit ${sec.points}schwelle: befördern erst ab der Schwelle, degradieren jederzeit. Ohne Schwelle frei.`
          : `Befördern und degradieren geht frei; mit dem Bereich ${sec.points} kommen ${sec.points}schwellen dazu.`}
        {list.length > 1 && ` Zum Umsortieren einen ${rank} am Griff links ziehen; die Schwellen müssen mit der Reihenfolge steigen.`}
      </p>
      {missing && (
        <p className="small">
          Noch nicht alle {sec.ranks} haben eine {sec.points}schwelle; für diese gelten keine Regeln zu {sec.points}.
        </p>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>Noch keine {sec.ranks}.</Empty>
      ) : (
        <ul className="card-list">
          {list.map((r, i) =>
            editing === r.id ? (
              <li key={r.id} {...sort.itemProps(i, 'card')}>
                <RankForm
                  rank={r}
                  thresholdLabel={thresholdLabel}
                  busy={busy}
                  onCancel={() => setEditing(null)}
                  onSave={async (data) => {
                    if (await run(() => api.updateRank(r.id, data), 'Gespeichert')) setEditing(null);
                  }}
                />
              </li>
            ) : (
              <li key={r.id} {...sort.itemProps(i, `card row ${r.active ? '' : 'inactive'}`)}>
                {list.length > 1 && sort.handle(i, r.title)}
                <div className="row-main">
                  <div className="row-title">
                    <RankLabel url={r.badge_url} title={r.title} />
                    {!r.active && <span className="badge badge-level-guest">inaktiv</span>}
                  </div>
                  <div className="row-sub">
                    {r.min_points !== null ? `ab ${r.min_points.toLocaleString('de-DE')} ${sec.points}` : 'ohne Schwelle'}
                    {' · '}
                    {r.assigned ? `${r.assigned}× vergeben` : 'nicht vergeben'}
                  </div>
                  <div className="row-actions">
                    <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => setEditing(r.id)}>
                      Ändern
                    </button>
                    {(r.assigned || !r.active) && (
                      <button
                        className="btn btn-small btn-secondary"
                        disabled={busy}
                        onClick={() => run(() => api.updateRank(r.id, { active: !r.active }), r.active ? 'Deaktiviert' : 'Aktiviert')}
                      >
                        {r.active ? 'Deaktivieren' : 'Aktivieren'}
                      </button>
                    )}
                    {!r.assigned && (
                      <button
                        className="btn btn-small btn-danger"
                        disabled={busy}
                        onClick={() => window.confirm(`„${r.title}“ löschen?`) && run(() => api.deleteRank(r.id), 'Gelöscht')}
                      >
                        Löschen
                      </button>
                    )}
                  </div>
                  <div className="rank-badge-tools" role="group" aria-label={`Abzeichen von ${r.title}`}>
                    {r.badge_url && <BadgePreview rank={r} />}
                    <div className="row-actions">
                      {r.badge_url ? (
                        <>
                          <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => chooseBadge(r.id)}>
                            Ersetzen
                          </button>
                          <button
                            className="btn btn-small btn-danger"
                            disabled={busy}
                            onClick={() =>
                              window.confirm(`Abzeichen von „${r.title}“ entfernen?`) &&
                              run(() => api.removeRankBadge(r.id), 'Abzeichen entfernt')
                            }
                          >
                            Entfernen
                          </button>
                        </>
                      ) : (
                        <button className="btn btn-small btn-secondary" disabled={busy} onClick={() => chooseBadge(r.id)}>
                          Abzeichen hochladen
                        </button>
                      )}
                    </div>
                    {r.badge_type === 'svg' && !r.badge_push && (
                      <p className="muted small">
                        Ohne Bild in Benachrichtigungen (keine PNG-Fassung); zum Nachholen das SVG erneut hochladen.
                      </p>
                    )}
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      <input
        ref={badgeInput}
        type="file"
        accept={BADGE_ACCEPT}
        hidden
        aria-label="Abzeichen-Datei"
        onChange={(e) => uploadBadge(e.target.files?.[0] ?? null)}
      />
      <p className="muted small">
        Abzeichen: PNG, JPG, WebP oder SVG, höchstens 1 MB. Am besten mit transparentem Hintergrund, der hell wie dunkel wirkt (siehe
        Vorschau).
      </p>

      <form className="form card" onSubmit={add}>
        <h3>Neuer {rank}</h3>
        <label className="field">
          <span>Bezeichnung</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="field">
          <span>{thresholdLabel} (optional)</span>
          <input type="number" inputMode="numeric" min={0} value={min} onChange={(e) => setMin(e.target.value)} />
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
        <p className="muted small">
          Neue {sec.ranks} kommen ans Ende der Liste, also als höchster {rank}; den Platz änderst du per Ziehen am Griff.
        </p>
      </form>
    </div>
  );
}

function RankForm({
  rank: r,
  thresholdLabel,
  busy,
  onSave,
  onCancel,
}: {
  rank: Rank;
  thresholdLabel: string;
  busy: boolean;
  onSave: (data: { title: string; min_points?: number | null }) => void;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState(r.title);
  const [min, setMin] = useState(r.min_points === null ? '' : String(r.min_points));
  return (
    <form
      className="form"
      onSubmit={(e) => {
        e.preventDefault();
        onSave({ title: title.trim(), min_points: parseThreshold(min) });
      }}
    >
      <label className="field">
        <span>Bezeichnung</span>
        <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required autoFocus />
      </label>
      <label className="field">
        <span>{thresholdLabel} (leer = ohne)</span>
        <input type="number" inputMode="numeric" min={0} value={min} onChange={(e) => setMin(e.target.value)} />
      </label>
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          Speichern
        </button>
        <button className="btn btn-secondary" type="button" onClick={onCancel}>
          Abbrechen
        </button>
      </div>
    </form>
  );
}

/**
 * Verwaltung → Beförderungen (nur mit Punkten): zuerst, wer unter der Schwelle des eigenen Dienstgrads liegt (Kandidaten
 * zum Degradieren, Issue #178), dann erreichte Schwellen, dann nach fehlenden Punkten.
 */
export function Promotions() {
  const { data, error, loading, reload } = useAsync(sources.promotions.load, sources.promotions.deps, sources.promotions.key);
  // Zahl an der Kachel „Beförderungen“ gleich mit aktualisieren.
  useEffect(() => {
    if (data) notePromotions(data);
  }, [data]);
  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  // Ohne nächsten Dienstgrad (schon ganz oben) gibt es nichts zu befördern; zum Degradieren können sie trotzdem anstehen.
  // Nach Abschnitten gruppiert; innerhalb eines Abschnitts bleibt die Reihenfolge des Servers (sort ist stabil).
  const list = (data ?? []).filter((p) => p.below_current_threshold || p.next_rank !== null).sort((a, b) => section(a) - section(b));
  const headings = ['Unter der Schwelle', 'Schwelle erreicht', 'Noch nicht erreicht'];
  return (
    <div>
      {list.length === 0 ? (
        <Empty>Niemand gefunden.</Empty>
      ) : (
        <ul className="card-list promotions">
          {list.map((p, i) => {
            const due = isDue(p);
            // Überschrift vor dem ersten Eintrag jedes Abschnitts.
            const heading = i === 0 || section(list[i - 1]) !== section(p) ? headings[section(p)] : null;
            return (
              <Fragment key={p.user_id}>
                {heading && <li className="section-title list-divider">{heading}</li>}
                <li
                  className={`card row clickable ${p.below_current_threshold ? 'below' : due ? 'due' : ''}`}
                  onClick={() => navigate(`verwaltung/mitglied/${p.user_id}/dienstgrad`)}
                >
                  <div className="row-main">
                    <div className="row-title">{p.name}</div>
                    <div className="row-sub">
                      {p.rank ? <RankLabel url={p.rank.badge_url} title={p.rank.title} /> : `Kein ${rank}`} ·{' '}
                      {p.points.toLocaleString('de-DE')} {sec.points}
                      {p.next_rank &&
                        p.points_to_next !== null &&
                        (due
                          ? ` · Schwelle für „${p.next_rank.title}“ erreicht${p.threshold_reached_since ? ` seit ${formatDate(p.threshold_reached_since)}` : ''}`
                          : ` · noch ${p.points_to_next.toLocaleString('de-DE')} bis „${p.next_rank.title}“`)}
                    </div>
                  </div>
                  <span className="chevron" aria-hidden="true">
                    ›
                  </span>
                </li>
              </Fragment>
            );
          })}
        </ul>
      )}
    </div>
  );
}

/**
 * Abschnitt der Beförderungsliste: 0 unter der Schwelle des eigenen Dienstgrads, 1 Schwelle erreicht, 2 noch nicht
 * erreicht. Wer unter der eigenen Schwelle liegt, hat die nächste nicht erreicht; jeder steht in genau einem Abschnitt.
 */
function section(p: Promotion): number {
  return p.below_current_threshold ? 0 : isDue(p) ? 1 : 2;
}

/** Mitgliedsdetails: Dienstgrad, mit Punkten der Stand zur Schwelle, befördern oder degradieren, Verlauf. */
export function MemberRank({
  id,
  member,
  status,
  history,
  onChanged,
  onToast,
  focus = false,
}: {
  id: number;
  /** Wer kein Mitglied mehr ist (z. B. Gast), kann den Dienstgrad nur verlieren. */
  member: boolean;
  status: RankStatus;
  history: RankHistoryEntry[];
  onChanged: () => void;
  onToast: (msg: string) => void;
  /** Aus „Beförderungen“ geöffnet: zum Abschnitt springen und den nächsten Dienstgrad vorschlagen. */
  focus?: boolean;
}) {
  // Alle, auch inaktive: Position und Schwelle des aktuellen Dienstgrads zählen auch, wenn er deaktiviert ist.
  const ranks = useAsync(sources.ranksAll.load, sources.ranksAll.deps, sources.ranksAll.key);
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const points = ranksUsePoints() && status.points !== undefined;
  const list = ranks.data ?? [];
  const currentIndex = list.findIndex((r) => r.id === status.rank?.id);
  const total = status.points ?? 0;
  // Mit Punkten nur Ziele anbieten, die die Regeln erlauben (der Server prüft trotzdem); degradieren geht immer.
  const options = list.filter((r, i) => {
    if (!member || r.id === status.rank?.id || !r.active) return false;
    const up = currentIndex === -1 || i > currentIndex;
    if (!points || !up) return true;
    return r.min_points === null || total >= r.min_points;
  });
  const sectionRef = useRef<HTMLElement>(null);
  useEffect(() => {
    if (focus) sectionRef.current?.scrollIntoView({ block: 'start' });
  }, [focus]);
  const suggested = focus && status.next_rank && options.some((r) => r.id === status.next_rank!.id) ? status.next_rank.id : null;
  const suggestedOnce = useRef(false);
  useEffect(() => {
    if (suggested === null || suggestedOnce.current) return;
    suggestedOnce.current = true;
    setTarget((t) => t || String(suggested));
  }, [suggested]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      await api.setMemberRank(id, target === 'none' ? null : Number(target), reason.trim());
      onToast(`${rank} geändert`);
      setTarget('');
      setReason('');
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="member-rank" ref={sectionRef}>
      <h3 className="section-title">{rank}</h3>
      <p className="member-rank-current">
        {status.rank && <RankBadge url={status.rank.badge_url} title={status.rank.title} size="medium" />}
        <strong>{status.rank?.title ?? `Kein ${rank}`}</strong>
        {points && status.next_rank && status.points_to_next !== null && status.points_to_next !== undefined && (
          <span className="muted small">
            {' '}
            ·{' '}
            {status.points_to_next <= 0
              ? `Schwelle für „${status.next_rank.title}“ erreicht${status.threshold_reached_since ? ` seit ${formatDate(status.threshold_reached_since)}` : ''}`
              : `noch ${status.points_to_next.toLocaleString('de-DE')} ${sec.points} bis „${status.next_rank.title}“`}
          </span>
        )}
      </p>
      {ranks.error && <ErrorBox message={ranks.error} onRetry={ranks.reload} />}
      <form className="form rank-change" onSubmit={submit}>
        <label className="field">
          <span>Neuer {rank}</span>
          <select value={target} onChange={(e) => setTarget(e.target.value)} required>
            <option value="" disabled>
              Bitte wählen …
            </option>
            {options.map((r) => (
              <option key={r.id} value={r.id}>
                {r.title}
                {points && r.min_points !== null ? ` (ab ${r.min_points.toLocaleString('de-DE')})` : ''}
              </option>
            ))}
            {status.rank && <option value="none">Kein {rank}</option>}
          </select>
        </label>
        <label className="field">
          <span>Begründung (optional)</span>
          <input type="text" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy || target === ''}>
          {rank} ändern
        </button>
        {points && ranks.data && options.length === 0 && !status.rank && (
          <p className="muted small">Zurzeit ist keine Änderung möglich: Befördern erst ab der Schwelle.</p>
        )}
      </form>
      <RankHistory entries={history} />
    </section>
  );
}
