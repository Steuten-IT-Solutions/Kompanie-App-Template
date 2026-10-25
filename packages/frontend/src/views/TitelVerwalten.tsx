import { useState, type FormEvent } from 'react';
import { api, can, type Title, type TitleRef } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';

const sec = brand.labels.sections;

/**
 * Hinweis bei einem Titel, den ein Amt vergibt (Issue #112): Von Hand lässt er sich nicht vergeben oder entfernen. Wer
 * Ämter pflegt, kommt mit dem Link direkt zum Amt.
 */
function OfficeHint({ office }: { office: { id: number; title: string } }) {
  const name = `das Amt „${office.title}“`;
  return (
    <span className="title-office-hint">
      Wird über{' '}
      {can('manage_offices') ? (
        <button type="button" className="link-button" onClick={() => navigate(`verwaltung/aemter/${office.id}`)}>
          {name}
        </button>
      ) : (
        name
      )}{' '}
      vergeben
    </span>
  );
}

/**
 * Verwaltung → Titel: anlegen, umbenennen, Reihenfolge der Anzeige, deaktivieren, löschen.
 * Zugewiesene Titel lassen sich nicht löschen, nur umbenennen oder deaktivieren.
 */
export function TitleList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.titles, [], 'titles');
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

  const saveOrder = useSaveQueue();
  function reorder(next: Title[]) {
    // Gleich in der neuen Reihenfolge zeigen, damit der Eintrag nach dem Loslassen nicht zurückspringt
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderTitles(next.map((t) => t.id)),
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

  return (
    <div>
      <p className="muted small">
        Ehrentitel und Ämter, unabhängig von {sec.ranks} und {sec.points}. Ein Mitglied kann mehrere haben; du vergibst sie in den
        Mitgliedsdetails. Die Reihenfolge hier gilt auch für die Anzeige; zum Ändern einen Titel am Griff links ziehen.
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
              <li key={t.id} {...sort.itemProps(i, 'card')}>
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
              <li key={t.id} {...sort.itemProps(i, `card row ${t.active ? '' : 'inactive'}`)}>
                {list.length > 1 && sort.handle(i, t.title)}
                <div className="row-main">
                  <div className="row-title">
                    {t.title}
                    {!t.active && <span className="badge badge-level-guest">inaktiv</span>}
                  </div>
                  <div className="row-sub">
                    {t.assigned ? `${t.assigned}× vergeben` : 'nicht vergeben'}
                    {t.office && (
                      <>
                        {' · '}
                        <OfficeHint office={t.office} />
                      </>
                    )}
                  </div>
                  <div className="row-actions">
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
  const all = useAsync(api.titles, [], 'titles');
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
            <div key={t.id}>
              <label className="toggle">
                <input
                  type="checkbox"
                  checked={selected.includes(t.id)}
                  disabled={!!t.office}
                  onChange={(e) => setSelected(e.target.checked ? [...selected, t.id] : selected.filter((s) => s !== t.id))}
                />
                <span>
                  {t.title}
                  {!t.active && <span className="muted small"> (deaktiviert – nach dem Abwählen nicht mehr vergebbar)</span>}
                </span>
              </label>
              {t.office && (
                <p className="muted small title-office-note">
                  <OfficeHint office={t.office} />: Ihn bekommt, wer dort eine laufende Amtszeit hat.
                </p>
              )}
            </div>
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
