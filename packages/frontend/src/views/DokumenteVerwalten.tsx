import { useRef, useState, type FormEvent } from 'react';
import { useDragSort, useSaveQueue } from '../dragSort';
import { api, type DocCategory, type DocumentInfo, type DocumentMeta, type DocVisibility } from '../api';
import brand from '../generated/brand';
import { formatBytes, formatDate, todayYmd } from '../format';
import { navigate } from '../router';
import { Empty, ErrorBox, FileInput, Loading, useAsync } from '../ui';
import { docGroupKey, groupDocuments, groupKey } from '../documents';
import { ACCEPT, DocGroupSection, DocSortSelect, typeLabel, useDocSort, useOpenGroups, VISIBILITY_LABELS } from './Dokumente';
import { confirmAsk, promptAsk } from '../ask';
import { IconButton } from '../icons';
import { t, tn } from '../i18n';
import { LANGUAGE_NAMES } from '../language';
import { extraLocales, fieldTranslationsInput, FieldTranslationsEditor, type FieldTranslations } from '../translationFields';

/** Titel je weitere Sprache (Plan „Mehrsprachigkeit“, PR 9e) */
const TITLE_FIELD = [{ key: 'title', label: (language: string) => t('Titel auf {language}', { language }), maxLength: 191 }];
const titleInput = (value: FieldTranslations | undefined, before?: FieldTranslations) =>
  fieldTranslationsInput(['title'], value ?? {}, before);

const sec = brand.labels.sections;

/** Felder für Titel, Kategorie, Stand und Sichtbarkeit (Hochladen und Bearbeiten). */
function MetaFields({
  meta,
  categories,
  onChange,
}: {
  meta: DocumentMeta;
  categories: DocCategory[];
  onChange: (meta: DocumentMeta) => void;
}) {
  return (
    <>
      <label className="field">
        <span>{t('Titel')}</span>
        <input type="text" maxLength={191} value={meta.title} onChange={(e) => onChange({ ...meta, title: e.target.value })} />
      </label>
      <label className="field">
        <span>{t('Kategorie')}</span>
        <select
          aria-label={t('Kategorie')}
          value={meta.category_id ?? ''}
          onChange={(e) => onChange({ ...meta, category_id: e.target.value ? Number(e.target.value) : null })}
        >
          <option value="">{t('Ohne Kategorie')}</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.title}
            </option>
          ))}
        </select>
      </label>
      <div className="field-row">
        <label className="field">
          <span>{t('Stand')}</span>
          <input type="date" value={meta.date} onChange={(e) => onChange({ ...meta, date: e.target.value })} />
        </label>
        <label className="field">
          <span>{t('Sichtbar für')}</span>
          <select
            aria-label={t('Sichtbar für')}
            value={meta.visibility}
            onChange={(e) => onChange({ ...meta, visibility: e.target.value as DocVisibility })}
          >
            {(Object.keys(VISIBILITY_LABELS) as DocVisibility[]).map((v) => (
              <option key={v} value={v}>
                {VISIBILITY_LABELS[v]}
              </option>
            ))}
          </select>
        </label>
      </div>
      <FieldTranslationsEditor
        fields={TITLE_FIELD}
        german={{ title: meta.title }}
        value={(meta.translations ?? {}) as FieldTranslations}
        onChange={(translations) => onChange({ ...meta, translations })}
        hint={(language) =>
          t('Wer die App auf {language} nutzt, sieht diesen Titel. Bleibt er leer, erscheint der deutsche.', { language })
        }
      />
    </>
  );
}

/** Vorab prüfen wie der Server; maxBytes nennt der Server (20 MB oder weniger, je nach Einstellungen). */
function checkFile(file: File, maxBytes: number): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!ACCEPT.split(',').includes(`.${ext}`)) {
    return t('Erlaubt sind PDF, Word, Excel, PowerPoint, OpenDocument sowie JPG- und PNG-Bilder.');
  }
  if (file.size > maxBytes) return t('Die Datei darf höchstens {size} groß sein.', { size: formatBytes(maxBytes) });
  return null;
}

const titleFromFile = (name: string) =>
  name
    .replace(/\.[^.]+$/, '')
    .replace(/[_-]+/g, ' ')
    .trim();

/** Wer Dokumente pflegt (manage_documents), verwaltet hier alle Dokumente und ihre Kategorien. */
export default function DokumenteVerwalten({ onToast }: { onToast: (msg: string) => void }) {
  // Zum Bearbeiten die deutschen Namen samt Fassungen je Sprache
  const { data, error, loading, reload, setData } = useAsync(api.documentsSource, [], 'documents-source');
  const fileInput = useRef<HTMLInputElement>(null);
  const replaceInput = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [meta, setMeta] = useState<DocumentMeta>({ title: '', category_id: null, date: todayYmd(), visibility: 'members' });
  const [editing, setEditing] = useState<{ id: number; meta: DocumentMeta; before?: FieldTranslations } | null>(null);
  const [replacing, setReplacing] = useState<DocumentInfo | null>(null);
  const [newCategory, setNewCategory] = useState('');
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [sort, setSort] = useDocSort();
  const { isOpen, toggle, show } = useOpenGroups();
  const saveOrder = useSaveQueue();
  const sortCategories = useDragSort(data?.categories ?? [], reorderCategories);

  if (loading && !data) return <Loading />;
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  const categories = data?.categories ?? [];
  const docs = data?.documents ?? [];
  const maxBytes = data?.max_bytes ?? 20 * 1024 * 1024;

  async function run(action: () => Promise<unknown>, msg: string) {
    setBusy(true);
    try {
      await action();
      onToast(msg);
      reload();
      return true;
    } catch (e) {
      onToast((e as Error).message);
      return false;
    } finally {
      setBusy(false);
    }
  }

  function reorderCategories(next: DocCategory[]) {
    // Gleich in der neuen Reihenfolge zeigen, damit die Kategorie nach dem Loslassen nicht zurückspringt
    setData({ ...data!, categories: next });
    saveOrder(
      () => api.reorderDocCategories(next.map((c) => c.id)),
      () => {
        onToast(t('Reihenfolge gespeichert'));
        reload();
      },
      (e) => {
        onToast(e.message);
        reload();
      },
    );
  }

  async function upload(e: FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (!file) return setFormError(t('Bitte eine Datei auswählen.'));
    const problem = checkFile(file, maxBytes);
    if (problem) return setFormError(problem);
    if (!meta.title.trim()) return setFormError(t('Bitte einen Titel angeben.'));
    if (!meta.date) return setFormError(t('Bitte den Stand angeben.'));
    setBusy(true);
    try {
      await api.createDocument(file, {
        ...meta,
        title: meta.title.trim(),
        translations: titleInput(meta.translations as FieldTranslations),
      });
      onToast(t('„{title}“ hochgeladen', { title: meta.title.trim() }));
      // Kategorie des neuen Dokuments aufklappen, damit man das Ergebnis sieht.
      show(docGroupKey(meta.category_id, categories));
      setFile(null);
      setMeta((m) => ({ ...m, title: '', translations: {} }));
      if (fileInput.current) fileInput.current.value = '';
      reload();
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function chooseFile(f: File | null) {
    setFile(f);
    if (f && !meta.title.trim()) setMeta((m) => ({ ...m, title: titleFromFile(f.name) }));
  }

  async function replace(f: File | null) {
    const doc = replacing;
    setReplacing(null);
    if (replaceInput.current) replaceInput.current.value = '';
    if (!doc || !f) return;
    const problem = checkFile(f, maxBytes);
    if (problem) return onToast(problem);
    await run(() => api.replaceDocumentFile(doc.id, f), t('Neue Fassung von „{title}“ gespeichert', { title: doc.title }));
  }

  async function saveEdit(e: FormEvent) {
    e.preventDefault();
    if (!editing) return;
    if (!editing.meta.title.trim()) return onToast(t('Bitte einen Titel angeben.'));
    const translations = titleInput(editing.meta.translations as FieldTranslations, editing.before ?? {});
    if (
      await run(() => api.updateDocument(editing.id, { ...editing.meta, title: editing.meta.title.trim(), translations }), t('Gespeichert'))
    ) {
      // Wurde die Kategorie geändert, ist danach die neue aufgeklappt.
      show(docGroupKey(editing.meta.category_id, categories));
      setEditing(null);
    }
  }

  async function addCategory(e: FormEvent) {
    e.preventDefault();
    if (!newCategory.trim()) return onToast(t('Bitte einen Namen für die Kategorie angeben.'));
    if (await run(() => api.createDocCategory(newCategory.trim()), t('Kategorie angelegt'))) setNewCategory('');
  }

  const categoryTitle = (id: number | null) => categories.find((c) => c.id === id)?.title ?? t('Ohne Kategorie');

  return (
    <div>
      <button className="btn btn-link back" onClick={() => navigate('dokumente')}>
        {t('‹ Alle {documents}', { documents: sec.documents })}
      </button>

      <form className="form card" onSubmit={upload}>
        <h3>{t('Dokument hochladen')}</h3>
        <label className="field">
          <span>{t('Datei')}</span>
          <FileInput inputRef={fileInput} accept={ACCEPT} onChange={(e) => chooseFile(e.target.files?.[0] ?? null)} />
        </label>
        <MetaFields meta={meta} categories={categories} onChange={setMeta} />
        <p className="muted small">
          {t('PDF, Word, Excel, PowerPoint, OpenDocument, JPG oder PNG, höchstens {size}.', { size: formatBytes(maxBytes) })}
        </p>
        {formError && (
          <p className="form-error" role="alert">
            {formError}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? t('Lädt hoch …') : t('Hochladen')}
        </button>
      </form>

      {/* Unsichtbares Dateifeld für „Neue Fassung“. */}
      <input ref={replaceInput} type="file" accept={ACCEPT} hidden onChange={(e) => replace(e.target.files?.[0] ?? null)} />

      <h3 className="section-title">{sec.documents}</h3>
      {docs.length === 0 ? <Empty>{t('Noch keine Dateien.')}</Empty> : <DocSortSelect value={sort} onChange={setSort} />}
      {groupDocuments(docs, categories, sort).map((g) => {
        const key = groupKey(g);
        // Während ein Dokument bearbeitet wird, bleibt seine Kategorie offen.
        const editingHere = g.docs.some((d) => d.id === editing?.id);
        return (
          <DocGroupSection key={key} group={g} open={editingHere || isOpen(key)} onToggle={() => !editingHere && toggle(key)}>
            <ul className="card-list">
              {g.docs.map((doc) =>
                editing?.id === doc.id ? (
                  <li key={doc.id} className="card">
                    <form className="form doc-edit" onSubmit={saveEdit}>
                      <MetaFields meta={editing.meta} categories={categories} onChange={(m) => setEditing({ ...editing, meta: m })} />
                      <div className="button-row">
                        <button className="btn" type="submit" disabled={busy}>
                          {t('Speichern')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>
                          {t('Abbrechen')}
                        </button>
                      </div>
                    </form>
                  </li>
                ) : (
                  <li key={doc.id} className="card row">
                    <div className="row-main">
                      <div className="row-title">{doc.title}</div>
                      <div className="row-sub">
                        {categoryTitle(doc.category_id)} · {t('Stand {date}', { date: formatDate(doc.date) })} · {typeLabel(doc)},{' '}
                        {formatBytes(doc.size)}
                      </div>
                      <div className="row-sub">{VISIBILITY_LABELS[doc.visibility]}</div>
                      <div className="row-actions">
                        <IconButton
                          icon="edit"
                          label={t('Bearbeiten')}
                          disabled={busy}
                          onClick={() =>
                            setEditing({
                              id: doc.id,
                              meta: {
                                title: doc.title,
                                category_id: doc.category_id,
                                date: doc.date,
                                visibility: doc.visibility,
                                translations: doc.translations ?? {},
                              },
                              before: (doc.translations ?? {}) as FieldTranslations,
                            })
                          }
                        />
                        <button
                          className="btn btn-small btn-secondary"
                          disabled={busy}
                          onClick={() => {
                            setReplacing(doc);
                            replaceInput.current?.click();
                          }}
                        >
                          {t('Neue Fassung')}
                        </button>
                        <IconButton
                          icon="delete"
                          label={t('Löschen')}
                          variant="danger"
                          disabled={busy}
                          onClick={async () =>
                            (await confirmAsk(t('„{title}“ wirklich löschen?', { title: doc.title }))) &&
                            run(() => api.deleteDocument(doc.id), t('Dokument gelöscht'))
                          }
                        />
                      </div>
                    </div>
                  </li>
                ),
              )}
            </ul>
          </DocGroupSection>
        );
      })}

      <h3 className="section-title">{t('Kategorien')}</h3>
      <form className="form card" onSubmit={addCategory}>
        <div className="field-row">
          <label className="field grow">
            <span>{t('Neue Kategorie')}</span>
            <input
              type="text"
              maxLength={191}
              placeholder={t('z. B. Protokolle')}
              value={newCategory}
              onChange={(e) => setNewCategory(e.target.value)}
            />
          </label>
        </div>
        <button className="btn btn-block" type="submit" disabled={busy}>
          {t('Kategorie hinzufügen')}
        </button>
      </form>
      <ul className="card-list doc-categories">
        {categories.map((c, i) => {
          const count = docs.filter((d) => d.category_id === c.id).length;
          return (
            <li key={c.id} {...sortCategories.itemProps(i, 'card row')}>
              {categories.length > 1 && sortCategories.handle(i, c.title)}
              <div className="row-main">
                <div className="row-title">{c.title}</div>
                <div className="row-sub">{tn('{count} Dokument', '{count} Dokumente', count)}</div>
                <div className="row-actions">
                  <button
                    className="btn btn-small btn-secondary"
                    disabled={busy}
                    onClick={async () => {
                      const next = await promptAsk(t('Neuer Name der Kategorie'), c.title);
                      if (next !== null && next.trim()) run(() => api.updateDocCategory(c.id, next.trim()), t('Kategorie umbenannt'));
                    }}
                  >
                    {t('Umbenennen')}
                  </button>
                  {extraLocales().map((lang) => (
                    <button
                      key={lang}
                      className="btn btn-small btn-secondary"
                      disabled={busy}
                      onClick={async () => {
                        const language = LANGUAGE_NAMES[lang];
                        const next = await promptAsk(
                          t('Name der Kategorie auf {language} (leer: der deutsche „{title}“)', { language, title: c.title }),
                          c.translations?.[lang]?.title ?? '',
                        );
                        if (next !== null)
                          run(() => api.updateDocCategory(c.id, null, { [lang]: { title: next.trim() } }), t('Kategorie umbenannt'));
                      }}
                    >
                      {t('Auf {language}', { language: LANGUAGE_NAMES[lang] })}
                    </button>
                  ))}
                  <IconButton
                    icon="delete"
                    label={t('Löschen')}
                    variant="danger"
                    disabled={busy}
                    onClick={async () =>
                      (await confirmAsk(
                        count === 0
                          ? t('Kategorie „{title}“ löschen?', { title: c.title })
                          : tn(
                              'Kategorie „{title}“ löschen? Das Dokument steht danach ohne Kategorie da.',
                              'Kategorie „{title}“ löschen? Die {count} Dokumente stehen danach ohne Kategorie da.',
                              count,
                              { title: c.title },
                            ),
                      )) && run(() => api.deleteDocCategory(c.id), t('Kategorie gelöscht'))
                    }
                  />
                </div>
              </div>
            </li>
          );
        })}
        {categories.length === 0 && <Empty>{t('Noch keine Kategorien. Dateien ohne Kategorie stehen in einer gemeinsamen Liste.')}</Empty>}
      </ul>
    </div>
  );
}
