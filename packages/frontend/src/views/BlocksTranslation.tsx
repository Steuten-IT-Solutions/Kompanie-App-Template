import { Suspense, useState, type FormEvent } from 'react';
import { api, type BlockTranslation, type BlocksKind } from '../api';
import { draftsFrom, type BlockDraft } from '../blocks/blockDrafts';
import { missingCount, translationInput } from '../blocks/blockTranslation';
import { t, type Locale } from '../i18n';
import { LANGUAGE_NAMES } from '../language';
import { ErrorBox, lazyView, Loading, useAsync } from '../ui';

const BlocksTranslator = lazyView(() => import('../blocks/BlocksTranslator'));

/** Was zum Übersetzen eines Inhalts aus Blöcken gebraucht wird (deutsch und die Fassung der Sprache). */
interface Source {
  title: string;
  german: BlockDraft[];
  own: { title: string; blocks: BlockTranslation };
  save: (title: string, blocks: BlockTranslation) => Promise<unknown>;
}

/** Grenzen wie auf dem Server (PageService::TITLE_MAX, BoardService::TRANSLATION_TITLE_MAX). */
const TITLE_MAX: Record<BlocksKind, number> = { page: 100, board: 1000 };

async function load(kind: BlocksKind, id: number, lang: Locale): Promise<Source> {
  if (kind === 'page') {
    const p = await api.pageSource(id);
    const german = draftsFrom(p.blocks, p.body);
    const own = p.translations?.[lang];
    return {
      title: p.title,
      german,
      own: { title: own?.title ?? '', blocks: own?.blocks ?? {} },
      save: (title, blocks) =>
        api.updatePage(id, { updated_at: p.updated_at, translations: { [lang]: { title, blocks: translationInput(german, blocks) } } }),
    };
  }
  // Nachrichten am Brett gibt es nur in der Liste; mit Verlauf stehen dort deutsch (source) und die Fassungen
  const item = (await api.board()).items.find((i) => i.kind === 'message' && i.id === id);
  if (!item || item.kind !== 'message') throw new Error(t('Diese Nachricht gibt es nicht mehr (abgelaufen oder gelöscht).'));
  const german = item.source ? draftsFrom(item.source.blocks, '') : draftsFrom(item.blocks, item.text);
  const own = item.translations?.[lang];
  return {
    title: item.source?.title ?? item.title,
    german,
    own: { title: own?.title ?? '', blocks: own?.blocks ?? {} },
    save: (title, blocks) => api.updateBoard(id, { translations: { [lang]: { title, blocks: translationInput(german, blocks) } } }),
  };
}

/**
 * Eine eigene Seite oder eine Nachricht am Brett in einer weiteren Sprache (Einstellungen → Sprachen → Übersetzungen):
 * Titel und Texte der Blöcke; Aufbau, Bilder und Einstellungen bleiben die deutschen. Gespeichert wird nur die Fassung
 * dieser Sprache, über die Route des Inhalts.
 */
export default function BlocksTranslation({
  kind,
  id,
  lang,
  onDone,
  onToast,
}: {
  kind: BlocksKind;
  id: number;
  lang: Locale;
  onDone: (saved: boolean) => void;
  onToast: (msg: string) => void;
}) {
  const [source, setSource] = useState<Source | null>(null);
  const [title, setTitle] = useState('');
  const [blocks, setBlocks] = useState<BlockTranslation>({});
  const { error, reload } = useAsync(
    () =>
      load(kind, id, lang).then((s) => {
        setSource(s);
        setTitle(s.own.title);
        setBlocks(s.own.blocks);
        return s;
      }),
    [kind, id, lang],
  );
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  if (error) return <ErrorBox message={error} onRetry={reload} />;
  if (!source) return <Loading shape="form" />;
  const language = LANGUAGE_NAMES[lang];
  const missing = missingCount(source.german, blocks) + (title.trim() === '' ? 1 : 0);

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!source) return;
    setBusy(true);
    setFormError(null);
    try {
      await source.save(title.trim(), blocks);
      onToast(t('Gespeichert'));
      onDone(true);
    } catch (err) {
      setFormError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="form blocks-translation" onSubmit={save}>
      <label className="field">
        <span>{t('Titel auf {language}', { language })}</span>
        <input
          type="text"
          lang={lang}
          maxLength={TITLE_MAX[kind]}
          value={title}
          placeholder={source.title}
          onChange={(e) => setTitle(e.target.value)}
        />
      </label>
      <div className="field">
        <span>{t('Inhalt')}</span>
        <Suspense fallback={<Loading shape="text" chunk />}>
          <BlocksTranslator german={source.german} value={blocks} onChange={setBlocks} idPrefix={`${kind}-${id}-${lang}`} />
        </Suspense>
      </div>
      {missing > 0 && (
        <p className="muted small">
          {t('Was leer bleibt, sehen Leser auf {language} auf Deutsch, mit dem Hinweis „Nur auf Deutsch verfügbar“.', { language })}
        </p>
      )}
      {formError && (
        <p className="form-error" role="alert">
          {formError}
        </p>
      )}
      <div className="button-row">
        <button className="btn" type="submit" disabled={busy}>
          {busy ? t('Speichert …') : t('Speichern')}
        </button>
        <button className="btn btn-secondary" type="button" disabled={busy} onClick={() => onDone(false)}>
          {t('Abbrechen')}
        </button>
      </div>
    </form>
  );
}
