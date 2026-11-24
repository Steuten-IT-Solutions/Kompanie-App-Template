import { Suspense } from 'react';
import type { BlockTranslation } from '../api';
import { t } from '../i18n';
import { baseLanguageLabel } from '../language';
import RichTextView from '../richtext/RichTextView';
import { lazyView, Loading } from '../ui';
import { BLOCK_KINDS, type BlockDraft } from './blockDrafts';
import { translatableFields } from './blockTranslation';

const RichTextEditor = lazyView(() => import('../richtext/RichTextEditor'));

/** Grenzen wie auf dem Server (Domain/Blocks/*Block.php). */
const MAX: Record<string, number> = { title: 100, running_text: 200, after_text: 200, label: 60, text: 1000, alt: 200, caption: 200 };
const ITEM_MAX: Record<string, number> = { time: 20, text: 200, value: 20, label: 60 };

const FIELD_LABEL = (field: string): string =>
  ({
    title: t('Überschrift'),
    running_text: t('Text, solange es läuft'),
    after_text: t('Text nach dem Ende'),
    label: t('Beschriftung'),
    text: t('Text'),
    alt: t('Beschreibung für Screenreader'),
    caption: t('Bildunterschrift'),
  })[field] ?? field;

const ITEM_LABEL = (field: string): string =>
  ({ time: t('Zeit'), text: t('Programmpunkt'), value: t('Wert'), label: t('Bezeichnung') })[field] ?? field;

type Row = Record<string, string>;

/**
 * Blöcke in einer weiteren Sprache schreiben (Plan „Mehrsprachigkeit“, PR 9b): Aufbau und Einstellungen kommen aus der
 * deutschen Fassung; je Block nur die Texte, leere Felder zeigen grau den deutschen. Blöcke ohne Text (Trennlinie,
 * Umfrage …) gelten für alle Sprachen und stehen hier nicht.
 */
export default function BlocksTranslator({
  german,
  value,
  onChange,
  idPrefix,
}: {
  german: BlockDraft[];
  value: BlockTranslation;
  onChange: (next: BlockTranslation) => void;
  idPrefix: string;
}) {
  const shown = german.filter((b) => translatableFields(b).length > 0);
  if (shown.length === 0) return <p className="muted">{t('Diese Seite hat noch keinen Text zum Übersetzen.')}</p>;
  const set = (id: string, field: string, v: unknown) => onChange({ ...value, [id]: { ...(value[id] ?? {}), [field]: v } });
  return (
    <ul className="stack blocks-translator">
      {shown.map((b) => {
        const rec = b as unknown as Record<string, unknown>;
        const own = value[b.id] ?? {};
        const kind = BLOCK_KINDS.find((k) => k.type === b.type)?.label ?? b.type;
        const id = `${idPrefix}-tr-${b.id}`;
        return (
          <li key={b.id} className="card block-translate">
            <div className="row-sub">{kind}</div>
            {translatableFields(b).map((f) => {
              if (f === 'html') {
                return (
                  <div key={f} className="field">
                    <details className="block-german">
                      <summary className="muted small">{t('Text auf {base}', { base: baseLanguageLabel() })}</summary>
                      <RichTextView html={String(rec.html ?? '')} />
                    </details>
                    <Suspense fallback={<Loading shape="text" chunk />}>
                      <RichTextEditor
                        value={typeof own.html === 'string' ? own.html : ''}
                        onChange={(html) => set(b.id, 'html', html)}
                        label={t('Text')}
                        id={id}
                      />
                    </Suspense>
                  </div>
                );
              }
              if (f === 'items') {
                const base = (rec.items as Row[] | undefined) ?? [];
                const rows = Array.isArray(own.items) ? (own.items as Row[]) : [];
                return (
                  <div key={f} className="block-rows">
                    {base.map((g, i) => (
                      <div key={i} className="block-row field-row">
                        {Object.keys(g).map((k) => (
                          <input
                            key={k}
                            type="text"
                            aria-label={`${ITEM_LABEL(k)} ${i + 1}`}
                            maxLength={ITEM_MAX[k]}
                            placeholder={g[k]}
                            value={rows[i]?.[k] ?? ''}
                            onChange={(e) => {
                              const next = base.map((_, j) => ({ ...(rows[j] ?? {}) }));
                              next[i] = { ...next[i], [k]: e.target.value };
                              set(b.id, 'items', next);
                            }}
                          />
                        ))}
                      </div>
                    ))}
                  </div>
                );
              }
              const long = b.type === 'notice' && f === 'text';
              const props = {
                maxLength: MAX[f],
                placeholder: String(rec[f] ?? ''),
                value: typeof own[f] === 'string' ? (own[f] as string) : '',
                onChange: (e: { target: { value: string } }) => set(b.id, f, e.target.value),
              };
              return (
                <label key={f} className="field">
                  <span>{FIELD_LABEL(f)}</span>
                  {long ? <textarea rows={3} {...props} /> : <input type="text" {...props} />}
                </label>
              );
            })}
          </li>
        );
      })}
    </ul>
  );
}
