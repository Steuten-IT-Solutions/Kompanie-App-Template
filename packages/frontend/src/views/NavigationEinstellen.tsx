import { useState, type ReactNode } from 'react';
import { defaultSections } from '../generated/brand';
import { adminTabs, adminTileCatalog } from '../adminNav';
import { api, applyNavigation, config, type Feature, type NavigationAdmin, type PageSummary } from '../api';
import { useDragSort } from '../dragSort';
import {
  draftError,
  draftOf,
  internOrderOf,
  isPageTarget,
  mergeOrder,
  orderToSave,
  SETUP,
  setupOrder,
  startOrder,
  moveTo,
  NAV_ICONS,
  NAV_TARGETS,
  pageTarget,
  placeOf,
  removeMenu,
  startChoices,
  targetIcon,
  unplaced,
  type FixedTarget,
  type NavigationSetting,
  type NavMenuSetting,
  type NavNames,
  type NavPlace,
  type NavStart,
  type NavTarget,
  type PageTarget,
} from '../navigation';
import { ErrorBox, Loading, useAsync } from '../ui';
import { confirmAsk } from '../ask';

/** Bereiche der App, ohne die ein Punkt der Navigation für niemanden erscheint. */
const FEATURES: Record<FixedTarget, Feature[]> = {
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
  seiten: 'Seite',
  stern: 'Stern',
  info: 'Info',
  karte: 'Karte',
  musik: 'Musik',
  pokal: 'Pokal',
  glocke: 'Glocke',
  fahne: 'Fahne',
  kamera: 'Kamera',
  haus: 'Haus',
  buch: 'Buch',
  uhr: 'Uhr',
};

/** Ab so vielen Punkten unten (mit Verwaltung und Meins) wird es auf schmalen Handys eng (Issue #362). */
const CROWDED = 8;

/** Standardname eines Bereichs (kompanie.json bzw. Standard), ohne eigenen Namen der Installation. */
function defaultLabel(t: string, pages: PageSummary[]): string {
  const sec = defaultSections;
  const f = config?.features;
  const fixed: Record<string, string> = {
    termine: sec.events,
    news: sec.news,
    galerie: sec.gallery,
    kompanien: sec.companies,
    sponsoren: sec.sponsors,
    mitglieder: f?.directory || !f?.offices ? sec.directory : sec.offices,
    dokumente: sec.documents,
    brett: sec.board,
    dienstgrade: sec.ranks,
    punktekatalog: sec.catalog,
    intern: sec.intern,
  };
  return fixed[t] ?? pages.find((p) => pageTarget(p.id) === t)?.title ?? t;
}

const isOn = (t: NavTarget): boolean => (isPageTarget(t) ? !!config?.features.pages : FEATURES[t].some((f) => !!config?.features[f]));

type Labels = Record<string, string | undefined>;

/**
 * Einstellungen → Navigation (Issue #277): Hauptpunkte der Tab-Leiste ordnen, Bereiche in Untermenüs schieben oder
 * ausblenden. „Verwaltung“ und „Meins“ stehen immer am Ende. Nur Darstellung: Jeder sieht weiter nur, was er sehen darf.
 * Ohne gespeicherte Einstellung gilt die Standardnavigation; der Entwurf beginnt dann mit ihr.
 */
export default function NavigationEinstellen({ onToast }: { onToast: (msg: string) => void }) {
  const { data, error, loading, reload, setData } = useAsync(api.navigation, [], 'navigation');
  // Eigene Seiten (Issue #287): jede ist ein eigener Punkt; Titel aktuell vom Server (auch gerade angelegte).
  const pagesOn = !!config?.features.pages;
  const pages = useAsync(pagesOn ? api.pages : () => Promise.resolve([] as PageSummary[]), [pagesOn]);
  if ((loading && !data) || (pages.loading && !pages.data)) return <Loading shape="form" />;
  if (error || !data) return <ErrorBox message={error ?? 'Die Navigation konnte nicht geladen werden.'} onRetry={reload} />;
  if (pages.error || !pages.data)
    return <ErrorBox message={pages.error ?? 'Die Seiten konnten nicht geladen werden.'} onRetry={pages.reload} />;
  return (
    <Editor
      key={JSON.stringify([data.navigation, data.names, data.start, data.show_labels, data.intern_order, data.admin_order])}
      data={data}
      pages={pages.data}
      onSaved={setData}
      onToast={onToast}
    />
  );
}

function Editor({
  data,
  pages,
  onSaved,
  onToast,
}: {
  data: NavigationAdmin;
  pages: PageSummary[];
  onSaved: (d: NavigationAdmin) => void;
  onToast: (msg: string) => void;
}) {
  const pageTargets: PageTarget[] = pages.map((p) => pageTarget(p.id));
  const known = (t: string): t is NavTarget => NAV_TARGETS.includes(t as FixedTarget) || pageTargets.includes(t as PageTarget);
  // Eigene Namen und Symbole, Startseiten, Namen in der Leiste (Issue #362); leer kommt vom Server ggf. als [].
  const savedNames: NavNames = Array.isArray(data.names) ? {} : data.names;
  const savedStart: NavStart = Array.isArray(data.start) ? {} : data.start;
  const [names, setNames] = useState<NavNames>(savedNames);
  const [start, setStart] = useState<NavStart>(savedStart);
  const [showLabels, setShowLabels] = useState(data.show_labels);
  const internLabel = savedNames.intern?.label || defaultLabel('intern', pages);
  const savedInternOrder = data.intern_order ?? [];
  const [initial] = useState<NavigationSetting>(() => draftOf(data.navigation, isOn, pageTargets, internLabel, savedInternOrder));
  // Reihenfolge der Verwaltung (für alle gleich), je Gruppe; die Kacheln bleiben in ihrer Gruppe.
  const [adminStart, setAdminStart] = useState<string[]>(() => startOrder(data.admin_order));
  const [adminSetup, setAdminSetup] = useState<string[]>(() => setupOrder(data.admin_order));
  const [draft, setDraft] = useState<NavigationSetting>(initial);
  const [busy, setBusy] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const custom = data.navigation !== null;
  // Unverändert aus der Standardnavigation, höchstens „Intern“ anders geordnet: Sie bleibt (auch mit eigenen Namen), statt
  // zur eigenen Navigation zu werden; die Reihenfolge in „Intern“ wird dann eigens gespeichert.
  const internOrder = custom ? null : internOrderOf(initial, draft);
  const keepDefault = internOrder !== null;
  const labels: Labels = Object.fromEntries(
    [...NAV_TARGETS, ...pageTargets, 'intern'].map((t) => [t, names[t]?.label?.trim() || defaultLabel(t, pages)]),
  );
  const problem = draftError(draft, data.label_max) ?? namesError(names, data.label_max);
  const menus = draft.items.filter((i): i is NavMenuSetting => typeof i !== 'string');
  const newOnes = unplaced(draft, pageTargets);
  const mainCount = (keepDefault ? 5 : draft.items.length + newOnes.length) + 2;
  const duplicates = duplicateNames(
    Object.fromEntries(Object.entries(labels).filter(([k]) => k !== 'intern' && known(k) && isOn(k) && !draft.hidden.includes(k))),
    keepDefault ? [labels.intern ?? ''] : menus.map((m) => m.label.trim()),
  );

  const change = (next: NavigationSetting) => {
    setDraft(next);
    setSaveError(null);
  };
  const rename = (key: string, patch: { label?: string; icon?: string }) => {
    setNames((n) => ({ ...n, [key]: { ...n[key], ...patch } }));
    setSaveError(null);
  };

  async function save(reset: boolean) {
    setBusy(true);
    setSaveError(null);
    const clean: NavNames = Object.fromEntries(
      Object.entries(names).flatMap(([k, v]) => {
        const one = { ...(v?.label?.trim() ? { label: v.label.trim() } : {}), ...(v?.icon ? { icon: v.icon } : {}) };
        return Object.keys(one).length > 0 ? [[k, one]] : [];
      }),
    );
    // Reihenfolge in „Intern“: nur speichern, wenn sie von der Standardreihenfolge abweicht.
    const std = draftOf(null, isOn, pageTargets, internLabel).items.at(-1);
    const standardIntern = typeof std === 'object' ? std.items : [];
    const ownIntern = keepDefault && internOrder.join() !== standardIntern.join() ? internOrder : null;
    const body = reset
      ? { navigation: null, names: {}, start: {}, show_labels: true, intern_order: null }
      : {
          navigation: keepDefault ? null : draft,
          names: clean,
          start,
          show_labels: showLabels,
          ...(keepDefault ? { intern_order: ownIntern } : {}),
          admin_order: orderToSave(adminStart, adminSetup),
        };
    // Neue Namen stehen überall in der App (auch in Überschriften): dann einmal neu laden.
    const reload = JSON.stringify(body.names) !== JSON.stringify(savedNames) || body.show_labels !== data.show_labels;
    try {
      const next = await api.saveNavigation(body);
      applyNavigation(next);
      onSaved(next);
      onToast(reset ? 'Standardnavigation wiederhergestellt' : 'Navigation gespeichert');
      if (reload) setTimeout(() => window.location.reload(), 800);
    } catch (err) {
      setSaveError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  /** Startseite: Auswahl aus dem, was in der Leiste steht (für Angemeldete auch „Meins“). */
  const startLabel = (t: string): string => {
    if (t === 'meins') return 'Meins';
    if (t === 'intern') return labels.intern ?? defaultLabel('intern', pages);
    const m = /^menue\/(\d+)$/.exec(t);
    if (m) return `Untermenü „${menus[Number(m[1]) - 1]?.label.trim() || `Menü ${m[1]}`}“`;
    return labels[t] ?? t;
  };
  const startSelect = (who: 'guest' | 'member', title: string) => {
    const choices = startChoices(keepDefault ? null : draft, who === 'member', pageTargets).filter(
      (t) => !known(t) || (isOn(t) && !draft.hidden.includes(t)),
    );
    return (
      <label className="field">
        <span>{title}</span>
        <select
          value={start[who] ?? ''}
          disabled={busy}
          onChange={(e) => {
            setStart({ ...start, [who]: e.target.value || undefined });
            setSaveError(null);
          }}
        >
          <option value="">Erster Punkt der Leiste</option>
          {choices.map((t) => (
            <option key={t} value={t}>
              {startLabel(t)}
            </option>
          ))}
        </select>
      </label>
    );
  };

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

  /** Eigener Name und eigenes Symbol (Issue #362); Platzhalter ist der Standardname bzw. der Titel der Seite. */
  const renameFields = (key: string, fallbackIcon: string) => {
    const standard = defaultLabel(key, pages);
    return (
      <details className="nav-rename">
        <summary>Umbenennen{names[key]?.label?.trim() ? ` (Standard: ${standard})` : ''}</summary>
        <label className="field">
          <span>{isPageTarget(key) ? 'Name im Tab (leer = Titel der Seite)' : 'Eigener Name (leer = Standard)'}</span>
          <input
            type="text"
            value={names[key]?.label ?? ''}
            placeholder={standard}
            maxLength={data.label_max}
            disabled={busy}
            onChange={(e) => rename(key, { label: e.target.value })}
          />
        </label>
        <label className="field">
          <span>Symbol</span>
          <select value={names[key]?.icon ?? ''} disabled={busy} onChange={(e) => rename(key, { icon: e.target.value || undefined })}>
            <option value="">Standard ({ICON_LABELS[fallbackIcon] ?? fallbackIcon})</option>
            {NAV_ICONS.map((i) => (
              <option key={i} value={i}>
                {ICON_LABELS[i] ?? i}
              </option>
            ))}
          </select>
        </label>
      </details>
    );
  };

  const targetRow = (t: NavTarget) => (
    <div className="row-main">
      <span className="row-title">{labels[t]}</span>
      {isPageTarget(t) && <span className="row-sub row-kind">Eigene Seite</span>}
      {!isOn(t) && <div className="row-sub">Der Bereich ist ausgeschaltet und erscheint erst, wenn er an ist.</div>}
      {placeSelect(t)}
      {renameFields(t, targetIcon(t))}
    </div>
  );

  return (
    <section className="card settings-section" id="einstellungen-navigation">
      <h3>Navigation</h3>
      <p className="small">
        {custom
          ? 'Zurzeit gilt eure eigene Navigation.'
          : `Zurzeit gilt die Standardnavigation: Wer das Recht „Intern“ hat, findet die Bereiche unter „${labels.intern}“ gesammelt, alle anderen sehen ${labels.mitglieder} und ${labels.dokumente} als eigene Punkte.`}
      </p>

      <h4>Unten in der Leiste</h4>
      <p className="small muted">
        Zum Ändern der Reihenfolge am Griff ziehen. Je mehr Punkte unten stehen, desto kleiner werden Schrift und Symbole.
      </p>
      {mainCount >= CROWDED && (
        <p className="small muted" role="note">
          Mit {mainCount} Punkten (mit Verwaltung und Meins) wird es auf schmalen Handys eng.
        </p>
      )}
      <MainList
        draft={draft}
        onMove={(items) => change({ ...draft, items })}
        disabled={busy}
        labels={labels}
        render={(item, index) => {
          if (typeof item === 'string') return known(item) ? targetRow(item) : null;
          return (
            <div className="row-main nav-menu">
              <label className="field">
                <span>Name des Untermenüs</span>
                <input
                  type="text"
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
                items={item.items.filter(known)}
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
            {draft.hidden.filter(known).map((t) => (
              <li key={t} className="card row">
                {targetRow(t)}
              </li>
            ))}
            {newOnes.map((t) => (
              <li key={t} className="card row">
                <div className="row-main">
                  <span className="row-title">{labels[t]}</span>
                  <div className="row-sub">Neu: erscheint hinten in der Leiste, bis du ihm einen Platz gibst.</div>
                  {placeSelect(t)}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {!custom && (
        <>
          <h4>Untermenü der Standardnavigation</h4>
          <ul className="card-list">
            <li className="card row">
              <div className="row-main">
                <span className="row-title">{labels.intern ?? defaultLabel('intern', pages)}</span>
                <div className="row-sub">
                  Sammelt in der Standardnavigation die internen Bereiche. Ihre Reihenfolge änderst du oben im Untermenü am Griff; die
                  Standardnavigation bleibt dabei erhalten.
                </div>
                {renameFields('intern', 'intern')}
              </div>
            </li>
          </ul>
        </>
      )}

      <h4>Startseite</h4>
      <p className="small muted">
        Was beim Öffnen der App erscheint. Wer die Startseite nicht sehen darf, landet auf dem ersten Punkt, den er sehen darf. Geteilte
        Links und Benachrichtigungen öffnen weiter ihre eigene Seite.
      </p>
      {startSelect('guest', 'Ohne Anmeldung')}
      {startSelect('member', 'Angemeldet')}

      <h4>Darstellung</h4>
      <label className="toggle">
        <input
          type="checkbox"
          checked={showLabels}
          disabled={busy}
          onChange={(e) => {
            setShowLabels(e.target.checked);
            setSaveError(null);
          }}
        />
        <span>Namen unter den Symbolen zeigen</span>
      </label>
      {!showLabels && <p className="small muted">Unten stehen nur die Symbole; die Namen stehen weiter oben auf jeder Seite.</p>}

      <h4>Verwaltung</h4>
      <p className="small muted">
        Reihenfolge der Knöpfe in der Verwaltung, für alle gleich. Zum Ändern am Griff ziehen. Jede Person sieht weiter nur die Knöpfe, die
        sie nutzen darf.
      </p>
      <AdminOrderList
        title="Startseite der Verwaltung"
        ids={adminStart}
        disabled={busy}
        onChange={(next) => {
          setAdminStart(next);
          setSaveError(null);
        }}
      />
      <AdminOrderList
        title="Einrichtungen"
        ids={adminSetup}
        disabled={busy}
        onChange={(next) => {
          setAdminSetup(next);
          setSaveError(null);
        }}
      />
      {Object.keys(orderToSave(adminStart, adminSetup)).length > 0 && (
        <button
          type="button"
          className="btn btn-small btn-secondary"
          disabled={busy}
          onClick={() => {
            setAdminStart(startOrder(null));
            setAdminSetup(setupOrder(null));
            setSaveError(null);
          }}
        >
          Standardreihenfolge der Verwaltung
        </button>
      )}

      {duplicates.length > 0 && (
        <p className="small muted" role="note">
          Mehrere Punkte heißen gleich: {duplicates.map((d) => `„${d}“`).join(', ')}.
        </p>
      )}
      {(problem || saveError) && (
        <p className="form-error" role="alert">
          {problem ?? saveError}
        </p>
      )}
      <button type="button" className="btn btn-block" disabled={busy || !!problem} onClick={() => void save(false)}>
        {busy ? 'Speichert …' : 'Navigation speichern'}
      </button>
      {(custom ||
        Object.keys(savedNames).length > 0 ||
        Object.keys(savedStart).length > 0 ||
        !data.show_labels ||
        savedInternOrder.length > 0) && (
        <button
          type="button"
          className="btn btn-block btn-secondary"
          disabled={busy}
          onClick={async () => {
            if (
              await confirmAsk(
                'Alles in der Navigation wird zurückgesetzt: Reihenfolge und Untermenüs, eigene Namen und Symbole, Startseiten und die Namen unter den Symbolen. Danach gilt wieder die Standardnavigation.',
              )
            )
              void save(true);
          }}
        >
          Standardnavigation wiederherstellen
        </button>
      )}
    </section>
  );
}

type Item = string | NavMenuSetting;

/** Prüfung der eigenen Namen vor dem Speichern (wie der Server); null = in Ordnung. */
function namesError(names: NavNames, labelMax: number): string | null {
  for (const v of Object.values(names)) {
    const label = v?.label?.trim() ?? '';
    if (label.length > labelMax) return `Ein Name darf höchstens ${labelMax} Zeichen lang sein („${label}“).`;
  }
  return null;
}

/** Namen, die mehr als ein Punkt trägt (nur ein Hinweis, Speichern geht trotzdem). */
function duplicateNames(labels: Labels, menuLabels: string[]): string[] {
  const seen = new Map<string, number>();
  for (const l of [...Object.values(labels), ...menuLabels]) {
    const k = (l ?? '').trim();
    if (k) seen.set(k, (seen.get(k) ?? 0) + 1);
  }
  return [...seen].filter(([, n]) => n > 1).map(([k]) => k);
}

/** Hauptpunkte: per Ziehen am Griff ordnen. */
function MainList({
  draft,
  onMove,
  disabled,
  render,
  labels,
}: {
  draft: NavigationSetting;
  labels: Labels;
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
            {draft.items.length > 1 && sort.handle(i, typeof item === 'string' ? (labels[item] ?? item) : item.label)}
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
  labels: Labels;
}) {
  const sort = useDragSort(items, onMove, disabled);
  if (items.length === 0) return null;
  return (
    <ul className="card-list nav-submenu">
      {items.map((t, i) => (
        <li key={t} {...sort.itemProps(i, 'row')}>
          {items.length > 1 && sort.handle(i, labels[t] ?? t)}
          {render(t)}
        </li>
      ))}
    </ul>
  );
}

/**
 * Kacheln einer Gruppe der Verwaltung per Ziehen ordnen. Gezeigt werden die, deren Bereich an ist; die übrigen behalten
 * ihren Platz in der gespeicherten Folge (mergeOrder).
 */
function AdminOrderList({
  title,
  ids,
  disabled,
  onChange,
}: {
  title: string;
  ids: string[];
  disabled: boolean;
  onChange: (next: string[]) => void;
}) {
  const catalog = adminTileCatalog();
  const shown = ids.filter((id) => catalog[id]?.on);
  const sort = useDragSort(shown, (next) => onChange(mergeOrder(ids, next)), disabled);
  const own = new Set(adminTabs().map((t) => t.id));
  if (shown.length === 0) return null;
  return (
    <>
      <p className="small">
        <strong>{title}</strong>
      </p>
      <ul className="card-list">
        {shown.map((id, i) => (
          <li key={id} {...sort.itemProps(i, 'card row')}>
            {shown.length > 1 && sort.handle(i, catalog[id]?.label ?? id)}
            <div className="row-main">
              <span className="row-title">{catalog[id]?.label ?? id}</span>
              {id !== SETUP && !own.has(id) && <div className="row-sub">Siehst du selbst nicht (fehlendes Recht).</div>}
            </div>
          </li>
        ))}
      </ul>
    </>
  );
}
