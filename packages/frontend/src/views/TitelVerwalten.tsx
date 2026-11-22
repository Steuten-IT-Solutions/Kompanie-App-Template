import { useState, type FormEvent } from 'react';
import { api, can, type Title, type TitleRef } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import brand from '../generated/brand';
import { navigate } from '../router';
import { Empty, ErrorBox, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { t } from '../i18n';
import { officeTitle } from '../officeName';

const sec = brand.labels.sections;

/**
 * Hinweis bei einem Titel, den ein Amt vergibt (Issue #112): Von Hand lässt er sich nicht vergeben oder entfernen. Wer
 * Ämter pflegt, kommt mit dem Link direkt zum Amt.
 */
function OfficeHint({ office }: { office: { id: number; title: string } }) {
  const name = t('das Amt „{title}“', { title: officeTitle(office) });
  // Satz um den Namen des Amts herum (der Name ist ein Link), Wortstellung je Sprache
  const [before, after = ''] = t('Wird über {office} vergeben').split('{office}');
  return (
    <span className="title-office-hint">
      {before}
      {can('manage_offices') ? (
        <button type="button" className="link-button" onClick={() => navigate(`verwaltung/aemter/${office.id}`)}>
          {name}
        </button>
      ) : (
        name
      )}
      {after}
    </span>
  );
}

/**
 * Verwaltung → Titel: anlegen, umbenennen, Reihenfolge der Anzeige, deaktivieren, löschen.
 * Zugewiesene Titel lassen sich nicht löschen, nur umbenennen oder deaktivieren.
 */
export function TitleList({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.titles.load, sources.titles.deps, sources.titles.key);
  // Nur sehen (view_titles ohne manage_titles): Liste ohne Anlegen, Umbenennen, Sortieren und Löschen.
  const editable = can('manage_titles');
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
    if (await run(() => api.createTitle(title.trim()), t('Titel angelegt'))) setTitle('');
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

  const sort = useDragSort(data ?? [], reorder, editing !== null || !editable);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const list = data!;

  return (
    <div>
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>{t('Noch keine {titles}.', { titles: sec.titles })}</Empty>
      ) : (
        <ul className="card-list title-list">
          {list.map((ti, i) =>
            editing === ti.id ? (
              <li key={ti.id} {...sort.itemProps(i, 'card')}>
                <form
                  className="form"
                  onSubmit={async (e) => {
                    e.preventDefault();
                    if (await run(() => api.updateTitle(ti.id, { title: editTitle.trim() }), t('Gespeichert'))) setEditing(null);
                  }}
                >
                  <label className="field">
                    <span>{t('Bezeichnung')}</span>
                    <input type="text" maxLength={60} value={editTitle} onChange={(e) => setEditTitle(e.target.value)} required autoFocus />
                  </label>
                  <div className="button-row">
                    <button className="btn" type="submit" disabled={busy}>
                      {t('Speichern')}
                    </button>
                    <button className="btn btn-secondary" type="button" onClick={() => setEditing(null)}>
                      {t('Abbrechen')}
                    </button>
                  </div>
                </form>
              </li>
            ) : (
              <li key={ti.id} {...sort.itemProps(i, `card row ${ti.active ? '' : 'inactive'}`)}>
                {editable && list.length > 1 && sort.handle(i, ti.title)}
                <div className="row-main">
                  <div className="row-title">
                    {ti.title}
                    {!ti.active && <span className="badge badge-level-guest">{t('inaktiv')}</span>}
                  </div>
                  <div className="row-sub">
                    {ti.assigned ? t('{count}× vergeben', { count: ti.assigned }) : t('nicht vergeben')}
                    {ti.office && (
                      <>
                        {' · '}
                        <OfficeHint office={ti.office} />
                      </>
                    )}
                  </div>
                  {editable && (
                    <div className="row-actions">
                      <button
                        className="btn btn-small btn-secondary"
                        disabled={busy}
                        onClick={() => {
                          setEditTitle(ti.title);
                          setEditing(ti.id);
                        }}
                      >
                        {t('Umbenennen')}
                      </button>
                      {(ti.assigned > 0 || !ti.active) && (
                        <button
                          className="btn btn-small btn-secondary"
                          disabled={busy}
                          onClick={() =>
                            run(() => api.updateTitle(ti.id, { active: !ti.active }), ti.active ? t('Deaktiviert') : t('Aktiviert'))
                          }
                        >
                          {ti.active ? t('Deaktivieren') : t('Aktivieren')}
                        </button>
                      )}
                      {ti.assigned === 0 && (
                        <IconButton
                          icon="delete"
                          label={t('Löschen')}
                          variant="danger"
                          disabled={busy}
                          onClick={async () =>
                            (await confirmAsk(t('„{name}“ löschen?', { name: ti.title }))) &&
                            run(() => api.deleteTitle(ti.id), t('Gelöscht'))
                          }
                        />
                      )}
                    </div>
                  )}
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      {editable && (
        <form className="form card" onSubmit={add}>
          <h3>{t('Neuer Titel')}</h3>
          <label className="field">
            <span>{t('Bezeichnung')}</span>
            <input type="text" maxLength={60} value={title} onChange={(e) => setTitle(e.target.value)} required />
          </label>
          <button className="btn btn-block" type="submit" disabled={busy}>
            {t('Anlegen')}
          </button>
        </form>
      )}
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
  readOnly = false,
}: {
  id: number;
  member: boolean;
  titles: TitleRef[];
  onChanged: () => void;
  onToast: (msg: string) => void;
  /** Nur sehen (view_titles ohne manage_titles): die Titel der Person als Liste. */
  readOnly?: boolean;
}) {
  const all = useAsync(sources.titles.load, sources.titles.deps, sources.titles.key);
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
      onToast(t('Titel gespeichert'));
      onChanged();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (readOnly) {
    return (
      <section className="member-titles">
        <h3 className="section-title">{sec.titles}</h3>
        {titles.length === 0 ? (
          <Empty>{t('Keine {titles}.', { titles: sec.titles })}</Empty>
        ) : (
          <ul className="card-list">
            {titles.map((t) => (
              <li key={t.id} className="card row">
                <div className="row-main">
                  <div className="row-title">{t.title}</div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    );
  }

  return (
    <section className="member-titles">
      <h3 className="section-title">{sec.titles}</h3>
      {all.error ? (
        <ErrorBox message={all.error} onRetry={all.reload} />
      ) : !all.data ? (
        <Loading shape="text" />
      ) : options.length === 0 ? (
        <p className="muted small">{t('Noch keine {titles} angelegt (Verwaltung → {titles}).', { titles: sec.titles })}</p>
      ) : (
        <form className="form" onSubmit={save}>
          {options.map((opt) => (
            <div key={opt.id}>
              <label className="toggle">
                <input
                  type="checkbox"
                  checked={selected.includes(opt.id)}
                  disabled={!!opt.office}
                  onChange={(e) => setSelected(e.target.checked ? [...selected, opt.id] : selected.filter((s) => s !== opt.id))}
                />
                <span>
                  {opt.title}
                  {!opt.active && <span className="muted small"> {t('(deaktiviert – nach dem Abwählen nicht mehr vergebbar)')}</span>}
                </span>
              </label>
              {opt.office && (
                <p className="muted small title-office-note">
                  <OfficeHint office={opt.office} />: {t('Ihn bekommt, wer dort eine laufende Amtszeit hat.')}
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
            {t('Titel speichern')}
          </button>
        </form>
      )}
    </section>
  );
}
