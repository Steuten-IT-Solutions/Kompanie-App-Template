/**
 * Fassungen einfacher Textfelder je weitere Sprache (Plan „Mehrsprachigkeit“, PR 9d: Termine und Umfragen). Je
 * eingeschaltete Sprache ein aufklappbarer Abschnitt; leere Felder zeigen grau den deutschen Text und gelten deutsch.
 */
import { useState, type ReactNode } from 'react';
import { enabledLocales, LANGUAGE_NAMES } from './language';
import { t, type Locale } from './i18n';

export type FieldValues = Record<string, string>;
export type FieldTranslations = Partial<Record<Locale, FieldValues>>;

export interface TranslatedField {
  key: string;
  /** Beschriftung für die Sprache (Name der Sprache) */
  label: (language: string) => string;
  multiline?: boolean;
  maxLength?: number;
}

export const extraLocales = (): Locale[] => enabledLocales().filter((l) => l !== 'de');

const trimmed = (keys: string[], own: FieldValues | undefined): FieldValues =>
  Object.fromEntries(keys.map((k) => [k, (own?.[k] ?? '').trim()]));

/**
 * Fassungen für die Anfrage: beim Anlegen (ohne $before) nur Sprachen, in denen etwas steht, beim Ändern nur Sprachen,
 * die sich gegenüber $before ändern; jeweils alle Felder (leer = deutsch). undefined, wenn nichts zu senden ist.
 */
export function fieldTranslationsInput(keys: string[], value: FieldTranslations, before?: FieldTranslations) {
  const out: Partial<Record<Locale, FieldValues>> = {};
  for (const lang of extraLocales()) {
    const next = trimmed(keys, value[lang]);
    const changed =
      before === undefined
        ? Object.values(next).some((v) => v !== '')
        : JSON.stringify(next) !== JSON.stringify(trimmed(keys, before[lang]));
    if (changed) out[lang] = next;
  }
  return Object.keys(out).length > 0 ? out : undefined;
}

export function FieldTranslationsEditor(props: {
  fields: TranslatedField[];
  /** Deutsche Werte als grauer Platzhalter */
  german: FieldValues;
  value: FieldTranslations;
  onChange: (next: FieldTranslations) => void;
  /** Hinweis unter der Überschrift (Name der Sprache) */
  hint: (language: string) => string;
  /** Weitere Felder je Sprache (z. B. Antwortmöglichkeiten einer Umfrage) */
  extra?: (lang: Locale) => ReactNode;
  /** Abschnitt offen, auch wenn noch nichts eingetragen ist */
  open?: boolean;
}) {
  const { fields, german, value, onChange } = props;
  return (
    <>
      {extraLocales().map((lang) => {
        const own = value[lang] ?? {};
        const language = LANGUAGE_NAMES[lang];
        const filled = Object.values(own).some((v) => v !== '');
        return (
          <Section key={lang} initiallyOpen={!!props.open || filled}>
            <summary>{t('Auf {language} schreiben (freiwillig)', { language })}</summary>
            <p className="muted small">{props.hint(language)}</p>
            {fields.map((f) => {
              const set = (v: string) => onChange({ ...value, [lang]: { ...own, [f.key]: v } });
              return (
                <label key={f.key} className="field">
                  <span>{f.label(language)}</span>
                  {f.multiline ? (
                    <textarea rows={3} value={own[f.key] ?? ''} placeholder={german[f.key] ?? ''} onChange={(e) => set(e.target.value)} />
                  ) : (
                    <input
                      type="text"
                      maxLength={f.maxLength}
                      value={own[f.key] ?? ''}
                      placeholder={german[f.key] ?? ''}
                      onChange={(e) => set(e.target.value)}
                    />
                  )}
                </label>
              );
            })}
            {props.extra?.(lang)}
          </Section>
        );
      })}
    </>
  );
}

/** Aufklappbar; offen bleibt, was einmal offen ist (auch wenn alle Felder wieder leer werden). */
function Section({ initiallyOpen, children }: { initiallyOpen: boolean; children: ReactNode }) {
  const [open, setOpen] = useState(initiallyOpen);
  return (
    <details className="board-translation" open={open} onToggle={(e) => setOpen(e.currentTarget.open)}>
      {children}
    </details>
  );
}
