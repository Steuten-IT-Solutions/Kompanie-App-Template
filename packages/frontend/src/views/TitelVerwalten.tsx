import { useState, type FormEvent } from 'react';
import { api, type Title, type TitleRef } from '../api';
import brand from '../generated/brand';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const sec = brand.labels.sections;

/** Titel als kleine Abzeichen, z. B. in „Meins“ und in der Mitgliederliste. */
export function TitleBadges({ titles }: { titles?: TitleRef[] }) {
  if (!titles || titles.length === 0) return null;
  return (
    <span className="title-badges">
      {titles.map((t) => (
        <span key={t.id} className="badge badge-title">
          {t.title}
        </span>
      ))}
    </span>
  );
}

/**
 * Verwaltung → Titel: anlegen, umbenennen, Reihenfolge der Anzeige, deaktivieren, löschen.
 * Zugewiesene Titel lassen sich nicht löschen, nur umbenennen oder deaktivieren.
 */
export function TitleList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.titles, []);
  const [title, setTitle] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [editTitle, setEditTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

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

  async function add(e: FormEvent) {
    e.preventDefault();
    if (await run(() => api.createTitle(title.trim()), 'Titel angelegt')) setTitle('');
  }

  async function move(list: Title[], index: number, dir: -1 | 1) {
    const ids = list.map((t) => t.id);
    [ids[index], ids[index + dir]] = [ids[index + dir], ids[index]];
    setBusy(true);
    setFormError(null);
    try {
      setData(await api.reorderTitles(ids));
    } catch (e) {
      setFormError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;

  return (
    <div>
      <p className="muted small">
        Ehrentitel und Ämter, unabhängig von {sec.ranks} und {sec.points}. Ein Mitglied kann mehrere haben; du vergibst sie in den
        Mitgliedsdetails. Die Reihenfolge hier gilt auch für die Anzeige.
      </p>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>Noch keine {sec.titles}.</Empty>
      ) : (
        <ul className="card-list title-list">
          {list.map((t, i) =>
            editing === t.id ? (
              <li key={t.id} className="card">
                <form
                  className="form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (await run(() => api.updateTitle(t.id, { title: editTitle.trim() }), 'Gespeichert')) setEditing(null);
                  }}
                >
                  <label className="field">
                    <span>Bezeichnung</span>
                    <input type="text" maxLength={60} value={editTitle} onChange={(e) => setEditTitle(e.target.value)} required autoFocus />
                  </label>
                  <div className="button-row">
                    <button className="btn" type="submit" disabled={busy}>
                      Speichern
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      Abbrechen
                    </button>
                  </div>
                </form>
              </li>
            ) : (
              <li key={t.id} className={`card row ${t.active ? '' : 'inactive'}`}>
                <div className="row-main">
                  <div className="row-title">
                    {t.title}
                    {!t.active && <span className="badge badge-level-guest">inaktiv</span>}
                  </div>
                  <div className="row-sub">{t.assigned ? `${t.assigned}× vergeben` : 'nicht vergeben'}</div>
                  <div className="row-actions">
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy || i === 0}
                      onClick={() => move(list, i, -1)}
                      aria-label={`${t.title} nach oben`}
                    >
                      nach oben
                    </button>
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy || i === list.length - 1}
                      onClick={() => move(list, i, 1)}
                      aria-label={`${t.title} nach unten`}
                    >
                      nach unten
                    </button>
                    <button
                      className="btn btn-small btn-secondary"
                      disabled={busy}
                      onClick={() => {
                        setEditTitle(t.title);
                        setEditing(t.id);
                      }}
                    >
                      Umbenennen
                    </button>
                    {(t.assigned > 0 || !t.active) && (
                      <button
                        className="btn btn-small btn-secondary"
                        disabled={busy}
                        onClick={() => run(() => api.updateTitle(t.id, { active: !t.active }), t.active ? 'Deaktiviert' : 'Aktiviert')}
                      >
                        {t.active ? 'Deaktivieren' : 'Aktivieren'}
                      </button>
                    )}
                    {t.assigned === 0 && (
                      <button
                        className="btn btn-small btn-danger"
                        disabled={busy}
                        onClick={() => window.confirm(`„${t.title}“ löschen?`) && run(() => api.deleteTitle(t.id), 'Gelöscht')}
                      >
                        Löschen
                      </button>
                    )}
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      <form className="form card" onSubmit={add}>
        <h3>Neuer Titel</h3>
        <label className="field">
          <span>Bezeichnung</span>
          <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <button className="btn btn-block" type="submit" disabled={busy}>
          Anlegen
        </button>
      </form>
    </div>
  );
}

/**
 * Mitgliedsdetails: Titel an- und abwählen. Deaktivierte Titel erscheinen nur, wenn das Mitglied sie hat; wer kein
 * Mitglied mehr ist, kann Titel nur verlieren.
 */
export function MemberTitles({
  id,
  member,
  titles,
  onChanged,
  onToast,
}: {
  id: number;
  member: boolean;
  titles: TitleRef[];
  onChanged: () => void;
  onToast: (msg: string) => void;
}) {
  const all = useAsync(api.titles, []);
  const current = titles.map((t) => t.id);
  const [selected, setSelected] = useState<number[]>(current);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const options = (all.data ?? []).filter((t) => current.includes(t.id) || (member && t.active));
  const changed = selected.length !== current.length || selected.some((s) => !current.includes(s));

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFormError(null);
    try {
      await api.setMemberTitles(id, selected);
      onToast('Titel gespeichert');
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="member-titles">
      <h3 className="section-title">{sec.titles}</h3>
      {all.error ? (
        <ErrorBox message={all.error} onRetry={all.reload} />
      ) : !all.data ? (
        <Loading />
      ) : options.length === 0 ? (
        <p className="muted small">
          Noch keine {sec.titles} angelegt (Verwaltung → {sec.titles}).
        </p>
      ) : (
        <form className="form" onSubmit={save}>
          {options.map((t) => (
            <label key={t.id} className="toggle">
              <input
                type="checkbox"
                checked={selected.includes(t.id)}
                onChange={(e) => setSelected(e.target.checked ? [...selected, t.id] : selected.filter((s) => s !== t.id))}
              />
              <span>
                {t.title}
                {!t.active && <span className="muted small"> (deaktiviert – nach dem Abwählen nicht mehr vergebbar)</span>}
              </span>
            </label>
          ))}
          {formError && (
            <p className="form-error" role="alert">
              {formError}
            </p>
          )}
          <button className="btn btn-block" type="submit" disabled={busy || !changed}>
            Titel speichern
          </button>
        </form>
      )}
    </section>
  );
}
