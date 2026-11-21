import { Suspense, useState, type FormEvent } from 'react';
import { api, ApiError, type BlockTranslation, type NewsItem } from '../api';
import { ErrorBox, Loading, lazyView, useAsync } from '../ui';
import { blocksInput, draftsFrom, newBlock, type BlockDraft } from '../blocks/blockDrafts';
import { missingCount, translationInput } from '../blocks/blockTranslation';
import { SOURCE_LOCALE, t, type Locale } from '../i18n';
import { EditLanguageSwitch, LANGUAGE_NAMES, useEditLocale } from '../language';

const BlocksEditor = lazyView(() => import('../blocks/BlocksEditor'));
const BlocksTranslator = lazyView(() => import('../blocks/BlocksTranslator'));

/** Grenze wie auf dem Server (NewsService::TITLE_MAX). */
const TITLE_MAX = 150;

/**
 * Beitrag der News in der App schreiben oder ändern (Quelle „In der App“, Recht manage_news): Titel und Inhalt aus
 * Blöcken (Text mit Bildern, Countdown …) und ein Titelbild (Issue #351); ohne Titelbild ist das erste Bild in einem
 * Textblock für alle das Vorschaubild der Liste. Beim Ändern schickt die App den geladenen Stand mit; hat jemand dazwischen gespeichert, sagt der Server 409 conflict.
 */
export default function NewsSchreiben({
  item,
  onDone,
  onToast,
}: {
  /** Beitrag zum Ändern; ohne: neuer Beitrag (auf Deutsch) */
  item?: NewsItem;
  onDone: (saved: NewsItem | null) => void;
  onToast: (msg: string) => void;
}) {
  if (!item) return <GermanEditor onDone={onDone} onToast={onToast} />;
  return <ExistingEditor id={item.id} onDone={onDone} onToast={onToast} />;
}

/**
 * Einen Beitrag ändern: lädt die deutsche Fassung und die Fassungen (source, auch wenn die App auf Englisch läuft); oben
 * „Schreiben auf“ (Plan „Mehrsprachigkeit“, PR 9c), in einer weiteren Sprache nur Titel und Texte.
 */
function ExistingEditor({ id, onDone, onToast }: { id: string; onDone: (saved: NewsItem | null) => void; onToast: (msg: string) => void }) {
  const lang = useEditLocale();
  const { data, error, reload } = useAsync(() => api.newsSource(id), [id]);
  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!data) return <Loading shape="form" />;
  return (
    <div>
      <EditLanguageSwitch content />
      {lang === SOURCE_LOCALE ? (
        <GermanEditor key={data.updated_at ?? ''} item={data} onDone={onDone} onToast={onToast} />
      ) : (
        <TranslationEditor key={lang} item={data} lang={lang} onDone={onDone} onToast={onToast} />
      )}
    </div>
  );
}

/** Titel und Texte eines Beitrags in einer weiteren Sprache; Titelbild, Aufbau und Einstellungen bleiben die deutschen. */
function TranslationEditor({
  item,
  lang,
  onDone,
  onToast,
}: {
  item: NewsItem;
  lang: Locale;
  onDone: (saved: NewsItem | null) => void;
  onToast: (msg: string) => void;
}) {
  const [title, setTitle] = useState(item.translations?.[lang]?.title ?? '');
  const [blocks, setBlocks] = useState<BlockTranslation>(item.translations?.[lang]?.blocks ?? {});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const german = draftsFrom(item.blocks, item.content ?? '');
  const missing = missingCount(german, blocks) + (title.trim() === '' ? 1 : 0);

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const saved = await api.updateNews(item.id, {
        updated_at: item.updated_at ?? null,
        translations: { [lang]: { title: title.trim(), blocks: translationInput(german, blocks) } },
      });
      onToast(t('Gespeichert'));
      onDone(saved);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card news-editor" onSubmit={save}>
      <h3>{t('Beitrag auf {language}', { language: LANGUAGE_NAMES[lang] })}</h3>
      <label className="field">
        <span>{t('Titel')}</span>
        <input
          type="text"
          maxLength={TITLE_MAX}
          value={title}
          placeholder={item.title}
          onChange={(e) => setTitle(e.target.value)}
          autoFocus
        />
      </label>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksTranslator german={german} value={blocks} onChange={setBlocks} idPrefix={`news-${item.id}-${lang}`} />
        </Suspense>
      </div>
      {missing > 0 && (
        <p className="muted small">
          {t('Was leer bleibt, sehen Leser auf {language} auf Deutsch, mit dem Hinweis „Nur auf Deutsch verfügbar“.', {
            language: LANGUAGE_NAMES[lang],
          })}
        </p>
      )}
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => onDone(null)}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}

/** Beitrag auf Deutsch schreiben oder ändern (Titel, Titelbild, Blöcke). */
function GermanEditor({
  item,
  onDone,
  onToast,
}: {
  /** Beitrag zum Ändern (deutsche Fassung mit content und updated_at); ohne: neuer Beitrag */
  item?: NewsItem;
  onDone: (saved: NewsItem | null) => void;
  onToast: (msg: string) => void;
}) {
  const [title, setTitle] = useState(item?.title ?? '');
  const [blocks, setBlocks] = useState<BlockDraft[]>(() => (item ? draftsFrom(item.blocks, item.content ?? '') : [newBlock('text')]));
  const [cover, setCover] = useState<{ uid: string | null; url: string | null }>({
    uid: item?.cover_uid ?? null,
    url: item?.cover ?? null,
  });
  const [uploading, setUploading] = useState(false);
  const [coverError, setCoverError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState(false);

  async function upload(file: File | null) {
    if (!file) return;
    setUploading(true);
    setCoverError(null);
    try {
      const img = await api.uploadRichImage(file);
      setCover({ uid: img.uid, url: img.url });
    } catch (e) {
      setCoverError((e as Error).message);
    } finally {
      setUploading(false);
    }
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const data = { title: title.trim(), blocks: blocksInput(blocks), cover: cover.uid };
      const saved = item ? await api.updateNews(item.id, { ...data, updated_at: item.updated_at ?? null }) : await api.createNews(data);
      onToast(item ? t('Gespeichert') : t('Beitrag veröffentlicht'));
      onDone(saved);
    } catch (err) {
      setConflict(err instanceof ApiError && err.code === 'conflict');
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form card news-editor" onSubmit={save}>
      <h3>{item ? t('Beitrag bearbeiten') : t('Neuer Beitrag')}</h3>
      <label className="field">
        <span>{t('Titel')}</span>
        <input type="text" maxLength={TITLE_MAX} value={title} onChange={(e) => setTitle(e.target.value)} required autoFocus />
      </label>
      <div className="field news-cover-field">
        <span>{t('Titelbild (optional)')}</span>
        {cover.url && (
          <div className="card news-cover-preview">
            <img className="news-image" src={cover.url} alt={t('Titelbild')} />
          </div>
        )}
        <div className="button-row">
          <label className="btn btn-small btn-secondary">
            {uploading ? t('Lädt …') : cover.url ? t('Bild ersetzen') : t('Bild wählen')}
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              hidden
              aria-label={t('Titelbild')}
              disabled={uploading}
              onChange={(e) => {
                void upload(e.target.files?.[0] ?? null);
                e.target.value = '';
              }}
            />
          </label>
          {cover.url && (
            <button type="button" className="btn btn-small btn-secondary" onClick={() => setCover({ uid: null, url: null })}>
              {t('Bild entfernen')}
            </button>
          )}
        </div>
        {coverError && (
          <p className="form-error" role="alert">
            {coverError}
          </p>
        )}
      </div>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksEditor value={blocks} onChange={setBlocks} idPrefix={`news-${item?.id ?? 'neu'}`} />
        </Suspense>
      </div>
      {error && (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy || conflict || uploading}>
          {item ? t('Speichern') : t('Veröffentlichen')}
        </button>
        <button className="btn btn-secondary" type="button" onClick={() => onDone(null)}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}
