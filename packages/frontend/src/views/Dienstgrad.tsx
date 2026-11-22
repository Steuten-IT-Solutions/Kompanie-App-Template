import { useState, type FormEvent } from 'react';
import { api, config, type Rank, type RankHistoryEntry } from '../api';
import brand from '../generated/brand';
import { formatDate, formatUtcDate, todayYmd } from '../format';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { RankBadge, RankLabel, rankName } from '../RankBadge';
import { Empty, ErrorBox, Loading, rich, useAsync } from '../ui';
import { sources } from '../viewData';
import { localeTag, t } from '../i18n';

const rank = brand.labels.rank;
const sec = brand.labels.sections;

/** „Meins“ → Dienstgrad: eigener Dienstgrad, mit Punkten der Weg zum nächsten, die Liste und der eigene Verlauf. */
export default function Dienstgrad() {
  const { data, error, loading, reload } = useAsync(sources.myRank.load, sources.myRank.deps, sources.myRank.key);
  if (loading && !data) return <Loading shape="stat" />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const d = data!;
  const withPoints = !!config?.features.points && d.points !== undefined;
  const next = d.next_rank ? { ...d.next_rank, title: rankName(d.next_rank.id, d.next_rank.title) } : null;
  const mine = d.rank ? rankName(d.rank.id, d.rank.title) : null;
  const missing = d.points_to_next ?? null;
  const progress = withPoints && next?.min_points ? Math.max(0, Math.min(1, (d.points ?? 0) / next.min_points)) : null;

  return (
    <div>
      {d.rank?.badge_url && (
        <div className="rank-badge-hero">
          <RankBadge url={d.rank.badge_url} title={mine ?? ''} size="large" />
        </div>
      )}
      <div className="card rank-card rank-progress">
        <div className="rank-card-label">{t('Mein {rank}', { rank })}</div>
        <div className="rank-card-title">{mine ?? t('Noch kein {rank}', { rank })}</div>
        {withPoints && next && missing !== null && (
          <>
            {progress !== null && (
              <progress
                className="progress"
                value={Math.round(progress * 100)}
                max={100}
                aria-label={t('Fortschritt bis {title}', { title: next.title })}
              />
            )}
            <div className={`rank-next${next.badge_url ? ' with-badge' : ''}`}>
              <RankBadge url={next.badge_url} title={next.title} size="small" />
              {missing <= 0 ? (
                <span>
                  {d.threshold_reached_since
                    ? t('{points} für „{title}“ erreicht (seit {date}) – die Beförderung steht noch aus.', {
                        points: sec.points,
                        title: next.title,
                        date: formatDate(d.threshold_reached_since),
                      })
                    : t('{points} für „{title}“ erreicht – die Beförderung steht noch aus.', { points: sec.points, title: next.title })}
                </span>
              ) : (
                <span>
                  {rich(t('Noch {count} {points} bis „{title}“.', { points: sec.points, title: next.title }), {
                    count: <strong>{missing.toLocaleString(localeTag())}</strong>,
                  })}
                </span>
              )}
            </div>
          </>
        )}
        {withPoints && !next && d.rank && <div className="rank-next">{t('Das ist der höchste {rank}.', { rank })}</div>}
      </div>

      <h2 className="section-title">{t('Alle {ranks}', { ranks: sec.ranks })}</h2>
      <ul className="card-list rank-list">
        {d.ranks.map((r) => (
          <li key={r.id} className={`card row${r.id === d.rank?.id ? ' rank-mine' : ''}`}>
            <div className="row-main">
              <div className="row-title">
                <RankLabel url={r.badge_url} title={rankName(r.id, r.title)} />
              </div>
              {r.id === d.rank?.id && <div className="row-sub">{t('Dein {rank}', { rank })}</div>}
            </div>
            {withPoints && r.min_points !== null && (
              <div className="row-value">{t('ab {count}', { count: r.min_points.toLocaleString(localeTag()) })}</div>
            )}
          </li>
        ))}
      </ul>

      <h2 className="section-title">{t('Verlauf')}</h2>
      <RankHistory entries={d.history} />
    </div>
  );
}

const KIND: Record<RankHistoryEntry['kind'], string> = {
  promotion: t('Beförderung'),
  demotion: t('Degradierung'),
  initial: t('Ersteinstufung'),
};

/** Tag eines UTC-Zeitpunkts in Ortszeit als JJJJ-MM-TT (für das Datumsfeld). */
function localYmd(utc: string): string {
  const d = new Date(utc.replace(' ', 'T') + 'Z');
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Verlauf des Dienstgrads. Mit edit (Dienstgrade verwalten) lassen sich der neue Dienstgrad und das Datum eines Eintrags
 * nachträglich ändern (der Server prüft Zukunft, Reihenfolge und Schwelle) und ein Eintrag nach Rückfrage löschen: Beim
 * neuesten gilt dann wieder der Dienstgrad davor.
 */
export function RankHistory({
  entries,
  edit,
}: {
  entries: RankHistoryEntry[];
  edit?: { memberId: number; ranks: Rank[]; onChanged: () => void; onToast: (msg: string) => void };
}) {
  const [editing, setEditing] = useState<number | null>(null);
  const [date, setDate] = useState('');
  const [target, setTarget] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  if (entries.length === 0) return <Empty>{t('Noch keine Einträge.')}</Empty>;

  const rankValue = (id: number | null) => (id === null ? 'none' : String(id));

  async function save(e: FormEvent, h: RankHistoryEntry) {
    e.preventDefault();
    if (!edit || h.id === undefined) return;
    setBusy(true);
    setFormError(null);
    try {
      // Erst der Dienstgrad, dann das Datum: Die Schwelle prüft der Server mit dem neuen Dienstgrad.
      if (target !== rankValue(h.to_rank_id ?? null)) {
        await api.setRankHistoryRank(edit.memberId, h.id, target === 'none' ? null : Number(target));
      }
      if (date !== localYmd(h.changed_at)) await api.setRankHistoryDate(edit.memberId, h.id, date);
      edit.onToast(t('Eintrag geändert'));
      setEditing(null);
      edit.onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove(h: RankHistoryEntry, newest: boolean) {
    if (!edit || h.id === undefined) return;
    const what = t('{kind} vom {date} löschen?', { kind: KIND[h.kind], date: formatUtcDate(h.changed_at) });
    const effect = newest
      ? t('Danach gilt wieder der {rank} davor: {title}.', {
          rank,
          title: h.from ? rankName(h.from_rank_id, h.from) : t('kein {rank}', { rank }),
        })
      : t('Der aktuelle {rank} ändert sich dadurch nicht.', { rank });
    if (!(await confirmAsk(`${what} ${effect}`, { okLabel: t('Löschen') }))) return;
    setBusy(true);
    try {
      await api.deleteRankHistoryEntry(edit.memberId, h.id);
      edit.onToast(t('Eintrag gelöscht'));
      setEditing(null);
      edit.onChanged();
    } catch (err) {
      edit.onToast((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <ul className="card-list">
      {entries.map((h, i) => (
        <li key={h.id ?? i} className="card row">
          {h.to && <RankBadge url={h.to_badge_url} title={rankName(h.to_rank_id, h.to)} size="small" />}
          <div className="row-main">
            <div className="row-title">
              {KIND[h.kind]}: {h.from ? rankName(h.from_rank_id, h.from) : t('kein {rank}', { rank })} →{' '}
              {h.to ? rankName(h.to_rank_id, h.to) : t('kein {rank}', { rank })}
            </div>
            <div className="row-sub">
              {formatUtcDate(h.changed_at)}
              {h.changed_by_name && ` · ${t('von {name}', { name: h.changed_by_name })}`}
              {h.points_at_change !== null && ` · ${h.points_at_change.toLocaleString(localeTag())} ${sec.points}`}
              {h.reason && ` · ${h.reason}`}
            </div>
            {edit && h.id !== undefined && editing === h.id && (
              <form className="form rank-history-date" onSubmit={(e) => save(e, h)}>
                <label className="field">
                  <span>{t('Neuer {rank}', { rank })}</span>
                  <select value={target} onChange={(e) => setTarget(e.target.value)} required>
                    {edit.ranks
                      .filter((r) => (r.active || r.id === h.to_rank_id) && (h.kind === 'initial' || r.id !== h.from_rank_id))
                      .map((r) => (
                        <option key={r.id} value={r.id}>
                          {rankName(r.id, r.title)}
                        </option>
                      ))}
                    {h.kind !== 'initial' && h.from_rank_id !== null && <option value="none">{t('Kein {rank}', { rank })}</option>}
                  </select>
                </label>
                <label className="field">
                  <span>{t('Neues Datum')}</span>
                  <input type="date" max={todayYmd()} value={date} onChange={(e) => setDate(e.target.value)} required />
                </label>
                {formError && (
                  <p className="form-error" role="alert">
                    {formError}
                  </p>
                )}
                <button className="btn btn-block" type="submit" disabled={busy || date === '' || target === ''}>
                  {t('Speichern')}
                </button>
                <button type="button" className="btn btn-secondary btn-block" disabled={busy} onClick={() => setEditing(null)}>
                  {t('Abbrechen')}
                </button>
              </form>
            )}
            {edit && h.id !== undefined && editing !== h.id && (
              <div className="row-actions icon-row">
                <IconButton
                  icon="edit"
                  label={t('Eintrag ändern')}
                  disabled={busy}
                  onClick={() => {
                    setDate(localYmd(h.changed_at));
                    setTarget(rankValue(h.to_rank_id ?? null));
                    setFormError(null);
                    setEditing(h.id!);
                  }}
                />
                <IconButton icon="delete" label={t('Eintrag löschen')} disabled={busy} onClick={() => remove(h, i === 0)} />
              </div>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}
