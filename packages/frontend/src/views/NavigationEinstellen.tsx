import { useState, type ReactNode } from 'react';
import { api, applyNavigation, config, type Feature, type NavigationAdmin } from '../api';
import { useDragSort } from '../dragSort';
import {
  draftError,
  draftOf,
  moveTo,
  NAV_ICONS,
  NAV_MAX_MAIN,
  NAV_TARGETS,
  placeOf,
  removeMenu,
  unplaced,
  type NavigationSetting,
  type NavMenuSetting,
  type NavPlace,
  type NavTarget,
} from '../navigation';
import { targetLabels } from '../navLabels';
import { ErrorBox, Loading, useAsync } from '../ui';

/** Bereiche der App, ohne die ein Punkt der Navigation für niemanden erscheint. */
const FEATURES: Record<NavTarget, Feature[]> = {
  termine: ['events', 'calendar', 'birthdays'],
  news: ['news'],
  galerie: ['gallery'],
  kompanien: ['companies'],
  sponsoren: ['sponsors'],
  mitglieder: ['directory', 'offices'],
  dokumente: ['documents'],
  brett: ['board'],
  dienstgrade: ['ranks'],
  punktekatalog: ['points'],
};

const ICON_LABELS: Record<string, string> = {
  ordner: 'Ordner',
  termine: 'Kalender',
  news: 'Zeitung',
  galerie: 'Bild',
  kompanien: 'Schild',
  sponsoren: 'Herz',
  mitglieder: 'Personen',
  dokumente: 'Dokument',
  brett: 'Brett',
  dienstgrade: 'Abzeichen',
  punktekatalog: 'Liste',
  stern: 'Stern',
};

const isOn = (t: NavTarget): boolean => FEATURES[t].some((f) => !!config?.features[f]);

/**
 * Einstellungen → Navigation (Issue #277): Hauptpunkte der Tab-Leiste ordnen, Bereiche in Untermenüs schieben oder
 * ausblenden. „Verwaltung“ und „Meins“ stehen immer am Ende. Nur Darstellung: Jeder sieht weiter nur, was er sehen darf.
 * Ohne gespeicherte Einstellung gilt die Standardnavigation; der Entwurf beginnt dann mit ihr.
 */
export default function NavigationEinstellen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.navigation, [], 'navigation');
  if (loading && !data) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Navigation konnte nicht geladen werden.'} onRetry={reload} />;
  return <Editor key={JSON.stringify(data.navigation)} data={data} onSaved={setData} onToast={onToast} />;
}

function Editor({
  data,
  onSaved,
  onToast,
}: {
  data: NavigationAdmin;
  onSaved: (d: NavigationAdmin) => void;
  onToast: (msg: string) => void;
}) {
  const [draft, setDraft] = useState<NavigationSetting>(() => draftOf(data.navigation, isOn));
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const labels = targetLabels();
  const custom = data.navigation !== null;
  const problem = draftError(draft, data.label_max);
  const menus = draft.items.filter((i): i is NavMenuSetting => typeof i !== 'string');
  const newOnes = unplaced(draft);

  const change = (next: NavigationSetting) => {
    setDraft(next);
    setSaveError(null);
  };

  async function save(navigation: NavigationSetting | null) {
    setBusy(true);
    setSaveError(null);
    try {
      const next = await api.saveNavigation(navigation);
      applyNavigation(next.navigation);
      onSaved(next);
      onToast(navigation ? 'Navigation gespeichert' : 'Standardnavigation wiederhergestellt');
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function updateMenu(index: number, patch: Partial<NavMenuSetting>) {
    let m = -1;
    change({
      ...draft,
      items: draft.items.map((i) => {
        if (typeof i === 'string') return i;
        m++;
        return m === index ? { ...i, ...patch } : i;
      }),
    });
  }

  function addMenu() {
    change({ ...draft, items: [...draft.items, { label: `Menü ${menus.length + 1}`, icon: 'ordner', items: [] }] });
  }

  /** Auswahl „Platz“ eines Bereichs: unten, in einem Untermenü oder nicht in der Navigation. */
  const placeSelect = (t: NavTarget) => (
    <select
      aria-label={`Platz von ${labels[t]}`}
      value={String(placeOf(draft, t))}
      disabled={busy}
      onChange={(e) => {
        const v = e.target.value;
        change(moveTo(draft, t, (v === 'main' || v === 'hidden' ? v : Number(v)) as NavPlace));
      }}
    >
      <option value="main">Unten in der Leiste</option>
      {menus.map((m, i) => (
        <option key={i} value={i}>
          Im Untermenü „{m.label.trim() || `Menü ${i + 1}`}“
        </option>
      ))}
      <option value="hidden">Nicht in der Navigation</option>
    </select>
  );

  const targetRow = (t: NavTarget) => (
    <div className="row-main">
      <span className="row-title">{labels[t]}</span>
      {!isOn(t) && <span className="row-sub">Der Bereich ist ausgeschaltet und erscheint erst, wenn er an ist.</span>}
      {placeSelect(t)}
    </div>
  );

  return (
    <section className="card settings-section" id="einstellungen-navigation">
      <h3>Navigation</h3>
      <p className="small muted">
        Welche Bereiche unten in der Leiste stehen, in welcher Reihenfolge, und welche ihr in Untermenüs zusammenfasst. Jede Person sieht
        davon nur, was sie sehen darf. Verwaltung und Meins stehen immer ganz rechts.
      </p>
      <p className="small">
        {custom
          ? 'Zurzeit gilt eure eigene Navigation.'
          : 'Zurzeit gilt die Standardnavigation: Wer das Recht „Intern“ hat, findet die Bereiche darunter gesammelt, alle anderen sehen Mitglieder und Dokumente als eigene Punkte.'}
      </p>

      <h4>Unten in der Leiste</h4>
      <p className="small muted">Höchstens {NAV_MAX_MAIN} Punkte. Zum Ändern der Reihenfolge am Griff ziehen.</p>
      <MainList
        draft={draft}
        onMove={(items) => change({ ...draft, items })}
        disabled={busy}
        labels={labels}
        render={(item, index) => {
          if (typeof item === 'string') return NAV_TARGETS.includes(item as NavTarget) ? targetRow(item as NavTarget) : null;
          return (
            <div className="row-main nav-menu">
              <label className="field">
                <span>Name des Untermenüs</span>
                <input
                  value={item.label}
                  maxLength={data.label_max}
                  disabled={busy}
                  onChange={(e) => updateMenu(index, { label: e.target.value })}
                />
              </label>
              <label className="field">
                <span>Symbol</span>
                <select value={item.icon} disabled={busy} onChange={(e) => updateMenu(index, { icon: e.target.value })}>
                  {NAV_ICONS.map((i) => (
                    <option key={i} value={i}>
                      {ICON_LABELS[i] ?? i}
                    </option>
                  ))}
                </select>
              </label>
              <SubList
                items={item.items.filter((t): t is NavTarget => NAV_TARGETS.includes(t as NavTarget))}
                disabled={busy}
                onMove={(next) => updateMenu(index, { items: next })}
                render={targetRow}
                labels={labels}
              />
              {item.items.length === 0 && <p className="small muted">Noch leer: Wähle bei einem Bereich „Im Untermenü …“.</p>}
              <button
                type="button"
                className="btn btn-small btn-secondary"
                disabled={busy}
                onClick={() => change(removeMenu(draft, index))}
              >
                Untermenü entfernen
              </button>
            </div>
          );
        }}
      />
      <button type="button" className="btn btn-secondary" disabled={busy} onClick={addMenu}>
        Untermenü hinzufügen
      </button>

      {(draft.hidden.length > 0 || newOnes.length > 0) && (
        <>
          <h4>Nicht in der Navigation</h4>
          <ul className="card-list">
            {draft.hidden
              .filter((t): t is NavTarget => NAV_TARGETS.includes(t as NavTarget))
              .map((t) => (
                <li key={t} className="card row">
                  {targetRow(t)}
                </li>
              ))}
            {newOnes.map((t) => (
              <li key={t} className="card row">
                <div className="row-main">
                  <span className="row-title">{labels[t]}</span>
                  <span className="row-sub">Neu: erscheint hinten in der Leiste, bis du ihm einen Platz gibst.</span>
                  {placeSelect(t)}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {(problem || saveError) && (
        <p className="form-error" role="alert">
          {problem ?? saveError}
        </p>
      )}
      <button type="button" className="btn btn-block" disabled={busy || !!problem} onClick={() => void save(draft)}>
        {busy ? 'Speichert …' : 'Navigation speichern'}
      </button>
      {custom && (
        <button
          type="button"
          className="btn btn-block btn-secondary"
          disabled={busy}
          onClick={() => {
            if (window.confirm('Die eigene Navigation wird gelöscht. Danach gilt wieder die Standardnavigation.')) void save(null);
          }}
        >
          Standardnavigation wiederherstellen
        </button>
      )}
    </section>
  );
}

type Item = string | NavMenuSetting;

/** Hauptpunkte: per Ziehen am Griff ordnen. */
function MainList({
  draft,
  onMove,
  disabled,
  render,
  labels,
}: {
  draft: NavigationSetting;
  labels: Record<NavTarget, string>;
  onMove: (items: Item[]) => void;
  disabled: boolean;
  /** index: Nummer des Untermenüs (ab 0), bei Bereichen -1 */
  render: (item: Item, index: number) => ReactNode;
}) {
  const sort = useDragSort(draft.items, onMove, disabled);
  let menu = -1;
  return (
    <ul className="card-list">
      {draft.items.map((item, i) => {
        if (typeof item !== 'string') menu++;
        return (
          <li key={typeof item === 'string' ? item : `menu-${i}`} {...sort.itemProps(i, 'card row')}>
            {draft.items.length > 1 && sort.handle(i, typeof item === 'string' ? (labels[item as NavTarget] ?? item) : item.label)}
            {render(item, typeof item === 'string' ? -1 : menu)}
          </li>
        );
      })}
    </ul>
  );
}

/** Bereiche eines Untermenüs: per Ziehen am Griff ordnen. */
function SubList({
  items,
  onMove,
  disabled,
  render,
  labels,
}: {
  items: NavTarget[];
  onMove: (next: NavTarget[]) => void;
  disabled: boolean;
  render: (t: NavTarget) => ReactNode;
  labels: Record<NavTarget, string>;
}) {
  const sort = useDragSort(items, onMove, disabled);
  if (items.length === 0) return null;
  return (
    <ul className="card-list nav-submenu">
      {items.map((t, i) => (
        <li key={t} {...sort.itemProps(i, 'row')}>
          {items.length > 1 && sort.handle(i, labels[t])}
          {render(t)}
        </li>
      ))}
    </ul>
  );
}
