import { Fragment, useState, type FormEvent } from 'react';
import brand from '../generated/brand';
import { locale, t, type Locale } from '../i18n';
import { enabledLocales, Flag, LANGUAGE_NAMES } from '../language';
import { setNameTranslations } from '../names';
import { api, type TranslationItem, type TranslationKind, type TranslationOverview } from '../api';
import { ErrorBox, Loading, useAsync } from '../ui';
import { contentRequests, isMissing, isName, keyOf, matches } from '../translationOverview';

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
  return <Editor data={data} onSaved={setData} onToast={onToast} />;
}

function Editor({
  data,
  onSaved,
  onToast,
}: {
  data: TranslationOverview;
  onSaved: (d: TranslationOverview) => void;
  onToast: (msg: string) => void;
}) {
  const lang = data.locale;
  const language = LANGUAGE_NAMES[lang];
  const [edits, setEdits] = useState<Record<string, string>>({});
  const [onlyMissing, setOnlyMissing] = useState(false);
  const [query, setQuery] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const value = (i: TranslationItem): string => edits[keyOf(i)] ?? i.text;
  const all = data.groups.flatMap((g) => g.items);
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

  const missingTotal = all.filter((i) => isMissing(i, value(i))).length;
  return (
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
          : t('{missing} von {total} Texten fehlen noch auf {language}.', { missing: missingTotal, total: all.length, language })}
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
        .filter((g) => g.items.length > 0)
        .map((g) => {
          const shown = g.items.filter((i) => matches(i, query, value(i)) && (!onlyMissing || isMissing(i, value(i)) || keyOf(i) in edits));
          const done = g.items.filter((i) => !isMissing(i, value(i))).length;
          const title = GROUP_TITLES[g.kind]();
          // Mit Suche oder „Nur fehlende“ alle Abschnitte offen; sonst zu, bis man einen öffnet
          const forced = query.trim() !== '' || onlyMissing;
          return (
            <details key={`${g.kind}-${forced}`} className="translation-group" open={forced}>
              <summary>
                <span className="row-title">{title}</span>{' '}
                <span className="muted small">{t('{done} von {total}', { done, total: g.items.length })}</span>
              </summary>
              {shown.length === 0 && <p className="small muted">{query.trim() === '' ? t('Alles übersetzt.') : t('Nichts gefunden.')}</p>}
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
  );
}
