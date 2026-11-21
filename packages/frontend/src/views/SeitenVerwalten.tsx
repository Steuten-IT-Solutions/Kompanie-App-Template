import { Suspense, useState, type FormEvent } from 'react';
import { api, ApiError, type BlockTranslation, type PageAudience, type PageDetail, type PageSummary } from '../api';
import { useDragSort, useSaveQueue } from '../dragSort';
import { formatShortTime } from '../format';
import brand from '../generated/brand';
import { Empty, ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { sources } from '../viewData';
import { AudienceBadge } from './Seiten';
import { blocksInput, draftsFrom, newBlock, type BlockDraft } from '../blocks/blockDrafts';
import { confirmAsk } from '../ask';
import { IconButton } from '../icons';
import { SOURCE_LOCALE, t, type Locale } from '../i18n';
import { EditLanguageSwitch, LANGUAGE_NAMES, useEditLocale } from '../language';
import { missingCount, translationInput } from '../blocks/blockTranslation';

const BlocksEditor = lazyView(() => import('../blocks/BlocksEditor'));
const BlocksTranslator = lazyView(() => import('../blocks/BlocksTranslator'));

const sec = brand.labels.sections;

/** Grenze wie auf dem Server (PageService::TITLE_MAX). */
const TITLE_MAX = 100;

type Draft = { title: string; audience: PageAudience; blocks: BlockDraft[] };
/** Neue Seiten beginnen mit einem leeren Textblock. */
const empty = (): Draft => ({ title: '', audience: 'members', blocks: [newBlock('text')] });

function PageFields({
  draft,
  onChange,
  idPrefix,
  autoFocus,
}: {
  draft: Draft;
  onChange: (d: Draft) => void;
  idPrefix: string;
  autoFocus?: boolean;
}) {
  const set = (patch: Partial<Draft>) => onChange({ ...draft, ...patch });
  return (
    <>
      <label className="field">
        <span>{t('Titel')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={draft.title}
          onChange={(e) => set({ title: e.target.value })}
          required
          autoFocus={autoFocus}
        />
      </label>
      <label className="field">
        <span>{t('Für wen?')}</span>
        <select value={draft.audience} onChange={(e) => set({ audience: e.target.value as PageAudience })}>
          <option value="members">{t('Nur Mitglieder')}</option>
          <option value="public">{t('Alle, auch ohne Anmeldung')}</option>
        </select>
      </label>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksEditor value={draft.blocks} onChange={(blocks) => set({ blocks })} idPrefix={idPrefix} />
        </Suspense>
      </div>
    </>
  );
}

/** Eine Seite bearbeiten: lädt den Text und den Stand; hat jemand dazwischen gespeichert, sagt der Server 409 conflict. */
function PageEditor({ id, onDone, onToast }: { id: number; onDone: (saved: boolean) => void; onToast: (msg: string) => void }) {
  const [loaded, setLoaded] = useState<PageDetail | null>(null);
  const [draft, setDraft] = useState<Draft>(empty);
  const [editorKey, setEditorKey] = useState(0);
  const { error, reload } = useAsync(
    () =>
      api.page(id).then((p) => {
        setLoaded(p);
        setDraft({ title: p.title, audience: p.audience, blocks: draftsFrom(p.blocks, p.body) });
        setEditorKey((k) => k + 1);
        return p;
      }),
    [id],
  );
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!loaded) return;
    setBusy(true);
    setFormError(null);
    try {
      await api.updatePage(id, {
        title: draft.title.trim(),
        audience: draft.audience,
        blocks: blocksInput(draft.blocks),
        updated_at: loaded.updated_at,
      });
      onToast(t('Gespeichert'));
      onDone(true);
    } catch (err) {
      setConflict(err instanceof ApiError && err.code === 'conflict');
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!loaded) return <Loading shape="form" />;
  return (
    <form className="form" onSubmit={save}>
      <PageFields key={editorKey} draft={draft} onChange={setDraft} idPrefix={`page-${id}`} autoFocus />
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || conflict}>
          {t('Speichern')}
        </button>
        {conflict && (
          <button
            className="btn btn-secondary"
            type="button"
            onClick={async () => {
              if (!(await confirmAsk(t('Neu laden? Deine Änderungen gehen dabei verloren.')))) return;
              setConflict(false);
              setFormError(null);
              setLoaded(null);
              reload();
            }}
          >
            {t('Neu laden')}
          </button>
        )}
        <button className="btn btn-secondary" type="button" onClick={() => onDone(false)}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

/**
 * Eine Seite in einer weiteren Sprache schreiben (Plan „Mehrsprachigkeit“, PR 9b): Titel und Texte der Blöcke; Aufbau,
 * „Für wen?“ und Einstellungen der Blöcke bleiben die deutschen. Gespeichert wird nur die Fassung dieser Sprache.
 */
function PageTranslationEditor({
  id,
  lang,
  onDone,
  onToast,
}: {
  id: number;
  lang: Locale;
  onDone: (saved: boolean) => void;
  onToast: (msg: string) => void;
}) {
  const [loaded, setLoaded] = useState<PageDetail | null>(null);
  const [title, setTitle] = useState('');
  const [blocks, setBlocks] = useState<BlockTranslation>({});
  const { error, reload } = useAsync(
    () =>
      api.pageSource(id).then((p) => {
        setLoaded(p);
        setTitle(p.translations?.[lang]?.title ?? '');
        setBlocks(p.translations?.[lang]?.blocks ?? {});
        return p;
      }),
    [id, lang],
  );
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!loaded) return <Loading shape="form" />;
  const german = draftsFrom(loaded.blocks, loaded.body);
  const missing = missingCount(german, blocks) + (title.trim() === '' ? 1 : 0);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!loaded) return;
    setBusy(true);
    setFormError(null);
    try {
      await api.updatePage(id, {
        updated_at: loaded.updated_at,
        translations: { [lang]: { title: title.trim(), blocks: translationInput(german, blocks) } },
      });
      onToast(t('Gespeichert'));
      onDone(true);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form" onSubmit={save}>
      <label className="field">
        <span>{t('Titel')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={title}
          placeholder={loaded.title}
          onChange={(e) => setTitle(e.target.value)}
          autoFocus
        />
      </label>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksTranslator german={german} value={blocks} onChange={setBlocks} idPrefix={`page-${id}-${lang}`} />
        </Suspense>
      </div>
      {missing > 0 && (
        <p className="muted small">
          {t('Was leer bleibt, sehen Leser auf {language} auf Deutsch, mit dem Hinweis „Nur auf Deutsch verfügbar“.', {
            language: LANGUAGE_NAMES[lang],
          })}
        </p>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => onDone(false)}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

/**
 * Verwaltung → Einrichtungen → Seiten (Issue #287): eigene Seiten anlegen, bearbeiten (Titel, für wen, Inhalt aus Blöcken),
 * Reihenfolge per Griff, löschen.
 */
export function PageAdmin({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(sources.pages.load, sources.pages.deps, sources.pages.key);
  const lang = useEditLocale();
  const foreign = lang !== SOURCE_LOCALE;
  const [draft, setDraft] = useState<Draft>(empty);
  const [newKey, setNewKey] = useState(0);
  const [editing, setEditing] = useState<number | null>(null);
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
    if (
      await run(
        () => api.createPage({ title: draft.title.trim(), audience: draft.audience, blocks: blocksInput(draft.blocks) }),
        t('Seite angelegt'),
      )
    ) {
      setDraft(empty());
      setNewKey((k) => k + 1);
    }
  }

  const saveOrder = useSaveQueue();
  function reorder(next: PageSummary[]) {
    setData(next);
    setFormError(null);
    saveOrder(
      () => api.reorderPages(next.map((p) => p.id)),
      setData,
      (e) => {
        setFormError(e.message);
        reload();
      },
    );
  }

  const sort = useDragSort(data ?? [], reorder, editing !== null);

  if (loading && !data) return <Loading />;
  if (error || !data)
    return <ErrorBox message={error ?? t('Die {section} konnten nicht geladen werden.', { section: sec.pages })} onRetry={reload} />;
  const list = data;

  return (
    <div>
      <EditLanguageSwitch content />
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      {list.length === 0 ? (
        <Empty>{t('Noch keine {section}.', { section: sec.pages })}</Empty>
      ) : (
        <ul className="card-list page-admin-list">
          {list.map((p, i) =>
            editing === p.id ? (
              <li key={p.id} {...sort.itemProps(i, 'card')}>
                {foreign ? (
                  <PageTranslationEditor
                    key={lang}
                    id={p.id}
                    lang={lang}
                    onToast={onToast}
                    onDone={(saved) => {
                      setEditing(null);
                      if (saved) reload();
                    }}
                  />
                ) : (
                  <PageEditor
                    id={p.id}
                    onToast={onToast}
                    onDone={(saved) => {
                      setEditing(null);
                      if (saved) reload();
                    }}
                  />
                )}
              </li>
            ) : (
              <li key={p.id} {...sort.itemProps(i, 'card row')}>
                {list.length > 1 && sort.handle(i, p.title)}
                <div className="row-main">
                  <div className="row-title">
                    {p.title} <AudienceBadge page={p} />
                    {foreign && p.untranslated_locales?.includes(lang) && (
                      <span className="badge badge-level-guest">{t('ohne {language}', { language: LANGUAGE_NAMES[lang] })}</span>
                    )}
                  </div>
                  {p.updated_at && (
                    <div className="row-sub">
                      {p.updated_by_name
                        ? t('Zuletzt geändert {time} von {name}', { time: formatShortTime(p.updated_at), name: p.updated_by_name })
                        : t('Zuletzt geändert {time}', { time: formatShortTime(p.updated_at) })}
                    </div>
                  )}
                  <div className="row-actions">
                    <IconButton icon="edit" label={t('Bearbeiten')} disabled={busy} onClick={() => setEditing(p.id)} />
                    <IconButton
                      icon="delete"
                      label={t('Löschen')}
                      variant="danger"
                      disabled={busy}
                      onClick={async () =>
                        (await confirmAsk(t('Seite „{title}“ samt ihren Bildern löschen?', { title: p.title }))) &&
                        run(() => api.deletePage(p.id), t('Gelöscht'))
                      }
                    />
                  </div>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      {foreign ? (
        <p className="muted">
          {t('Neue Seiten legst du auf Deutsch an; danach schreibst du hier die Fassung für {language}.', {
            language: LANGUAGE_NAMES[lang],
          })}
        </p>
      ) : (
        <form className="form card" onSubmit={add}>
          <h3>{t('Neue Seite')}</h3>
          <PageFields key={newKey} draft={draft} onChange={setDraft} idPrefix="page-new" />
          <button className="btn btn-block" type="submit" disabled={busy}>
            {t('Anlegen')}
          </button>
        </form>
      )}
    </div>
  );
}
