/**
 * Sprachwahl (Plan „Mehrsprachigkeit“): Knopf mit Flagge in der Kopfzeile und dieselbe Wahl unter Meins → Konto.
 *
 * Zu sehen nur, wenn der Admin mehr als Deutsch eingeschaltet hat (Einstellungen → Sprachen). Die Wahl gilt am Gerät
 * (auch für Gäste) und, wer angemeldet ist, am Konto (Mails und Push kommen dann in dieser Sprache). Danach lädt die
 * Seite neu: Die Texte stehen beim Laden fest (i18n.ts).
 */
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';
import { api, config } from './api';
import { useBackClose } from './backClose';
import { isLocale, locale, saveDeviceLocale, SOURCE_LOCALE, t, type Locale } from './i18n';

/** Name jeder Sprache in ihr selbst (so findet man die eigene, auch wenn man die aktuelle nicht liest). */
export const LANGUAGE_NAMES: Readonly<Record<Locale, string>> = { de: 'Deutsch', en: 'English' };

/** Eingeschaltete Sprachen; Deutsch immer zuerst. */
export function enabledLocales(): Locale[] {
  const list = (config?.languages ?? []).filter(isLocale);
  return list.includes('de') ? list : ['de', ...list];
}

/** Gibt es etwas zu wählen? */
export const hasLanguageChoice = (): boolean => enabledLocales().length > 1;

/** Sprache wählen: am Gerät merken, am Konto speichern (angemeldet), Seite neu laden. */
export async function chooseLanguage(next: Locale): Promise<void> {
  if (next === locale()) return;
  saveDeviceLocale(next);
  if (config?.user) {
    try {
      await api.setMyLocale(next);
    } catch {
      // Offline oder Fehler: Die Wahl am Gerät gilt trotzdem; am Konto steht sie beim nächsten Mal.
    }
  }
  window.location.reload();
}

/** Flagge als Bild (dekorativ: der Name der Sprache steht daneben bzw. im Namen des Knopfs). */
export function Flag({ lang }: { lang: Locale }) {
  const id = useId().replace(/:/g, '');
  if (lang === 'de') {
    return (
      <svg className="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false">
        <rect width="30" height="20" fill="#000" />
        <rect y="6.67" width="30" height="6.67" fill="#D00" />
        <rect y="13.33" width="30" height="6.67" fill="#FFCE00" />
      </svg>
    );
  }
  // Englisch: diagonal geteilt, unten links Vereinigtes Königreich, oben rechts USA (Sternenfeld rechts, damit es zu sehen ist).
  return (
    <svg className="flag" viewBox="0 0 30 20" aria-hidden="true" focusable="false">
      <defs>
        <clipPath id={`${id}-uk`}>
          <path d="M0 0L30 20H0Z" />
        </clipPath>
        <clipPath id={`${id}-us`}>
          <path d="M0 0H30V20Z" />
        </clipPath>
      </defs>
      <g clipPath={`url(#${id}-uk)`}>
        <rect width="30" height="20" fill="#012169" />
        <path d="M0 0L30 20M30 0L0 20" stroke="#FFF" strokeWidth="4" />
        <path d="M0 0L30 20M30 0L0 20" stroke="#C8102E" strokeWidth="1.4" />
        <path d="M15 0V20M0 10H30" stroke="#FFF" strokeWidth="6" />
        <path d="M15 0V20M0 10H30" stroke="#C8102E" strokeWidth="3.4" />
      </g>
      <g clipPath={`url(#${id}-us)`}>
        <rect width="30" height="20" fill="#B22234" />
        {[1, 3, 5, 7, 9, 11].map((i) => (
          <rect key={i} y={(i * 20) / 13} width="30" height={20 / 13} fill="#FFF" />
        ))}
        <rect x="17" y="0" width="13" height={(7 * 20) / 13} fill="#3C3B6E" />
        {[
          [20, 2.2],
          [24, 2.2],
          [28, 2.2],
          [22, 5],
          [26, 5],
          [24, 8],
          [28, 8],
        ].map(([cx, cy]) => (
          <circle key={`${cx}-${cy}`} cx={cx} cy={cy} r="0.8" fill="#FFF" />
        ))}
      </g>
      <path d="M0 0L30 20" stroke="rgb(0 0 0 / 25%)" strokeWidth="0.6" />
    </svg>
  );
}

/** Kopfzeile: Flagge der aktuellen Sprache, öffnet die Auswahl. */
export function LanguageButton() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const current = locale();
  useBackClose(open, () => setOpen(false));
  useEffect(() => {
    if (!open) return;
    ref.current?.querySelector<HTMLButtonElement>('[aria-checked="true"]')?.focus();
    const outside = (e: PointerEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    const key = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false);
        ref.current?.querySelector<HTMLButtonElement>('.topbar-lang')?.focus();
      }
    };
    document.addEventListener('pointerdown', outside);
    document.addEventListener('keydown', key);
    return () => {
      document.removeEventListener('pointerdown', outside);
      document.removeEventListener('keydown', key);
    };
  }, [open]);
  if (!hasLanguageChoice()) return null;
  const label = t('Sprache: {name}', { name: LANGUAGE_NAMES[current] });
  return (
    <div className="lang-menu-wrap" ref={ref}>
      <button
        type="button"
        className="btn btn-link topbar-lang"
        aria-label={label}
        title={label}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen(!open)}
      >
        <Flag lang={current} />
      </button>
      {open && (
        <ul className="lang-menu" role="menu" aria-label={t('Sprache wählen')}>
          {enabledLocales().map((l) => (
            <li key={l} role="none">
              <button
                type="button"
                role="menuitemradio"
                aria-checked={l === current}
                lang={l}
                onClick={() => {
                  setOpen(false);
                  void chooseLanguage(l);
                }}
              >
                <Flag lang={l} />
                <span>{LANGUAGE_NAMES[l]}</span>
                {l === current && (
                  <span className="lang-check" aria-hidden="true">
                    ✓
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Meins → Konto: dieselbe Wahl als Knöpfe nebeneinander. */
export function LanguageCard() {
  if (!hasLanguageChoice()) return null;
  const current = locale();
  return (
    <div className="card account-card">
      <h3>{t('Sprache')}</h3>
      <p className="muted small">{t('Gilt für die App, für Mails und für Benachrichtigungen an dich.')}</p>
      <nav className="segmented lang-choice" aria-label={t('Sprache')}>
        {enabledLocales().map((l) => (
          <button
            key={l}
            type="button"
            lang={l}
            className={l === current ? 'active' : ''}
            aria-pressed={l === current}
            onClick={() => void chooseLanguage(l)}
          >
            <Flag lang={l} /> {LANGUAGE_NAMES[l]}
          </button>
        ))}
      </nav>
    </div>
  );
}

/**
 * „Einrichten auf“ (Plan „Mehrsprachigkeit“, Einrichten je Sprache): In welcher Sprache der Admin gerade eigene Namen
 * einträgt. Deutsch ist die Hauptfassung; in einer weiteren Sprache zeigen die Masken deren Namen, leere Felder den
 * deutschen als Hinweis. Die Wahl gilt am Gerät, bis sie jemand ändert; ohne weitere Sprache immer Deutsch.
 */
const EDIT_KEY = 'kompanie-edit-locale';
const editListeners = new Set<() => void>();
let editChoice: Locale | null = null;

function storedEditLocale(): Locale {
  if (editChoice === null) {
    try {
      const v = globalThis.localStorage?.getItem(EDIT_KEY);
      editChoice = isLocale(v) ? v : SOURCE_LOCALE;
    } catch {
      editChoice = SOURCE_LOCALE;
    }
  }
  return enabledLocales().includes(editChoice) ? editChoice : SOURCE_LOCALE;
}

/** Sprache, auf der gerade eingerichtet wird. */
export const editLocale = (): Locale => storedEditLocale();

export function setEditLocale(next: Locale): void {
  editChoice = next;
  try {
    globalThis.localStorage?.setItem(EDIT_KEY, next);
  } catch {
    // ohne Speicher: gilt bis zum Neuladen
  }
  editListeners.forEach((l) => l());
}

/** Sprache, auf der gerade eingerichtet wird, und Neuzeichnen bei einem Wechsel. */
export function useEditLocale(): Locale {
  return useSyncExternalStore((l) => {
    editListeners.add(l);
    return () => editListeners.delete(l);
  }, storedEditLocale);
}

/**
 * Wahl „Einrichten auf: Deutsch | English“ oben in den Einstellungen; nur mit weiterer Sprache. Mit content über einem
 * Editor für Inhalte („Schreiben auf“, Plan „Mehrsprachigkeit“, PR 9b): dieselbe Wahl, eigener Hinweis.
 */
export function EditLanguageSwitch({ content = false }: { content?: boolean }) {
  const current = useEditLocale();
  if (!hasLanguageChoice()) return null;
  return (
    <div className="edit-lang">
      <span className="muted small">{content ? t('Schreiben auf:') : t('Einrichten auf:')}</span>
      <nav className="segmented lang-choice" aria-label={content ? t('Inhalt schreiben auf') : t('Eigene Namen einrichten auf')}>
        {enabledLocales().map((l) => (
          <button
            key={l}
            type="button"
            lang={l}
            className={l === current ? 'active' : ''}
            aria-pressed={l === current}
            onClick={() => setEditLocale(l)}
          >
            <Flag lang={l} /> {LANGUAGE_NAMES[l]}
          </button>
        ))}
      </nav>
      {current !== SOURCE_LOCALE && (
        <p className="muted small">
          {content
            ? t(
                'Du schreibst die Fassung für {language}. Leere Felder zeigen grau den deutschen Text; er gilt, solange nichts eingetragen ist. Aufbau und Einstellungen änderst du auf Deutsch.',
                { language: LANGUAGE_NAMES[current] },
              )
            : t(
                'Du trägst die Namen für {language} ein. Leere Felder zeigen den deutschen Namen; er gilt, solange nichts eingetragen ist.',
                {
                  language: LANGUAGE_NAMES[current],
                },
              )}
        </p>
      )}
    </div>
  );
}

/** Hinweis über einem Inhalt, der in der Sprache der App (noch) fehlt: untranslated aus der Antwort des Servers. */
export function UntranslatedNote({ untranslated }: { untranslated?: 'all' | 'some' | null }) {
  if (!untranslated) return null;
  return (
    <p className="untranslated-note muted small">
      {untranslated === 'all' ? t('Nur auf Deutsch verfügbar') : t('Teilweise nur auf Deutsch verfügbar')}
    </p>
  );
}
