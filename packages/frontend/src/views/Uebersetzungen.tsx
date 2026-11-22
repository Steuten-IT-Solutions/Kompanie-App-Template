import { Fragment, Suspense, useState, type FormEvent } from 'react';
import brand from '../generated/brand';
import { locale, t, type Locale } from '../i18n';
import { enabledLocales, Flag, LANGUAGE_NAMES } from '../language';
import { setNameTranslations } from '../names';
import { api, type TranslationItem, type TranslationKind, type TranslationOverview } from '../api';
import { ErrorBox, lazyView, Loading, useAsync } from '../ui';
import { contentRequests, isBlocks, isMissing, isName, keyOf, matches } from '../translationOverview';

const BlocksTranslation = lazyView(() => import('./BlocksTranslation'));

const sec = brand.labels.sections;

/** Überschrift je Art, in der Reihenfolge der Antwort (TranslationService::KINDS). */
const GROUP_TITLES: Record<TranslationKind, () => string> = {
  nav: () => t('Bereiche der Navigation'),
  role: () => t('Rollen'),
  rank: () => sec.ranks,
  office: () => sec.offices,
  office_group: () => t('Gruppen der Ämter'),
  event_kind: () => t('Terminarten'),
  menu: () => t('Untermenüs'),
  event: () => t('Kommende Termine'),
  calendar_entry: () => t('Kommende Termine im internen Kalender'),
  poll: () => t('Laufende Umfragen'),
  document: () => sec.documents,
  document_category: () => t('Kategorien der Dokumente'),
  album: () => t('Alben der Galerie'),
  page: () => sec.pages,
  board: () => sec.board,
};

/**
 * Einstellungen → Sprachen → Übersetzungen: alle übersetzbaren Namen und einfachen Inhalte an einer Stelle (Plan
 * „Übersetzungen zentral“). Je Art ein aufklappbarer Abschnitt mit Zähler; links der Text der Basissprache, rechts das
 * Feld der gewählten Sprache, bei Inhalten unter ihrem Titel. Ein leeres Feld zeigt grau, was Leser ohne Eintrag sehen.
 * Gespeichert wird gesammelt: Namen über PUT /admin/translations, Inhalte über ihre eigene Route (translations).
 */
export default function Uebersetzungen({ onToast }: { onToast: (msg: string) => void }) {
  const extra = enabledLocales().filter((l) => l !== 'de');
  const [lang, setLang] = useState<Locale>(extra[0] ?? 'en');
  if (extra.length === 0) return null;
  return (
    <section className="card settings-section" id="einstellungen-uebersetzungen" aria-labelledby="uebersetzungen-titel">
      <h3 id="uebersetzungen-titel">{t('Übersetzungen')}</h3>
      {extra.length > 1 && (
        <nav className="segmented lang-choice" aria-label={t('Sprache der Übersetzungen')}>
          {extra.map((l) => (
            <button
              key={l}
              type="button"
              lang={l}
              className={l === lang ? 'active' : ''}
              aria-pressed={l === lang}
              onClick={() => setLang(l)}
            >
              <Flag lang={l} /> {LANGUAGE_NAMES[l]}
            </button>
          ))}
        </nav>
      )}
      <Loader key={lang} lang={lang} onToast={onToast} />
    </section>
  );
}

function Loader({ lang, onToast }: { lang: Locale; onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(() => api.translationOverview(lang), [lang]);
  if (loading && !data) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? t('Die Übersetzungen konnten nicht geladen werden.')} onRetry={reload} />;
  return <Editor data={data} onSaved={setData} onReload={reload} onToast={onToast} />;
}

function Editor({
  data,
  onSaved,
  onReload,
  onToast,
}: {
  data: TranslationOverview;
  onSaved: (d: TranslationOverview) => void;
  /** Stand neu laden (nach dem Speichern einer Seite oder Nachricht) */
  onReload: () => void;
  onToast: (msg: string) => void;
}) {
  const lang = data.locale;
  const language = LANGUAGE_NAMES[lang];
  const [edits, setEdits] = useState<Record<string, string>>({});
  const [onlyMissing, setOnlyMissing] = useState(false);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** Geöffnete Seite oder Nachricht (keyOf) */
  const [open, setOpen] = useState<string | null>(null);
  const value = (i: TranslationItem): string => edits[keyOf(i)] ?? i.text;
  const all = data.groups.flatMap((g) => g.items).filter((i) => !isBlocks(i));
  const everything = data.groups.flatMap((g) => g.items);
  const changed = all.filter((i) => value(i).trim() !== i.text);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (changed.length === 0) return onToast(t('Nichts geändert.'));
    setBusy(true);
    setError(null);
    const saved = new Map<string, string>();
    const names = changed.filter(isName).map((i) => ({ kind: i.kind, ref: i.ref, field: i.field, text: value(i).trim() }));
    try {
      if (names.length > 0) {
        setNameTranslations((await api.saveTranslations(lang, names)).translations);
        for (const i of names) saved.set(keyOf(i), i.text);
      }
      // Inhalte einzeln über ihre Route (dieselbe Prüfung und dasselbe Änderungsprotokoll wie im Editor)
      for (const req of contentRequests(all, value)) {
        await api.saveContentTranslation(req.kind, req.id, lang, req.fields, req.title);
        for (const k of req.keys) saved.set(k, edits[k]?.trim() ?? all.find((i) => keyOf(i) === k)?.text ?? '');
      }
      onToast(t('Gespeichert'));
      // Namen der Navigation stehen überall (auch in Überschriften): in dieser Sprache einmal neu laden
      if (locale() === lang && names.some((i) => i.kind === 'nav' || i.kind === 'menu')) setTimeout(() => window.location.reload(), 800);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      // Was schon gespeichert ist, übernehmen (auch wenn ein späterer Inhalt scheitert)
      if (saved.size > 0) {
        onSaved({
          ...data,
          groups: data.groups.map((g) => ({ ...g, items: g.items.map((i) => ({ ...i, text: saved.get(keyOf(i)) ?? i.text })) })),
        });
        setEdits((x) => Object.fromEntries(Object.entries(x).filter(([k]) => !saved.has(k))));
      }
      setBusy(false);
    }
  }

  const missingTotal = everything.filter((i) => isMissing(i, value(i))).length;
  // Mit Suche oder „Nur fehlende“ alle Abschnitte offen; sonst zu, bis man einen öffnet
  const forced = query.trim() !== '' || onlyMissing;
  const visible = (g: TranslationOverview['groups'][number]) =>
    g.items.filter(
      (i) => matches(i, query, value(i)) && (!onlyMissing || isMissing(i, value(i)) || keyOf(i) in edits || keyOf(i) === open),
    );
  const groupHead = (g: TranslationOverview['groups'][number]) => (
    <summary>
      <span className="row-title">{GROUP_TITLES[g.kind]()}</span>{' '}
      <span className="muted small">
        {t('{done} von {total}', { done: g.items.filter((i) => !isMissing(i, value(i))).length, total: g.items.length })}
      </span>
    </summary>
  );
  const nothing = (n: number) =>
    n === 0 && <p className="small muted">{query.trim() === '' ? t('Alles übersetzt.') : t('Nichts gefunden.')}</p>;
  return (
    <>
      <form className="form translations" onSubmit={submit}>
        <p className="small muted">
          {t(
            'Hier trägst du eigene Namen und Inhalte auf {language} ein. Ein leeres Feld zeigt grau, was Leser ohne Eintrag sehen; meist ist das der deutsche Text.',
            { language },
          )}
        </p>
        <p className="small" role="status">
          {missingTotal === 0
            ? t('Alles übersetzt.')
            : t('{missing} von {total} Texten fehlen noch auf {language}.', { missing: missingTotal, total: everything.length, language })}
        </p>
        <input
          type="search"
          className="search"
          placeholder={t('Suchen …')}
          aria-label={t('Übersetzungen durchsuchen')}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <label className="toggle">
          <input type="checkbox" checked={onlyMissing} onChange={(e) => setOnlyMissing(e.target.checked)} />
          <span>{t('Nur fehlende zeigen')}</span>
        </label>
        {/* Arten ohne Einträge (z. B. noch keine Terminarten) fallen weg */}
        {data.groups
          .filter((g) => g.items.length > 0 && !isBlocks(g))
          .map((g) => {
            const shown = visible(g);
            return (
              <details key={`${g.kind}-${forced}`} className="translation-group" open={forced}>
                {groupHead(g)}
                {nothing(shown.length)}
                {shown.map((i, n) => {
                  const content = !isName(i);
                  // Bei Inhalten über den Feldern eines Inhalts sein Titel (einmal je Inhalt)
                  const heading = content && (n === 0 || shown[n - 1].ref !== i.ref);
                  const field = {
                    lang,
                    maxLength: i.max,
                    value: value(i),
                    placeholder: i.fallback,
                    'aria-label': content
                      ? t('{field} von „{name}“ auf {language}', { field: i.label ?? '', name: i.context ?? '', language })
                      : t('{name} auf {language}', { name: i.source, language }),
                    disabled: busy,
                  };
                  const set = (v: string) => setEdits((x) => ({ ...x, [keyOf(i)]: v }));
                  return (
                    <Fragment key={keyOf(i)}>
                      {heading && (
                        <p className="translation-context row-title" lang={data.source_locale}>
                          {i.context}
                        </p>
                      )}
                      <label className="field translation-row">
                        <span lang={content ? undefined : data.source_locale}>{content ? i.label : i.source}</span>
                        {i.multiline ? (
                          <textarea rows={3} {...field} onChange={(e) => set(e.target.value)} />
                        ) : (
                          <input type="text" {...field} onChange={(e) => set(e.target.value)} />
                        )}
                      </label>
                    </Fragment>
                  );
                })}
              </details>
            );
          })}
        {error && (
          <p className="form-error" role="alert">
            {error}
          </p>
        )}
        <button className="btn btn-block" type="submit" disabled={busy}>
          {busy ? t('Speichert …') : changed.length > 0 ? t('Speichern ({count})', { count: changed.length }) : t('Speichern')}
        </button>
      </form>
      {/* Seiten und Nachrichten am Brett: je Inhalt ein eigenes Formular (Block für Block), außerhalb dieses Formulars */}
      {data.groups
        .filter((g) => g.items.length > 0 && isBlocks(g))
        .map((g) => {
          const shown = visible(g);
          return (
            <details key={`${g.kind}-${forced}`} className="translation-group" open={forced}>
              {groupHead(g)}
              <p className="small muted">{t('Jeden Inhalt übersetzt du Block für Block und speicherst ihn einzeln.')}</p>
              {nothing(shown.length)}
              {shown.map((i) => (
                <div key={keyOf(i)} className="translation-blocks">
                  <div className="translation-blocks-head">
                    <span className="row-title" lang={data.source_locale}>
                      {i.source}
                    </span>{' '}
                    <span className="muted small">{t('{done} von {total} übersetzt', { done: i.done ?? 0, total: i.total ?? 0 })}</span>
                    {keyOf(i) !== open && (
                      <button
                        type="button"
                        className="btn btn-small btn-secondary"
                        aria-label={t('„{name}“ übersetzen', { name: i.source })}
                        onClick={() => setOpen(keyOf(i))}
                      >
                        {t('Übersetzen')}
                      </button>
                    )}
                  </div>
                  {keyOf(i) === open && (
                    <Suspense fallback={<Loading shape="form" chunk />}>
                      <BlocksTranslation
                        kind={i.kind as 'page' | 'board'}
                        id={Number(i.ref)}
                        lang={lang}
                        onToast={onToast}
                        onDone={(saved) => {
                          setOpen(null);
                          if (saved) onReload();
                        }}
                      />
                    </Suspense>
                  )}
                </div>
              ))}
            </details>
          );
        })}
    </>
  );
}
